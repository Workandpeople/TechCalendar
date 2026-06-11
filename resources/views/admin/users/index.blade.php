<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Admin</p>
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
            <form id="user-filters-form" method="GET" action="{{ route('admin.users') }}" class="grid grid-cols-1 gap-4 md:grid-cols-5">
                <div class="md:col-span-2">
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" class="gc-input" placeholder="Nom, prenom, email" />
                </div>

                <div>
                    <label class="gc-label" for="role">Role</label>
                    <select id="role" name="role" class="gc-input">
                        <option value="">Tous</option>
                        <option value="0" @selected($filters['role'] === '0')>Gerant</option>
                        <option value="1" @selected($filters['role'] === '1')>Planning</option>
                        <option value="2" @selected($filters['role'] === '2')>Tech</option>
                    </select>
                </div>

                <div>
                    <label class="gc-label" for="admin">Admin</label>
                    <select id="admin" name="admin" class="gc-input">
                        <option value="">Tous</option>
                        <option value="1" @selected($filters['admin'] === '1')>Oui</option>
                        <option value="0" @selected($filters['admin'] === '0')>Non</option>
                    </select>
                </div>

                <div>
                    <label class="gc-label" for="status">Statut</label>
                    <select id="status" name="status" class="gc-input">
                        <option value="active" @selected($filters['status'] === 'active')>Actifs</option>
                        <option value="trashed" @selected($filters['status'] === 'trashed')>Supprimes</option>
                        <option value="all" @selected($filters['status'] === 'all')>Tous</option>
                    </select>
                </div>

                <div class="md:col-span-5 flex items-center justify-between">
                    <button type="button" class="gc-btn-primary" data-modal-open="create-user-modal">Creer un utilisateur</button>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.users') }}" class="gc-link">Reset filtres</a>
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
                            <th class="px-4 py-3 font-semibold">Admin</th>
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
                                    @if ($user->role === 0) Gerant @elseif ($user->role === 1) Planning @else Tech @endif
                                </td>
                                <td class="px-4 py-3">{{ $user->admin ? 'Oui' : 'Non' }}</td>
                                <td class="px-4 py-3">{{ $user->trashed() ? 'Supprime' : 'Actif' }}</td>
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
                                                data-user-admin="{{ $user->admin ? 1 : 0 }}"
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

                                            <form method="POST" action="{{ route('admin.users.send-reset-link', $user->id) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="gc-btn-soft">Envoyer reset</button>
                                            </form>

                                            <button type="button" class="gc-btn-danger" data-modal-open="delete-user-modal" data-delete-url="{{ route('admin.users.destroy', $user->id) }}" data-user-name="{{ $user->full_name }}">Soft delete</button>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.restore', $user->id) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="gc-btn-soft">Restaurer</button>
                                            </form>

                                            <button type="button" class="gc-btn-danger" data-modal-open="force-delete-user-modal" data-force-delete-url="{{ route('admin.users.force-delete', $user->id) }}" data-user-name="{{ $user->full_name }}">Suppression definitive</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucun utilisateur.</td>
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
            <form method="POST" action="{{ route('admin.users.store') }}" class="mt-4 space-y-4" data-validate-form>
                @csrf
                @include('admin.users.partials.form-fields', ['prefix' => 'create', 'user' => null])
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
                @include('admin.users.partials.form-fields', ['prefix' => 'edit', 'user' => null])
                <div class="gc-modal-actions">
                    <button type="button" class="gc-link" data-modal-close="edit-user-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-user-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Soft delete utilisateur</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Le compte <span id="delete-user-name" class="font-medium" style="color:var(--gc-text);"></span> sera desactive.</p>
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
            <h2 class="text-lg font-semibold">Suppression definitive</h2>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Le compte <span id="force-delete-user-name" class="font-medium" style="color:var(--gc-text);"></span> sera supprime definitivement.</p>
            <form id="force-delete-user-form" method="POST" action="#" class="mt-4 flex justify-end gap-2">
                @csrf
                @method('DELETE')
                <button type="button" class="gc-link" data-modal-close="force-delete-user-modal">Annuler</button>
                <button type="submit" class="gc-btn-primary" style="background:#9f1239;">Supprimer</button>
            </form>
        </div>
    </div>

    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const MAPBOX_TOKEN = @json(config('services.mapbox.token'));
        const DEPARTMENT_GEOJSON_URL = @json(asset('geo/departements.geojson'));
        const openButtons = document.querySelectorAll('[data-modal-open]');
        const closeButtons = document.querySelectorAll('[data-modal-close]');
        const filtersForm = document.getElementById('user-filters-form');
        const roleInputs = document.querySelectorAll('[data-role-input]');
        const departmentMaps = {};
        const departmentGeoJson = { loaded: false, data: null };

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
            if (count) count.textContent = `${selected.length} selection${selected.length > 1 ? 's' : ''}`;
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

            if (!addressInput || !latInput || !lngInput) {
                return;
            }

            if (!MAPBOX_TOKEN) {
                return;
            }

            let list = addressInput.parentElement.querySelector('.gc-mapbox-suggestions');
            if (!list) {
                list = document.createElement('div');
                list.className = 'gc-mapbox-suggestions';
                addressInput.parentElement.appendChild(list);
            }

            if (addressInput.dataset.mapboxBound === '1') {
                return;
            }
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
                    form.action = `/admin/users/${userId}`;
                    document.getElementById('edit_first_name').value = button.dataset.userFirstName || '';
                    document.getElementById('edit_last_name').value = button.dataset.userLastName || '';
                    document.getElementById('edit_email').value = button.dataset.userEmail || '';
                    document.getElementById('edit_role').value = String(button.dataset.userRole ?? 0);
                    document.getElementById('edit_admin').value = String(button.dataset.userAdmin ?? 0);
                    document.getElementById('edit_phone').value = button.dataset.userPhone || '';
                    document.getElementById('edit_address').value = button.dataset.userAddress || '';
                    document.getElementById('edit_department_code').value = button.dataset.userDepartmentCode || '';
                    document.getElementById('edit_latitude').value = button.dataset.userLatitude || '';
                    document.getElementById('edit_longitude').value = button.dataset.userLongitude || '';
                    document.getElementById('edit_day_start_time').value = button.dataset.userDayStartTime || '';
                    document.getElementById('edit_day_end_time').value = button.dataset.userDayEndTime || '';
                    document.getElementById('edit_break_duration_minutes').value = button.dataset.userBreakDurationMinutes || '';
                    setCheckboxValues('[data-service-checkbox="edit"]', parseJsonDataset(button.dataset.userServiceIds));
                    setCheckboxValues('[data-department-checkbox="edit"]', parseJsonDataset(button.dataset.userDepartmentCodes));
                    toggleTechFields('edit');
                    initMapboxAutocomplete('edit');
                    bindDepartmentCheckboxes('edit');
                    updateDepartmentMapVisibility('edit');
                    window.TechCalendarForms?.refresh(form);
                }

                if (modalId === 'create-user-modal') {
                    setCheckboxValues('[data-service-checkbox="create"]', []);
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
            const instantInputs = ['role', 'admin', 'status']
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
            input.addEventListener('change', () => toggleTechFields(prefix));
        });
    </script>
</x-layouts.app>
