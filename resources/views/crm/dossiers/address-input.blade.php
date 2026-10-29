@php
    $addressPrecision = old('address_precision', 'address') === 'city' ? 'city' : 'address';
@endphp
<div data-creation-address data-suggestions-url="{{ $addressSuggestionsUrl }}" class="space-y-4">
    <input type="hidden" name="mapbox_id" value="{{ is_string(old('mapbox_id')) ? old('mapbox_id') : '' }}">
    <input type="hidden" name="address_precision" value="{{ $addressPrecision }}">
    <div><label for="site_name" class="gc-label">Nom du site / bâtiment</label><input id="site_name" name="site_name" class="gc-input" maxlength="190" value="{{ is_scalar(old('site_name', $dossier->site_name)) ? old('site_name', $dossier->site_name) : '' }}"></div>
    <div><label for="address" class="gc-label">Adresse de l’intervention</label>
        <input id="address" name="address" class="gc-input" maxlength="500" value="{{ is_scalar(old('address', $dossier->address)) ? old('address', $dossier->address) : '' }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="creation-address-options" aria-describedby="creation-address-status" @required($addressPrecision !== 'city')>
        <ul id="creation-address-options" class="gc-address-suggestions" role="listbox" aria-label="Adresses ou communes proposées par Mapbox" hidden></ul>
    </div>
    <button type="button" class="gc-link text-sm" data-address-fallback aria-expanded="{{ $addressPrecision === 'city' ? 'true' : 'false' }}" aria-controls="creation-address-locality">Adresse non trouvée</button>
    <div id="creation-address-locality" data-address-locality class="grid gap-4 sm:grid-cols-2">
        <div><label for="postal_code" class="gc-label">Code postal</label><input id="postal_code" name="postal_code" class="gc-input" maxlength="10" inputmode="numeric" value="{{ is_scalar(old('postal_code', $dossier->postal_code)) ? old('postal_code', $dossier->postal_code) : '' }}" @required($addressPrecision === 'city')></div>
        <div><label for="city" class="gc-label">Ville *</label><input id="city" name="city" class="gc-input" required maxlength="190" autocomplete="off" value="{{ is_scalar(old('city', $dossier->city)) ? old('city', $dossier->city) : '' }}" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="creation-city-options"></div>
        <ul id="creation-city-options" class="gc-address-suggestions sm:col-span-2" role="listbox" aria-label="Communes proposées par Mapbox" hidden></ul>
    </div>
    <p id="creation-address-status" data-creation-address-status role="status" aria-live="polite" class="text-xs text-slate-500">Recherchez une adresse Mapbox ou utilisez « Adresse non trouvée » pour localiser la commune.</p>
    <p data-address-approximate class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900" @if ($addressPrecision !== 'city') hidden @endif>Position approximative sur la commune / code postal : les trajets seront estimés jusqu’à confirmation de l’adresse exacte.</p>
</div>
