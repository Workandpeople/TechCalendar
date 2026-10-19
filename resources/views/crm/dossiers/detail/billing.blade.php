@php
    $billingSummary = \App\Services\Crm\DossierBillingService::summary($dossier);
    $billingHistory = $dossier->operations->pluck('billing')->filter();
    $canMark = $billingSummary['tone'] === 'slate' && $dossier->operations->contains(fn ($operation) => in_array(\App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'], \App\Services\Crm\DossierBillingService::BILLABLE_STATES, true));
@endphp
<section id="facturation" class="gc-card gc-dossier-panel scroll-mt-24" aria-labelledby="billing-title">
    <header class="gc-dossier-panel-heading"><h2 id="billing-title"><x-nav-icon name="check" />Suivi de facturation</h2></header>
    <div class="gc-dossier-panel-body space-y-4">
        <x-billing-badge :label="$billingSummary['label']" :tone="$billingSummary['tone']" />
        <p class="text-sm text-slate-600">Un seul marquage pour tout le dossier. Il reste conservé lors des changements d’état et bloque une seconde facturation. Ce suivi ne crée pas de facture et ne vaut pas paiement.</p>
        @if ($dossier->billing_review_required)
            <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Un historique de facturation Coffrac nécessite une vérification. Toute nouvelle facturation est bloquée. Les preuves historiques restent conservées.</div>
        @endif
        @if ($billingHistory->isNotEmpty())
            <p class="text-sm font-semibold">Déjà facturé</p>
            <details>
                <summary class="cursor-pointer text-sm font-semibold">Historique comptable conservé</summary>
                <ul class="divide-y divide-slate-200">
                    @foreach ($billingHistory as $billing)
                        <li class="space-y-1 py-3 text-xs">
                            <p>{{ $billing->source === 'native' ? 'Marqué le' : 'Historique Coffrac du' }} {{ $billing->marked_at?->format('d/m/Y H:i') ?: 'date non renseignée' }}@if ($billing->marker) · {{ $billing->marker->full_name }}@endif</p>
                            <p>Référence facture : {{ $billing->invoice_reference ?: 'Non renseignée' }}</p>
                        </li>
                    @endforeach
                </ul>
            </details>
        @else
            @can('manageBilling', $dossier)
                @if ($canMark)
                    <details class="rounded-lg border border-slate-200 p-3" @if (old('confirmed')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold">Marquer le dossier facturé</summary>
                        <form method="POST" action="{{ route('v2.dossiers.billing.mark', $dossier) }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="version" value="{{ $dossier->version }}">
                            <div><label class="gc-label" for="billing-reference">Référence de facture (facultative)</label><input class="gc-input" id="billing-reference" name="invoice_reference" maxlength="190" value="{{ old('invoice_reference') }}"></div>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required> Je confirme que ce dossier a déjà été facturé. Ce marquage restera conservé.</label>
                            <button type="submit" class="gc-btn-success"><x-nav-icon name="check" />Confirmer la facturation</button>
                        </form>
                    </details>
                @elseif (! $dossier->billing_review_required)
                    <p class="text-xs text-slate-500">Le marquage est disponible à partir du contrôle RT.</p>
                @endif
            @else
                <p class="text-xs text-slate-500">Marquage réservé aux personnes habilitées à la comptabilité.</p>
            @endcan
        @endif
    </div>
</section>
