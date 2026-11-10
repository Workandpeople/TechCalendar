<?php

namespace App\Services\Tech;

use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingAttempt;
use App\Services\Mymar\MymarBookingClient;
use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarBookingOutbox;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Mymar\MymarPendingBookings;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MymarOnSiteReports
{
    public function __construct(private OnSitePhoto $photos, private MymarActionSettings $settings,
        private MymarBookingReaderSettings $reader, private Factory $http) {}

    public function page(User $actor, int $rdv): array
    {
        $this->authorizeTechnician($actor);
        if ($incident = $this->existing($actor, $rdv)) {
            if (in_array($incident->sourceRequest?->status, ['uncertain', 'dispatching'], true)) {
                try {
                    $this->reconcile($incident);
                } catch (RuntimeException) {
                    // Local proof remains available when the private reader is unavailable.
                }
            }

            return ['incident' => $incident->fresh('sourceRequest'), 'source' => $incident->source_context, 'snapshot' => null];
        }
        $source = $this->scheduled($actor, $rdv);

        return ['incident' => null, 'source' => $source, 'snapshot' => $this->snapshot($source, $actor)];
    }

    public function snapshot(array $source, User $actor): string
    {
        return hash_hmac('sha256', json_encode([$actor->id, $source['dossier_id'], $source['rdv_id'],
            $source['technician_id'], $source['expected_revision']], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function submit(User $actor, int $rdv, array $data, UploadedFile $photo): TechnicianIncident
    {
        $this->authorizeTechnician($actor);
        if ($incident = $this->existing($actor, $rdv)) {
            abort_unless($incident->request_id === $data['request_id'], 409, 'Ce rendez-vous a déjà un signalement. Rechargez la page.');

            return $this->transmit($actor, $rdv);
        }
        $source = $this->scheduled($actor, $rdv);
        if (! hash_equals($this->snapshot($source, $actor), $data['snapshot'])) {
            throw ValidationException::withMessages(['snapshot' => 'Le rendez-vous MAR a changé. Rechargez sa fiche avant de signaler le problème.']);
        }
        $stored = $this->photos->store($photo, $source['dossier_id'], mymar: true);
        $used = false;
        try {
            $incident = DB::transaction(function () use ($actor, $rdv, $data, $source, $stored, &$used): TechnicianIncident {
                $used = false;
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->configuration($actor, true);
                if ($existing = $this->existing($actor, $rdv)) {
                    abort_unless($existing->request_id === $data['request_id'], 409);

                    return $existing;
                }
                $incident = TechnicianIncident::create([
                    'source' => 'mymar', 'request_id' => $data['request_id'], 'technician_id' => $actor->id,
                    'mymar_dossier_id' => $source['dossier_id'], 'mymar_rdv_id' => $rdv,
                    'reason' => $data['reason'], 'comment' => trim($data['comment'] ?? ''),
                    'reported_at' => now(), 'appointment_starts_at' => Carbon::parse($source['starts_at'])->tz('Europe/Paris'),
                    'source_context' => [...$source, 'photo' => $stored],
                ]);
                // Full text and photo stay private in Cofrac; MYMAR receives a short, traced summary.
                $command = MymarBookingCommand::fromArray([
                    'format' => 'cofrac.mymar-booking-command', 'version' => 1, 'action' => 'on_site_problem',
                    'request_id' => $incident->request_id, 'cofrac_actor_id' => $actor->id,
                    'dossier_id' => $source['dossier_id'], 'rdv_id' => $rdv, 'technician_id' => $source['technician_id'],
                    'expected_revision' => $source['expected_revision'], 'reason' => $incident->reasonLabel(),
                    'comment' => $incident->reasonLabel().' · Signalement Cofrac #'.$incident->id
                        .' du '.$incident->reported_at->format('d/m/Y H:i').'. Photo et commentaire complet dans Cofrac.',
                ]);
                $incident->forceFill(['source_context' => [...$incident->source_context, 'command_fingerprint' => $command->fingerprint()]])->save();
                $this->outbox($this->configuration($actor, true))->enqueue($command, $actor);
                $used = true;

                return $incident;
            }, 3);
        } catch (Throwable $error) {
            try {
                if (! TechnicianIncident::where('source_context->photo->path', $stored['path'])->exists()) {
                    Storage::disk('local')->delete($stored['path']);
                }
            } catch (Throwable) {
                // Never delete proof after an uncertain local commit.
            }
            if ($error instanceof RuntimeException && $error->getMessage() === 'mymar.booking_local_dossier_busy') {
                throw ValidationException::withMessages(['snapshot' => 'Une autre demande MYMAR est en cours pour ce dossier. Le signalement n’est pas enregistré ; contactez le planning avant de réessayer.']);
            }
            throw $error;
        }
        if (! $used) {
            Storage::disk('local')->delete($stored['path']);
        }

        try {
            return $this->transmit($actor, $rdv);
        } catch (Throwable) {
            // Proof/outbox are already committed; report their real state rather than ask for a new photo.
            return $incident->fresh('sourceRequest');
        }
    }

    public function transmit(User $actor, int $rdv): TechnicianIncident
    {
        $this->authorizeTechnician($actor);
        $incident = $this->existing($actor, $rdv);
        abort_unless($incident, 404);
        $record = $incident->sourceRequest;
        if ($record?->status === 'queued') {
            $config = $this->configuration($actor, true);
            $this->outbox($config)->process($record->id, function (MymarBookingCommand $command, Closure $send): MymarBookingAttempt {
                $attempted = false;
                try {
                    $actor = User::query()->findOrFail($command->payload['cofrac_actor_id']);
                    $source = $this->scheduled($actor, $command->payload['rdv_id']);
                    if ($source['dossier_id'] !== $command->payload['dossier_id']
                        || $source['technician_id'] !== $command->payload['technician_id']
                        || ! hash_equals($source['expected_revision'], $command->payload['expected_revision'])) {
                        return new MymarBookingAttempt('rejected', 'booking_source_changed');
                    }

                    return DB::transaction(function () use ($actor, $command, $send, &$attempted): MymarBookingAttempt {
                        $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                        $config = $this->configuration($actor, true);
                        if ($this->sourceTechnician($config, $actor) !== $command->payload['technician_id']) {
                            return new MymarBookingAttempt('rejected', 'booking_author_not_allowed');
                        }

                        $attempted = true;

                        return $send();
                    });
                } catch (Throwable) {
                    return new MymarBookingAttempt($attempted ? 'uncertain' : 'rejected', 'booking_source_unavailable');
                }
            });
        } elseif (in_array($record?->status, ['uncertain', 'dispatching'], true)) {
            $this->reconcile($incident);
        }
        $incident->refresh()->load('sourceRequest');
        if ($incident->sourceRequest?->status === 'confirmed') {
            $this->refreshPlanning();
        }

        return $incident;
    }

    public static function canDispatch(User $actor, MymarBookingCommand $command): bool
    {
        if (! config('crm.v2_enabled') || $actor->trashed() || $actor->isExternal() || $actor->must_change_password
            || $actor->role !== 2 || $actor->admin || $command->payload['action'] !== 'on_site_problem') {
            return false;
        }
        $incident = TechnicianIncident::where('source', 'mymar')->where('request_id', $command->payload['request_id'])
            ->where('technician_id', $actor->id)->where('mymar_dossier_id', $command->payload['dossier_id'])
            ->where('mymar_rdv_id', $command->payload['rdv_id'])->first();

        return $incident !== null && hash_equals($incident->source_context['command_fingerprint'] ?? '', $command->fingerprint());
    }

    public function authorizeProof(User $actor, TechnicianIncident $incident): void
    {
        abort_unless(config('crm.v2_enabled') && ! $actor->trashed() && ! $actor->isExternal() && ! $actor->must_change_password
            && $incident->source === 'mymar' && ($actor->can('viewAny', TechnicianIncident::class)
                || ($actor->role === 2 && (int) $incident->technician_id === $actor->id)), 403);
    }

    private function existing(User $actor, int $rdv): ?TechnicianIncident
    {
        $incident = TechnicianIncident::where('source', 'mymar')->where('mymar_rdv_id', $rdv)->with('sourceRequest')->first();
        if ($incident) {
            abort_unless((int) $incident->technician_id === $actor->id, 403);
        }

        return $incident;
    }

    private function scheduled(User $actor, int $rdv): array
    {
        $config = $this->configuration($actor, true);
        $sourceId = $this->sourceTechnician($config, $actor);
        abort_unless($sourceId !== null, 403);
        $source = $this->readerClient()->scheduled($rdv);
        abort_unless($source['technician_id'] === $sourceId, 403);

        return $source;
    }

    private function authorizeTechnician(User $actor): void
    {
        abort_unless(config('crm.v2_enabled') && ! $actor->trashed() && ! $actor->isExternal() && ! $actor->must_change_password
            && $actor->role === 2 && ! $actor->admin, 403);
    }

    private function configuration(User $actor, bool $write): array
    {
        $this->authorizeTechnician($actor);
        $config = $this->settings->load();
        if (! ($config['guard_enabled'] ?? false) || ($write && ! $config['writes_enabled'])) {
            abort(503, 'Le signalement MAR est momentanément indisponible. Contactez le planning.');
        }

        return $config;
    }

    private function sourceTechnician(array $config, User $actor): ?int
    {
        return collect($config['bindings'])->firstWhere('cofrac_user_id', $actor->id)['source_technician_id'] ?? null;
    }

    private function outbox(array $config): MymarBookingOutbox
    {
        return new MymarBookingOutbox(new MymarBookingClient($this->http, enabled: true,
            writesEnabled: $config['writes_enabled'], port: 19848, token: $this->settings->token()), true);
    }

    private function readerClient(): MymarBookingClient
    {
        if ($this->reader->load() === null) {
            throw new RuntimeException('mymar.booking_reader_disabled');
        }

        return new MymarBookingClient($this->http, enabled: true, token: $this->reader->token());
    }

    private function reconcile(TechnicianIncident $incident): void
    {
        (new MymarBookingOutbox($this->readerClient(), true))->process($incident->request_id);
        if ($incident->sourceRequest()->first()?->status === 'confirmed') {
            $this->refreshPlanning();
        }
    }

    private function refreshPlanning(): void
    {
        try {
            app(MymarPendingBookings::class)->invalidate();
            app(MymarObservationSync::class)->run();
        } catch (Throwable) {
            // Confirmation is durable even if the display cache cannot refresh immediately.
        }
    }
}
