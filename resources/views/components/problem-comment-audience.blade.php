@props(['id'])
<div>
    <label class="gc-label" for="{{ $id }}">Portée du commentaire et du rappel</label>
    <select id="{{ $id }}" name="audience" class="gc-input">
        @foreach (\App\Services\Crm\ContentAudience::LABELS as $key => $label)
            <option value="{{ $key }}" @selected($key === 'client')>{{ $label }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Le rappel est facultatif, sauf pour une demande de rappel qui exige une date et une heure.</p>
</div>
