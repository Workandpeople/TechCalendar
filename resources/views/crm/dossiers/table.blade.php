@php
    $columns = $table->columns($filters);
    $columnShares = $table->columnShares($filters);
    $canArchive = auth()->user()->can('manageArchiving', \App\Models\Dossier::class);
    $canSelect = $canArchive && ($filters['status'] ?? null) === 'problem';
@endphp
<section class="overflow-hidden rounded-2xl border border-slate-200" aria-label="Résultats de recherche">
    <div class="gc-dossier-results-toolbar border-b border-slate-200 bg-white px-4 py-3">
        <div><h2 class="font-semibold">{{ number_format($dossiers->total(), 0, ',', ' ') }} dossier(s) · {{ \App\Services\Crm\DossierStatusCatalog::STATUSES[$filters['status'] ?? '']['label'] ?? 'Tous les dossiers actifs' }}</h2>
        <p class="mt-1 text-xs text-slate-500">{{ filled($filters['sort'] ?? null) ? 'Tri : '.\App\Services\Crm\DossierTable::COLUMNS[$filters['sort']].' · '.(($filters['direction'] ?? 'asc') === 'asc' ? 'croissant' : 'décroissant') : 'Tri par défaut : derniers dossiers ajoutés' }}</p></div>
        <div class="gc-dossier-results-options">
            <label><input type="checkbox" name="all_columns" form="dossier-filters" data-dossier-all-columns value="1" @checked($filters['all_columns'] ?? false)> Toutes les colonnes</label>
            @include('crm.dossiers.page-size', ['paginator' => $dossiers])
        </div>
    </div>
    @if ($canSelect)
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-4 py-3" data-dossier-batch-url="{{ route('v2.dossiers.batch') }}" data-batch-limit="{{ \App\Services\Crm\DossierArchiving::MAX_BATCH_SIZE }}">
            <span class="text-sm" role="status" aria-live="polite"><strong data-dossier-selection-count>0</strong> dossier(s) sélectionné(s)</span>
            <button type="button" class="gc-btn-secondary" data-dossier-batch-action="cancel" disabled>Annuler la sélection</button>
            <button type="button" class="gc-btn-danger" data-dossier-batch-action="delete" disabled>Supprimer la sélection</button>
            <p class="w-full text-xs text-slate-500">Sélection limitée à cette page, {{ \App\Services\Crm\DossierArchiving::MAX_BATCH_SIZE }} dossiers maximum. Aucun traitement automatique des anciens dossiers.</p>
            <label class="gc-label w-full">Motif (facultatif)<input type="text" data-dossier-batch-reason maxlength="1000" class="gc-input" placeholder="Motif conservé dans l’historique interne"></label>
        </div>
    @endif
    <div class="gc-dossier-table-scroll" tabindex="0" role="region" aria-label="Tableau des dossiers">
        <table class="gc-dossier-table {{ count($columns) < 10 ? 'gc-dossier-table-compact' : '' }} {{ isset($columns['planning_user']) ? 'gc-dossier-table-assignment' : '' }}">
            <colgroup>
                <col class="gc-dossier-col-action">
                @if ($canSelect)<col class="gc-dossier-col-selection">@endif
                @foreach ($columns as $key => $label)
                    <col class="gc-dossier-col-{{ $key }}" style="width: {{ number_format($columnShares[$key] * 100, 6, '.', '') }}%">
                @endforeach
                @if ($canArchive)<col class="gc-dossier-col-delete">@endif
            </colgroup>
            <thead><tr>
                <th scope="col" class="gc-dossier-action"><span class="sr-only">Voir le dossier</span></th>
                @if ($canSelect)<th scope="col"><input type="checkbox" data-dossier-select-page aria-label="Sélectionner les dossiers en problème RDV de cette page"></th>@endif
                @foreach ($columns as $key => $label)@include('crm.dossiers.table-heading')@endforeach
                @if ($canArchive)<th scope="col" class="gc-dossier-delete"><span class="sr-only">Supprimer le dossier</span></th>@endif
            </tr></thead>
            <tbody>
                @forelse ($dossiers as $dossier)
                    @php
                        $appointment = $dossier->listingAppointment;
                        $modification = $table->modification($dossier);
                        $address = $table->address($dossier);
                    @endphp
                    <tr data-dossier-comments>
                        <td class="gc-dossier-action"><a class="gc-dossier-open" aria-label="Ouvrir le dossier {{ $dossier->beneficiaryLabel() }}" title="Ouvrir le dossier" href="{{ route('v2.dossiers.show', $dossier) }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a></td>
                        @if ($canSelect)
                            <td>@can('delete', $dossier)<input type="checkbox" data-dossier-select data-id="{{ $dossier->id }}" data-version="{{ $dossier->version }}" aria-label="Sélectionner le dossier {{ $dossier->reference }}">@endcan</td>
                        @endif
                        @foreach ($columns as $key => $label)
                            <td data-column="{{ $key }}">
                                @switch($key)
                                    @case('lot')
                                        <p class="gc-dossier-cell-lines" title="{{ $dossier->listing_lot }}">{{ $dossier->listing_lot ?: '—' }}</p>
                                        @break
                                    @case('created_at')
                                        <time datetime="{{ $dossier->created_at?->toIso8601String() }}">{{ $dossier->created_at?->format('d/m/Y') ?: '—' }}</time>
                                        @break
                                    @case('modified_at')
                                        <time datetime="{{ $modification['date']?->toIso8601String() }}">{{ $modification['date']?->format('d/m/Y') ?: '—' }}</time>
                                        <span class="gc-dossier-cell-muted">{{ $modification['date']?->format('H:i') }}</span>
                                        <p class="gc-dossier-cell-lines gc-dossier-change" title="{{ $modification['label'] }}">{{ $modification['label'] }}</p>
                                        @break
                                    @case('appointment_at')
                                        @if ($appointment)
                                            <time datetime="{{ $appointment->starts_at->toIso8601String() }}">{{ $appointment->starts_at->format('d/m/Y') }}</time>
                                            <span class="gc-dossier-cell-muted">{{ $appointment->starts_at->format('H:i') }}</span>
                                            @if ($appointment->trashed())<span class="gc-dossier-cell-muted">Historique</span>@endif
                                        @else<span class="gc-dossier-cell-muted">Non placé</span>@endif
                                        @break
                                    @case('client')
                                        <a class="gc-dossier-client gc-dossier-cell-lines" data-dossier-comments-trigger href="{{ route('v2.dossiers.show', $dossier) }}">{{ $dossier->beneficiaryLabel() }}</a>
                                        @foreach (\App\Services\Crm\DossierSections::badges($dossier) as $badge)<span class="gc-dossier-status" data-tone="blue">{{ $badge }}</span>@endforeach
                                        @php($billingSummary = \App\Services\Crm\DossierBillingService::summary($dossier))
                                        @if ($billingSummary['tone'] !== 'slate')<a href="{{ route('v2.dossiers.show', $dossier) }}#facturation"><x-billing-badge :label="$billingSummary['label']" :tone="$billingSummary['tone']" /></a>@endif
                                        <a href="{{ route('v2.dossiers.show', $dossier) }}#commentaires" class="gc-dossier-comments-link"
                                            aria-label="Voir les {{ $dossier->comments_count }} commentaires du dossier {{ $dossier->reference }}">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 15a3 3 0 0 1-3 3H8l-5 4V6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3z"/></svg>
                                            {{ $dossier->comments_count }}
                                        </a>
                                        @include('crm.dossiers.comments-preview', ['dossier' => $dossier])
                                        @break
                                    @case('address')
                                        <button type="button" class="gc-dossier-map-trigger" data-dossier-map-url="{{ route('v2.dossiers.map', $dossier) }}" data-dossier-map-address="{{ $address['full'] }}"
                                            title="{{ $address['original'] }}" aria-label="Voir la carte : {{ $address['full'] ?: 'Adresse non renseignée' }}">
                                            <strong class="gc-dossier-address-street">{{ $address['street'] ?: 'Adresse non renseignée' }}</strong>
                                            @if ($address['locality'] !== '')<span class="gc-dossier-address-locality">{{ $address['locality'] }}</span>@endif
                                        </button>
                                        @break
                                    @case('company')
                                        @php($companies = $table->companies($dossier))
                                        @if ($companies['client'] !== '')<span class="gc-dossier-cell-lines font-semibold" data-company-client title="{{ $companies['client'] }}">{{ $companies['client'] }}</span>@endif
                                        @if ($companies['delegataire'] !== '')<span class="gc-dossier-cell-lines {{ $companies['client'] !== '' ? 'gc-dossier-cell-muted' : '' }}" data-company-delegataire title="Délégataire : {{ $companies['delegataire'] }}">Délégataire : {{ $companies['delegataire'] }}</span>@endif
                                        @if ($companies['client'] === '' && $companies['delegataire'] === '')<span class="gc-dossier-cell-muted">—</span>@endif
                                        @break
                                    @case('phone')
                                        <ul class="gc-dossier-phones" aria-label="Numéros de téléphone">
                                            @forelse ($table->phoneNumbers($dossier) as $phone)
                                                <li>@if ($phone['href'])<a href="{{ $phone['href'] }}" aria-label="Appeler le {{ $phone['label'] }}">{{ $phone['label'] }}</a>@else<span>{{ $phone['label'] }}</span>@endif</li>
                                            @empty<li>—</li>@endforelse
                                        </ul>
                                        @break
                                    @case('service')
                                        @if ($dossier->trashed())<span class="gc-dossier-status" data-tone="slate">Dossier supprimé</span>@endif
                                        <ul class="space-y-2">@forelse ($dossier->operations as $operation)
                                            <li><span class="gc-dossier-cell-lines" title="{{ $operation->service?->name ?: $operation->legacy_service_name }}">{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation à rapprocher' }}</span><x-dossier-status :status="$operation->legacy_status" /></li>
                                        @empty<li><x-dossier-status /></li>@endforelse</ul>
                                        @break
                                    @case('technician')
                                        @if (($filters['status'] ?? null) === 'awaiting_visit' && $appointment && ! $appointment->trashed()
                                            && ! $dossier->operations->firstWhere('id', $appointment->dossier_operation_id)?->inspected_on)
                                            @include('crm.dossiers.technician-assignment', ['auto' => true])
                                        @else
                                            <span class="gc-dossier-cell-lines" title="{{ $appointment?->technician?->full_name_with_departments }}">{{ $appointment?->technician?->full_name_with_departments ?: '—' }}</span>
                                        @endif
                                        @break
                                    @case('planning_user')
                                        @include('crm.dossiers.planning-assignment')
                                        @break
                                @endswitch
                            </td>
                        @endforeach
                        @if ($canArchive)
                            <td class="gc-dossier-delete">@can('delete', $dossier)
                                <button type="button" class="gc-link text-sm text-red-700" data-dossier-delete-url="{{ route('v2.dossiers.destroy', $dossier) }}" data-version="{{ $dossier->version }}" data-reference="{{ $dossier->reference }}" aria-label="Supprimer le dossier {{ $dossier->reference }}">Supprimer</button>
                            @endcan</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 1 + (int) $canSelect + (int) $canArchive }}" class="text-center">Aucun dossier ne correspond à ces filtres.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
