@props(['name'])

@switch($name)
    @case('dashboard')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <rect x="3" y="3" width="8" height="8" rx="2" />
            <rect x="13" y="3" width="8" height="5" rx="2" />
            <rect x="13" y="10" width="8" height="11" rx="2" />
            <rect x="3" y="13" width="8" height="8" rx="2" />
        </svg>
        @break

    @case('users')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <path d="M16 19a4 4 0 0 0-8 0" />
            <circle cx="12" cy="11" r="3" />
            <path d="M5 19a3 3 0 0 1 3-3" />
            <path d="M19 19a3 3 0 0 0-3-3" />
        </svg>
        @break

    @case('settings')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <path d="M12 8.5A3.5 3.5 0 1 0 12 15.5A3.5 3.5 0 1 0 12 8.5Z" />
            <path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1 1 0 0 0-1.1-.2 1 1 0 0 0-.6.9V20a2 2 0 1 1-4 0v-.2a1 1 0 0 0-.6-.9 1 1 0 0 0-1.1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1 1 0 0 0 .2-1.1 1 1 0 0 0-.9-.6H4a2 2 0 1 1 0-4h.2a1 1 0 0 0 .9-.6 1 1 0 0 0-.2-1.1l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1 1 0 0 0 1.1.2 1 1 0 0 0 .6-.9V4a2 2 0 1 1 4 0v.2a1 1 0 0 0 .6.9 1 1 0 0 0 1.1-.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1 1 0 0 0-.2 1.1 1 1 0 0 0 .9.6h.2a2 2 0 1 1 0 4h-.2a1 1 0 0 0-.9.6Z" />
        </svg>
        @break

    @case('services')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <path d="m4 8 8-4 8 4-8 4-8-4Z" />
            <path d="m4 12 8 4 8-4" />
            <path d="m4 16 8 4 8-4" />
        </svg>
        @break

    @case('lots')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <path d="M4 7.5 12 3l8 4.5-8 4.5-8-4.5Z" />
            <path d="M4 12.5 12 17l8-4.5" />
            <path d="M4 17.5 12 22l8-4.5" />
            <path d="M12 12v5" />
        </svg>
        @break

    @case('appointments')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M8 3v4M16 3v4M3 10h18" />
            <path d="m9 14 2 2 4-4" />
        </svg>
        @break

    @case('book')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <rect x="4" y="4" width="16" height="16" rx="2" />
            <path d="M8 2v4M16 2v4M8 12h8M12 8v8" />
        </svg>
        @break

    @case('tracking')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <circle cx="12" cy="12" r="8" />
            <path d="M12 7v5l3 3" />
        </svg>
        @break

    @case('planning')
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
            <rect x="3" y="4" width="18" height="17" rx="2" />
            <path d="M3 9h18M8 2v4M16 2v4M8 13h3M13 13h3M8 17h3" />
        </svg>
        @break
@endswitch
