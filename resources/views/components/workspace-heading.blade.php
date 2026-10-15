@props(['title', 'description' => null, 'eyebrow' => null, 'icon' => null])
@php $context = \App\Support\WorkspaceUi::forRoute(request()->route()?->getName()); @endphp
<header {{ $attributes->class(['gc-workspace-heading']) }}>
    <div class="gc-workspace-heading-main">
        <span class="gc-workspace-emblem" aria-hidden="true"><x-nav-icon :name="$icon ?? $context['icon']" /></span>
        <div class="min-w-0">
            @if ($eyebrow)<p class="gc-workspace-eyebrow">{{ $eyebrow }}</p>@endif
            <h1>{{ $title }}</h1>
            @if ($description)<p class="gc-workspace-description">{{ $description }}</p>@endif
        </div>
    </div>
    @if ($slot->isNotEmpty())<div class="gc-workspace-heading-actions">{{ $slot }}</div>@endif
</header>
