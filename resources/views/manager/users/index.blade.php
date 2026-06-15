<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Gérant</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Gestion des users</h1>
        </div>

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#f5c2c7;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="gc-alert">
                {{ session('status') }}
            </div>
        @endif

        <section class="gc-card p-4">
            <form id="manager-user-filters-form" method="GET" action="{{ route('manager.users') }}" class="grid grid-cols-1 gap-4 md:grid-cols-5">
                <div class="md:col-span-3">
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" class="gc-input" placeholder="Nom, prénom, email" />
                </div>

                <div>
                    <label class="gc-label" for="role">Role</label>
                    <select id="role" name="role" class="gc-input">
                        <option value="">Tous</option>
                        <option value="0" @selected($filters['role'] === '0')>Gérant</option>
                        <option value="1" @selected($filters['role'] === '1')>Planning</option>
                        <option value="2" @selected($filters['role'] === '2')>Tech</option>
                    </select>
                </div>

                <div>
                    <label class="gc-label" for="status">Statut</label>
                    <select id="status" name="status" class="gc-input">
                        <option value="active" @selected($filters['status'] === 'active')>Actifs</option>
                        <option value="trashed" @selected($filters['status'] === 'trashed')>Supprimés</option>
                        <option value="all" @selected($filters['status'] === 'all')>Tous</option>
                    </select>
                </div>

                <div class="md:col-span-5 flex items-center justify-between">
                    <button type="button" class="gc-btn-primary" data-modal-open="create-user-modal">Creer un utilisateur</button>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('manager.users') }}" class="gc-link">Réinitialiser les filtres</a>
                    </div>
                </div>
            </form>
        </section>

        <section class="gc-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b" style="border-color:var(--gc-border);background:#f8f8f8;">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Nom</th>
                            <th class="px-4 py-3 font-semibold">Email</th>
                            <th class="px-4 py-3 font-semibold">Role</th>
                            <th class="px-4 py-3 font-semibold">Statut</th>
                            <th class="px-4 py-3 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr class="border-b last:border-b-0" style="border-color:var(--gc-border);">
                                <td class="px-4 py-3">{{ $user->full_name }}</td>
                                <td class="px-4 py-3">{{ $user->email }}</td>
                                <td class="px-4 py-3">
                                    @if ($user->role === 0) Gérant @elseif ($user->role === 1) Planning @else Tech @endif
                                </td>
                                <td class="px-4 py-3">{{ $user->trashed() ? 'Supprimé' : 'Actif' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @if (! $user->trashed())
                                            <button
                                                type="button"
                                                class="gc-btn-soft"
                                                data-modal-open="edit-user-modal"
                                                data-user-id="{{ $user->id }}"
                                                data-user-first-name="{{ $user->first_name }}"
                                                data-user-last-name="{{ $user->last_name }}"
                                                data-user-email="{{ $user->email }}"
                                                data-user-role="{{ $user->role }}"
                                                data-user-phone="{{ $user->phone }}"
                                                data-user-address="{{ $user->address }}"
                                                data-user-department-code="{{ $user->department_code }}"
                                                data-user-latitude="{{ $user->latitude }}"
                                                data-user-longitude="{{ $user->longitude }}"
                                                data-user-day-start-time="{{ $user->day_start_time }}"
                                                data-user-day-end-time="{{ $user->day_end_time }}"
                                                data-user-break-duration-minutes="{{ $user->break_duration_minutes }}"
                                                data-user-service-ids='@json($user->services->pluck('id')->values())'
                                                data-user-department-codes='@json($user->departments->pluck('code')->values())'
                                            >Modifier</button>

                                            <form method="POST" action="{{ route('manager.users.send-reset-link', $user->id) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="gc-btn-soft">Envoyer reset</button>
                                            </form>

                                            @if ($user->role === 2)
                                                @php
                                                    $absencePayload = $user->absences->map(fn ($absence) => [
                                                        'id' => $absence->id,
                                                        'starts_on' => $absence->starts_at?->toDateString(),
                                                        'ends_on' => $absence->ends_at?->toDateString(),
                                                        'reason' => $absence->reason,
                                                    ])->values();
                                                @endphp
                                                <button
                                                    type="button"
                                                    class="gc-btn-soft"
                                                    data-modal-open="absence-user-modal"
                                                    data-user-id="{{ $user->id }}"
                                                    data-user-name="{{ $user->full_name }}"
                                                    data-user-absences='@json($absencePayload)'
                                                >Absence</button>
                                            @endif

                                            <button type="button" class="gc-btn-danger" data-modal-open="delete-user-modal" data-delete-url="{{ route('manager.users.destroy', $user->id) }}" data-user-name="{{ $user->full_name }}">Soft delete</button>
                                        @else
                                            <form method="POST" action="{{ route('manager.users.restore', $user->id) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="gc-btn-soft">Restaurer</button>
                                            </form>

                                            <button type="button" class="gc-btn-danger" data-modal-open="force-delete-user-modal" data-force-delete-url="{{ route('manager.users.force-delete', $user->id) }}" data-user-name="{{ $user->full_name }}">Suppression définitive</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucun utilisateur.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t px-4 py-3" style="border-color:var(--gc-border);">
                {{ $users->links() }}
            </div>
        </section>
    </div>

    <div id="create-user-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <h2 class="text-lg font-semibold">Creer un utilisateur</h2>
            <form method="POST" action="{{ route('manager.users.store') }}" class="mt-4 space-y-4" data-validate-form>
                @csrf
                @include('manager.users.partials.form-fields', ['prefix' => 'create', 'user' => null])
                <div class="gc-modal-actions">
                    <button type="button" class="gc-link" data-modal-close="create-user-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Creer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="edit-user-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <h2 class="text-lg font-semibold">Modifier un utilisateur</h2>
            <form id="edit-user-form" method="POST" action="#" class="mt-4 space-y-4" data-validate-form>
                @csrf
                @method('PUT')
                @include('manager.users.partials.form-fields', ['prefix' => 'edit', 'user' => null])
                <div class="gc-modal-actions">
                    <button type="button" class="gc-link" data-modal-close="edit-user-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="absence-user-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Technicien</p>
                    <h2 class="text-lg font-semibold">Absences de <span id="absence-user-name"></span></h2>
                </div>
                <button type="button" class="gc-link" data-modal-close="absence-user-modal">Fermer</button>
            </div>

            <div id="absence-user-list" class="mt-5 space-y-3"></div>

            <form id="absence-user-form" method="POST" action="#" class="mt-5 rounded-xl border p-4" style="border-color:var(--gc-border);" data-validate-form>
                @csrf
                <h3 class="font-semibold" style="color:var(--gc-text);">Ajouter une absence</h3>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="gc-label" for="absence_starts_on">Début</label>
                        <input id="absence_starts_on" name="starts_on" type="date" class="gc-input" required />
                    </div>
                    <div>
                        <label class="gc-label" for="absence_ends_on">Fin</label>
                        <input id="absence_ends_on" name="ends_on" type="date" class="gc-input" required />
                    </div>
                    <div>
                        <label class="gc-label" for="absence_reason">Motif optionnel</label>
                        <input id="absence_reason" name="reason" type="text" class="gc-input" maxlength="255" placeholder="Conges, maladie..." />
                    </div>
                </div>
                <div class="gc-modal-actions mt-4">
                    <button type="submit" class="gc-btn-primary">Ajouter l'absence</button>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-user-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Soft delete utilisateur</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Le compte <span id="delete-user-name" class="font-medium" style="color:var(--gc-text);"></span> sera désactivé.</p>
            <form id="delete-user-form" method="POST" action="#" class="mt-4 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" class="gc-link" data-modal-close="delete-user-modal">Annuler</button>
                <button type="submit" class="gc-btn-primary" style="background:#9f1239;">Confirmer</button>
            </form>
        </div>
    </div>

    <div id="force-delete-user-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Suppression définitive</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Le compte <span id="force-delete-user-name" class="font-medium" style="color:var(--gc-text);"></span> sera supprimé définitivement.</p>
            <form id="force-delete-user-form" method="POST" action="#" class="mt-4 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" class="gc-link" data-modal-close="force-delete-user-modal">Annuler</button>
                <button type="submit" class="gc-btn-primary" style="background:#9f1239;">Supprimér</button>
            </form>
        </div>
    </div>

    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const MAPBOX_TOKEN = @json(config('services.mapbox.token'));
        const DEPARTMENT_GEOJSON_URL = @json(asset('geo/départements.geojson'));
        const openButtons = document.querySelectorAll('[data-modal-open]');
        const closeButtons = document.querySelectorAll('[data-modal-close]');
        const filtersForm = document.getElementById('manager-user-filters-form');
        const roleInputs = document.querySelectorAll('[data-role-input]');
        const absenceStoreUrlTemplate = @json(route('manager.users.absences.store', ['user' => '__USER__']));
        const absenceDestroyUrlTemplate = @json(route('manager.users.absences.destroy', ['user' => '__USER__', 'absence' => '__ABSENCE__']));
        const csrfToken = @json(csrf_token());
        const departmentMaps = {};
        const departmentGeoJson = { loaded: false, data: null };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const formatAbsenceDate = (value) => {
            if (!value) return '-';

            return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR');
        };

        const renderAbsenceList = (userId, absences) => {
            const list = document.getElementById('absence-user-list');

            if (!list) return;

            if (!Array.isArray(absences) || absences.length === 0) {
                list.innerHTML = '<div class="rounded-xl border border-dashed p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucune absence à venir pour ce technicien.</div>';
                return;
            }

            list.innerHTML = absences.map((absence) => {
                const destroyUrl = absenceDestroyUrlTemplate
                    .replace('__USER__', encodeURIComponent(userId))
                    .replace('__ABSENCE__', encodeURIComponent(absence.id));

                return `
                    <article class="flex flex-col gap-3 rounded-xl border p-4 md:flex-row md:items-center md:justify-between" style="border-color:var(--gc-border);">
                        <div>
                            <p class="font-semibold" style="color:var(--gc-text);">Abs du ${escapeHtml(formatAbsenceDate(absence.starts_on))} au ${escapeHtml(formatAbsenceDate(absence.ends_on))}</p>
                            <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">${escapeHtml(absence.reason || 'Motif non renseigné')}</p>
                        </div>
                        <form method="POST" action="${destroyUrl}" class="shrink-0">
                            <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="gc-btn-danger">Supprimér</button>
                        </form>
                    </article>
                `;
            }).join('');
        };

        const extractDepartmentCode = (feature) => {
            const contexts = Array.isArray(feature?.context) ? feature.context : [];
            const postcodeContext = contexts.find((context) => String(context.id || '').startsWith('postcode'));
            const postcode = postcodeContext?.text || feature?.properties?.postcode || feature?.place_name?.match(/\b\d{5}\b/)?.[0] || '';

            if (!postcode) return '';

            return String(postcode).slice(0, 3).startsWith('97')
                ? String(postcode).slice(0, 3)
                : String(postcode).slice(0, 2).toUpperCase();
        };

        const toggleTechFields = (prefix) => {
            const roleInput = document.getElementById(`${prefix}_role`);
            const techFields = document.getElementById(`${prefix}_tech_fields`);
            if (!roleInput || !techFields) return;

            const isTech = roleInput.value === '2';
            techFields.classList.toggle('hidden', !isTech);

            ['phone', 'address', 'break_duration_minutes', 'day_start_time', 'day_end_time']
                .map((field) => document.getElementById(`${prefix}_${field}`))
                .filter(Boolean)
                .forEach((field) => {
                    field.required = isTech;
                    if (!isTech) {
                        field.classList.remove('is-valid', 'is-invalid');
                    }
                });

            if (isTech) {
                requestAnimationFrame(() => updateDepartmentMapVisibility(prefix));
            }

            window.TechCalendarForms?.refresh(roleInput.form);
        };

        const parseJsonDataset = (value) => {
            try {
                return JSON.parse(value || '[]');
            } catch (error) {
                return [];
            }
        };

        const setCheckboxValues = (selector, values) => {
            const normalized = values.map((value) => String(value));
            document.querySelectorAll(selector).forEach((checkbox) => {
                checkbox.checked = normalized.includes(String(checkbox.value));
            });
        };

        const syncServiceSelectAll = (prefix) => {
            const checkboxes = Array.from(document.querySelectorAll(`[data-service-checkbox="${prefix}"]`));
            const selectAll = document.querySelector(`[data-service-select-all="${prefix}"]`);

            if (!selectAll || checkboxes.length === 0) return;

            const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
            selectAll.checked = checkedCount === checkboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        };

        document.querySelectorAll('[data-service-select-all]').forEach((selectAll) => {
            selectAll.addEventListener('change', () => {
                const prefix = selectAll.dataset.serviceSelectAll;

                document.querySelectorAll(`[data-service-checkbox="${prefix}"]`).forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                });

                syncServiceSelectAll(prefix);
                window.TechCalendarForms?.refresh(selectAll.form);
            });
        });

        document.querySelectorAll('[data-service-checkbox]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => syncServiceSelectAll(checkbox.dataset.serviceCheckbox));
        });

        const selectedDepartmentCodes = (prefix) => Array.from(document.querySelectorAll(`[data-department-checkbox="${prefix}"]:checked`)).map((checkbox) => checkbox.value);

        const addressCoordinates = (prefix) => {
            const latValue = document.getElementById(`${prefix}_latitude`)?.value;
            const lngValue = document.getElementById(`${prefix}_longitude`)?.value;

            if (!latValue || !lngValue) return null;

            const lat = Number(latValue);
            const lng = Number(lngValue);

            return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
        };

        const setAddressMarker = (prefix) => {
            const coordinates = addressCoordinates(prefix);
            const mapState = departmentMaps[prefix];
            if (!coordinates || !mapState?.map) return;

            if (!mapState.addressMarker) {
                const markerElement = document.createElement('div');
                markerElement.className = 'h-4 w-4 rounded-full border-2 border-white shadow-lg';
                markerElement.style.background = '#31424c';
                mapState.addressMarker = new window.mapboxgl.Marker({ element: markerElement, anchor: 'center' });
            }

            mapState.addressMarker.setLngLat([coordinates.lng, coordinates.lat]).addTo(mapState.map);
        };

        const updateDepartmentMapVisibility = (prefix) => {
            const wrapper = document.getElementById(`${prefix}_department_map_wrapper`);
            const hint = document.getElementById(`${prefix}_department_map_hint`);
            const coordinates = addressCoordinates(prefix);

            if (!wrapper || !hint) return;

            wrapper.classList.toggle('hidden', !coordinates);
            hint.classList.toggle('hidden', Boolean(coordinates));

            if (!coordinates) return;

            initDepartmentMap(prefix).then(() => {
                const mapState = departmentMaps[prefix];
                if (!mapState?.map) return;

                mapState.map.resize();
                mapState.map.flyTo({ center: [coordinates.lng, coordinates.lat], zoom: 7 });
                setAddressMarker(prefix);
            });
        };

        const refreshDepartmentUi = (prefix) => {
            const selected = selectedDepartmentCodes(prefix);
            const count = document.getElementById(`${prefix}_department_count`);
            if (count) count.textContent = `${selected.length} Sélection${selected.length > 1 ? 's' : ''}`;
            window.TechCalendarForms?.refresh(document.getElementById(`${prefix}_role`)?.form);

            document.querySelectorAll(`[data-department-chip="${prefix}"]`).forEach((chip) => {
                const checked = selected.includes(chip.dataset.departmentCode);
                chip.style.background = checked ? 'var(--gc-accent-soft)' : '#fff';
                chip.style.borderColor = checked ? '#d8c27a' : 'var(--gc-border)';
            });

            const mapState = departmentMaps[prefix];
            if (mapState?.map?.getLayer(`${prefix}-departments-fill`)) {
                const fillColor = selected.length === 0
                    ? '#9ccfe3'
                    : ['match', ['get', 'code'], selected, '#d8c27a', '#9ccfe3'];
                const fillOpacity = selected.length === 0
                    ? 0.28
                    : ['match', ['get', 'code'], selected, 0.72, 0.28];

                mapState.map.setPaintProperty(`${prefix}-departments-fill`, 'fill-color', fillColor);
                mapState.map.setPaintProperty(`${prefix}-departments-fill`, 'fill-opacity', fillOpacity);
            }
        };

        const bindDepartmentCheckboxes = (prefix) => {
            document.querySelectorAll(`[data-department-checkbox="${prefix}"]`).forEach((checkbox) => {
                if (checkbox.dataset.bound === '1') return;
                checkbox.dataset.bound = '1';
                checkbox.addEventListener('change', () => refreshDepartmentUi(prefix));
            });
            refreshDepartmentUi(prefix);
        };

        const loadDepartmentGeoJson = async () => {
            if (departmentGeoJson.loaded) return departmentGeoJson.data;
            const response = await fetch(DEPARTMENT_GEOJSON_URL, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            data.features = (data.features || []).map((feature) => ({
                ...feature,
                properties: {
                    ...feature.properties,
                    code: String(feature.properties?.code || feature.properties?.CODE_DEPT || '').toUpperCase(),
                },
            }));
            departmentGeoJson.loaded = true;
            departmentGeoJson.data = data;
            return data;
        };

        const initDepartmentMap = async (prefix) => {
            bindDepartmentCheckboxes(prefix);
            const container = document.getElementById(`${prefix}_department_map`);
            if (!container || !MAPBOX_TOKEN || !window.mapboxgl) return;

            if (departmentMaps[prefix]) {
                departmentMaps[prefix].map.resize();
                setAddressMarker(prefix);
                return;
            }

            window.mapboxgl.accessToken = MAPBOX_TOKEN;
            const map = new window.mapboxgl.Map({
                container,
                style: 'mapbox://styles/mapbox/light-v11',
                center: [2.4, 46.7],
                zoom: 4.6,
            });
            departmentMaps[prefix] = { map, addressMarker: null };

            map.on('load', async () => {
                try {
                    const data = await loadDepartmentGeoJson();
                    map.addSource(`${prefix}-departments`, { type: 'geojson', data });
                    map.addLayer({
                        id: `${prefix}-departments-fill`,
                        type: 'fill',
                        source: `${prefix}-departments`,
                        paint: {
                            'fill-color': '#9ccfe3',
                            'fill-opacity': 0.28,
                        },
                    });
                    map.addLayer({
                        id: `${prefix}-departments-line`,
                        type: 'line',
                        source: `${prefix}-departments`,
                        paint: {
                            'line-color': '#31424c',
                            'line-opacity': 0.35,
                            'line-width': 1,
                        },
                    });
                    map.on('click', `${prefix}-departments-fill`, (event) => {
                        const code = String(event.features?.[0]?.properties?.code || '').toUpperCase();
                        const checkbox = document.querySelector(`[data-department-checkbox="${prefix}"][value="${code}"]`);
                        if (!checkbox) return;
                        checkbox.checked = !checkbox.checked;
                        refreshDepartmentUi(prefix);
                        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    refreshDepartmentUi(prefix);
                    setAddressMarker(prefix);
                } catch (error) {}
            });
        };

        const openModal = (id) => {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('hidden');
        };

        const initMapboxAutocomplete = (prefix) => {
            const addressInput = document.getElementById(`${prefix}_address`);
            const departmentCodeInput = document.getElementById(`${prefix}_department_code`);
            const latInput = document.getElementById(`${prefix}_latitude`);
            const lngInput = document.getElementById(`${prefix}_longitude`);

            if (!addressInput || !latInput || !lngInput || !MAPBOX_TOKEN) return;

            let list = addressInput.parentElement.querySelector('.gc-mapbox-suggestions');
            if (!list) {
                list = document.createElement('div');
                list.className = 'gc-mapbox-suggestions';
                addressInput.parentElement.appendChild(list);
            }

            if (addressInput.dataset.mapboxBound === '1') return;
            addressInput.dataset.mapboxBound = '1';

            let debounceTimer;
            addressInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                const query = addressInput.value.trim();
                departmentCodeInput.value = '';
                latInput.value = '';
                lngInput.value = '';
                updateDepartmentMapVisibility(prefix);
                if (query.length < 3) {
                    list.innerHTML = '';
                    list.classList.add('hidden');
                    return;
                }

                debounceTimer = setTimeout(async () => {
                    const url = new URL(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json`);
                    url.searchParams.set('access_token', MAPBOX_TOKEN);
                    url.searchParams.set('autocomplete', 'true');
                    url.searchParams.set('language', 'fr');
                    url.searchParams.set('country', 'fr');
                    url.searchParams.set('limit', '5');

                    try {
                        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                        const data = await response.json();
                        const features = Array.isArray(data.features) ? data.features : [];
                        if (features.length === 0) {
                            list.innerHTML = '';
                            list.classList.add('hidden');
                            return;
                        }

                        list.innerHTML = features
                            .map((feature) => `<button type="button" class="gc-mapbox-item" data-address="${feature.place_name}" data-department-code="${extractDepartmentCode(feature)}" data-lng="${feature.center?.[0] ?? ''}" data-lat="${feature.center?.[1] ?? ''}">${feature.place_name}</button>`)
                            .join('');
                        list.classList.remove('hidden');

                        list.querySelectorAll('.gc-mapbox-item').forEach((item) => {
                            item.addEventListener('click', () => {
                                addressInput.value = item.dataset.address || '';
                                departmentCodeInput.value = item.dataset.departmentCode || '';
                                lngInput.value = item.dataset.lng || '';
                                latInput.value = item.dataset.lat || '';
                                const departmentCheckbox = document.querySelector(`[data-department-checkbox="${prefix}"][value="${item.dataset.departmentCode || ''}"]`);
                                if (departmentCheckbox) {
                                    departmentCheckbox.checked = true;
                                    refreshDepartmentUi(prefix);
                                    departmentCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                const mapState = departmentMaps[prefix];
                                if (mapState?.map && item.dataset.lng && item.dataset.lat) {
                                    mapState.map.flyTo({ center: [Number(item.dataset.lng), Number(item.dataset.lat)], zoom: 7 });
                                }
                                updateDepartmentMapVisibility(prefix);
                                list.innerHTML = '';
                                list.classList.add('hidden');
                            });
                        });
                    } catch (error) {
                        list.innerHTML = '';
                        list.classList.add('hidden');
                    }
                }, 250);
            });
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('hidden');
        };

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const modalId = button.dataset.modalOpen;

                if (modalId === 'edit-user-modal') {
                    const form = document.getElementById('edit-user-form');
                    const userId = button.dataset.userId || '';
                    form.action = `/manager/users/${userId}`;
                    document.getElementById('edit_first_name').value = button.dataset.userFirstName || '';
                    document.getElementById('edit_last_name').value = button.dataset.userLastName || '';
                    document.getElementById('edit_email').value = button.dataset.userEmail || '';
                    document.getElementById('edit_role').value = String(button.dataset.userRole ?? 0);
                    document.getElementById('edit_phone').value = button.dataset.userPhone || '';
                    document.getElementById('edit_address').value = button.dataset.userAddress || '';
                    document.getElementById('edit_department_code').value = button.dataset.userDepartmentCode || '';
                    document.getElementById('edit_latitude').value = button.dataset.userLatitude || '';
                    document.getElementById('edit_longitude').value = button.dataset.userLongitude || '';
                    document.getElementById('edit_day_start_time').value = button.dataset.userDayStartTime || '';
                    document.getElementById('edit_day_end_time').value = button.dataset.userDayEndTime || '';
                    document.getElementById('edit_break_duration_minutes').value = button.dataset.userBreakDurationMinutes || '';
                    setCheckboxValues('[data-service-checkbox="edit"]', parseJsonDataset(button.dataset.userServiceIds));
                    syncServiceSelectAll('edit');
                    setCheckboxValues('[data-department-checkbox="edit"]', parseJsonDataset(button.dataset.userDepartmentCodes));
                    toggleTechFields('edit');
                    initMapboxAutocomplete('edit');
                    bindDepartmentCheckboxes('edit');
                    updateDepartmentMapVisibility('edit');
                    window.TechCalendarForms?.refresh(form);
                }

                if (modalId === 'create-user-modal') {
                    setCheckboxValues('[data-service-checkbox="create"]', []);
                    syncServiceSelectAll('create');
                    setCheckboxValues('[data-department-checkbox="create"]', []);
                    refreshDepartmentUi('create');
                    initMapboxAutocomplete('create');
                    updateDepartmentMapVisibility('create');
                    window.TechCalendarForms?.refresh(document.getElementById('create_role')?.form);
                }

                if (modalId === 'delete-user-modal') {
                    document.getElementById('delete-user-form').action = button.dataset.deleteUrl;
                    document.getElementById('delete-user-name').textContent = button.dataset.userName || '';
                }

                if (modalId === 'force-delete-user-modal') {
                    document.getElementById('force-delete-user-form').action = button.dataset.forceDeleteUrl;
                    document.getElementById('force-delete-user-name').textContent = button.dataset.userName || '';
                }

                if (modalId === 'absence-user-modal') {
                    const userId = button.dataset.userId || '';
                    const absences = parseJsonDataset(button.dataset.userAbsences);
                    document.getElementById('absence-user-name').textContent = button.dataset.userName || '';
                    document.getElementById('absence-user-form').action = absenceStoreUrlTemplate.replace('__USER__', encodeURIComponent(userId));
                    document.getElementById('absence_starts_on').value = '';
                    document.getElementById('absence_ends_on').value = '';
                    document.getElementById('absence_reason').value = '';
                    renderAbsenceList(userId, absences);
                    window.TechCalendarForms?.refresh(document.getElementById('absence-user-form'));
                }

                openModal(modalId);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => closeModal(button.dataset.modalClose));
        });

        document.querySelectorAll('.gc-modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.classList.add('hidden');
            });
        });

        window.addEventListener('techcalendar:layout-resized', () => {
            Object.values(departmentMaps).forEach((mapState) => mapState?.map?.resize());
        });

        if (filtersForm) {
            const searchInput = document.getElementById('q');
            const instantInputs = ['role', 'status']
                .map((id) => document.getElementById(id))
                .filter(Boolean);

            instantInputs.forEach((input) => {
                input.addEventListener('change', () => filtersForm.submit());
            });

            let debounceTimer;
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => filtersForm.submit(), 350);
                });
            }
        }

        roleInputs.forEach((input) => {
            const prefix = input.dataset.roleInput;
            toggleTechFields(prefix);
            bindDepartmentCheckboxes(prefix);
            syncServiceSelectAll(prefix);
            input.addEventListener('change', () => toggleTechFields(prefix));
        });
    </script>
</x-layouts.app>
