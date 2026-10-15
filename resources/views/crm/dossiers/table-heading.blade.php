@php
    $activeSort = ($filters['sort'] ?? null) === $key;
    $direction = $activeSort ? ($filters['direction'] ?? 'asc') : null;
    $nextFilters = \Illuminate\Support\Arr::except($filters, ['page', 'sort', 'direction']);
    if ($direction !== 'desc') {
        $nextFilters['sort'] = $key;
        $nextFilters['direction'] = $direction === 'asc' ? 'desc' : 'asc';
    }
    $sortLabel = $direction === 'desc' ? 'Retirer le tri' : ($direction === 'asc' ? 'Trier par ordre décroissant' : 'Trier par ordre croissant');
@endphp
<th scope="col" data-column="{{ $key }}" aria-sort="{{ $direction === 'asc' ? 'ascending' : ($direction === 'desc' ? 'descending' : 'none') }}">
    <a href="{{ route('v2.dossiers.index', $nextFilters) }}" title="{{ $sortLabel }} : {{ $label }}" aria-label="{{ $sortLabel }} : {{ $label }}" class="gc-dossier-sort">
        <span>{{ $label }}</span>
        <svg aria-hidden="true" width="12" height="16" viewBox="0 0 12 16" class="shrink-0" fill="currentColor">
            <path d="m6 2 4 5H2z" opacity="{{ $direction === 'asc' ? '1' : '.25' }}" />
            <path d="m6 14 4-5H2z" opacity="{{ $direction === 'desc' ? '1' : '.25' }}" />
        </svg>
    </a>
</th>
