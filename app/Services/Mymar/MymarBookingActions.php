<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Exceptions\AppointmentPlacementConflict;
use App\Models\MymarBookingRequest;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MymarBookingActions
{
    public function __construct(private MymarActionSettings $settings, private MymarPendingBookings $pending, private Factory $http, private MymarBookingReaderSettings $reader) {}

    public function submit(User $actor, array $data, string $action): array
    {
        $config = $this->configuration($actor, true);
        $reservation = null;
        $payload = ['format' => 'cofrac.mymar-booking-command', 'version' => 1, 'request_id' => $data['request_id'],
            'action' => $action, 'dossier_id' => (int) $data['dossier_id'], 'cofrac_actor_id' => $actor->id, 'expected_revision' => $data['expected_revision']];
        if ($action === 'place') {
            $nativeId = (int) $data['technician_id'];
            $sourceId = collect($config['bindings'])->firstWhere('cofrac_user_id', $nativeId)['source_technician_id'] ?? null;
            if ($sourceId === null) {
                throw ValidationException::withMessages(['technician_id' => 'Ce technicien n’a pas de correspondance MAR validée.']);
            }
            $payload += ['technician_id' => $sourceId, 'starts_at' => $data['starts_at'], 'duration_minutes' => (int) $data['duration_minutes']];
            $start = Carbon::instance(MymarBookingCommand::instant($data['starts_at']));
            $reservation = ['cofrac_technician_id' => $nativeId, 'reserved_starts_at' => $start->format('Y-m-d H:i:s'),
                'reserved_ends_at' => $start->copy()->addMinutes((int) $data['duration_minutes'])->format('Y-m-d H:i:s')];
        } else {
            $payload += ['comment' => $data['comment'], 'reason' => $data['reason'] ?? null, 'recall_at' => $data['recall_at'] ?? null];
        }
        $command = MymarBookingCommand::fromArray($payload);
        $outbox = $this->outbox($config);
        // Reopening a known request never requires the source to still be pending.
        $known = MymarBookingRequest::query()->find($data['request_id']);
        if ($known) {
            abort_unless($known->author_id === $actor->id && hash_equals($known->fingerprint, $command->fingerprint()), 409, 'Cette référence correspond à une autre demande.');

            return $this->result($this->process($outbox, $known->id), $actor);
        }
        if ($reservation !== null && $start->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Choisissez un créneau à venir.']);
        }
        $source = $this->pending->find($command->payload['dossier_id'], true);
        if ($source === null || ! hash_equals($source['expected_revision'], $command->payload['expected_revision'])) {
            throw ValidationException::withMessages(['dossier_id' => 'Le dossier MAR a changé. Actualisez la liste avant de continuer.']);
        }
        $record = DB::transaction(function () use ($outbox, $command, $actor, $reservation, $source): MymarBookingRequest {
            if ($reservation !== null) {
                $technician = $this->lockTechnician($reservation['cofrac_technician_id']);
                $start = Carbon::parse($reservation['reserved_starts_at'], 'Europe/Paris');
                $end = Carbon::parse($reservation['reserved_ends_at'], 'Europe/Paris');
                $checks = app(AppointmentSlotAvailability::class)->inspect($technician, $start, $end, $source);
                if ($checks['conflicts'] !== []) {
                    throw AppointmentPlacementConflict::fromConflicts($checks['conflicts'], canForce: false);
                }
            }

            return $outbox->enqueue($command, $actor, $reservation);
        });

        return $this->result($this->process($outbox, $record->id), $actor);
    }

    public function status(User $actor, string $id): array
    {
        $config = $this->configuration($actor, false);
        $record = MymarBookingRequest::query()->findOrFail($id);
        abort_unless($record->author_id === $actor->id || $actor->admin, 403);
        // A GET never initiates an unsent command, even if a browser repeatedly polls it.
        if (in_array($record->status, ['dispatching', 'uncertain'], true)) {
            if ($this->reader->load() === null) {
                throw new RuntimeException('mymar.booking_reader_disabled');
            }
            $client = new MymarBookingClient($this->http, enabled: true, writesEnabled: false, port: 19847, token: $this->reader->token());
            $record = $this->process(new MymarBookingOutbox($client, true), $id);
        }

        return $this->result($record, $actor);
    }

    private function process(MymarBookingOutbox $outbox, string $id): MymarBookingRequest
    {
        $before = MymarBookingRequest::query()->findOrFail($id)->status;
        $record = $outbox->process($id, function (MymarBookingCommand $command, \Closure $send): MymarBookingAttempt {
            try {
                return DB::transaction(function () use ($command, $send): MymarBookingAttempt {
                    $actor = User::query()->lockForUpdate()->find($command->payload['cofrac_actor_id']);
                    $this->configuration($actor, true);
                    if ($command->payload['action'] === 'place') {
                        $record = MymarBookingRequest::query()->findOrFail($command->payload['request_id']);
                        $technician = $this->lockTechnician($record->cofrac_technician_id);
                        $start = Carbon::parse($record->reserved_starts_at->format('Y-m-d H:i:s'), 'Europe/Paris');
                        $end = Carbon::parse($record->reserved_ends_at->format('Y-m-d H:i:s'), 'Europe/Paris');
                        if ($start->isPast()) {
                            throw ValidationException::withMessages(['starts_at' => 'Le créneau est passé.']);
                        }
                        // Held through the bounded POST; an uncertain result keeps its reservation.
                        app(AppointmentSlotAvailability::class)->assertAvailable($technician, $start, $end,
                            ['mymar_request_id' => $record->id, 'location_precision' => 'unknown']);
                    }

                    return $send();
                });
            } catch (ValidationException) {
                return new MymarBookingAttempt('rejected', 'booking_native_slot_changed');
            }
        });
        if ($before !== 'confirmed' && $record->status === 'confirmed') {
            $this->pending->invalidate();
        }

        return $record;
    }

    private function lockTechnician(int $id): User
    {
        return User::query()->internal()->where('role', 2)->where('admin', false)->lockForUpdate()->findOrFail($id);
    }

    private function configuration(?User $actor, bool $write): array
    {
        abort_unless($actor && ! $actor->trashed() && ! $actor->isExternal() && ($actor->admin || in_array($actor->role, [0, 1], true)), 403);
        $config = $this->settings->load();
        if (! config('crm.v2_enabled') || ! ($config['guard_enabled'] ?? false) || ($write && ! $config['writes_enabled'])) {
            throw new RuntimeException('mymar.actions_disabled');
        }

        return $config;
    }

    private function client(array $config): MymarBookingClient
    {
        return new MymarBookingClient($this->http, enabled: true, writesEnabled: $config['writes_enabled'], port: 19848, token: $this->settings->token());
    }

    private function outbox(array $config): MymarBookingOutbox
    {
        return new MymarBookingOutbox($this->client($config), true);
    }

    private function result(MymarBookingRequest $record, User $actor): array
    {
        $message = match ($record->status) {
            'confirmed' => $record->command['action'] === 'place' ? 'Rendez-vous placé et confirmé dans MYMAR.' : 'Problème rendez-vous enregistré dans MYMAR.',
            'rejected' => 'La demande a été refusée. Aucun nouveau placement confirmé ; actualisez puis choisissez un autre créneau.',
            default => 'Confirmation MYMAR en attente. Ne renouvelez pas la demande : utilisez « Vérifier la confirmation ».',
        };

        return ['request_id' => $record->id, 'state' => $record->status, 'message' => $message,
            'confirmed' => $record->status === 'confirmed', 'terminal' => in_array($record->status, ['confirmed', 'rejected'], true),
            'action' => $record->command['action'], 'dossier_id' => $record->source_dossier_id,
            'source_result' => $record->status === 'confirmed' ? $record->result : null];
    }
}
