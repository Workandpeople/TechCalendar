<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierWorkflow
{
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
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => trim($data['comment'])]);
            $details = $data['problem_type'] ?? 'Problème rendez-vous';
            if (filled($data['recall_date'] ?? null) && filled($data['recall_time'] ?? null)) {
                $details .= ' · Rappel le '.$data['recall_date'].' à '.$data['recall_time'];
            }
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'status_changed',
                'body' => 'Problème rendez-vous · Fiche #'.$operation->id.' · '.$details,
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
