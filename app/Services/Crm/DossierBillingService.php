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
    public const BILLABLE_STATES = ['report', 'non_compliant', 'recontrol', 'rt_control'];

    public static function summary(Dossier $dossier): array
    {
        $count = $dossier->operations->filter(fn ($operation) => $operation->billing !== null)->count();
        $total = $dossier->operations->count();

        return match (true) {
            $dossier->billing_review_required => ['label' => 'Facturation à vérifier', 'tone' => 'amber'],
            $count === 0 => ['label' => 'Non facturé', 'tone' => 'slate'],
            $count < $total => ['label' => 'Partiellement facturé ('.$count.'/'.$total.')', 'tone' => 'amber'],
            default => ['label' => 'Entièrement facturé', 'tone' => 'green'],
        };
    }

    public function mark(User $actor, Dossier $dossier, DossierOperation $operation, array $data): void
    {
        abort_unless($operation->dossier_id === $dossier->id, 404);
        Gate::forUser($actor)->authorize('manageBilling', $dossier);

        DB::transaction(function () use ($actor, $dossier, $operation, $data): void {
            // Same lock order as the post-visit workflow; the unique operation key is a second guard.
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            $currentActor = User::query()->find($actor->id);
            abort_unless($currentActor, 403);
            Gate::forUser($currentActor)->authorize('manageBilling', $dossier);

            if ($operation->billing()->exists() || DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'invoiced') {
                throw ValidationException::withMessages(['billing' => 'Cette prestation est déjà facturée. Aucune seconde facturation n’a été enregistrée.']);
            }
            if ($dossier->billing_review_required) {
                throw ValidationException::withMessages(['billing' => 'Un historique de facturation Coffrac doit être vérifié sur ce dossier. Nouvelle facturation bloquée.']);
            }
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de confirmer la facturation.']);
            }
            if (! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], self::BILLABLE_STATES, true)) {
                throw ValidationException::withMessages(['billing' => 'La prestation doit être en contrôle, non-conformité, recontrôle ou rapport avant ce marquage.']);
            }

            $billing = DossierBilling::query()->forceCreate([
                'dossier_id' => $dossier->id, 'dossier_operation_id' => $operation->id,
                'marked_at' => now(), 'marked_by' => $actor->id,
                'invoice_reference' => trim($data['invoice_reference'] ?? '') ?: null,
                'source' => 'native',
            ]);
            $dossier->activities()->create([
                'author_id' => $actor->id, 'kind' => 'billing',
                'body' => 'Prestation #'.$operation->id.' marquée facturée.'
                    .($billing->invoice_reference ? ' Référence : '.$billing->invoice_reference.'.' : '')
                    .' Le statut métier est conservé.',
                'changed_fields' => ['billing_id' => $billing->id, 'operation_id' => $operation->id,
                    'invoice_reference' => $billing->invoice_reference, 'action' => 'marked_invoiced'],
            ]);
            $dossier->increment('version');
        }, 3);
    }
}
