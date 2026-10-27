@props(['section', 'scope', 'nested' => false])
@php
    $panelId = 'sidebar-'.$scope.'-'.$section['id'].'-items';
    $leafItems = function (array $items) use (&$leafItems) {
        return collect($items)->flatMap(fn ($item) => isset($item['items']) ? $leafItems($item['items'])->all() : [$item]);
    };
    $links = $leafItems($section['items']);
    $notificationCount = $links->sum(fn ($item) => max(0, (int) ($item['badge'] ?? 0)));
    $patterns = $links
        ->flatMap(fn ($item) => (array) ($item['active'] ?? $item['route']))->all();
    $isSectionActive = request()->routeIs(...$patterns);
@endphp
<section class="sidebar-section {{ $nested ? 'sidebar-section-nested' : '' }}" aria-label="{{ $section['label'] }}">
    @if ($section['collapsible'] ?? false)
        <button type="button" class="sidebar-section-toggle {{ $isSectionActive ? 'sidebar-section-current' : '' }}"
            data-sidebar-section-toggle="{{ $section['id'] }}" aria-expanded="true" aria-controls="{{ $panelId }}">
            <span>{{ $section['label'] }}</span>
            @if ($notificationCount > 0 || ($section['id'] === 'planner' && config('crm.v2_enabled')))
                <span class="sidebar-notification sidebar-section-notification {{ $notificationCount > 0 ? '' : 'hidden' }}" @if ($section['id'] === 'planner' && config('crm.v2_enabled')) data-booking-pending-count @endif
                    aria-label="{{ $notificationCount }} notification(s) dans {{ $section['label'] }}"
                    title="{{ $notificationCount }} notification(s) dans {{ $section['label'] }}">{{ $notificationCount }}</span>
            @endif
            <svg class="sidebar-section-chevron h-4 w-4 shrink-0" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m6 9 6 6 6-6" /></svg>
        </button>
    @else
        <h3 class="sidebar-section-title px-3 pb-2 text-xs font-semibold uppercase tracking-[0.1em] text-[color:var(--gc-text-soft)]">{{ $section['label'] }}</h3>
    @endif
    <ul id="{{ $panelId }}" class="sidebar-section-content space-y-1">
        @foreach ($section['items'] as $item)
            @if (isset($item['items']))
                <li><x-sidebar-section :section="$item" :scope="$scope" :nested="true" /></li>
            @else
                @php $isActive = request()->routeIs(...(array) ($item['active'] ?? $item['route'])); @endphp
                <li class="sidebar-item">
                    <a href="{{ route($item['route']) }}" class="sidebar-link {{ $isActive ? 'sidebar-link-active' : '' }}" data-workspace-tone="{{ \App\Support\WorkspaceUi::forRoute($item['route'])['tone'] }}" title="{{ $item['label'] }}" @if ($isActive) aria-current="page" @endif>
                        <span class="gc-sidebar-icon shrink-0" aria-hidden="true"><x-nav-icon :name="$item['icon']" /></span>
                        <span class="sidebar-link-label">{{ $item['label'] }}</span>
                        @if (($item['badge'] ?? 0) > 0 || ($item['route'] === 'planner.book' && config('crm.v2_enabled')))
                            <span class="sidebar-notification {{ ($item['badge'] ?? 0) > 0 ? '' : 'hidden' }}" @if ($item['route'] === 'planner.book' && config('crm.v2_enabled')) data-booking-pending-count @endif aria-label="{{ $item['badge'] }} {{ $item['badge_label'] }}" title="{{ $item['badge'] }} {{ $item['badge_label'] }}">{{ $item['badge'] }}</span>
                        @endif
                    </a>
                </li>
            @endif
        @endforeach
    </ul>
</section>
