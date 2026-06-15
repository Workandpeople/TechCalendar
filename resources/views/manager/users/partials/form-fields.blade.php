@php
    $target = $user;
@endphp

<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <label class="gc-label" for="{{ $prefix }}_first_name">Prénom</label>
        <input id="{{ $prefix }}_first_name" name="first_name" type="text" class="gc-input" value="{{ old('first_name', $target?->first_name) }}" required />
    </div>

    <div>
        <label class="gc-label" for="{{ $prefix }}_last_name">Nom</label>
        <input id="{{ $prefix }}_last_name" name="last_name" type="text" class="gc-input" value="{{ old('last_name', $target?->last_name) }}" required />
    </div>
</div>

<div>
    <label class="gc-label" for="{{ $prefix }}_email">Email</label>
    <input id="{{ $prefix }}_email" name="email" type="email" class="gc-input" value="{{ old('email', $target?->email) }}" required />
</div>

<div>
    <label class="gc-label" for="{{ $prefix }}_role">Role</label>
    <select id="{{ $prefix }}_role" name="role" class="gc-input" data-role-input="{{ $prefix }}" required>
            <option value="0">Gérant</option>
        <option value="1">Planning</option>
        <option value="2">Tech</option>
    </select>
</div>

<div id="{{ $prefix }}_tech_fields" class="tech-only-fields hidden space-y-4 rounded-xl border p-4" style="border-color:var(--gc-border);">
    <h3 class="text-sm font-semibold" style="color:var(--gc-text);">Informations technicien</h3>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="gc-label" for="{{ $prefix }}_phone">Téléphone</label>
            <input id="{{ $prefix }}_phone" name="phone" type="text" class="gc-input" value="{{ old('phone', $target?->phone) }}" />
        </div>

        <div>
            <label class="gc-label" for="{{ $prefix }}_break_duration_minutes">Durée de la pause (minutes)</label>
            <input id="{{ $prefix }}_break_duration_minutes" name="break_duration_minutes" type="number" min="0" max="240" class="gc-input" value="{{ old('break_duration_minutes', $target?->break_duration_minutes) }}" />
        </div>
    </div>

    <div class="relative">
        <label class="gc-label" for="{{ $prefix }}_address">Adresse (Mapbox suggestion)</label>
        <input id="{{ $prefix }}_address" name="address" type="text" class="gc-input" value="{{ old('address', $target?->address) }}" />
        <input id="{{ $prefix }}_department_code" name="department_code" type="hidden" value="{{ old('department_code', $target?->department_code) }}" />
        <input id="{{ $prefix }}_latitude" name="latitude" type="hidden" value="{{ old('latitude', $target?->latitude) }}" />
        <input id="{{ $prefix }}_longitude" name="longitude" type="hidden" value="{{ old('longitude', $target?->longitude) }}" />
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between gap-3">
            <label class="gc-label mb-0">Prestations habilitées</label>
            <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium" style="color:var(--gc-text-soft);">
                <input type="checkbox" class="gc-check" data-service-select-all="{{ $prefix }}" />
                Sélectionner tout
            </label>
        </div>
        <div class="gc-validation-group grid max-h-44 grid-cols-1 gap-2 overflow-y-auto rounded-lg border p-3 md:grid-cols-2" style="border-color:var(--gc-border);" data-required-checkbox-group data-validation-label="Sélectionne au moins une prestation">
            @foreach ($services as $service)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" class="gc-check service-checkbox" name="service_ids[]" value="{{ $service->id }}" data-service-checkbox="{{ $prefix }}" />
                    <span>{{ $service->type }} - {{ $service->name }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between gap-3">
            <label class="gc-label mb-0">Départements couverts</label>
            <span id="{{ $prefix }}_department_count" class="text-xs" style="color:var(--gc-text-soft);">0 sélection</span>
        </div>
        <div id="{{ $prefix }}_department_map_wrapper" class="hidden">
            <div id="{{ $prefix }}_department_map" class="mb-3 overflow-hidden rounded-xl border" style="height:420px;border-color:var(--gc-border);"></div>
            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">Clique sur la carte pour sélectionner les départements couverts. Le point marque l'adresse du tech.</p>
        </div>
        <p id="{{ $prefix }}_department_map_hint" class="rounded-lg border p-3 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);background:#f8f8f8;">Renseigne une adresse via Mapbox pour afficher la carte des départements.</p>
        <div class="gc-validation-group hidden" data-required-checkbox-group data-validate-hidden-group="true" data-validation-label="Sélectionne au moins un département">
            @foreach ($departments as $department)
                <label data-department-chip="{{ $prefix }}" data-department-code="{{ $department->code }}">
                    <input type="checkbox" class="gc-check department-checkbox" name="department_codes[]" value="{{ $department->code }}" data-department-checkbox="{{ $prefix }}" />
                </label>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="gc-label" for="{{ $prefix }}_day_start_time">Début de journée</label>
            <input id="{{ $prefix }}_day_start_time" name="day_start_time" type="time" class="gc-input" value="{{ old('day_start_time', $target?->day_start_time) }}" />
        </div>

        <div>
            <label class="gc-label" for="{{ $prefix }}_day_end_time">Fin de journée</label>
            <input id="{{ $prefix }}_day_end_time" name="day_end_time" type="time" class="gc-input" value="{{ old('day_end_time', $target?->day_end_time) }}" />
        </div>
    </div>
</div>
