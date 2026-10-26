@props(['inactive' => false])

<span class="gc-user-status" data-user-status="{{ $inactive ? 'inactive' : 'active' }}">
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true" focusable="false">
        <circle cx="10" cy="10" r="7.25" />
        @if ($inactive)
            <path d="M6.5 10h7" stroke-linecap="round" />
        @else
            <path d="m6.5 10 2.25 2.25 4.75-4.75" stroke-linecap="round" stroke-linejoin="round" />
        @endif
    </svg>
    {{ $inactive ? 'Désactivé' : 'Actif' }}
</span>
