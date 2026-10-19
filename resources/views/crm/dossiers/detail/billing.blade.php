@php
    $billingSummary = \App\Services\Crm\DossierBillingService::summary($dossier);
@endphp
<section id="facturation" class="gc-card gc-dossier-panel scroll-mt-24" aria-labelledby="billing-title">
    <header class="gc-dossier-panel-heading"><h2 id="billing-title"><x-nav-icon name="check" />Suivi de facturation</h2></header>
    <div class="gc-dossier-panel-body space-y-4">
        <x-billing-badge :label="$billingSummary['label']" :tone="$billingSummary['tone']" />
        <p class="text-sm text-slate-600">Indépendant du statut : un retour en non-conformité ou en recontrôle ne remet pas la prestation à facturer. Ce suivi ne crée pas de facture et ne vaut pas paiement.</p>
        @if ($dossier->billing_review_required)
            <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                Un historique Coffrac indique une facturation sans attribution certaine à une prestation. La nouvelle facturation est bloquée sur ce dossier jusqu’à vérification par un administrateur. Aucun rattachement n’a été inventé.
            </div>
        @endif
        <ul class="divide-y divide-slate-200">
            @foreach ($dossier->operations as $operation)
                @php
                    $billing = $operation->billing;
                    $canMark = ! $dossier->billing_review_required && in_array(\App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'], \App\Services\Crm\DossierBillingService::BILLABLE_STATES, true);
                    $failedBilling = (string) old('_billing_operation') === (string) $operation->id;
                @endphp
                <li class="space-y-2 py-3" id="facturation-{{ $operation->id }}">
                    <p class="text-sm font-semibold">{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation' }} · #{{ $operation->id }}</p>
                    @if ($billing)
                        <x-billing-badge />
                        <p class="text-xs text-slate-600">{{ $billing->source === 'native' ? 'Marqué le' : 'Historique Coffrac du' }} {{ $billing->marked_at?->format('d/m/Y H:i') ?: 'date non renseignée' }}@if ($billing->marker) · {{ $billing->marker->full_name }}@endif</p>
                        <p class="text-xs">Référence facture : {{ $billing->invoice_reference ?: 'Non renseignée' }}</p>
                        @if ($billing->source !== 'native')<p class="text-xs text-slate-500">Reprise de la marque historique, sans déduction de montant ni de paiement.</p>@endif
                    @else
                        <x-billing-badge :label="$dossier->billing_review_required ? 'À vérifier' : 'Non facturé'" :tone="$dossier->billing_review_required ? 'amber' : 'slate'" />
                        @can('manageBilling', $dossier)
                            @if ($canMark)
                                <details class="rounded-lg border border-slate-200 p-3" @if ($failedBilling) open @endif>
                                    <summary class="cursor-pointer text-sm font-semibold">Marquer cette prestation facturée</summary>
                                    <form method="POST" action="{{ route('v2.dossiers.billing.store', [$dossier, $operation]) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $dossier->version }}">
                                        <input type="hidden" name="_billing_operation" value="{{ $operation->id }}">
                                        <div><label class="gc-label" for="billing-reference-{{ $operation->id }}">Référence de facture (facultative)</label><input class="gc-input" id="billing-reference-{{ $operation->id }}" name="invoice_reference" maxlength="190" value="{{ $failedBilling ? old('invoice_reference') : '' }}"></div>
                                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required> Je confirme que cette prestation a déjà été facturée. Ce marquage restera conservé.</label>
                                        <button type="submit" class="gc-btn-success"><x-nav-icon name="check" />Confirmer la facturation</button>
                                    </form>
                                </details>
                            @elseif (! $dossier->billing_review_required)
                                <p class="text-xs text-slate-500">Le marquage est disponible à partir du contrôle RT.</p>
                            @endif
                        @else
                            <p class="text-xs text-slate-500">Marquage réservé aux gérants, administrateurs et personnes habilitées à la comptabilité.</p>
                        @endcan
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>
