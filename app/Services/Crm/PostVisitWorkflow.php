<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\DossierOperation;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\User;
use App\Services\Capcee\AutomaticCapceeReports;
use App\Services\LotStatusService;
use App\Support\CommentContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostVisitWorkflow
{
    public const OUTCOMES = [
        'satisfactory' => 'Satisfaisant',
        'unsatisfactory' => 'Non satisfaisant',
        'unverifiable' => 'Inaccessible / non vérifiable',
    ];

    public const REPORT_STATES = ['visited', 'rt_control', 'recontrol', 'report'];

    public const TRANSITIONS = [
        'record_visit' => ['from' => ['awaiting_visit'], 'to' => 'visited', 'label' => 'Visite effectuée', 'review' => false],
        'submit_control' => ['from' => ['visited'], 'to' => 'rt_control', 'label' => 'Contrôle soumis au RT', 'review' => false],
        'request_correction' => ['from' => ['visited', 'rt_control', 'recontrol'], 'to' => 'non_compliant', 'label' => 'Correction demandée au client', 'review' => true],
        'validate_report' => ['from' => ['rt_control', 'recontrol'], 'to' => 'report', 'label' => 'Rapport validé', 'review' => true],
        'reopen_control' => ['from' => ['report', 'invoiced'], 'to' => 'rt_control', 'label' => 'Validation retirée pour réexamen', 'review' => true],
    ];

    public function __construct(private readonly LotStatusService $lotStatuses) {}

    public function recordReport(User $actor, Dossier $dossier, DossierOperation $operation, DossierDocument $document, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        $this->transition($actor, $dossier, $operation, [
            ...$data, 'action' => 'record_report', 'report_document_id' => $document->id,
        ], ['from' => self::REPORT_STATES, 'to' => $document->client_visible ? 'report' : 'rt_control',
            'label' => $document->client_visible ? 'Rapport Genius enregistré' : 'Rapport privé transmis au contrôle RT']);
    }

    public function apply(User $actor, Dossier $dossier, DossierOperation $operation, array $data): void
    {
        abort_unless($operation->dossier_id === $dossier->id, 404);
        $transition = self::TRANSITIONS[$data['action']] ?? null;
        if (! $transition) {
            throw ValidationException::withMessages(['action' => 'Action après visite inconnue.']);
        }
        Gate::forUser($actor)->authorize($transition['review'] ? 'reviewControl' : 'update', $dossier);

        $this->transition($actor, $dossier, $operation, $data, $transition);
    }

    public function respond(User $actor, Dossier $dossier, DossierOperation $operation, array $data): void
    {
        abort_unless($operation->dossier_id === $dossier->id, 404);
        Gate::forUser($actor)->authorize('respondToNonConformity', $dossier);
        $file = $data['document'] ?? null;
        $comment = trim($data['comment'] ?? '');
        if ($comment !== '') {
            Gate::forUser($actor)->authorize('comment', $dossier);
        }
        if ($file instanceof UploadedFile) {
            Gate::forUser($actor)->authorize('uploadDocument', $dossier);
        }

        $document = null;
        try {
            // One attempt: a file write cannot be replayed safely by a transaction retry.
            DB::transaction(function () use ($actor, $dossier, $operation, $data, $file, $comment, &$document): void {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
                Gate::forUser($actor)->authorize('respondToNonConformity', $dossier);
                // Store the correction while still non-compliant; the transition rolls back with it.
                if ($file instanceof UploadedFile) {
                    $document = app(DossierRegistry::class)->addDocument($actor, $dossier, $file,
                        filled($data['document_name'] ?? null) ? $data['document_name'] : Str::limit($file->getClientOriginalName(), 190, ''));
                }
                $activity = $this->transition($actor, $dossier, $operation, [
                    'version' => $data['version'], 'action' => 'client_response', 'client_visible' => true,
                    'comment' => $comment ?: 'Demande explicite de recontrôle par '.($actor->externalClient?->kind === 'delegataire' ? 'le délégataire.' : 'le client.'),
                ], ['from' => ['non_compliant'], 'to' => 'recontrol', 'label' => 'Recontrôle demandé']);
                if ($document !== null) {
                    $activity->update(['changed_fields' => [...$activity->changed_fields, 'response_document_id' => $document->id]]);
                }
            });
        } catch (Throwable $exception) {
            if ($document !== null) {
                Storage::disk('local')->delete($document->path);
            }
            throw $exception;
        }
    }

    private function transition(User $actor, Dossier $dossier, DossierOperation $operation, array $data, array $transition): DossierActivity
    {
        return DB::transaction(function () use ($actor, $dossier, $operation, $data, $transition): DossierActivity {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            if ($data['action'] === 'record_report') {
                $transition['review'] = DossierStatusCatalog::describe($operation->legacy_status)['key'] !== 'visited';
            }
            if ($data['action'] === 'client_response') {
                // Recheck ownership under the lock in case staff reassigned the dossier.
                Gate::forUser($actor)->authorize('respondToNonConformity', $dossier);
            } else {
                Gate::forUser($actor)->authorize(($transition['review'] ?? false) ? 'reviewControl' : 'update', $dossier);
            }
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de poursuivre le contrôle.']);
            }
            $state = DossierStatusCatalog::describe($operation->legacy_status)['key'];
            if (! in_array($state, $transition['from'], true)) {
                throw ValidationException::withMessages(['action' => 'Cette action n’est plus disponible dans l’état actuel de la prestation.']);
            }
            if (blank($data['comment'] ?? null)) {
                throw ValidationException::withMessages(['comment' => 'Un commentaire est obligatoire pour tracer le traitement.']);
            }
            if (in_array($operation->legacy_status, DossierStatusCatalog::STATUSES['invoiced']['values'], true) && ! $dossier->billings()->exists()) {
                throw ValidationException::withMessages(['action' => 'La reprise de l’historique de facturation doit être terminée avant de rouvrir cette prestation.']);
            }
            if ($data['action'] === 'reopen_control' && $operation->capceeReports()->exists()) {
                throw ValidationException::withMessages(['action' => 'Un envoi CAPCEE existe pour ce contrôle. Sa correction nécessite une nouvelle version du rapport ; la validation locale ne peut pas être retirée seule.']);
            }

            $lots = Lot::query()->whereIn('id', $this->physicalRows($operation)->select('lot_id'))->orderBy('id')->lockForUpdate()->get();
            if ($lots->contains(fn (Lot $lot) => Lot::isArchivedStatus($lot->status))) {
                throw ValidationException::withMessages(['action' => 'Un lot lié est archivé. Son résultat ne peut plus être modifié depuis le dossier.']);
            }

            $previous = $operation->only(['legacy_status', 'visit_appointment_id', 'inspected_on', 'control_outcome', 'control_comment', 'control_submitted_at', 'control_validated_at', 'control_validated_by', 'report_document_id']);
            match ($data['action']) {
                'record_visit' => $this->recordVisit($dossier, $operation, (int) $data['appointment_id'], (int) ($data['visit_document_id'] ?? 0)),
                'record_report', 'validate_report' => $this->applyReport($actor, $dossier, $operation, (int) $data['report_document_id'], $transition['to'] === 'report'),
                'reopen_control', 'request_correction', 'client_response' => $operation->forceFill(['control_validated_at' => null, 'control_validated_by' => null]),
                default => null,
            };
            $operation->forceFill(['legacy_status' => DossierStatusCatalog::STATUSES[$transition['to']]['values'][0]])->save();

            // Opinion belongs to the uploaded report, not to the business state or its validation.
            $satisfaction = match ($operation->control_outcome) {
                'satisfactory' => true, 'unsatisfactory' => false, default => null,
            };
            if (in_array($data['action'], ['record_report', 'validate_report'], true)) {
                $this->physicalRows($operation)->update([
                    'physical_satisfaction' => $satisfaction,
                    'physical_satisfaction_synced_at' => now(),
                ]);
                foreach ($lots as $lot) {
                    $this->lotStatuses->refresh($lot);
                }
            }
            $activity = $dossier->activities()->create([
                'author_id' => $actor->id, 'kind' => 'post_visit',
                'client_visible' => (bool) ($data['client_visible'] ?? false),
                'delegataire_visible' => $data['action'] === 'client_response'
                    ? $actor->externalClient?->kind === 'delegataire'
                    : (bool) ($data['delegataire_visible'] ?? false),
                'body' => $transition['label'].' · Fiche #'.$operation->id
                    .($data['action'] !== 'client_response' && $operation->control_outcome ? ' · '.self::OUTCOMES[$operation->control_outcome] : '')."\n".CommentContent::forStorage($data['comment'], 'comment'),
                'changed_fields' => ['operation_id' => $operation->id, 'action' => $data['action'], 'before' => $previous,
                    'after' => $operation->only(array_keys($previous)), 'physical_satisfaction' => $satisfaction,
                    'lot_ids' => $lots->modelKeys(), 'visit_document_id' => $data['visit_document_id'] ?? null],
            ]);
            $dossier->increment('version');
            if ($transition['to'] === 'report') {
                app(AutomaticCapceeReports::class)->capture($actor, $dossier, $operation);
            }

            return $activity;
        }, 3);
    }

    private function recordVisit(Dossier $dossier, DossierOperation $operation, int $appointmentId, int $documentId): void
    {
        $document = $dossier->documents()->find($documentId);
        if (! $document || $document->document_type !== 'visit' || $document->dossier_operation_id !== $operation->id
            || ! Storage::disk('local')->exists($document->path)) {
            throw ValidationException::withMessages(['visit_document_id' => 'Ajoute un document de visite technique lié à cette prestation pour confirmer la visite.']);
        }
        $appointment = $operation->appointments()->lockForUpdate()->find($appointmentId);
        if (! $appointment || $appointment->status !== Appointment::STATUS_SCHEDULED
            || ! $appointment->ends_at || $appointment->ends_at->isFuture()) {
            throw ValidationException::withMessages(['appointment_id' => 'Sélectionne un rendez-vous terminé, non retiré et sans problème, lié à cette prestation.']);
        }
        $operation->forceFill(['visit_appointment_id' => $appointment->id, 'inspected_on' => $appointment->starts_at->toDateString()]);
    }

    private function applyReport(User $actor, Dossier $dossier, DossierOperation $operation, int $documentId, bool $validated): void
    {
        $document = $dossier->documents()->find($documentId);
        if (! $document || $document->document_type !== 'report' || $document->dossier_operation_id !== $operation->id
            || $document->mime_type !== 'application/pdf' || ! $document->inspected_on
            || ! isset(self::OUTCOMES[$document->control_outcome]) || ! Storage::disk('local')->exists($document->path)) {
            throw ValidationException::withMessages(['report_document_id' => 'Ajoute un rapport PDF avec son avis et sa date de visite pour cette prestation.']);
        }
        $operation->forceFill([
            'inspected_on' => $document->inspected_on, 'control_outcome' => $document->control_outcome,
            'control_comment' => $document->description, 'control_submitted_at' => now(),
            'report_document_id' => $document->id, 'control_validated_by' => $validated ? $actor->id : null,
            'control_validated_at' => $validated ? now() : null,
        ]);
    }

    private function physicalRows(DossierOperation $operation): Builder
    {
        return LotAppointment::query()
            ->whereIn('appointment_id', $operation->appointments()->withTrashed()->select('id'))
            ->where(fn (Builder $query) => $query->whereNull('processing_mode')->orWhere('processing_mode', LotAppointment::PROCESSING_MODE_PHYSICAL));
    }

    public static function assertPlanningMutable(DossierOperation $operation): void
    {
        if ($operation->inspected_on || $operation->control_submitted_at || $operation->control_validated_at) {
            throw ValidationException::withMessages(['appointment' => 'Cette visite appartient à un contrôle engagé. Son rendez-vous et sa liaison ne peuvent plus être réinitialisés ou déplacés. Utilise le traitement après visite.']);
        }
    }
}
