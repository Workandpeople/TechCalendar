<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DossierBillingService
{
    public const BILLABLE_STATES = ['problem', 'report', 'non_compliant', 'recontrol', 'rt_control'];

    public static function summary(Dossier $dossier): array
    {
        $count = $dossier->operations->filter(fn ($operation) => $operation->billing !== null
            || in_array($operation->legacy_status, DossierStatusCatalog::STATUSES['invoiced']['values'], true))->count();

        return match (true) {
            $dossier->billing_review_required => ['label' => 'Facturation à vérifier', 'tone' => 'amber'],
            $count === 0 => ['label' => 'Non facturé', 'tone' => 'slate'],
            default => ['label' => 'Facturé', 'tone' => 'green'],
        };
    }

    public function mark(User $actor, Dossier $dossier, ?DossierOperation $operation, array $data): void
    {
        abort_if($operation && $operation->dossier_id !== $dossier->id, 404);
        Gate::forUser($actor)->authorize('manageBilling', $dossier);

        DB::transaction(function () use ($actor, $dossier, $operation, $data): void {
            // Serialize all marks on the parent, including requests for different operations.
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $currentActor = User::query()->find($actor->id);
            abort_unless($currentActor, 403);
            Gate::forUser($currentActor)->authorize('manageBilling', $dossier);

            if ($dossier->billings()->exists() || $dossier->operations()->whereIn('legacy_status', DossierStatusCatalog::STATUSES['invoiced']['values'])->exists()) {
                throw ValidationException::withMessages(['billing' => 'Ce dossier est déjà facturé. Aucune seconde facturation n’a été enregistrée.']);
            }
            if ($dossier->billing_review_required) {
                throw ValidationException::withMessages(['billing' => 'Un historique de facturation Coffrac doit être vérifié sur ce dossier. Nouvelle facturation bloquée.']);
            }
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de confirmer la facturation.']);
            }
            $operations = $dossier->operations()->orderBy('id')->lockForUpdate()->get();
            $operation = $operation ? $operations->firstWhere('id', $operation->id) : $operations->first(
                fn ($candidate) => in_array(DossierStatusCatalog::describe($candidate->legacy_status)['key'], self::BILLABLE_STATES, true)
            );
            if (! $operation || ! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], self::BILLABLE_STATES, true)) {
                throw ValidationException::withMessages(['billing' => 'Le dossier doit avoir une prestation en problème rendez-vous, contrôle, non-conformité, recontrôle ou rapport avant ce marquage.']);
            }

            $billing = DossierBilling::query()->forceCreate([
                'dossier_id' => $dossier->id, 'dossier_operation_id' => $operation->id,
                'marked_at' => now(), 'marked_by' => $actor->id,
                'invoice_reference' => trim($data['invoice_reference'] ?? '') ?: null,
                'source' => 'native',
                'evidence' => ['scope' => 'dossier'],
            ]);
            $dossier->activities()->create([
                'author_id' => $actor->id, 'kind' => 'billing',
                'body' => 'Dossier marqué facturé.'
                    .($billing->invoice_reference ? ' Référence : '.$billing->invoice_reference.'.' : '')
                    .' Le statut métier est conservé.',
                'changed_fields' => ['billing_id' => $billing->id, 'operation_id' => $operation->id,
                    'invoice_reference' => $billing->invoice_reference, 'action' => 'marked_invoiced', 'scope' => 'dossier'],
            ]);
            $dossier->increment('version');
        }, 3);
    }
}
