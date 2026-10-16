<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierOperation;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\User;
use App\Services\LotStatusService;
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

    public const TRANSITIONS = [
        'record_visit' => ['from' => ['awaiting_visit'], 'to' => 'visited', 'label' => 'Visite effectuée', 'review' => false],
        'submit_control' => ['from' => ['visited'], 'to' => 'rt_control', 'label' => 'Contrôle soumis au RT', 'review' => false],
        'revise_control' => ['from' => ['rt_control'], 'to' => 'rt_control', 'label' => 'Conclusion du contrôle mise à jour', 'review' => true],
        'request_correction' => ['from' => ['rt_control', 'recontrol'], 'to' => 'non_compliant', 'label' => 'Correction demandée au client', 'review' => true],
        'validate_report' => ['from' => ['rt_control', 'recontrol'], 'to' => 'report', 'label' => 'Rapport validé', 'review' => true],
        'reopen_control' => ['from' => ['report'], 'to' => 'rt_control', 'label' => 'Validation retirée pour réexamen', 'review' => true],
    ];

    public function __construct(private readonly LotStatusService $lotStatuses) {}

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
        } elseif ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Ajoutez un commentaire ou un document pour transmettre votre réponse.']);
        }

        $document = null;
        try {
            // One attempt: a file write cannot be replayed safely by a transaction retry.
            DB::transaction(function () use ($actor, $dossier, $operation, $data, $file, $comment, &$document): void {
                $activity = $this->transition($actor, $dossier, $operation, [
                    'version' => $data['version'], 'action' => 'client_response', 'client_visible' => true,
                    'comment' => $comment ?: 'Pièce justificative transmise par le client.',
                ], ['from' => ['non_compliant'], 'to' => 'recontrol', 'label' => 'Réponse du client transmise pour recontrôle']);
                if ($file instanceof UploadedFile) {
                    $document = app(DossierRegistry::class)->addDocument($actor, $dossier, $file,
                        filled($data['document_name'] ?? null) ? $data['document_name'] : Str::limit($file->getClientOriginalName(), 190, ''));
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
            if ($data['action'] === 'client_response') {
                // Recheck ownership under the lock in case staff reassigned the dossier.
                Gate::forUser($actor)->authorize('respondToNonConformity', $dossier);
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
            if ($data['action'] === 'reopen_control' && $operation->capceeReports()->exists()) {
                throw ValidationException::withMessages(['action' => 'Un envoi CAPCEE existe pour ce contrôle. Sa correction nécessite une nouvelle version du rapport ; la validation locale ne peut pas être retirée seule.']);
            }

            $lots = Lot::query()->whereIn('id', $this->physicalRows($operation)->select('lot_id'))->orderBy('id')->lockForUpdate()->get();
            if ($lots->contains(fn (Lot $lot) => Lot::isArchivedStatus($lot->status))) {
                throw ValidationException::withMessages(['action' => 'Un lot lié est archivé. Son résultat ne peut plus être modifié depuis le dossier.']);
            }

            $previous = $operation->only(['legacy_status', 'visit_appointment_id', 'inspected_on', 'control_outcome', 'control_comment', 'control_submitted_at', 'control_validated_at', 'control_validated_by', 'report_document_id']);
            match ($data['action']) {
                'record_visit' => $this->recordVisit($operation, (int) $data['appointment_id']),
                'submit_control', 'revise_control' => $this->submitControl($operation, $data),
                'validate_report' => $this->validateReport($actor, $dossier, $operation, $data, $state),
                'reopen_control', 'request_correction', 'client_response' => $operation->forceFill(['control_validated_at' => null, 'control_validated_by' => null]),
                default => null,
            };
            $operation->forceFill(['legacy_status' => DossierStatusCatalog::STATUSES[$transition['to']]['values'][0]])->save();

            // Only a validated opinion is projected; a draft or an unverifiable visit is not a failure.
            $satisfaction = $operation->control_validated_at ? match ($operation->control_outcome) {
                'satisfactory' => true, 'unsatisfactory' => false, default => null,
            } : null;
            $this->physicalRows($operation)->update([
                'physical_satisfaction' => $satisfaction,
                'physical_satisfaction_synced_at' => now(),
            ]);
            foreach ($lots as $lot) {
                $this->lotStatuses->refresh($lot);
            }
            $activity = $dossier->activities()->create([
                'author_id' => $actor->id, 'kind' => 'post_visit',
                'client_visible' => (bool) ($data['client_visible'] ?? false),
                'delegataire_visible' => $data['action'] === 'client_response'
                    ? $actor->externalClient?->kind === 'delegataire'
                    : (bool) ($data['delegataire_visible'] ?? false),
                'body' => $transition['label'].' · Fiche #'.$operation->id
                    .($data['action'] !== 'client_response' && $operation->control_outcome ? ' · '.self::OUTCOMES[$operation->control_outcome] : '')."\n".trim($data['comment']),
                'changed_fields' => ['operation_id' => $operation->id, 'action' => $data['action'], 'before' => $previous,
                    'after' => $operation->only(array_keys($previous)), 'physical_satisfaction' => $satisfaction,
                    'lot_ids' => $lots->modelKeys()],
            ]);
            $dossier->increment('version');

            return $activity;
        }, 3);
    }

    private function recordVisit(DossierOperation $operation, int $appointmentId): void
    {
        $appointment = $operation->appointments()->lockForUpdate()->find($appointmentId);
        if (! $appointment || $appointment->status !== Appointment::STATUS_SCHEDULED
            || ! $appointment->ends_at || $appointment->ends_at->isFuture()) {
            throw ValidationException::withMessages(['appointment_id' => 'Sélectionne un rendez-vous terminé, non retiré et sans problème, lié à cette prestation.']);
        }
        $operation->forceFill(['visit_appointment_id' => $appointment->id, 'inspected_on' => $appointment->starts_at->toDateString()]);
    }

    private function submitControl(DossierOperation $operation, array $data): void
    {
        if (! array_key_exists($data['outcome'] ?? '', self::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => 'Renseigne une conclusion valide.']);
        }
        $operation->forceFill([
            'inspected_on' => $data['inspected_on'], 'control_outcome' => $data['outcome'],
            'control_comment' => trim($data['comment']), 'control_submitted_at' => now(),
            'control_validated_at' => null, 'control_validated_by' => null, 'report_document_id' => null,
        ]);
    }

    private function validateReport(User $actor, Dossier $dossier, DossierOperation $operation, array $data, string $state): void
    {
        if ($state === 'recontrol') {
            // The client's response never carries the reviewer's conclusion.
            if (blank($data['inspected_on'] ?? null)) {
                throw ValidationException::withMessages(['inspected_on' => 'Renseigne la date réelle de visite avant de valider le recontrôle.']);
            }
            $this->submitControl($operation, $data);
        }
        if (! $operation->control_submitted_at || ! $operation->inspected_on || ! isset(self::OUTCOMES[$operation->control_outcome])) {
            throw ValidationException::withMessages(['outcome' => 'La conclusion native est manquante. Renseigne la conclusion du contrôle avant validation.']);
        }
        $document = $dossier->documents()->find((int) $data['report_document_id']);
        if (! $document || $document->mime_type !== 'application/pdf' || ! Storage::disk('local')->exists($document->path)) {
            throw ValidationException::withMessages(['report_document_id' => 'Choisis un PDF disponible dans les documents de ce dossier. Aucun rapport externe n’est téléchargé automatiquement.']);
        }
        $operation->forceFill(['report_document_id' => $document->id, 'control_validated_by' => $actor->id, 'control_validated_at' => now()]);
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
