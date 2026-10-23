<nav class="gc-dossier-categories" aria-label="Filtrer les dossiers par état">
    @foreach ($categories as $key => $category)
        @php
            $selected = ($filters['status'] ?? 'all') === $key || ($key === 'all' && empty($filters['status']));
            $categoryFilters = \Illuminate\Support\Arr::except($filters, ['status', 'page']);
            if ($key !== 'all') $categoryFilters['status'] = $key;
            [$categoryIcon, $categoryHint] = \App\Support\WorkspaceUi::status($key);
        @endphp
        <a class="gc-dossier-category" data-tone="{{ $category['tone'] }}" data-category="{{ $key }}"
            href="{{ route($categoryRoute ?? 'v2.dossiers.index', $categoryFilters) }}" @if ($selected) aria-current="page" @endif>
            <span class="gc-dossier-category-icon" aria-hidden="true"><x-nav-icon :name="$categoryIcon" /></span>
            <span class="gc-dossier-category-label">{{ $category['label'] }}</span>
            <span class="gc-dossier-category-count">{{ number_format($category['count'], 0, ',', ' ') }}</span>
            <span class="gc-dossier-category-hint">{{ $categoryHint }}</span>
        </a>
    @endforeach
</nav>
