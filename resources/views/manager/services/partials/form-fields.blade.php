<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <label class="gc-label" for="{{ $prefix }}_type">Type</label>
        <select id="{{ $prefix }}_type" name="type" class="gc-input" required>
            @foreach ($types as $type)
                <option value="{{ $type }}">{{ \App\Support\CofracText::normalize($type) }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="gc-label" for="{{ $prefix }}_average_duration_minutes">Temps moyen (minutes)</label>
        <input id="{{ $prefix }}_average_duration_minutes" name="average_duration_minutes" type="number" min="5" max="1440" class="gc-input" required />
    </div>
</div>

<div>
    <label class="gc-label" for="{{ $prefix }}_name">Nom de la prestation</label>
    <input id="{{ $prefix }}_name" name="name" type="text" class="gc-input" maxlength="190" required />
</div>

@unless(config('crm.v2_enabled'))
<div>
    <label class="gc-label" for="{{ $prefix }}_external_aliases">Alias Cofrac</label>
    <textarea
        id="{{ $prefix }}_external_aliases"
        name="external_aliases"
        class="gc-input min-h-[120px]"
        placeholder="Un libellé Cofrac par ligne. Exemple: RES EC 104 (01/01/25)"
    >{{ old('external_aliases') }}</textarea>
    <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Ces alias permettent de relier automatiquement les prestations reçues depuis Cofrac à cette prestation interne.</p>
</div>

@endunless
