<?php

namespace App\Services\Tech;

use App\Models\MymarBookingRequest;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingAttempt;
use App\Services\Mymar\MymarBookingClient;
use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarBookingOutbox;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarPendingBookings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MymarIncidentStatus
{
    public function __construct(private MymarActionSettings $settings, private MymarBookingReaderSettings $reader, private Factory $http) {}

    public function context(User $actor, TechnicianIncident $incident): array
    {
        Gate::forUser($actor)->authorize('changeStatus', $incident);
        $record = $incident->statusRequest;
        if ($record && in_array($record->status, ['queued', 'dispatching', 'uncertain'], true)) {
            return ['context' => null, 'current_label' => 'Décision MYMAR en attente de confirmation', 'statuses' => [],
                'planning_status' => $incident->planning_status, 'note' => $incident->planning_note ?? '',
                'resume_url' => route('planner.incidents.status.resume', $incident),
                'message' => 'Une décision existe déjà. Vérifiez ou reprenez cette même demande, sans créer de seconde écriture.'];
        }
        $this->configuration(true);
        $reader = $this->readerClient();
        $writer = $this->writerClient();
        $key = 'mymar.incident-capabilities.'.hash('sha256', $this->reader->token().$this->settings->token());
        abort_unless(Cache::remember($key, 30, fn () => $reader->supportsIncidentStatusReview() && $writer->supportsIncidentStatusReview()),
            503, 'Les deux passerelles MAR doivent être mises à jour avant de changer un statut. Les preuves sont conservées.');
        $source = $this->sourceContext($incident->mymar_dossier_id);

        return ['context' => IncidentStatusContext::issue($actor, $incident, [...$source, 'followup_before' => $incident->only(['planning_status', 'planning_note'])]),
            'current_label' => MymarBookingCommand::REVIEW_STATUSES[$source['status_id']],
            'statuses' => array_map(fn ($row) => ['value' => (string) $row['id'], 'label' => $row['label']], $source['statuses']),
            'planning_status' => $incident->planning_status, 'note' => $incident->planning_note ?? '',
            'message' => 'Décision MYMAR après travaux. Aucun horaire, technicien, document ou montant n’est modifié. Le retour en prise de RDV ou l’annulation archive le créneau courant. Pour replanifier une visite, utilisez le planning.'];
    }

    public function submit(User $actor, TechnicianIncident $incident, array $context, array $input): array
    {
        Gate::forUser($actor)->authorize('changeStatus', $incident);
        $this->configuration(true);
        $source = $context['data'];
        if (! ctype_digit($input['target_status']) || ! in_array((int) $input['target_status'], array_column($source['statuses'] ?? [], 'id'), true)
            || ($source['dossier_id'] ?? null) !== $incident->mymar_dossier_id) {
            throw ValidationException::withMessages(['target_status' => 'Choisissez un statut MAR disponible.']);
        }
        $target = (int) $input['target_status'];
        $command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
            'request_id' => $context['request_id'], 'cofrac_actor_id' => $actor->id, 'action' => 'review_status',
            'dossier_id' => $incident->mymar_dossier_id, 'expected_revision' => $source['expected_revision'], 'target_status_id' => $target,
            'reason' => 'Decision signalement Cofrac #'.$incident->id,
            'comment' => 'Decision administrative Cofrac #'.$incident->id.' : '.MymarBookingCommand::REVIEW_STATUSES[$target].'. Preuves conservees dans Cofrac.']);
        $intent = ['request_id' => $command->payload['request_id'], 'fingerprint' => $command->fingerprint(),
            'target_status_id' => $target, 'planning_status' => $input['planning_status'], 'note' => trim($input['note']),
            'followup_before' => $source['followup_before'], 'applied' => false];
        $known = MymarBookingRequest::query()->find($command->payload['request_id']);
        if ($known) {
            $stored = $incident->fresh()->source_context['status_review'] ?? [];
            unset($stored['applied']);
            $compare = $intent;
            unset($compare['applied']);
            abort_unless($known->author_id === $actor->id && hash_equals($known->fingerprint, $command->fingerprint())
                && IncidentStatusContext::sameData($stored, $compare), 409);

            return $this->resume($actor, $incident);
        }
        $fresh = $this->sourceContext($incident->mymar_dossier_id);
        if (! hash_equals($fresh['expected_revision'], $source['expected_revision'])) {
            throw ValidationException::withMessages(['context' => 'Le dossier MYMAR a changé. Rechargez les statuts, aucune décision envoyée.']);
        }
        try {
            DB::transaction(function () use ($actor, $incident, $context, $command, $intent): void {
                $locked = TechnicianIncident::query()->lockForUpdate()->findOrFail($incident->id);
                $actor = User::withTrashed()->findOrFail($actor->id);
                Gate::forUser($actor)->authorize('changeStatus', $locked);
                if ($locked->version !== $context['version']) {
                    throw ValidationException::withMessages(['context' => 'Le signalement a changé. Rechargez les statuts.']);
                }
                if ($locked->statusRequest && in_array($locked->statusRequest->status, ['queued', 'dispatching', 'uncertain'], true)) {
                    throw ValidationException::withMessages(['context' => 'Une décision MYMAR est déjà en cours. Vérifiez sa confirmation.']);
                }
                $legacy = $locked->sourceRequest;
                if ($legacy?->status === 'queued' && in_array($legacy->command['action'] ?? null, ['on_site_problem', 'on_site_rebook'], true)) {
                    // Retire only a proven unsent automatic command; uncertain receipts remain protected.
                    $this->outbox()->process($legacy->id, static fn () => new MymarBookingAttempt('rejected', 'booking_author_not_allowed'));
                }
                $locked->forceFill(['source_context' => [...$locked->source_context, 'status_review' => $intent], 'version' => $locked->version + 1])->save();
                $this->outbox()->enqueue($command, $actor);
            }, 3);
        } catch (\RuntimeException $error) {
            if ($error->getMessage() === 'mymar.booking_local_dossier_busy') {
                throw ValidationException::withMessages(['context' => 'Une autre demande MYMAR est en cours pour ce dossier. Vérifiez sa confirmation avant de changer le statut.']);
            }
            throw $error;
        }

        return $this->resume($actor, $incident);
    }

    public function resume(User $actor, TechnicianIncident $incident): array
    {
        $actor = User::withTrashed()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize('changeStatus', $incident);
        abort_unless($incident->source === 'mymar', 404);
        $incident->refresh()->load('statusRequest');
        $record = $incident->statusRequest;
        abort_unless($record && $record->command['action'] === 'review_status', 404);
        $this->configuration($record->status === 'queued');
        $outbox = in_array($record->status, ['uncertain', 'dispatching'], true)
            ? new MymarBookingOutbox($this->readerClient(), true) : $this->outbox();
        $record = $outbox->process($record->id, static fn ($command, $send) => $send());
        if ($record->status === 'confirmed') {
            $this->finalize($incident, $record);
            try {
                app(MymarPendingBookings::class)->invalidate();
            } catch (\Throwable $error) {
                report($error);
            }

            return ['state' => 'confirmed', 'message' => 'Statut MYMAR confirmé et suivi du signalement enregistré. Le planning s’actualise en arrière-plan.'];
        }

        return ['state' => $record->status, 'message' => $record->status === 'rejected'
            ? 'MYMAR a refusé cette décision. Les preuves et le suivi comptable sont conservés ; rechargez les statuts avant de réessayer.'
            : 'Décision enregistrée, confirmation MYMAR en attente. Vérifiez cette demande sans renvoyer une nouvelle décision.'];
    }

    public static function canDispatch(User $actor, MymarBookingCommand $command): bool
    {
        $incident = TechnicianIncident::query()->where('source', 'mymar')->where('mymar_dossier_id', $command->payload['dossier_id'])
            ->where('source_context->status_review->request_id', $command->payload['request_id'])->first();

        return $incident && Gate::forUser($actor)->allows('changeStatus', $incident)
            && hash_equals($incident->source_context['status_review']['fingerprint'] ?? '', $command->fingerprint());
    }

    private function finalize(TechnicianIncident $incident, MymarBookingRequest $record): void
    {
        DB::transaction(function () use ($incident, $record): void {
            $locked = TechnicianIncident::query()->lockForUpdate()->findOrFail($incident->id);
            $review = $locked->source_context['status_review'] ?? [];
            if (($review['request_id'] ?? null) !== $record->id || ($review['applied'] ?? false)) {
                return;
            }
            $before = $locked->only(['planning_status', 'planning_note']);
            $review['applied'] = true;
            $followup = IncidentStatusContext::sameData($review['followup_before'] ?? null, $before)
                ? ['planning_status' => $review['planning_status'], 'planning_note' => $review['note'],
                    'handled_by' => $record->author_id, 'handled_at' => now()] : [];
            $locked->forceFill([...$followup, 'version' => $locked->version + 1,
                'source_context' => [...$locked->source_context, 'status_review' => $review, 'status_decision_pending' => false]])->save();
            DB::table('technician_incident_reviews')->insert(['incident_id' => $locked->id, 'author_id' => $record->author_id,
                'section' => 'planning', 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode([...$locked->only(['planning_status', 'planning_note']), 'mymar_status_id' => $record->result['status_id'],
                    'request_id' => $record->id], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        }, 3);
    }

    private function configuration(bool $write): array
    {
        $config = $this->settings->load();
        abort_unless(($config['guard_enabled'] ?? false) && (! $write || $config['writes_enabled']), 503,
            'Les changements de statut MAR sont momentanément indisponibles. Les preuves sont conservées.');

        return $config;
    }

    private function sourceContext(int $dossier): array
    {
        try {
            return $this->readerClient()->incidentContext($dossier);
        } catch (\RuntimeException) {
            abort(503, 'Le statut MYMAR est indisponible. Les preuves sont conservées ; réessayez plus tard.');
        }
    }

    private function readerClient(): MymarBookingClient
    {
        abort_unless($this->reader->load() !== null, 503, 'Lecture MAR indisponible. Réessayez plus tard.');

        return new MymarBookingClient($this->http, enabled: true, token: $this->reader->token());
    }

    private function writerClient(): MymarBookingClient
    {
        $config = $this->configuration(false);

        return new MymarBookingClient($this->http, enabled: true, writesEnabled: $config['writes_enabled'], port: 19848, token: $this->settings->token());
    }

    private function outbox(): MymarBookingOutbox
    {
        return new MymarBookingOutbox($this->writerClient(), true);
    }
}
