@php
    $columns = $table->columns($filters);
    $columnShares = $table->columnShares($filters);
@endphp
<section class="overflow-hidden rounded-2xl border border-slate-200" aria-label="Résultats de recherche">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
        <h2 class="font-semibold">{{ number_format($dossiers->total(), 0, ',', ' ') }} dossier(s) · {{ $categories[($filters['status'] ?? null) ?: 'all']['label'] ?? 'Facturés' }}</h2>
        <span class="text-xs text-slate-500">{{ filled($filters['sort'] ?? null) ? 'Tri : '.\App\Services\Crm\DossierTable::COLUMNS[$filters['sort']].' · '.(($filters['direction'] ?? 'asc') === 'asc' ? 'croissant' : 'décroissant') : 'Tri par défaut : derniers dossiers ajoutés' }}</span>
    </div>
    <div class="gc-dossier-table-scroll" tabindex="0" role="region" aria-label="Tableau des dossiers">
        <table class="gc-dossier-table {{ count($columns) < 10 ? 'gc-dossier-table-compact' : '' }} {{ isset($columns['planning_user']) ? 'gc-dossier-table-assignment' : '' }}">
            <colgroup>
                <col class="gc-dossier-col-action">
                @foreach ($columns as $key => $label)
                    <col class="gc-dossier-col-{{ $key }}" style="width: {{ number_format($columnShares[$key] * 100, 6, '.', '') }}%">
                @endforeach
            </colgroup>
            <thead><tr>
                <th scope="col" class="gc-dossier-action"><span class="sr-only">Voir le dossier</span></th>
                @foreach ($columns as $key => $label)@include('crm.dossiers.table-heading')@endforeach
            </tr></thead>
            <tbody>
                @forelse ($dossiers as $dossier)
                    @php
                        $appointment = $dossier->listingAppointment;
                        $modification = $table->modification($dossier);
                        $fullAddress = trim($dossier->address.' '.$dossier->postal_code.' '.$dossier->city);
                    @endphp
                    <tr data-dossier-comments>
                        <td class="gc-dossier-action"><a class="gc-dossier-open" aria-label="Ouvrir le dossier {{ $dossier->beneficiaryLabel() }}" title="Ouvrir le dossier" href="{{ route('v2.dossiers.show', $dossier) }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a></td>
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
                                        <button type="button" class="gc-dossier-map-trigger" data-dossier-map-url="{{ route('v2.dossiers.map', $dossier) }}" data-dossier-map-address="{{ $fullAddress }}"
                                            aria-label="Voir la carte : {{ $fullAddress ?: 'Adresse non renseignée' }}">
                                            <span class="gc-dossier-cell-lines">{{ $dossier->address ?: 'Adresse non renseignée' }}</span>
                                            <span class="gc-dossier-cell-muted">{{ $dossier->postal_code }} {{ $dossier->city }}</span>
                                        </button>
                                        @break
                                    @case('company')
                                        @php($company = $dossier->delegataire ?: $dossier->client)
                                        <span class="gc-dossier-cell-lines" title="{{ $company?->company_name ?: $company?->name }}">{{ $company?->company_name ?: $company?->name ?: '—' }}</span>
                                        @break
                                    @case('phone')
                                        <span class="gc-dossier-cell-lines" title="{{ $dossier->phone }}">{{ $dossier->phone ?: '—' }}</span>
                                        @break
                                    @case('service')
                                        <ul class="space-y-2">@forelse ($dossier->operations as $operation)
                                            <li><span class="gc-dossier-cell-lines" title="{{ $operation->service?->name ?: $operation->legacy_service_name }}">{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation à rapprocher' }}</span><x-dossier-status :status="$operation->legacy_status" /></li>
                                        @empty<li><x-dossier-status /></li>@endforelse</ul>
                                        @break
                                    @case('technician')
                                        <span class="gc-dossier-cell-lines" title="{{ $appointment?->technician?->full_name_with_departments }}">{{ $appointment?->technician?->full_name_with_departments ?: '—' }}</span>
                                        @break
                                    @case('planning_user')
                                        @include('crm.dossiers.planning-assignment')
                                        @break
                                @endswitch
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 1 }}" class="text-center">Aucun dossier ne correspond à ces filtres.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
