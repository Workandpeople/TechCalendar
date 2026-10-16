@props(['action', 'visible' => false, 'delegataireVisible' => false, 'label', 'hint' => null, 'compact' => false])
@php($audience = \App\Services\Crm\ContentAudience::key($visible, $delegataireVisible))
<div class="gc-sharing" data-audience="{{ $audience }}">
    <span class="gc-audience-badge" data-audience="{{ $audience }}"><x-nav-icon :name="$visible ? 'users' : 'lock'" />{{ \App\Services\Crm\ContentAudience::LABELS[$audience] }}</span>
    <details class="gc-sharing-editor">
        <summary>Modifier l’accès<span class="sr-only"> à {{ $label }}</span></summary>
        <form method="POST" action="{{ $action }}" class="mt-2 space-y-2">
            @csrf @method('PATCH')
            <label class="gc-label">Destinataires
                <select class="gc-input" name="audience" aria-label="Destinataires de {{ $label }}">
                    @foreach (\App\Services\Crm\ContentAudience::LABELS as $value => $title)<option value="{{ $value }}" @selected($audience === $value)>{{ $title }}</option>@endforeach
                </select>
            </label>
            <button class="gc-btn-primary" type="submit">Appliquer</button>
        </form>
    </details>
    @if ($hint)<span class="text-xs text-slate-500">{{ $hint }}</span>@endif
</div>
