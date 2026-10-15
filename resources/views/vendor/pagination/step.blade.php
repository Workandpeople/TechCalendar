@php
    $previous = $direction === 'previous';
    $url = $previous ? $paginator->previousPageUrl() : $paginator->nextPageUrl();
    $label = __('pagination.'.$direction);
@endphp

@if ($url)
    <a href="{{ $url }}" rel="{{ $previous ? 'prev' : 'next' }}" class="gc-pagination-button gc-pagination-step" aria-label="{{ $label }}" title="{{ $label }}">
@else
    <span class="gc-pagination-button gc-pagination-step is-disabled" aria-disabled="true" aria-label="{{ $label }}" title="{{ $label }}">
@endif
    @if ($previous)
        <svg class="gc-pagination-icon" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true" focusable="false"><path d="m12 5-5 5 5 5" stroke-linecap="round" stroke-linejoin="round" /></svg>
    @endif
    <span>{{ $label }}</span>
    @unless ($previous)
        <svg class="gc-pagination-icon" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true" focusable="false"><path d="m8 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" /></svg>
    @endunless
@if ($url)
    </a>
@else
    </span>
@endif
