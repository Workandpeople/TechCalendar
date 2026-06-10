<x-layouts.app>
    <div class="tech-planning-page space-y-4 sm:space-y-6">
        @php
            $appointmentActionLinks = function ($appointment): array {
                $phone = preg_replace('/[^\d+]/', '', (string) $appointment->customer_phone);
                $destination = $appointment->latitude && $appointment->longitude
                    ? $appointment->latitude.','.$appointment->longitude
                    : $appointment->address;

                return [
                    'phone' => $phone ? 'tel:'.$phone : null,
                    'maps' => 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode((string) $destination),
                ];
            };
        @endphp

        <section class="overflow-hidden rounded-3xl border bg-white shadow-sm" style="border-color:var(--gc-border);">
            <div class="bg-[color:var(--gc-primary)] px-5 py-5 text-white sm:px-6">
                <p class="text-sm text-white/70">Mon planning</p>
                <div class="mt-2 flex items-end justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold leading-tight">Bonjour {{ $technician->first_name }}</h1>
                        <p class="mt-1 text-sm text-white/75">Tes interventions et prochains trajets.</p>
                    </div>
                    <span class="hidden rounded-2xl bg-white/10 px-3 py-2 text-sm font-medium text-white sm:inline-flex">
                        {{ $technician->full_name }}
                    </span>
                </div>
            </div>

            <div class="flex gap-3 overflow-x-auto px-4 py-4 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-5 xl:grid-cols-4">
                @foreach ($stats as $stat)
                    @php
                        $tones = [
                            'blue' => ['#dbeafe', '#1d4ed8'],
                            'green' => ['#dcfce7', '#15803d'],
                            'gold' => ['#fef3c7', '#b45309'],
                            'pink' => ['#ffe4e6', '#be123c'],
                        ];
                        [$toneBg, $toneText] = $tones[$stat['tone']] ?? ['var(--gc-accent-soft)', 'var(--gc-text)'];
                    @endphp
                    <article class="min-w-[152px] rounded-2xl border p-4 sm:min-w-0" style="border-color:var(--gc-border);background:linear-gradient(135deg,#ffffff 0%,#fbfbfb 100%);">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium" style="color:var(--gc-text-soft);">{{ $stat['label'] }}</p>
                                <p class="mt-2 text-2xl font-semibold leading-none" style="color:var(--gc-text);">{{ $stat['value'] }}</p>
                            </div>
                            <span class="h-3 w-3 shrink-0 rounded-full" style="background:{{ $toneText }};"></span>
                        </div>
                        <p class="mt-3 line-clamp-2 text-xs" style="color:var(--gc-text-soft);">{{ $stat['detail'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid grid-cols-1 gap-4 xl:grid-cols-[420px_minmax(0,1fr)]">
            <div class="space-y-4">
                <article class="rounded-3xl border bg-white p-5 shadow-sm" style="border-color:var(--gc-border);">
                    <p class="text-sm font-medium" style="color:var(--gc-text-soft);">Prochaine intervention</p>

                    @if ($nextAppointment)
                        @php $nextLinks = $appointmentActionLinks($nextAppointment); @endphp
                        <div class="mt-4 rounded-2xl p-4" style="background:var(--gc-accent-soft);">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">
                                        {{ $nextAppointment->starts_at->locale('fr')->isoFormat('ddd D MMM') }}
                                    </p>
                                    <p class="mt-1 text-4xl font-semibold leading-none" style="color:var(--gc-text);">
                                        {{ $nextAppointment->starts_at->format('H:i') }}
                                    </p>
                                </div>
                                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold" style="color:var(--gc-text);">
                                    {{ $nextAppointment->service?->type ?? 'RDV' }}
                                </span>
                            </div>

                            <h2 class="mt-5 text-xl font-semibold" style="color:var(--gc-text);">
                                {{ $nextAppointment->customer_first_name }} {{ $nextAppointment->customer_last_name }}
                            </h2>
                            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                                {{ $nextAppointment->service ? $nextAppointment->service->type.' - '.$nextAppointment->service->name : 'Prestation' }}
                            </p>
                            <p class="mt-3 text-sm leading-relaxed" style="color:var(--gc-text);">{{ $nextAppointment->address }}</p>

                            <div class="mt-5 grid grid-cols-2 gap-3">
                                @if ($nextLinks['phone'])
                                    <a href="{{ $nextLinks['phone'] }}" class="gc-btn-primary text-center">Appeler</a>
                                @else
                                    <span class="rounded-lg border px-4 py-2.5 text-center text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Pas de tel.</span>
                                @endif
                                <a href="{{ $nextLinks['maps'] }}" target="_blank" rel="noopener" class="rounded-lg border px-4 py-2.5 text-center text-sm font-medium transition hover:bg-[color:var(--gc-accent-soft)]" style="border-color:var(--gc-border);color:var(--gc-text);">
                                    Itineraire
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="mt-4 rounded-2xl border border-dashed p-5 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                            Aucun rendez-vous a venir.
                        </div>
                    @endif
                </article>

                <article class="rounded-3xl border bg-white p-5 shadow-sm" style="border-color:var(--gc-border);">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">A venir</p>
                            <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Prochains RDV</h2>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                            {{ $upcomingAppointments->count() }}
                        </span>
                    </div>

                    <div class="space-y-3">
                        @forelse ($upcomingAppointments as $appointment)
                            @php $links = $appointmentActionLinks($appointment); @endphp
                            <article class="relative rounded-2xl border p-4" style="border-color:var(--gc-border);">
                                <div class="flex gap-3">
                                    <div class="flex w-14 shrink-0 flex-col items-center rounded-2xl px-2 py-2 text-center" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                                        <span class="text-xs font-semibold uppercase">{{ $appointment->starts_at->locale('fr')->isoFormat('ddd') }}</span>
                                        <span class="text-lg font-semibold leading-tight">{{ $appointment->starts_at->format('d') }}</span>
                                        <span class="text-xs">{{ $appointment->starts_at->format('H:i') }}</span>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <h3 class="truncate font-semibold" style="color:var(--gc-text);">
                                                    {{ $appointment->customer_first_name }} {{ $appointment->customer_last_name }}
                                                </h3>
                                                <p class="mt-1 truncate text-xs" style="color:var(--gc-text-soft);">
                                                    {{ $appointment->service ? $appointment->service->type.' - '.$appointment->service->name : 'Prestation' }}
                                                </p>
                                            </div>
                                            <span class="rounded-full px-2 py-1 text-xs" style="background:#e0f2fe;color:#1d4ed8;">
                                                {{ $appointment->service?->type ?? 'RDV' }}
                                            </span>
                                        </div>

                                        <p class="mt-2 line-clamp-2 text-xs leading-relaxed" style="color:var(--gc-text-soft);">{{ $appointment->address }}</p>

                                        <div class="mt-3 flex gap-2">
                                            @if ($links['phone'])
                                                <a href="{{ $links['phone'] }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-white" style="background:var(--gc-primary);">Appeler</a>
                                            @endif
                                            <a href="{{ $links['maps'] }}" target="_blank" rel="noopener" class="rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color:var(--gc-border);color:var(--gc-text);">
                                                Itineraire
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <p class="text-sm" style="color:var(--gc-text-soft);">Aucun rendez-vous a venir.</p>
                        @endforelse
                    </div>
                </article>
            </div>

            <section class="tech-calendar-card rounded-3xl border bg-white p-4 shadow-sm sm:p-5" style="border-color:var(--gc-border);">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Calendrier</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Interventions planifiees</h2>
                    <p class="mt-1 text-xs sm:text-sm" style="color:var(--gc-text-soft);">Sur mobile, touche un RDV pour afficher les details et les actions.</p>
                </div>
                <div id="tech-calendar"></div>
            </section>
        </section>
    </div>

    <div id="tech-appointment-sheet" class="fixed inset-0 z-50 hidden items-end bg-black/40 p-0 sm:items-center sm:p-4">
        <div class="max-h-[calc(100vh-1rem)] w-full overflow-y-auto overscroll-contain rounded-t-3xl border bg-white p-5 shadow-2xl sm:mx-auto sm:max-w-lg sm:rounded-3xl" style="border-color:var(--gc-border);">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p id="tech-sheet-service" class="text-sm" style="color:var(--gc-text-soft);"></p>
                    <h2 id="tech-sheet-customer" class="mt-1 text-xl font-semibold" style="color:var(--gc-text);"></h2>
                </div>
                <button id="tech-sheet-close" type="button" class="rounded-full border px-3 py-1 text-sm" style="border-color:var(--gc-border);color:var(--gc-text);">Fermer</button>
            </div>

            <dl class="mt-5 space-y-3 text-sm">
                <div>
                    <dt class="font-medium" style="color:var(--gc-text-soft);">Horaire</dt>
                    <dd id="tech-sheet-time" class="mt-1" style="color:var(--gc-text);"></dd>
                </div>
                <div>
                    <dt class="font-medium" style="color:var(--gc-text-soft);">Adresse</dt>
                    <dd id="tech-sheet-address" class="mt-1 leading-relaxed" style="color:var(--gc-text);"></dd>
                </div>
                <div id="tech-sheet-comment-wrap" class="hidden">
                    <dt class="font-medium" style="color:var(--gc-text-soft);">Commentaire</dt>
                    <dd id="tech-sheet-comment" class="mt-1 leading-relaxed" style="color:var(--gc-text);"></dd>
                </div>
            </dl>

            <div class="mt-5 rounded-2xl border p-3" style="border-color:var(--gc-border);">
                <div>
                    <p class="text-sm font-medium" style="color:var(--gc-text);">Journee du technicien</p>
                    <p class="text-xs" style="color:var(--gc-text-soft);">Clique un trajet pour le mettre en couleur. Les autres restent en pointille.</p>
                </div>
                <div id="tech-sheet-map" class="mt-3 h-64 overflow-hidden rounded-xl border" style="border-color:var(--gc-border);"></div>
                <div id="tech-sheet-route-summary" class="mt-3 rounded-lg px-3 py-2 text-sm" style="background:var(--gc-accent-soft);color:var(--gc-text);"></div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-3">
                <a id="tech-sheet-phone" href="#" class="gc-btn-primary text-center">Appeler</a>
                <a id="tech-sheet-maps" href="#" target="_blank" rel="noopener" class="rounded-lg border px-4 py-2.5 text-center text-sm font-medium" style="border-color:var(--gc-border);color:var(--gc-text);">
                    Itineraire
                </a>
            </div>
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">

    <style>
        @media (max-width: 767px) {
            .tech-planning-page .fc {
                font-size: 12px;
            }

            .tech-planning-page .fc-toolbar.fc-header-toolbar {
                align-items: flex-start;
                gap: 8px;
                margin-bottom: 12px;
            }

            .tech-planning-page .fc-toolbar-title {
                font-size: 16px;
                line-height: 1.2;
            }

            .tech-planning-page .fc-button {
                border-radius: 10px;
                font-size: 12px;
                padding: 6px 9px;
            }

            .tech-planning-page .fc-timegrid-slot {
                height: 38px;
            }

            .tech-planning-page .fc-event {
                border-radius: 10px;
                padding: 2px 4px;
            }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>
    <script>
        const calendarEl = document.getElementById('tech-calendar');
        const mobileCalendarQuery = window.matchMedia('(max-width: 767px)');
        const techMapboxToken = @json($mapboxToken ?? null);
        const techRouteNumber = (value) => value === null || value === undefined || value === '' ? NaN : Number(value);
        const techHome = {
            lat: techRouteNumber(@json($technician->latitude)),
            lng: techRouteNumber(@json($technician->longitude)),
            name: @json($technician->address ?: 'Domicile'),
        };
        let techCalendar = null;
        let techSheetMap = null;
        let techSheetMapMarkers = [];
        let techSheetMapRenderRequestId = 0;

        const techCalendarToolbar = () => mobileCalendarQuery.matches
            ? { left: 'prev,next', center: 'title', right: 'today' }
            : { left: 'prev,next today', center: 'title', right: 'timeGridWeek,timeGridDay' };

        const techCalendarHeight = () => mobileCalendarQuery.matches ? 620 : 760;

        const formatEventDate = (date) => new Intl.DateTimeFormat('fr-FR', {
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);

        const mapsUrlForEvent = (event) => {
            const props = event.extendedProps;
            const destination = props.latitude && props.longitude
                ? `${props.latitude},${props.longitude}`
                : props.address;

            return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination || '')}`;
        };

        const techEscapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const techRouteDuration = (minutes) => {
            const safeMinutes = Math.max(0, Math.round(Number(minutes || 0)));
            const hours = Math.floor(safeMinutes / 60);
            const remainingMinutes = safeMinutes % 60;

            if (hours === 0) return `${remainingMinutes} min`;

            return remainingMinutes > 0 ? `${hours}h${String(remainingMinutes).padStart(2, '0')}` : `${hours}h`;
        };

        const techHaversineDistanceKm = (fromLat, fromLng, toLat, toLng) => {
            const earthRadiusKm = 6371;
            const toRadians = (value) => value * Math.PI / 180;
            const latDelta = toRadians(toLat - fromLat);
            const lngDelta = toRadians(toLng - fromLng);
            const a = Math.sin(latDelta / 2) ** 2
                + Math.cos(toRadians(fromLat)) * Math.cos(toRadians(toLat)) * Math.sin(lngDelta / 2) ** 2;

            return earthRadiusKm * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
        };

        const techValidPoint = (point) => Number.isFinite(point?.lat) && Number.isFinite(point?.lng);

        const sameTechPlanningDay = (leftDate, rightDate) => leftDate?.toDateString?.() === rightDate?.toDateString?.();

        const sameTechPlanningEvent = (left, right) => {
            if (!left || !right) return false;
            if (left === right) return true;

            return String(left.id || '') !== '' && String(left.id) === String(right.id || '');
        };

        const techMarkerElement = (color, label) => {
            const element = document.createElement('div');
            element.className = 'flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-[10px] font-bold shadow-lg';
            element.style.background = color;
            element.style.color = '#fff';
            element.textContent = label;

            return element;
        };

        const ensureTechSheetMap = () => {
            const container = document.getElementById('tech-sheet-map');

            if (!techMapboxToken || !window.mapboxgl) {
                container.innerHTML = '<div class="flex h-full items-center justify-center px-4 text-center text-sm" style="color:var(--gc-text-soft);">Carte indisponible: token Mapbox ou librairie manquante.</div>';
                return null;
            }

            window.mapboxgl.accessToken = techMapboxToken;

            if (!techSheetMap) {
                techSheetMap = new window.mapboxgl.Map({
                    container: 'tech-sheet-map',
                    style: 'mapbox://styles/mapbox/light-v11',
                    center: [2.2137, 46.2276],
                    zoom: 5,
                    interactive: false,
                });
            }

            return techSheetMap;
        };

        const clearTechSheetMap = () => {
            techSheetMapMarkers.forEach((marker) => marker.remove());
            techSheetMapMarkers = [];

            if (!techSheetMap || !techSheetMap.loaded()) return;

            [...techSheetMap.getStyle().layers]
                .filter((layer) => layer.id.startsWith('tech-sheet-route'))
                .forEach((layer) => techSheetMap.removeLayer(layer.id));

            Object.keys(techSheetMap.getStyle().sources)
                .filter((source) => source.startsWith('tech-sheet-route'))
                .forEach((source) => techSheetMap.removeSource(source));
        };

        const fetchTechSheetRoute = async (origin, destination) => {
            const fallbackDistanceKm = techHaversineDistanceKm(origin.lat, origin.lng, destination.lat, destination.lng);
            const fallback = {
                feature: {
                    type: 'Feature',
                    geometry: {
                        type: 'LineString',
                        coordinates: [[origin.lng, origin.lat], [destination.lng, destination.lat]],
                    },
                    properties: {},
                },
                distance_km: fallbackDistanceKm,
                duration_minutes: Math.max(1, Math.round((fallbackDistanceKm / 65) * 60)),
            };

            if (!techMapboxToken) return fallback;

            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${origin.lng},${origin.lat};${destination.lng},${destination.lat}`);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');
            url.searchParams.set('access_token', techMapboxToken);

            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                if (!response.ok) return fallback;

                const payload = await response.json();
                const route = payload.routes?.[0];

                return route?.geometry ? {
                    feature: {
                        type: 'Feature',
                        geometry: route.geometry,
                        properties: {},
                    },
                    distance_km: Number(route.distance || 0) / 1000,
                    duration_minutes: Math.max(1, Math.round(Number(route.duration || 0) / 60)),
                } : fallback;
            } catch (error) {
                return fallback;
            }
        };

        const techAppointmentPointFromEvent = (event, currentEvent) => {
            const props = event.extendedProps || {};

            return {
                kind: 'appointment',
                lat: techRouteNumber(props.latitude),
                lng: techRouteNumber(props.longitude),
                name: props.customer_name || props.service_label || event.title || 'RDV',
                event,
                isCurrent: sameTechPlanningEvent(event, currentEvent),
            };
        };

        const buildTechSheetDaySegments = (event) => {
            if (!techValidPoint(techHome)) return [];

            const appointments = (techCalendar ? techCalendar.getEvents() : [])
                .filter((candidate) => sameTechPlanningDay(candidate.start, event.start))
                .map((candidate) => techAppointmentPointFromEvent(candidate, event))
                .filter(techValidPoint)
                .sort((left, right) => left.event.start - right.event.start);

            if (!appointments.some((appointment) => appointment.isCurrent)) {
                const currentPoint = techAppointmentPointFromEvent(event, event);
                if (techValidPoint(currentPoint)) appointments.push(currentPoint);
                appointments.sort((left, right) => left.event.start - right.event.start);
            }

            if (appointments.length === 0) return [];

            const points = [
                { ...techHome, kind: 'home', name: techHome.name || 'Domicile' },
                ...appointments,
                { ...techHome, kind: 'home', name: techHome.name || 'Domicile', isReturnHome: true },
            ];

            return points.slice(0, -1).map((from, index) => {
                const to = points[index + 1];
                const isCurrent = Boolean(to.isCurrent);

                return {
                    from,
                    to,
                    isCurrent,
                    badge: isCurrent
                        ? 'Trajet vers ce RDV'
                        : (from.isCurrent ? 'Suite de journee' : (to.kind === 'home' ? 'Retour domicile' : 'Autre trajet')),
                };
            });
        };

        const renderTechSheetRouteSummary = (segments, isLoading = false, activeIndex = null, onSelect = null) => {
            const summary = document.getElementById('tech-sheet-route-summary');

            if (isLoading) {
                summary.textContent = 'Calcul de la journee...';
                return;
            }

            if (!segments || segments.length === 0) {
                summary.textContent = 'Journee indisponible pour ce RDV.';
                return;
            }

            const defaultActiveIndex = segments.findIndex((segment) => segment.isCurrent);
            const safeActiveIndex = Number.isInteger(activeIndex) && activeIndex >= 0 && activeIndex < segments.length
                ? activeIndex
                : Math.max(0, defaultActiveIndex);

            summary.innerHTML = `
                <div class="space-y-2">
                    ${segments.map((segment, index) => {
                        const isActive = index === safeActiveIndex;

                        return `
                        <button type="button" data-tech-sheet-segment-index="${index}" class="flex w-full items-center justify-between gap-3 rounded-lg border px-2 py-2 text-left transition hover:shadow-sm" style="border-color:${isActive ? 'var(--gc-accent)' : 'transparent'};background:${isActive ? '#ffffff' : 'transparent'};">
                            <div class="min-w-0">
                                <span class="text-xs font-semibold">${techEscapeHtml(segment.badge)}</span>
                                <p class="truncate text-xs" style="color:var(--gc-text-soft);">${techEscapeHtml(segment.from.name)} → ${techEscapeHtml(segment.to.name)}</p>
                            </div>
                            <strong class="shrink-0 text-xs">${Number(segment.route?.distance_km || 0).toFixed(1)} km · ${techRouteDuration(segment.route?.duration_minutes)}</strong>
                        </button>
                    `;
                    }).join('')}
                </div>
            `;

            summary.querySelectorAll('[data-tech-sheet-segment-index]').forEach((button) => {
                button.addEventListener('click', () => {
                    onSelect?.(Number(button.dataset.techSheetSegmentIndex));
                });
            });
        };

        const renderTechSheetMap = async (event) => {
            if (document.getElementById('tech-appointment-sheet')?.classList.contains('hidden')) return;

            const renderRequestId = ++techSheetMapRenderRequestId;
            const map = ensureTechSheetMap();
            let segments = buildTechSheetDaySegments(event);

            if (!map) {
                renderTechSheetRouteSummary([]);
                return;
            }

            if (segments.length === 0) {
                renderTechSheetRouteSummary([]);
                clearTechSheetMap();
                return;
            }

            if (!map.loaded()) {
                map.once('load', () => renderTechSheetMap(event));
                return;
            }

            clearTechSheetMap();
            renderTechSheetRouteSummary([], true);

            const enrichedSegments = [];

            for (const segment of segments) {
                const route = await fetchTechSheetRoute(segment.from, segment.to);
                if (renderRequestId !== techSheetMapRenderRequestId) return;
                enrichedSegments.push({ ...segment, route });
            }

            const defaultActiveIndex = Math.max(0, enrichedSegments.findIndex((segment) => segment.isCurrent));

            const renderSelectedSegment = (activeSegmentIndex = defaultActiveIndex) => {
                if (renderRequestId !== techSheetMapRenderRequestId) return;

                const safeActiveIndex = Number.isInteger(activeSegmentIndex) && activeSegmentIndex >= 0 && activeSegmentIndex < enrichedSegments.length
                    ? activeSegmentIndex
                    : defaultActiveIndex;

                renderTechSheetRouteSummary(enrichedSegments, false, safeActiveIndex, (selectedIndex) => {
                    renderSelectedSegment(selectedIndex);
                });
                clearTechSheetMap();

                const bounds = new window.mapboxgl.LngLatBounds();
                const appointmentMarkerKeys = new Set();
                const segmentsForDisplay = enrichedSegments.map((segment, index) => ({
                    ...segment,
                    isHighlighted: index === safeActiveIndex,
                }));
                const orderedSegments = [
                    ...segmentsForDisplay.filter((segment) => !segment.isHighlighted),
                    ...segmentsForDisplay.filter((segment) => segment.isHighlighted),
                ];

                techSheetMapMarkers.push(new window.mapboxgl.Marker({ element: techMarkerElement('#31424c', 'D') })
                    .setLngLat([enrichedSegments[0].from.lng, enrichedSegments[0].from.lat])
                    .addTo(map));

                enrichedSegments.forEach((segment) => {
                    [segment.from, segment.to].forEach((point) => bounds.extend([point.lng, point.lat]));
                    (segment.route?.feature?.geometry?.coordinates || []).forEach((coordinate) => bounds.extend(coordinate));

                    if (segment.to.kind === 'appointment') {
                        const key = segment.to.event?.id ? String(segment.to.event.id) : `${segment.to.lng},${segment.to.lat}`;

                        if (!appointmentMarkerKeys.has(key)) {
                            appointmentMarkerKeys.add(key);
                            techSheetMapMarkers.push(new window.mapboxgl.Marker({
                                element: techMarkerElement(segment.to.isCurrent ? '#d8c27a' : '#64748b', segment.to.isCurrent ? 'R' : String(appointmentMarkerKeys.size)),
                            })
                                .setLngLat([segment.to.lng, segment.to.lat])
                                .addTo(map));
                        }
                    }
                });

                orderedSegments.forEach((segment, index) => {
                    const sourceId = `tech-sheet-route-${index}`;

                    if (!segment.route?.feature) return;

                    map.addSource(sourceId, { type: 'geojson', data: segment.route.feature });
                    map.addLayer({
                        id: sourceId,
                        type: 'line',
                        source: sourceId,
                        layout: {
                            'line-cap': 'round',
                            'line-join': 'round',
                        },
                        paint: {
                            'line-color': segment.isHighlighted ? '#31424c' : '#64748b',
                            'line-width': segment.isHighlighted ? 5 : 3,
                            'line-opacity': segment.isHighlighted ? 0.9 : 0.5,
                            ...(segment.isHighlighted ? {} : { 'line-dasharray': [1.5, 2.2] }),
                        },
                    });
                });

                map.fitBounds(bounds, {
                    padding: 48,
                    maxZoom: 13,
                    duration: 0,
                });
                map.resize();
            };

            renderSelectedSegment();
        };

        const openTechAppointmentSheet = (event) => {
            const props = event.extendedProps;
            const sheet = document.getElementById('tech-appointment-sheet');
            const phoneLink = document.getElementById('tech-sheet-phone');
            const commentWrap = document.getElementById('tech-sheet-comment-wrap');
            const phoneHref = props.customer_phone ? `tel:${String(props.customer_phone).replace(/[^\d+]/g, '')}` : '';

            document.getElementById('tech-sheet-service').textContent = props.service_label || 'Prestation';
            document.getElementById('tech-sheet-customer').textContent = props.customer_name || event.title;
            document.getElementById('tech-sheet-time').textContent = `${formatEventDate(event.start)} - ${event.end ? event.end.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : ''}`;
            document.getElementById('tech-sheet-address').textContent = props.address || 'Adresse non renseignee';
            document.getElementById('tech-sheet-maps').href = mapsUrlForEvent(event);

            if (phoneHref) {
                phoneLink.href = phoneHref;
                phoneLink.classList.remove('pointer-events-none', 'opacity-50');
            } else {
                phoneLink.href = '#';
                phoneLink.classList.add('pointer-events-none', 'opacity-50');
            }

            if (props.comment) {
                document.getElementById('tech-sheet-comment').textContent = props.comment;
                commentWrap.classList.remove('hidden');
            } else {
                commentWrap.classList.add('hidden');
            }

            sheet.classList.remove('hidden');
            sheet.classList.add('flex');
            window.setTimeout(() => renderTechSheetMap(event), 80);
        };

        const closeTechAppointmentSheet = () => {
            const sheet = document.getElementById('tech-appointment-sheet');
            techSheetMapRenderRequestId++;
            sheet.classList.add('hidden');
            sheet.classList.remove('flex');
        };

        if (calendarEl && window.FullCalendar) {
            techCalendar = new window.FullCalendar.Calendar(calendarEl, {
                initialView: mobileCalendarQuery.matches ? 'timeGridDay' : 'timeGridWeek',
                locale: 'fr',
                firstDay: 1,
                weekends: false,
                allDaySlot: false,
                height: techCalendarHeight(),
                expandRows: true,
                nowIndicator: true,
                headerToolbar: techCalendarToolbar(),
                buttonText: {
                    today: "Aujourd'hui",
                    month: 'Mois',
                    week: 'Semaine',
                    day: 'Jour',
                    list: 'Liste',
                },
                slotMinTime: '08:00:00',
                slotMaxTime: '21:00:00',
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
                events: async (fetchInfo, successCallback, failureCallback) => {
                    try {
                        const response = await fetch('{{ route('tech.planning.events') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                start: fetchInfo.startStr,
                                end: fetchInfo.endStr,
                            }),
                        });
                        const payload = await response.json();

                        if (!response.ok) {
                            throw new Error(payload?.message || 'Erreur calendrier.');
                        }

                        successCallback((payload.events || []).map((event) => ({
                            ...event,
                            backgroundColor: '#31424c',
                            borderColor: '#d8c27a',
                            textColor: '#ffffff',
                        })));
                    } catch (error) {
                        failureCallback(error);
                    }
                },
                eventDidMount: (info) => {
                    const props = info.event.extendedProps;
                    info.el.title = [
                        props.service_label,
                        props.customer_name,
                        props.customer_phone,
                        props.address,
                        props.comment,
                    ].filter(Boolean).join('\n');
                },
                eventClick: (info) => {
                    info.jsEvent.preventDefault();
                    openTechAppointmentSheet(info.event);
                },
            });

            techCalendar.render();
        }

        const refreshTechCalendarViewport = () => {
            if (!techCalendar) return;

            techCalendar.setOption('height', techCalendarHeight());
            techCalendar.setOption('headerToolbar', techCalendarToolbar());

            if (mobileCalendarQuery.matches && techCalendar.view.type !== 'timeGridDay') {
                techCalendar.changeView('timeGridDay');
            }

            if (!mobileCalendarQuery.matches && techCalendar.view.type === 'timeGridDay') {
                techCalendar.changeView('timeGridWeek');
            }

            techCalendar.updateSize();
        };

        if (mobileCalendarQuery.addEventListener) {
            mobileCalendarQuery.addEventListener('change', refreshTechCalendarViewport);
        }

        window.addEventListener('techcalendar:layout-resized', refreshTechCalendarViewport);
        window.addEventListener('resize', () => window.setTimeout(refreshTechCalendarViewport, 120));

        document.getElementById('tech-sheet-close')?.addEventListener('click', closeTechAppointmentSheet);
        document.getElementById('tech-appointment-sheet')?.addEventListener('click', (event) => {
            if (event.target.id === 'tech-appointment-sheet') {
                closeTechAppointmentSheet();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeTechAppointmentSheet();
            }
        });
    </script>
</x-layouts.app>
