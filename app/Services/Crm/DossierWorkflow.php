<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\User;
use App\Support\CommentContent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierWorkflow
{
    public function changeStatus(User $actor, Dossier $dossier, DossierOperation $operation, array $data): bool
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless($operation->dossier_id === $dossier->id, 404);
        $status = DossierStatusCatalog::manualStates()[$data['status'] ?? ''] ?? null;
        if (! $status) {
            throw ValidationException::withMessages(['status' => 'Choisissez un état métier valide. La facturation et la suppression ont leurs actions dédiées.']);
        }

        return DB::transaction(function () use ($actor, $dossier, $operation, $data, $status): bool {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('update', $dossier);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Rechargez la fiche avant de modifier son état.']);
            }
            $previous = $operation->legacy_status;
            if (DossierStatusCatalog::describe($previous)['key'] === $data['status']) {
                return false;
            }
            if (in_array($previous, DossierStatusCatalog::STATUSES['invoiced']['values'], true) && ! $dossier->billings()->exists()) {
                // Never erase the last billing evidence when a legacy ledger still needs reconciliation.
                $dossier->forceFill(['billing_review_required' => true,
                    'billing_review_evidence' => [...($dossier->billing_review_evidence ?? []),
                        'manual_status_changes' => [...($dossier->billing_review_evidence['manual_status_changes'] ?? []),
                            ['operation_id' => $operation->id, 'legacy_status' => $previous]]]])->save();
            }
            // Status is independent of proof documents, technical validation, satisfaction and booking dates.
            $operation->forceFill(['legacy_status' => $status['values'][0]])->save();
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'status_changed',
                'body' => 'État modifié manuellement : '.DossierStatusCatalog::describe($previous)['label'].' → '.$status['label'],
                'client_visible' => false, 'delegataire_visible' => false,
                'changed_fields' => ['operation_id' => $operation->id, 'action' => 'manual_status',
                    'previous_status' => $previous, 'status' => $operation->legacy_status,
                    'before' => ['legacy_status' => $previous], 'after' => ['legacy_status' => $operation->legacy_status]]]);
            $dossier->increment('version');

            return true;
        }, 3);
    }

    public function reportProblem(User $actor, Dossier $dossier, DossierOperation $operation, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless($operation->dossier_id === $dossier->id, 404);
        DB::transaction(function () use ($actor, $dossier, $operation, $data): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de signaler le problème.']);
            }
            if (filled($data['appointment_id'] ?? null)) {
                $appointment = $operation->appointments()->lockForUpdate()->find($data['appointment_id']);
                if (! $appointment || ! in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_PROBLEM], true)) {
                    throw ValidationException::withMessages(['appointment_id' => 'Ce rendez-vous n’est plus disponible sur cette fiche.']);
                }
                $appointment->update([
                    'comment' => $data['comment'], 'status' => Appointment::STATUS_PROBLEM, 'problem_reported_at' => now(),
                    'external_payload' => [...($appointment->external_payload ?? []),
                        'techcalendar_problem' => Arr::only($data, ['comment', 'problem_type', 'recall_date', 'recall_time'])],
                ]);
            } elseif ($operation->appointments()->exists()) {
                throw ValidationException::withMessages(['appointment_id' => 'Sélectionne le rendez-vous concerné.']);
            }
            $this->recordProblem($actor, $operation, $data);
        }, 3);
    }

    /** The caller also updates the appointment inside this transaction, when there is one. */
    public function recordProblem(User $actor, DossierOperation $operation, array $data): void
    {
        DB::transaction(function () use ($actor, $operation, $data): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($operation->dossier_id);
            Gate::forUser($actor)->authorize('update', $dossier);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->legacy_status && ! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], ['pending', 'problem', 'awaiting_visit'], true)) {
                throw ValidationException::withMessages(['comment' => 'Cette fiche est dans le circuit de contrôle ou de clôture. Un problème RDV ne peut pas écraser cet état.']);
            }
            if (blank($data['comment'] ?? null)) {
                throw ValidationException::withMessages(['comment' => 'Un commentaire est obligatoire.']);
            }
            $previousStatus = $operation->legacy_status;
            $operation->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
            $comment = CommentContent::forStorage($data['comment'], 'comment');
            if (filled($data['recall_date'] ?? null)) {
                $comment .= "\nRappel prévu le ".CarbonImmutable::parse($data['recall_date'])->format('d/m/Y');
                if (filled($data['recall_time'] ?? null)) {
                    $comment .= ' à '.$data['recall_time'];
                }
                $comment .= '.';
            }
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $comment,
                'changed_fields' => ['operation_id' => $operation->id, ...Arr::only($data, ['problem_type', 'recall_date', 'recall_time'])],
                'client_visible' => (bool) ($data['client_visible'] ?? false),
                'delegataire_visible' => (bool) (($data['client_visible'] ?? false) && ($data['delegataire_visible'] ?? false))]);
            $details = $data['problem_type'] ?? 'Problème rendez-vous';
            if (filled($data['recall_date'] ?? null)) {
                $details .= ' · Rappel le '.$data['recall_date'].(filled($data['recall_time'] ?? null) ? ' à '.$data['recall_time'] : '');
            }
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'status_changed',
                'body' => 'Problème rendez-vous · Fiche #'.$operation->id.' · '.$details,
                'client_visible' => false, 'delegataire_visible' => false,
                'changed_fields' => ['operation_id' => $operation->id, 'previous_status' => $previousStatus,
                    'status' => 'PROBLÈME RENDEZ-VOUS', 'problem_type' => $data['problem_type'] ?? null,
                    'recall_date' => $data['recall_date'] ?? null, 'recall_time' => $data['recall_time'] ?? null]]);
            $dossier->increment('version');
        });
    }

    public function reopen(User $actor, Dossier $dossier, DossierOperation $operation, int $version, string $comment): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless($operation->dossier_id === $dossier->id, 404);
        DB::transaction(function () use ($actor, $dossier, $operation, $version, $comment): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            if ($dossier->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de reprendre.']);
            }
            if (DossierStatusCatalog::describe($operation->legacy_status)['key'] !== 'problem') {
                throw ValidationException::withMessages(['operation' => 'Seule une fiche en problème peut revenir à la prise de RDV.']);
            }
            if ($operation->appointments()->exists()) {
                throw ValidationException::withMessages(['operation' => 'Un rendez-vous est déjà lié : utilise « Replacer le RDV » pour conserver son historique sans créer de doublon.']);
            }
            if (blank($comment)) {
                throw ValidationException::withMessages(['comment' => 'Indique le motif de la reprise.']);
            }
            $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => trim($comment)]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'status_changed',
                'body' => 'Fiche #'.$operation->id.' remise en prise de RDV.',
                'changed_fields' => ['operation_id' => $operation->id, 'status' => 'PRISE DE RDV']]);
            $dossier->increment('version');
        }, 3);
    }
}
