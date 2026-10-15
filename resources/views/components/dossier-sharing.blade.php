@props(['action', 'visible' => false, 'label', 'hint' => null, 'compact' => false])

<div class="space-y-2">
    <p @class(['text-xs font-medium', 'text-emerald-700' => $visible, 'text-slate-600' => ! $visible]) @if ($compact) title="{{ $visible ? 'Client affecté et équipe interne' : 'Équipe interne uniquement' }}{{ $hint ? ' · '.$hint : '' }}" @endif>
        @if ($compact)
            <span class="gc-dossier-visibility">{{ $visible ? 'Public' : 'Privé' }}</span><span class="sr-only"> · {{ $visible ? 'Client affecté et équipe interne' : 'Équipe interne uniquement' }}{{ $hint ? ' · '.$hint : '' }}</span>
        @else
            {{ $visible ? 'Public · Client affecté et équipe interne' : 'Privé · Équipe interne uniquement' }}
        @endif
    </p>
    @if ($hint && ! $compact)<p class="text-xs text-slate-500">{{ $hint }}</p>@endif
    <form method="POST" action="{{ $action }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="client_visible" value="{{ $visible ? '0' : '1' }}">
        <button type="submit" class="gc-btn-soft" aria-label="{{ $visible ? 'Rendre privé' : 'Rendre public' }} : {{ $label }}">
            {{ $visible ? 'Rendre privé' : 'Rendre public' }}
        </button>
    </form>
</div>
