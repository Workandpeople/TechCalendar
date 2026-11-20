<?php

namespace App\Services\Tech;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\DossierWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TechnicianIncidentStatus
{
    public function __construct(private MymarIncidentStatus $mar, private OnSiteVisitContext $visits) {}

    public function context(User $actor, TechnicianIncident $incident): array
    {
        $actor = User::withTrashed()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize('changeStatus', $incident);
        $incident->refresh();
        if ($incident->source === 'mymar') {
            return $this->mar->context($actor, $incident);
        }
        [$appointment, $dossier] = $this->native($incident);
        $operation = $appointment->dossierOperation;
        $data = ['dossier_version' => $dossier->version, 'operation_id' => $operation->id,
            'booking_revision' => $this->visits->fingerprint($appointment), 'appointment_status' => $appointment->status];

        return ['context' => IncidentStatusContext::issue($actor, $incident, $data),
            'current_label' => DossierStatusCatalog::describe($operation->legacy_status)['label'],
            'statuses' => collect(DossierStatusCatalog::manualStates())->map(fn ($status, $key) => ['value' => $key, 'label' => $status['label']])->values()->all(),
            'planning_status' => $incident->planning_status, 'note' => $incident->planning_note ?? '',
            'message' => 'Décision sur cette prestation uniquement. Les photos, commentaires et données comptables sont conservés.'];
    }

    public function submit(User $actor, TechnicianIncident $incident, array $input): array
    {
        $actor = User::withTrashed()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize('changeStatus', $incident);
        $context = IncidentStatusContext::read($actor, $incident, $input['context']);
        if ($incident->source === 'mymar') {
            return $this->mar->submit($actor, $incident, $context, $input);
        }
        if (! array_key_exists($input['target_status'], DossierStatusCatalog::manualStates())) {
            throw ValidationException::withMessages(['target_status' => 'Choisissez un statut Cofrac disponible.']);
        }

        return DB::transaction(function () use ($actor, $incident, $context, $input): array {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($incident->dossier_id);
            Gate::forUser($actor)->authorize('update', $dossier);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($context['data']['operation_id']);
            $appointment = Appointment::withTrashed()->lockForUpdate()->findOrFail($incident->appointment_id);
            $locked = TechnicianIncident::query()->lockForUpdate()->findOrFail($incident->id);
            $actor = User::withTrashed()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('changeStatus', $locked);
            $intent = ['request_id' => $context['request_id'], 'target_status' => $input['target_status'],
                'planning_status' => $input['planning_status'], 'note' => trim($input['note'])];
            if (IncidentStatusContext::sameData($locked->source_context['status_review'] ?? null, $intent)) {
                return ['message' => 'Cette décision est déjà enregistrée.', 'state' => 'confirmed'];
            }
            $appointment->setRelation('dossierOperation', $operation);
            $operation->setRelation('dossier', $dossier);
            if ($locked->version !== $context['version'] || $dossier->version !== $context['data']['dossier_version']
                || $appointment->dossier_operation_id !== $operation->id || $appointment->trashed()
                || ! hash_equals($context['data']['booking_revision'], $this->visits->fingerprint($appointment))
                || $appointment->status !== $context['data']['appointment_status']) {
                throw ValidationException::withMessages(['context' => 'Le dossier, le RDV ou le signalement a changé. Rechargez les statuts : aucune modification appliquée.']);
            }
            $sameVisit = ! ($locked->source_context['evidence_only'] ?? false)
                && IncidentStatusContext::sameData($locked->source_context['booking'] ?? null, $this->visits->booking($appointment));
            if ($sameVisit && in_array($input['target_status'], ['pending', 'problem', 'cancelled'], true)) {
                $appointment->forceFill(['status' => $input['target_status'] === 'cancelled'
                    ? Appointment::STATUS_CANCELLED : Appointment::STATUS_PROBLEM])->save();
            }
            app(DossierWorkflow::class)->changeStatus($actor, $dossier, $operation,
                ['version' => $dossier->version, 'status' => $input['target_status']]);
            $locked->forceFill(['planning_status' => $input['planning_status'], 'planning_note' => trim($input['note']),
                'handled_by' => $actor->id, 'handled_at' => now(), 'version' => $locked->version + 1,
                'source_context' => [...($locked->source_context ?? []), 'status_decision_pending' => false, 'status_review' => $intent]])->save();
            if ($sameVisit && ($appointment->external_payload['techcalendar_on_site']['request_id'] ?? null) === $locked->request_id) {
                $payload = $appointment->external_payload;
                $payload['techcalendar_on_site']['status_decision_pending'] = false;
                $appointment->forceFill(['external_payload' => $payload])->save();
            }
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'on_site_status_reviewed',
                'body' => 'Décision administrative depuis le signalement #'.$locked->id.'.',
                'client_visible' => false, 'delegataire_visible' => false, 'changed_fields' => ['incident_id' => $locked->id, ...$intent]]);

            return ['message' => 'Statut du dossier et suivi du signalement enregistrés. Aucune facture créée.', 'state' => 'confirmed'];
        }, 3);
    }

    private function native(TechnicianIncident $incident): array
    {
        $appointment = Appointment::withTrashed()->with('dossierOperation.dossier')->findOrFail($incident->appointment_id);
        $dossier = $appointment->dossierOperation?->dossier;
        abort_unless($dossier && ! $dossier->trashed() && ! $appointment->trashed() && $dossier->id === $incident->dossier_id, 404);

        return [$appointment, $dossier];
    }
}
