<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">{{ $section ?? 'Planning' }}</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">{{ $title ?? 'Suivi des rdv' }}</h1>
        </div>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Techniciens</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Filtre puis coche les techniciens à afficher dans le calendrier.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button id="select-visible-techs" type="button" class="gc-btn-soft">Cocher les visibles</button>
                    <button id="clear-selected-techs" type="button" class="gc-btn-soft">Tout decocher</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                <div>
                    <label class="gc-label" for="technician_filter">Recherche</label>
                    <input id="technician_filter" type="search" class="gc-input" placeholder="Nom, prenom, telephone ou adresse" autocomplete="off" />
                </div>
                <div class="flex h-[42px] items-center justify-between gap-3 self-end rounded-lg border px-4" style="border-color:var(--gc-border);background:var(--gc-accent-soft);">
                    <div class="text-xs uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Selection</div>
                    <div class="text-xl font-semibold whitespace-nowrap" style="color:var(--gc-text);"><span id="selected-tech-count">0</span> tech(s)</div>
                </div>
            </div>

            <div id="technician-list" class="mt-4 grid max-h-96 grid-cols-1 gap-3 overflow-y-auto pr-1 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($technicians as $technician)
                    @php
                        $departmentCode = $technician->department_code ?: '--';
                        $technicianLabel = trim($technician->last_name.' '.$technician->first_name);
                    @endphp
                    <label
                        class="technician-card flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition hover:bg-[color:var(--gc-accent-soft)]"
                        style="border-color:var(--gc-border);"
                        data-search="{{ str($technicianLabel.' '.$technician->phone.' '.$technician->address.' '.$departmentCode)->lower() }}"
                    >
                        <input
                            type="checkbox"
                            class="gc-check technician-checkbox"
                            value="{{ $technician->id }}"
                            data-name="{{ $technicianLabel }} ({{ $departmentCode }})"
                        />
                        <span class="min-w-0 font-medium" style="color:var(--gc-text);">
                            {{ $technicianLabel }} <span style="color:var(--gc-text-soft);">({{ $departmentCode }})</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Rendez-vous des techniciens selectionnes</h2>
                    <p id="calendar-helper" class="text-sm" style="color:var(--gc-text-soft);">Coche au moins un technicien pour afficher ses rendez-vous.</p>
                </div>
                <div id="calendar-legend" class="flex flex-wrap gap-2"></div>
            </div>

            <div class="relative min-h-[780px]">
                <div id="tracking-calendar"></div>
                <div id="tracking-calendar-loading" class="absolute inset-0 z-10 hidden items-start justify-center rounded-xl bg-white/85 px-4 pt-24 backdrop-blur-sm">
                    <div class="w-full max-w-md rounded-2xl border bg-white p-5 shadow-lg" style="border-color:var(--gc-border);">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p id="tracking-calendar-loading-title" class="text-sm font-semibold" style="color:var(--gc-text);">Chargement du calendrier</p>
                                <p id="tracking-calendar-loading-detail" class="mt-1 text-xs" style="color:var(--gc-text-soft);">Preparation des paquets...</p>
                            </div>
                            <div class="h-9 w-9 shrink-0 animate-spin rounded-full border-4 border-[color:var(--gc-accent-soft)] border-t-[color:var(--gc-primary)]"></div>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full" style="background:var(--gc-accent-soft);">
                            <div id="tracking-calendar-progress-bar" class="h-full rounded-full transition-all duration-200" style="width:0%;background:var(--gc-primary);"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div id="tracking-appointment-modal" class="gc-modal hidden">
        <div class="gc-modal-panel max-w-6xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Rendez-vous</p>
                    <h2 id="tracking_detail_service" class="text-lg font-semibold" style="color:var(--gc-text);"></h2>
                </div>
                <button type="button" id="tracking-detail-close" class="gc-link">Fermer</button>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
                <section class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium" style="color:var(--gc-text);">Trajet</p>
                            <p id="tracking_route_origin" class="text-xs" style="color:var(--gc-text-soft);"></p>
                        </div>
                    </div>
                    <div id="tracking-detail-map" class="h-[360px] overflow-hidden rounded-xl border" style="border-color:var(--gc-border);"></div>
                    <div id="tracking_route_summary" class="mt-3 rounded-lg px-3 py-2 text-sm" style="background:var(--gc-accent-soft);color:var(--gc-text);"></div>
                </section>

                <section class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2 xl:grid-cols-1">
                        <div>
                            <dt style="color:var(--gc-text-soft);">Technicien</dt>
                            <dd id="tracking_detail_technician" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Client</dt>
                            <dd id="tracking_detail_customer" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Telephone client</dt>
                            <dd id="tracking_detail_customer_phone" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Duree</dt>
                            <dd id="tracking_detail_duration" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Debut</dt>
                            <dd id="tracking_detail_start" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Fin</dt>
                            <dd id="tracking_detail_end" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Cree par</dt>
                            <dd id="tracking_detail_created_by" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Adresse</dt>
                            <dd id="tracking_detail_address" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                    </dl>
                </section>
            </div>

            <form id="tracking-comment-form" class="mt-4 rounded-xl border p-4" style="border-color:var(--gc-border);">
                <input id="tracking_detail_appointment_id" type="hidden" />
                <label class="gc-label" for="tracking_detail_comment">Commentaire</label>
                <textarea id="tracking_detail_comment" rows="5" class="gc-input" style="min-height:130px;" placeholder="Ajouter ou modifier le commentaire du RDV"></textarea>
                <div id="tracking_comment_status" class="mt-2 hidden text-sm"></div>
                <div class="mt-3 flex flex-wrap justify-end gap-2">
                    <button id="tracking-delete-appointment-btn" type="button" class="gc-btn-danger">Soft delete le RDV</button>
                    <button id="tracking-restore-appointment-btn" type="button" class="gc-btn-soft hidden">Reactiver le RDV</button>
                    <button id="tracking-save-comment-btn" type="submit" class="gc-btn-primary">Enregistrer le commentaire</button>
                </div>
            </form>
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const technicianFilterInput = document.getElementById('technician_filter');
        const technicianCards = Array.from(document.querySelectorAll('.technician-card'));
        const technicianCheckboxes = Array.from(document.querySelectorAll('.technician-checkbox'));
        const selectedTechCount = document.getElementById('selected-tech-count');
        const calendarHelper = document.getElementById('calendar-helper');
        const calendarLegend = document.getElementById('calendar-legend');
        const trackingAppointmentModal = document.getElementById('tracking-appointment-modal');
        const trackingCommentForm = document.getElementById('tracking-comment-form');
        const trackingCommentUrlTemplate = @json(route('planner.tracking.appointments.comment', ['appointment' => '__APPOINTMENT__']));
        const trackingDestroyUrlTemplate = @json(route('planner.tracking.appointments.destroy', ['appointment' => '__APPOINTMENT__']));
        const trackingRestoreUrlTemplate = @json(route('planner.tracking.appointments.restore', ['appointment' => '__APPOINTMENT__']));
        const trackingEventsUrl = @json(route('planner.tracking.events'));
        const trackingCsrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const trackingMapboxToken = @json($mapboxToken ?? null);
        const trackingCalendarLoading = document.getElementById('tracking-calendar-loading');
        const trackingCalendarLoadingDetail = document.getElementById('tracking-calendar-loading-detail');
        const trackingCalendarProgressBar = document.getElementById('tracking-calendar-progress-bar');
        const trackingEventsBatchSize = 80;
        const routeColors = ['#1d4ed8', '#0f766e', '#b45309', '#7e22ce', '#be123c', '#475569', '#a16207', '#0369a1'];
        const trackingQueryParams = new URLSearchParams(window.location.search);
        const trackingInitialTechnicianIds = new Set([
            ...trackingQueryParams.getAll('technician_id'),
            ...trackingQueryParams.getAll('technician_ids').flatMap((value) => value.split(',')),
        ]
            .map((value) => String(value).trim())
            .filter((value) => value !== ''));
        const trackingInitialDate = trackingQueryParams.get('date');
        let trackingCalendar = null;
        let trackingInitialComment = '';
        let trackingDetailMap = null;
        let trackingDetailMarkers = [];
        let trackingEventsAbortController = null;
        let trackingEventsRequestId = 0;

        const formatDateTime = (value) => {
            if (!value) return '-';

            return new Date(value).toLocaleString('fr-FR', {
                weekday: 'short',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        };

        const setText = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value || '-';
        };

        const trackingMarkerElement = (color, label) => {
            const element = document.createElement('div');
            element.style.width = '30px';
            element.style.height = '30px';
            element.style.borderRadius = '9999px';
            element.style.background = color;
            element.style.border = '3px solid #ffffff';
            element.style.boxShadow = '0 8px 20px rgba(49,66,76,0.28)';
            element.style.color = '#ffffff';
            element.style.display = 'flex';
            element.style.alignItems = 'center';
            element.style.justifyContent = 'center';
            element.style.fontSize = '12px';
            element.style.fontWeight = '700';
            element.textContent = label;

            return element;
        };

        const ensureTrackingDetailMap = () => {
            const container = document.getElementById('tracking-detail-map');

            if (!trackingMapboxToken || !window.mapboxgl) {
                container.innerHTML = '<div class="flex h-full items-center justify-center px-4 text-center text-sm" style="color:var(--gc-text-soft);">Carte indisponible: token Mapbox ou librairie manquante.</div>';
                return null;
            }

            window.mapboxgl.accessToken = trackingMapboxToken;

            if (!trackingDetailMap) {
                trackingDetailMap = new window.mapboxgl.Map({
                    container: 'tracking-detail-map',
                    style: 'mapbox://styles/mapbox/light-v11',
                    center: [2.2137, 46.2276],
                    zoom: 5,
                    interactive: false,
                });
            }

            return trackingDetailMap;
        };

        const clearTrackingDetailMap = () => {
            trackingDetailMarkers.forEach((marker) => marker.remove());
            trackingDetailMarkers = [];

            if (!trackingDetailMap) return;

            if (trackingDetailMap.getLayer('tracking-detail-route')) {
                trackingDetailMap.removeLayer('tracking-detail-route');
            }

            if (trackingDetailMap.getSource('tracking-detail-route')) {
                trackingDetailMap.removeSource('tracking-detail-route');
            }
        };

        const fetchTrackingRoute = async (origin, destination) => {
            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${origin.lng},${origin.lat};${destination.lng},${destination.lat}`);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');
            url.searchParams.set('access_token', trackingMapboxToken);

            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });

            if (!response.ok) return null;

            const payload = await response.json();

            return payload.routes?.[0] || null;
        };

        const setTrackingRouteSummary = (message) => {
            const summary = document.getElementById('tracking_route_summary');
            if (summary) summary.textContent = message;
        };

        const renderTrackingDetailMap = async (event) => {
            const props = event.extendedProps;
            const map = ensureTrackingDetailMap();
            const destination = {
                lat: Number(props.latitude),
                lng: Number(props.longitude),
            };
            const origin = {
                lat: Number(props.origin_latitude),
                lng: Number(props.origin_longitude),
            };

            setText('tracking_route_origin', props.origin_label ? `Depart: ${props.origin_label} - ${props.origin_name || '-'}` : null);

            if (!map) {
                setTrackingRouteSummary('Trajet non affiche.');
                return;
            }

            if (!Number.isFinite(destination.lat) || !Number.isFinite(destination.lng)) {
                clearTrackingDetailMap();
                setTrackingRouteSummary('Coordonnees du RDV indisponibles.');
                return;
            }

            if (!map.loaded()) {
                map.once('load', () => renderTrackingDetailMap(event));
                return;
            }

            clearTrackingDetailMap();

            const bounds = new window.mapboxgl.LngLatBounds();

            trackingDetailMarkers.push(new window.mapboxgl.Marker({ element: trackingMarkerElement('#31424c', 'R') })
                .setLngLat([destination.lng, destination.lat])
                .addTo(map));
            bounds.extend([destination.lng, destination.lat]);

            if (Number.isFinite(origin.lat) && Number.isFinite(origin.lng)) {
                trackingDetailMarkers.push(new window.mapboxgl.Marker({ element: trackingMarkerElement('#d8c27a', 'D') })
                    .setLngLat([origin.lng, origin.lat])
                    .addTo(map));
                bounds.extend([origin.lng, origin.lat]);

                try {
                    const route = await fetchTrackingRoute(origin, destination);

                    if (route?.geometry) {
                        map.addSource('tracking-detail-route', {
                            type: 'geojson',
                            data: {
                                type: 'Feature',
                                geometry: route.geometry,
                                properties: {},
                            },
                        });
                        map.addLayer({
                            id: 'tracking-detail-route',
                            type: 'line',
                            source: 'tracking-detail-route',
                            layout: {
                                'line-cap': 'round',
                                'line-join': 'round',
                            },
                            paint: {
                                'line-color': '#31424c',
                                'line-width': 5,
                                'line-opacity': 0.82,
                            },
                        });

                        setTrackingRouteSummary(`${(route.distance / 1000).toFixed(1)} km en voiture - environ ${Math.round(route.duration / 60)} min.`);
                    } else {
                        setTrackingRouteSummary('Trace Mapbox indisponible, affichage des points uniquement.');
                    }
                } catch (error) {
                    setTrackingRouteSummary('Trace Mapbox indisponible, affichage des points uniquement.');
                }
            } else {
                setTrackingRouteSummary('Origine indisponible, affichage du RDV uniquement.');
            }

            map.fitBounds(bounds, {
                padding: 64,
                maxZoom: 13,
                duration: 0,
            });
            map.resize();
        };

        const closeTrackingAppointmentModal = () => {
            trackingAppointmentModal.classList.add('hidden');
        };

        const openTrackingAppointmentModal = (event) => {
            const props = event.extendedProps;

            document.getElementById('tracking_detail_appointment_id').value = event.id;
            setText('tracking_detail_service', props.service_label);
            setText('tracking_detail_technician', props.technician_name);
            setText('tracking_detail_customer', props.customer_name);
            setText('tracking_detail_customer_phone', props.customer_phone);
            setText('tracking_detail_duration', props.duration_minutes ? `${props.duration_minutes} min` : '-');
            setText('tracking_detail_start', formatDateTime(event.start));
            setText('tracking_detail_end', formatDateTime(event.end));
            setText('tracking_detail_created_by', props.created_by_name);
            setText('tracking_detail_address', props.address);
            trackingInitialComment = props.comment || '';
            document.getElementById('tracking_detail_comment').value = trackingInitialComment;
            document.getElementById('tracking_comment_status').classList.add('hidden');
            document.getElementById('tracking-delete-appointment-btn').classList.toggle('hidden', Boolean(props.deleted_at));
            document.getElementById('tracking-restore-appointment-btn').classList.toggle('hidden', !props.deleted_at);
            trackingAppointmentModal.classList.remove('hidden');
            window.setTimeout(() => renderTrackingDetailMap(event), 80);
        };

        const selectedTechnicianIds = () => technicianCheckboxes
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => Number(checkbox.value));

        const chunkArray = (items, size) => {
            const chunks = [];

            for (let index = 0; index < items.length; index += size) {
                chunks.push(items.slice(index, index + size));
            }

            return chunks;
        };

        const showTrackingCalendarLoader = (chunkCount, technicianCount) => {
            trackingCalendarProgressBar.style.width = '0%';
            trackingCalendarLoadingDetail.textContent = `${technicianCount} technicien(s), ${chunkCount} paquet(s) a charger.`;
            trackingCalendarLoading.classList.remove('hidden');
            trackingCalendarLoading.classList.add('flex');
        };

        const updateTrackingCalendarLoader = (loadedChunks, chunkCount, eventCount) => {
            const progress = chunkCount > 0 ? Math.round((loadedChunks / chunkCount) * 100) : 100;
            trackingCalendarProgressBar.style.width = `${progress}%`;
            trackingCalendarLoadingDetail.textContent = `Paquet ${loadedChunks}/${chunkCount} charge - ${eventCount} RDV trouve(s).`;
        };

        const hideTrackingCalendarLoader = () => {
            trackingCalendarLoading.classList.add('hidden');
            trackingCalendarLoading.classList.remove('flex');
        };

        const fetchTrackingEventsChunk = async (technicianIds, fetchInfo, signal) => {
            const response = await fetch(trackingEventsUrl, {
                method: 'POST',
                signal,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': trackingCsrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    technician_ids: technicianIds,
                    start: fetchInfo.startStr,
                    end: fetchInfo.endStr,
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : payload?.message;
                throw new Error(firstError || 'Erreur calendrier.');
            }

            return payload.events || [];
        };

        const loadTrackingEventsInBatches = async (technicianIds, fetchInfo) => {
            trackingEventsAbortController?.abort();

            const requestId = ++trackingEventsRequestId;
            const controller = new AbortController();
            trackingEventsAbortController = controller;
            const chunks = chunkArray(technicianIds, trackingEventsBatchSize);
            const events = [];

            showTrackingCalendarLoader(chunks.length, technicianIds.length);

            try {
                for (const [index, chunk] of chunks.entries()) {
                    const chunkEvents = await fetchTrackingEventsChunk(chunk, fetchInfo, controller.signal);

                    if (requestId !== trackingEventsRequestId) {
                        return null;
                    }

                    events.push(...chunkEvents);
                    updateTrackingCalendarLoader(index + 1, chunks.length, events.length);
                }

                return events;
            } finally {
                if (requestId === trackingEventsRequestId) {
                    window.setTimeout(hideTrackingCalendarLoader, 220);
                }
            }
        };

        const selectedTechnicianColorMap = () => selectedTechnicianIds().reduce((colors, id, index) => ({
            ...colors,
            [id]: routeColors[index % routeColors.length],
        }), {});

        const updateLegend = () => {
            const colors = selectedTechnicianColorMap();
            const selectedCheckboxes = technicianCheckboxes.filter((checkbox) => checkbox.checked);
            selectedTechCount.textContent = selectedCheckboxes.length;
            calendarHelper.textContent = selectedCheckboxes.length > 0
                ? 'Le calendrier affiche uniquement les techniciens coches.'
                : 'Coche au moins un technicien pour afficher ses rendez-vous.';

            calendarLegend.innerHTML = selectedCheckboxes.map((checkbox) => `
                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                    <span style="display:inline-block;width:10px;height:10px;border-radius:9999px;background:${colors[Number(checkbox.value)]};"></span>
                    ${checkbox.dataset.name}
                </span>
            `).join('');
        };

        const styleTrackingEvent = (event) => {
            const isDeleted = Boolean(event.extendedProps?.deleted_at);
            const colors = selectedTechnicianColorMap();
            const color = colors[event.extendedProps?.technician_id] || '#31424c';

            return {
                ...event,
                backgroundColor: isDeleted ? 'rgba(190,18,60,0.14)' : color,
                borderColor: isDeleted ? '#be123c' : color,
                textColor: isDeleted ? '#7f1d1d' : '#ffffff',
                classNames: isDeleted ? ['appointment-soft-deleted'] : [],
            };
        };

        const applyTrackingEventStyle = (event) => {
            const isDeleted = Boolean(event.extendedProps?.deleted_at);
            const colors = selectedTechnicianColorMap();
            const color = colors[event.extendedProps?.technician_id] || '#31424c';

            event.setProp('backgroundColor', isDeleted ? 'rgba(190,18,60,0.14)' : color);
            event.setProp('borderColor', isDeleted ? '#be123c' : color);
            event.setProp('textColor', isDeleted ? '#7f1d1d' : '#ffffff');
            event.setProp('classNames', isDeleted ? ['appointment-soft-deleted'] : []);
        };

        const refetchCalendar = () => {
            updateLegend();
            if (trackingCalendar) trackingCalendar.refetchEvents();
        };

        const applyTechnicianFilter = () => {
            const query = technicianFilterInput.value.trim().toLowerCase();

            technicianCards.forEach((card) => {
                card.classList.toggle('hidden', query !== '' && !card.dataset.search.includes(query));
            });
        };

        const applyInitialTechnicianSelection = () => {
            if (trackingInitialTechnicianIds.size === 0) return;

            technicianCheckboxes.forEach((checkbox) => {
                checkbox.checked = trackingInitialTechnicianIds.has(String(checkbox.value));
            });
        };

        const initTrackingCalendar = () => {
            const calendarEl = document.getElementById('tracking-calendar');
            if (!calendarEl || !window.FullCalendar || trackingCalendar) return;

            trackingCalendar = new window.FullCalendar.Calendar(calendarEl, {
                initialView: 'timeGridWeek',
                initialDate: trackingInitialDate || undefined,
                locale: 'fr',
                firstDay: 1,
                buttonText: {
                    today: "Aujourd'hui",
                    month: 'Mois',
                    week: 'Semaine',
                    day: 'Jour',
                    list: 'Liste',
                },
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
                },
                titleFormat: {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                },
                dayHeaderFormat: {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'numeric',
                },
                slotLabelFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                },
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                },
                slotMinTime: '08:00:00',
                slotMaxTime: '21:00:00',
                height: 780,
                nowIndicator: true,
                weekends: false,
                allDaySlot: false,
	                events: async (fetchInfo, successCallback, failureCallback) => {
	                    const technicianIds = selectedTechnicianIds();
	
	                    if (technicianIds.length === 0) {
	                        trackingEventsAbortController?.abort();
	                        trackingEventsRequestId++;
	                        hideTrackingCalendarLoader();
	                        successCallback([]);
	                        return;
	                    }
	
	                    try {
	                        const events = await loadTrackingEventsInBatches(technicianIds, fetchInfo);

	                        if (events === null) {
	                            successCallback([]);
	                            return;
	                        }

	                        successCallback(events.map(styleTrackingEvent));
	                    } catch (error) {
	                        if (error.name === 'AbortError') {
	                            successCallback([]);
	                            return;
	                        }

	                        trackingCalendarLoadingDetail.textContent = error.message || 'Impossible de charger le calendrier.';
	                        failureCallback(error);
	                    }
	                },
                eventDidMount: (info) => {
                    const props = info.event.extendedProps;
                    if (props.deleted_at) {
                        info.el.style.opacity = '0.72';
                        info.el.style.borderWidth = '2px';
                    }

                    info.el.title = [
                        props.deleted_at ? 'RDV soft-deleted' : null,
                        props.service_label,
                        props.customer_name,
                        props.customer_phone,
                        props.address,
                        props.created_by_name ? `Cree par: ${props.created_by_name}` : null,
                        props.comment,
                    ].filter(Boolean).join('\n');
                },
                eventClick: (info) => {
                    info.jsEvent.preventDefault();
                    openTrackingAppointmentModal(info.event);
                },
            });

            trackingCalendar.render();
        };

        technicianFilterInput.addEventListener('input', applyTechnicianFilter);
        technicianCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', refetchCalendar));

        document.getElementById('select-visible-techs').addEventListener('click', () => {
            technicianCards
                .filter((card) => !card.classList.contains('hidden'))
                .forEach((card) => {
                    const checkbox = card.querySelector('.technician-checkbox');
                    if (checkbox) checkbox.checked = true;
                });
            refetchCalendar();
        });

        document.getElementById('clear-selected-techs').addEventListener('click', () => {
            technicianCheckboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
            refetchCalendar();
        });

        document.getElementById('tracking-detail-close').addEventListener('click', closeTrackingAppointmentModal);
        trackingAppointmentModal.addEventListener('click', (event) => {
            if (event.target.id === 'tracking-appointment-modal') closeTrackingAppointmentModal();
        });

        trackingCommentForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const appointmentId = document.getElementById('tracking_detail_appointment_id').value;
            const comment = document.getElementById('tracking_detail_comment').value;
            const status = document.getElementById('tracking_comment_status');
            const button = document.getElementById('tracking-save-comment-btn');

            if (!appointmentId) return;

            button.disabled = true;
            button.textContent = 'Enregistrement...';
            status.classList.add('hidden');

            try {
                const response = await fetch(trackingCommentUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ comment }),
                });
                const payload = await response.json();

                if (!response.ok) throw new Error(payload?.message || 'Erreur commentaire.');

                const calendarEvent = trackingCalendar?.getEventById(appointmentId);
                if (calendarEvent) calendarEvent.setExtendedProp('comment', payload.comment || '');
                trackingInitialComment = payload.comment || '';

                status.style.color = '#0f766e';
                status.textContent = 'Commentaire enregistre.';
                status.classList.remove('hidden');
            } catch (error) {
                status.style.color = '#9f1239';
                status.textContent = 'Impossible d enregistrer le commentaire.';
                status.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Enregistrer le commentaire';
            }
        });

        document.getElementById('tracking-delete-appointment-btn').addEventListener('click', async () => {
            const appointmentId = document.getElementById('tracking_detail_appointment_id').value;
            const comment = document.getElementById('tracking_detail_comment').value.trim();
            const status = document.getElementById('tracking_comment_status');
            const button = document.getElementById('tracking-delete-appointment-btn');

            if (!appointmentId) return;

            if (!comment) {
                status.style.color = '#9f1239';
                status.textContent = 'Un commentaire est obligatoire avant de soft delete le RDV.';
                status.classList.remove('hidden');
                return;
            }

            if (comment === trackingInitialComment.trim()) {
                status.style.color = '#9f1239';
                status.textContent = 'Le commentaire doit etre modifie avant de soft delete le RDV.';
                status.classList.remove('hidden');
                return;
            }

            button.disabled = true;
            button.textContent = 'Desactivation...';
            status.classList.add('hidden');

            try {
                const response = await fetch(trackingDestroyUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ comment }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Impossible de soft delete le RDV.';
                    throw new Error(firstError);
                }

                const calendarEvent = trackingCalendar?.getEventById(appointmentId);
                if (calendarEvent) {
                    calendarEvent.setExtendedProp('comment', payload.comment || comment);
                    calendarEvent.setExtendedProp('deleted_at', payload.deleted_at || new Date().toISOString());
                    applyTrackingEventStyle(calendarEvent);
                }

                document.getElementById('tracking-delete-appointment-btn').classList.add('hidden');
                document.getElementById('tracking-restore-appointment-btn').classList.remove('hidden');
                trackingInitialComment = payload.comment || comment;
                status.style.color = '#9f1239';
                status.textContent = 'Rendez-vous soft-deleted.';
                status.classList.remove('hidden');
            } catch (error) {
                status.style.color = '#9f1239';
                status.textContent = error.message || 'Impossible de soft delete le RDV.';
                status.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Soft delete le RDV';
            }
        });

        document.getElementById('tracking-restore-appointment-btn').addEventListener('click', async () => {
            const appointmentId = document.getElementById('tracking_detail_appointment_id').value;
            const comment = document.getElementById('tracking_detail_comment').value.trim();
            const status = document.getElementById('tracking_comment_status');
            const button = document.getElementById('tracking-restore-appointment-btn');

            if (!appointmentId) return;

            if (!comment) {
                status.style.color = '#9f1239';
                status.textContent = 'Un commentaire est obligatoire avant de reactiver le RDV.';
                status.classList.remove('hidden');
                return;
            }

            if (comment === trackingInitialComment.trim()) {
                status.style.color = '#9f1239';
                status.textContent = 'Le commentaire doit etre modifie avant de reactiver le RDV.';
                status.classList.remove('hidden');
                return;
            }

            button.disabled = true;
            button.textContent = 'Reactivation...';
            status.classList.add('hidden');

            try {
                const response = await fetch(trackingRestoreUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ comment }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Impossible de reactiver le RDV.';
                    throw new Error(firstError);
                }

                const calendarEvent = trackingCalendar?.getEventById(appointmentId);
                if (calendarEvent) {
                    calendarEvent.setExtendedProp('comment', payload.comment || comment);
                    calendarEvent.setExtendedProp('deleted_at', null);
                    applyTrackingEventStyle(calendarEvent);
                }

                document.getElementById('tracking-delete-appointment-btn').classList.remove('hidden');
                document.getElementById('tracking-restore-appointment-btn').classList.add('hidden');
                trackingInitialComment = payload.comment || comment;
                status.style.color = '#0f766e';
                status.textContent = 'Rendez-vous reactive.';
                status.classList.remove('hidden');
            } catch (error) {
                status.style.color = '#9f1239';
                status.textContent = error.message || 'Impossible de reactiver le RDV.';
                status.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Reactiver le RDV';
            }
        });

        applyInitialTechnicianSelection();
        initTrackingCalendar();
        updateLegend();

        window.addEventListener('techcalendar:layout-resized', () => {
            trackingCalendar?.updateSize();
        });
    </script>
</x-layouts.app>
