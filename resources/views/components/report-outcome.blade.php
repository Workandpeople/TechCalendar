@props(['outcome' => null])
@php
    $value = is_string($outcome) ? \Illuminate\Support\Str::lower(\Illuminate\Support\Str::squish(\Illuminate\Support\Str::ascii($outcome))) : '';
    $key = match ($value) {
        'satisfactory', 'satisfaisant' => 'satisfactory',
        'unsatisfactory', 'non satisfaisant' => 'unsatisfactory',
        'unverifiable', 'inaccessible', 'non verifiable', 'inaccessible / non verifiable' => 'unverifiable',
        default => null,
    };
    $label = \App\Services\Crm\PostVisitWorkflow::OUTCOMES[$key] ?? null;
@endphp
@if ($label)
    <span class="gc-report-outcome" data-report-outcome="{{ $key }}">
        <span class="gc-report-outcome-icon" role="img" tabindex="0" aria-label="{{ $label }}" title="{{ $label }}">
            @if ($key === 'unverifiable')
                <span aria-hidden="true">-</span>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M8 9h.01M16 9h.01" stroke-width="3" />
                    <path d="{{ $key === 'satisfactory' ? 'M7.5 13.5c1 4 8 4 9 0' : 'M7.5 17c1-4 8-4 9 0' }}" />
                </svg>
            @endif
        </span>
        <span aria-hidden="true">{{ $key === 'unverifiable' ? 'Inaccessible' : $label }}</span>
    </span>
@endif
