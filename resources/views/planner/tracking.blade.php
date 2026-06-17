<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">{{ $section ?? 'Planning' }}</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">{{ $title ?? 'Suivi des RDV' }}</h1>
        </div>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Techniciens</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Filtre puis coche les techniciens à afficher dans le calendrier.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button id="select-visible-techs" type="button" class="gc-btn-soft">Cocher les visibles</button>
                    <button id="clear-selected-techs" type="button" class="gc-btn-soft">Tout décocher</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                <div>
                    <label class="gc-label" for="technician_filter">Recherche</label>
                    <input id="technician_filter" type="search" class="gc-input" placeholder="Nom, prénom, téléphone ou adresse" autocomplete="off" />
                </div>
                <div class="flex h-[42px] items-center justify-between gap-3 self-end rounded-lg border px-4" style="border-color:var(--gc-border);background:var(--gc-accent-soft);">
                    <div class="text-xs uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Sélection</div>
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
            <div class="mb-4">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Rendez-vous des techniciens sélectionnés</h2>
                    <p id="calendar-helper" class="text-sm" style="color:var(--gc-text-soft);">Coche au moins un technicien pour afficher ses rendez-vous.</p>
                </div>
                <div id="calendar-legend" class="mt-3 flex flex-wrap gap-2"></div>
            </div>

            <div class="mb-4 rounded-xl border p-4" style="border-color:var(--gc-border);background:var(--gc-accent-soft);">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(220px,280px)_auto] lg:items-end">
                    <div>
                        <label class="gc-label" for="tracking_service_filter">Prestation</label>
                        <select id="tracking_service_filter" class="gc-input">
                            <option value="">Toutes les prestations</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}">{{ $service->type }} - {{ $service->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                            <label class="gc-label" for="tracking_status_filter">État du RDV</label>
                        <select id="tracking_status_filter" class="gc-input">
                            <option value="all">Tous les RDV</option>
                            <option value="active">Actifs uniquement</option>
                            <option value="deleted">Soft-deleted uniquement</option>
                        </select>
                    </div>
                    <button id="tracking-reset-filters" type="button" class="gc-btn-soft">Réinitialiser</button>
                </div>
            </div>

            <div class="relative min-h-[780px]">
                <div id="tracking-calendar"></div>
                <div id="tracking-calendar-loading" class="absolute inset-0 z-10 hidden items-start justify-center rounded-xl bg-white/85 px-4 pt-24 backdrop-blur-sm">
                    <div class="w-full max-w-md rounded-2xl border bg-white p-5 shadow-lg" style="border-color:var(--gc-border);">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p id="tracking-calendar-loading-title" class="text-sm font-semibold" style="color:var(--gc-text);">Chargement du calendrier</p>
                                <p id="tracking-calendar-loading-detail" class="mt-1 text-xs" style="color:var(--gc-text-soft);">Préparation des paquets...</p>
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
        <div class="gc-modal-panel max-h-[calc(100vh-2rem)] max-w-6xl overflow-y-auto">
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
                    <div id="tracking_day_route_summary" class="mt-3 rounded-lg border px-3 py-3 text-sm" style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);"></div>
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
                            <dt style="color:var(--gc-text-soft);">Téléphone client</dt>
                            <dd id="tracking_detail_customer_phone" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Durée</dt>
                            <dd id="tracking_detail_duration" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Début</dt>
                            <dd id="tracking_detail_start" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Fin</dt>
                            <dd id="tracking_detail_end" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Créé par</dt>
                            <dd id="tracking_detail_created_by" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                        <div>
                            <dt style="color:var(--gc-text-soft);">Adresse</dt>
                            <dd id="tracking_detail_address" class="font-medium" style="color:var(--gc-text);"></dd>
                        </div>
                    </dl>

                    <form id="tracking-details-form" class="mt-4 rounded-xl border p-4" style="border-color:var(--gc-border);background:var(--gc-accent-soft);" data-validate-form>
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold" style="color:var(--gc-text);">Modifier le RDV</h3>
                            <p class="text-xs" style="color:var(--gc-text-soft);">Date, heure, durée et adresse peuvent être ajustées en cas de changement de dernière minute.</p>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="gc-label" for="tracking_detail_starts_at">Date et heure</label>
                                <input id="tracking_detail_starts_at" type="datetime-local" class="gc-input" required />
                            </div>
                            <div>
                                <label class="gc-label" for="tracking_detail_duration_minutes">Durée (minutes)</label>
                                <input id="tracking_detail_duration_minutes" type="number" min="15" max="600" step="5" class="gc-input" required />
                            </div>
                        </div>

                        <div class="relative mt-3">
                            <label class="gc-label" for="tracking_detail_address_input">Adresse</label>
                            <input id="tracking_detail_address_input" type="text" maxlength="255" class="gc-input" required autocomplete="off" />
                            <input id="tracking_detail_latitude" type="hidden" />
                            <input id="tracking_detail_longitude" type="hidden" />
                        </div>

                        <div id="tracking_details_status" class="mt-2 hidden text-sm"></div>
                        <div class="mt-3 flex justify-end">
                            <button id="tracking-save-details-btn" type="submit" class="gc-btn-primary">Enregistrer les modifications</button>
                        </div>
                    </form>

                    <form id="tracking-reassign-form" class="mt-4 rounded-xl border p-4" style="border-color:var(--gc-border);background:var(--gc-accent-soft);">
                        <label class="gc-label" for="tracking_reassign_technician_id">Réaffecter le RDV</label>
                        <select id="tracking_reassign_technician_id" class="gc-input">
                            @foreach ($technicians as $technician)
                                @php
                                    $departmentCode = $technician->department_code ?: '--';
                                    $technicianLabel = trim($technician->last_name.' '.$technician->first_name).' ('.$departmentCode.')';
                                @endphp
                                <option
                                    value="{{ $technician->id }}"
                                    data-label="{{ $technicianLabel }}"
                                    data-service-ids="{{ $technician->services->pluck('id')->implode(',') }}"
                                >
                                    {{ $technicianLabel }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">Les techniciens incompatibles avec la prestation sont désactivés.</p>
                        <div id="tracking_reassign_status" class="mt-2 hidden text-sm"></div>
                        <div class="mt-3 flex justify-end">
                            <button id="tracking-reassign-btn" type="submit" class="gc-btn-primary">Réaffecter</button>
                        </div>
                    </form>
                </section>
            </div>

            <form id="tracking-comment-form" class="mt-4 rounded-xl border p-4" style="border-color:var(--gc-border);">
                <input id="tracking_detail_appointment_id" type="hidden" />
                <label class="gc-label" for="tracking_detail_comment">Commentaire</label>
                <textarea id="tracking_detail_comment" rows="5" class="gc-input" style="min-height:130px;" placeholder="Ajouter ou modifier le commentaire du RDV"></textarea>
                <div id="tracking_comment_status" class="mt-2 hidden text-sm"></div>
                <div class="mt-3 flex flex-wrap justify-end gap-2">
                    <button id="tracking-delete-appointment-btn" type="button" class="gc-btn-danger">Soft delete le RDV</button>
                    <button id="tracking-restore-appointment-btn" type="button" class="gc-btn-soft hidden">Réactiver le RDV</button>
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
        const trackingServiceFilter = document.getElementById('tracking_service_filter');
        const trackingStatusFilter = document.getElementById('tracking_status_filter');
        const trackingResetFilters = document.getElementById('tracking-reset-filters');
        const trackingAppointmentModal = document.getElementById('tracking-appointment-modal');
        const trackingDetailsForm = document.getElementById('tracking-details-form');
        const trackingCommentForm = document.getElementById('tracking-comment-form');
        const trackingReassignForm = document.getElementById('tracking-reassign-form');
        const trackingReassignSelect = document.getElementById('tracking_reassign_technician_id');
        const trackingReassignStatus = document.getElementById('tracking_reassign_status');
        const trackingReassignButton = document.getElementById('tracking-reassign-btn');
        const trackingReassignOptions = Array.from(trackingReassignSelect?.querySelectorAll('option') || []);
        const trackingCommentUrlTemplate = @json(route('planner.tracking.appointments.comment', ['appointment' => '__APPOINTMENT__']));
        const trackingDetailsUrlTemplate = @json(route('planner.tracking.appointments.details', ['appointment' => '__APPOINTMENT__']));
        const trackingReassignUrlTemplate = @json(route('planner.tracking.appointments.technician', ['appointment' => '__APPOINTMENT__']));
        const trackingDestroyUrlTemplate = @json(route('planner.tracking.appointments.destroy', ['appointment' => '__APPOINTMENT__']));
        const trackingRestoreUrlTemplate = @json(route('planner.tracking.appointments.restore', ['appointment' => '__APPOINTMENT__']));
        const trackingEventsUrl = @json(route('planner.tracking.events'));
        const trackingCsrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const trackingMapboxToken = @json($mapboxToken ?? null);
        const trackingCalendarLoading = document.getElementById('tracking-calendar-loading');
        const trackingCalendarLoadingDetail = document.getElementById('tracking-calendar-loading-detail');
        const trackingCalendarProgressBar = document.getElementById('tracking-calendar-progress-bar');
        const trackingEventsBatchSize = 80;
        const routeColors = [
            '#1d4ed8', '#047857', '#b91c1c', '#7e22ce', '#0f766e', '#c2410c', '#4338ca', '#be123c',
            '#0369a1', '#15803d', '#a21caf', '#b45309', '#0e7490', '#6d28d9', '#9f1239', '#166534',
            '#1e40af', '#854d0e', '#0f766e', '#7f1d1d', '#4c1d95', '#155e75', '#365314', '#991b1b',
            '#2563eb', '#059669', '#dc2626', '#9333ea', '#0891b2', '#ea580c', '#4f46e5', '#e11d48',
            '#0284c7', '#16a34a', '#c026d3', '#ca8a04', '#0d9488', '#8b5cf6', '#f43f5e', '#22c55e',
            '#3b82f6', '#10b981', '#ef4444', '#a855f7', '#06b6d4', '#f97316', '#6366f1', '#f59e0b',
            '#1e3a8a', '#064e3b', '#7f1d1d', '#581c87', '#164e63', '#7c2d12', '#312e81', '#881337',
            '#075985', '#14532d', '#701a75', '#713f12', '#134e4a', '#5b21b6', '#9d174d', '#166534',
        ];
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
        let trackingDetailMapRenderRequestId = 0;
        let trackingEventsAbortController = null;
        let trackingEventsRequestId = 0;
        let trackingAppointmentTooltip = null;

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

        const formatDateTimeLocalInput = (value) => {
            if (!value) return '';

            const date = new Date(value);
            const offsetDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));

            return offsetDate.toISOString().slice(0, 16);
        };

        const setText = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value || '-';
        };

        const setTrackingReassignStatus = (message, color = '#0f766e') => {
            if (!trackingReassignStatus) return;

            trackingReassignStatus.style.color = color;
            trackingReassignStatus.textContent = message;
            trackingReassignStatus.classList.toggle('hidden', !message);
        };

        const trackingEscapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const ensureTrackingAppointmentTooltip = () => {
            if (trackingAppointmentTooltip) {
                return trackingAppointmentTooltip;
            }

            trackingAppointmentTooltip = document.createElement('div');
            trackingAppointmentTooltip.style.cssText = [
                'position:fixed',
                'z-index:90',
                'display:none',
                'pointer-events:none',
                'max-width:360px',
                'border-radius:16px',
                'padding:12px 14px',
                'background:#31424c',
                'color:#ffffff',
                'box-shadow:0 18px 45px rgba(15,23,42,.28)',
                'font-size:12px',
                'line-height:1.45',
                'transform:translate(-50%, calc(-100% - 14px))',
            ].join(';');
            document.body.appendChild(trackingAppointmentTooltip);

            return trackingAppointmentTooltip;
        };

        const moveTrackingAppointmentTooltip = (event) => {
            const tooltip = ensureTrackingAppointmentTooltip();
            const safeLeft = Math.min(Math.max(event.clientX, 180), window.innerWidth - 180);
            const safeTop = Math.max(event.clientY, 120);

            tooltip.style.left = `${safeLeft}px`;
            tooltip.style.top = `${safeTop}px`;
        };

        const trackingAppointmentServiceType = (props) => (
            props.service_type
            || String(props.service_label || 'RDV').split(' - ')[0]
            || 'RDV'
        );

        const trackingAppointmentLocationLabel = (props) => {
            const postalCity = [props.postal_code, props.city]
                .filter(Boolean)
                .join(' ');

            return postalCity || props.location_label || props.address || 'Lieu non renseigné';
        };

        const showTrackingAppointmentTooltip = (mouseEvent, calendarEvent) => {
            const props = calendarEvent.extendedProps || {};
            const tooltip = ensureTrackingAppointmentTooltip();
            const serviceType = trackingAppointmentServiceType(props);
            const location = trackingAppointmentLocationLabel(props);
            const timeRange = [formatDateTime(calendarEvent.start), formatDateTime(calendarEvent.end)]
                .filter((value) => value && value !== '-')
                .join(' → ');

            tooltip.innerHTML = `
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <p style="font-weight:800;letter-spacing:.02em;">${trackingEscapeHtml(serviceType)}</p>
                        ${props.deleted_at ? '<span style="border-radius:9999px;background:rgba(254,226,226,.18);color:#fecdd3;padding:3px 8px;font-size:11px;font-weight:700;">Désactivé</span>' : ''}
                    </div>
                    <div style="margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:10px;">
                        <p style="color:rgba(255,255,255,.68);font-size:11px;text-transform:uppercase;letter-spacing:.08em;">Client</p>
                        <p style="margin-top:2px;font-weight:700;">${trackingEscapeHtml(props.customer_name || calendarEvent.title || '-')}</p>
                    </div>
                    <div style="margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:10px;">
                        <p style="color:rgba(255,255,255,.68);font-size:11px;text-transform:uppercase;letter-spacing:.08em;">Code postal / ville</p>
                        <p style="margin-top:2px;font-weight:700;">${trackingEscapeHtml(location)}</p>
                    </div>
                    <div style="margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:10px;">
                        <p style="color:rgba(255,255,255,.68);font-size:11px;text-transform:uppercase;letter-spacing:.08em;">Type de RDV</p>
                        <p style="margin-top:2px;font-weight:700;">${trackingEscapeHtml(props.service_label || serviceType)}</p>
                    </div>
                    ${timeRange ? `<p style="margin-top:10px;color:rgba(255,255,255,.78);">${trackingEscapeHtml(timeRange)}</p>` : ''}
                </div>
            `;
            tooltip.style.display = 'block';
            moveTrackingAppointmentTooltip(mouseEvent);
        };

        const hideTrackingAppointmentTooltip = () => {
            if (trackingAppointmentTooltip) {
                trackingAppointmentTooltip.style.display = 'none';
            }
        };

        const setTrackingDetailsStatus = (message, color = '#0f766e') => {
            const status = document.getElementById('tracking_details_status');
            if (!status) return;

            status.style.color = color;
            status.textContent = message;
            status.classList.toggle('hidden', !message);
        };

        const initTrackingDetailAddressAutocomplete = () => {
            const addressInput = document.getElementById('tracking_detail_address_input');
            const latInput = document.getElementById('tracking_detail_latitude');
            const lngInput = document.getElementById('tracking_detail_longitude');

            if (!addressInput || !latInput || !lngInput || !trackingMapboxToken) return;

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
                latInput.value = '';
                lngInput.value = '';

                const query = addressInput.value.trim();
                if (query.length < 3) {
                    list.innerHTML = '';
                    list.classList.add('hidden');
                    return;
                }

                debounceTimer = window.setTimeout(async () => {
                    const url = new URL(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json`);
                    url.searchParams.set('access_token', trackingMapboxToken);
                    url.searchParams.set('autocomplete', 'true');
                    url.searchParams.set('language', 'fr');
                    url.searchParams.set('country', 'fr');
                    url.searchParams.set('limit', '5');

                    try {
                        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                        const payload = await response.json();
                        const features = Array.isArray(payload.features) ? payload.features : [];

                        list.innerHTML = features.map((feature) => `
                            <button type="button" class="gc-mapbox-item" data-address="${trackingEscapeHtml(feature.place_name || '')}" data-lng="${feature.center?.[0] ?? ''}" data-lat="${feature.center?.[1] ?? ''}">
                                ${trackingEscapeHtml(feature.place_name || '')}
                            </button>
                        `).join('');
                        list.classList.toggle('hidden', features.length === 0);

                        list.querySelectorAll('.gc-mapbox-item').forEach((item) => {
                            item.addEventListener('click', () => {
                                addressInput.value = item.dataset.address || '';
                                lngInput.value = item.dataset.lng || '';
                                latInput.value = item.dataset.lat || '';
                                list.innerHTML = '';
                                list.classList.add('hidden');
                                window.TechCalendarForms?.refresh(addressInput.form);
                            });
                        });
                    } catch (error) {
                        list.innerHTML = '';
                        list.classList.add('hidden');
                    }
                }, 250);
            });
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

            if (!trackingDetailMap || !trackingDetailMap.loaded()) return;

            [...trackingDetailMap.getStyle().layers]
                .filter((layer) => layer.id.startsWith('tracking-detail-route'))
                .forEach((layer) => trackingDetailMap.removeLayer(layer.id));

            Object.keys(trackingDetailMap.getStyle().sources)
                .filter((source) => source.startsWith('tracking-detail-route'))
                .forEach((source) => trackingDetailMap.removeSource(source));
        };

        const fetchTrackingRoute = async (origin, destination) => {
            const fallbackDistanceKm = trackingHaversineDistanceKm(origin.lat, origin.lng, destination.lat, destination.lng);
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
                source: 'estimation interne',
            };

            if (!trackingMapboxToken) return fallback;

            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${origin.lng},${origin.lat};${destination.lng},${destination.lat}`);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');
            url.searchParams.set('access_token', trackingMapboxToken);

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
                    source: 'Mapbox voiture',
                } : fallback;
            } catch (error) {
                return fallback;
            }
        };

        const setTrackingRouteSummary = (message) => {
            const summary = document.getElementById('tracking_route_summary');
            if (summary) summary.textContent = message;
        };

        const formatTrackingRouteDuration = (minutes) => {
            const safeMinutes = Math.max(0, Math.round(Number(minutes || 0)));
            const hours = Math.floor(safeMinutes / 60);
            const remainingMinutes = safeMinutes % 60;

            if (hours === 0) return `${remainingMinutes} min`;

            return remainingMinutes > 0 ? `${hours}h${String(remainingMinutes).padStart(2, '0')}` : `${hours}h`;
        };

        const trackingHaversineDistanceKm = (fromLat, fromLng, toLat, toLng) => {
            const earthRadiusKm = 6371;
            const toRadians = (value) => value * Math.PI / 180;
            const latDelta = toRadians(toLat - fromLat);
            const lngDelta = toRadians(toLng - fromLng);
            const a = Math.sin(latDelta / 2) ** 2
                + Math.cos(toRadians(fromLat)) * Math.cos(toRadians(toLat)) * Math.sin(lngDelta / 2) ** 2;

            return earthRadiusKm * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
        };

        const setTrackingDayRouteSummary = (content) => {
            const summary = document.getElementById('tracking_day_route_summary');
            if (summary) summary.innerHTML = content;
        };

        const trackingRouteNumber = (value) => value === null || value === undefined || value === '' ? NaN : Number(value);

        const validTrackingPoint = (point) => Number.isFinite(point?.lat) && Number.isFinite(point?.lng);

        const sameTrackingDay = (leftDate, rightDate) => leftDate?.toDateString?.() === rightDate?.toDateString?.();

        const sameTrackingEvent = (left, right) => {
            if (!left || !right) return false;
            if (left === right) return true;

            return String(left.id || '') !== '' && String(left.id) === String(right.id || '');
        };

        const updateTrackingReassignButtonState = () => {
            if (!trackingReassignButton || !trackingReassignSelect) return;

            const selectedOption = trackingReassignSelect.selectedOptions[0];
            const currentTechnicianId = trackingReassignSelect.dataset.currentTechnicianId || '';
            const selectedTechnicianId = trackingReassignSelect.value || '';

            trackingReassignButton.disabled = !selectedTechnicianId
                || selectedTechnicianId === currentTechnicianId
                || Boolean(selectedOption?.disabled);
        };

        const updateTrackingReassignOptions = (serviceId, currentTechnicianId) => {
            if (!trackingReassignSelect) return;

            const normalizedServiceId = String(serviceId || '');
            const normalizedCurrentTechnicianId = String(currentTechnicianId || '');

            trackingReassignSelect.dataset.currentTechnicianId = normalizedCurrentTechnicianId;
            trackingReassignSelect.dataset.serviceId = normalizedServiceId;

            trackingReassignOptions.forEach((option) => {
                const baseLabel = option.dataset.label || option.textContent;
                const optionServiceIds = String(option.dataset.serviceIds || '')
                    .split(',')
                    .map((value) => value.trim())
                    .filter(Boolean);
                const isCurrentTechnician = option.value === normalizedCurrentTechnicianId;
                const coversService = !normalizedServiceId || optionServiceIds.includes(normalizedServiceId);

                option.disabled = !isCurrentTechnician && !coversService;
                option.textContent = `${baseLabel}${isCurrentTechnician ? ' (actuel)' : (!coversService ? ' (prestation non couverte)' : '')}`;
            });

            trackingReassignSelect.value = normalizedCurrentTechnicianId;
            updateTrackingReassignButtonState();
        };

        const trackingTechnicianHomePoint = (props) => ({
            kind: 'home',
            lat: trackingRouteNumber(props.technician_latitude),
            lng: trackingRouteNumber(props.technician_longitude),
            name: props.technician_address || 'Domicile',
            label: 'Domicile',
        });

        const trackingAppointmentPointFromEvent = (event, currentEvent) => {
            const props = event.extendedProps || {};

            return {
                kind: 'appointment',
                lat: trackingRouteNumber(props.latitude),
                lng: trackingRouteNumber(props.longitude),
                name: props.customer_name || props.service_label || event.title || 'RDV',
                label: 'RDV',
                event,
                isCurrent: sameTrackingEvent(event, currentEvent),
            };
        };

        const sameDayTrackingAppointments = (currentEvent) => {
            const props = currentEvent.extendedProps || {};
            const events = (trackingCalendar ? trackingCalendar.getEvents() : []).filter((event) => {
                const eventProps = event.extendedProps || {};

                return String(eventProps.technician_id || '') === String(props.technician_id || '')
                    && sameTrackingDay(event.start, currentEvent.start)
                    && (!eventProps.deleted_at || sameTrackingEvent(event, currentEvent))
                    && Number.isFinite(trackingRouteNumber(eventProps.latitude))
                    && Number.isFinite(trackingRouteNumber(eventProps.longitude));
            });

            if (!events.some((event) => sameTrackingEvent(event, currentEvent))) {
                events.push(currentEvent);
            }

            return Array.from(new Map(events.map((event) => [String(event.id), event])).values())
                .filter((event) => event.start)
                .sort((left, right) => left.start - right.start);
        };

        const buildTrackingDayRouteSegments = (event) => {
            const props = event.extendedProps || {};
            const home = trackingTechnicianHomePoint(props);

            if (!validTrackingPoint(home)) return [];

            const appointmentPoints = sameDayTrackingAppointments(event)
                .map((appointmentEvent) => trackingAppointmentPointFromEvent(appointmentEvent, event))
                .filter(validTrackingPoint);

            if (appointmentPoints.length === 0) return [];

            const points = [
                home,
                ...appointmentPoints,
                { ...home, label: 'Retour domicile', isReturnHome: true },
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
                        : (from.isCurrent ? 'Suite de journée' : (to.kind === 'home' ? 'Retour domicile' : 'Autre trajet')),
                };
            });
        };

        const trackingFallbackCurrentSegment = (event) => {
            const props = event.extendedProps || {};
            const origin = {
                kind: 'origin',
                lat: trackingRouteNumber(props.origin_latitude),
                lng: trackingRouteNumber(props.origin_longitude),
                name: props.origin_name || props.origin_label || 'Origine',
                label: props.origin_label || 'Origine',
            };
            const destination = {
                kind: 'appointment',
                lat: trackingRouteNumber(props.latitude),
                lng: trackingRouteNumber(props.longitude),
                name: props.customer_name || event.title || 'RDV',
                label: 'RDV',
                isCurrent: true,
            };

            return validTrackingPoint(origin) && validTrackingPoint(destination)
                ? [{ from: origin, to: destination, isCurrent: true, badge: 'Trajet vers ce RDV' }]
                : [];
        };

        const enrichTrackingDaySegments = async (segments, renderRequestId) => {
            const enrichedSegments = [];

            for (const segment of segments) {
                const route = await fetchTrackingRoute(segment.from, segment.to);
                if (renderRequestId !== trackingDetailMapRenderRequestId) return null;
                enrichedSegments.push({ ...segment, route });
            }

            return enrichedSegments;
        };

        const renderTrackingDayRouteSummary = (segments, isLoading = false, activeIndex = null, onSelect = null) => {
            if (isLoading) {
                setTrackingDayRouteSummary(`
                    <div class="flex items-center justify-between gap-3">
                        <span style="color:var(--gc-text-soft);">Calcul de la journée du technicien...</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">En cours</span>
                    </div>
                `);
                return;
            }

            if (!segments || segments.length === 0) {
                setTrackingDayRouteSummary('<span style="color:var(--gc-text-soft);">Journée du technicien indisponible pour ce RDV.</span>');
                return;
            }

            const defaultActiveIndex = segments.findIndex((segment) => segment.isCurrent);
            const safeActiveIndex = Number.isInteger(activeIndex) && activeIndex >= 0 && activeIndex < segments.length
                ? activeIndex
                : Math.max(0, defaultActiveIndex);

            setTrackingDayRouteSummary(`
                <div class="mb-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Journée du technicien</p>
                    <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Clique une ligne pour mettre son trajet en couleur. Les autres restent en pointillé.</p>
                </div>
                <div class="space-y-2">
                    ${segments.map((segment, index) => {
                        const isActive = index === safeActiveIndex;

                        return `
                        <button type="button" data-tracking-day-segment-index="${index}" class="flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition hover:shadow-sm" style="border-color:${isActive ? 'var(--gc-accent)' : 'transparent'};background:${isActive ? 'var(--gc-accent-soft)' : '#f8fafc'};">
                            <div class="min-w-0">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background:${isActive ? '#ffffff' : 'var(--gc-accent-soft)'};color:var(--gc-text);">${trackingEscapeHtml(segment.badge)}</span>
                                <p class="mt-1 truncate font-medium">${trackingEscapeHtml(segment.from.name)} → ${trackingEscapeHtml(segment.to.name)}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="font-semibold">${Number(segment.route?.distance_km || 0).toFixed(1)} km</p>
                                <p class="text-xs" style="color:var(--gc-text-soft);">${formatTrackingRouteDuration(segment.route?.duration_minutes)}</p>
                            </div>
                        </button>
                    `;
                    }).join('')}
                </div>
            `);

            document.querySelectorAll('[data-tracking-day-segment-index]').forEach((button) => {
                button.addEventListener('click', () => {
                    onSelect?.(Number(button.dataset.trackingDaySegmentIndex));
                });
            });
        };

        const renderTrackingDetailMap = async (event) => {
            const renderRequestId = ++trackingDetailMapRenderRequestId;
            const props = event.extendedProps;
            const map = ensureTrackingDetailMap();
            let segments = buildTrackingDayRouteSegments(event);

            setText('tracking_route_origin', props.origin_label ? `Depart: ${props.origin_label} - ${props.origin_name || '-'}` : null);

            if (!map) {
                setTrackingRouteSummary('Trajet non affiché.');
                renderTrackingDayRouteSummary([]);
                return;
            }

            if (segments.length === 0) {
                segments = trackingFallbackCurrentSegment(event);
            }

            if (segments.length === 0) {
                clearTrackingDetailMap();
                setTrackingRouteSummary('Coordonnées du RDV indisponibles.');
                renderTrackingDayRouteSummary([]);
                return;
            }

            if (!map.loaded()) {
                map.once('load', () => renderTrackingDetailMap(event));
                return;
            }

            clearTrackingDetailMap();
            setTrackingRouteSummary('Calcul du trajet...');
            renderTrackingDayRouteSummary([], true);

            const enrichedSegments = await enrichTrackingDaySegments(segments, renderRequestId);
            if (!enrichedSegments || renderRequestId !== trackingDetailMapRenderRequestId) return;

            const defaultActiveIndex = Math.max(0, enrichedSegments.findIndex((segment) => segment.isCurrent));

            const renderSelectedSegment = (activeSegmentIndex = defaultActiveIndex) => {
                if (renderRequestId !== trackingDetailMapRenderRequestId) return;

                const safeActiveIndex = Number.isInteger(activeSegmentIndex) && activeSegmentIndex >= 0 && activeSegmentIndex < enrichedSegments.length
                    ? activeSegmentIndex
                    : defaultActiveIndex;
                const activeSegment = enrichedSegments[safeActiveIndex] || enrichedSegments[0];

                setTrackingRouteSummary(`${Number(activeSegment.route?.distance_km || 0).toFixed(1)} km en voiture - environ ${formatTrackingRouteDuration(activeSegment.route?.duration_minutes)}.`);
                renderTrackingDayRouteSummary(enrichedSegments, false, safeActiveIndex, (selectedIndex) => {
                    renderSelectedSegment(selectedIndex);
                });

                clearTrackingDetailMap();

                const colors = selectedTechnicianColorMap();
                const color = colors[Number(props.technician_id)] || '#31424c';
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

                trackingDetailMarkers.push(new window.mapboxgl.Marker({ element: trackingMarkerElement(color, 'D') })
                    .setLngLat([enrichedSegments[0].from.lng, enrichedSegments[0].from.lat])
                    .addTo(map));
                bounds.extend([enrichedSegments[0].from.lng, enrichedSegments[0].from.lat]);

                enrichedSegments.forEach((segment) => {
                    [segment.from, segment.to].forEach((point) => bounds.extend([point.lng, point.lat]));
                    (segment.route?.feature?.geometry?.coordinates || []).forEach((coordinate) => bounds.extend(coordinate));

                    if (segment.to.kind === 'appointment') {
                        const key = segment.to.event?.id ? String(segment.to.event.id) : `${segment.to.lng},${segment.to.lat}`;

                        if (!appointmentMarkerKeys.has(key)) {
                            appointmentMarkerKeys.add(key);
                            trackingDetailMarkers.push(new window.mapboxgl.Marker({
                                element: trackingMarkerElement(segment.to.isCurrent ? '#31424c' : color, segment.to.isCurrent ? 'R' : String(appointmentMarkerKeys.size)),
                            })
                                .setLngLat([segment.to.lng, segment.to.lat])
                                .addTo(map));
                        }
                    }
                });

                orderedSegments.forEach((segment, index) => {
                    const sourceId = `tracking-detail-route-${index}`;

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
                            'line-color': segment.isHighlighted ? color : '#64748b',
                            'line-width': segment.isHighlighted ? 5 : 3,
                            'line-opacity': segment.isHighlighted ? 0.9 : 0.5,
                            ...(segment.isHighlighted ? {} : { 'line-dasharray': [1.5, 2.2] }),
                        },
                    });
                });

                map.fitBounds(bounds, {
                    padding: 64,
                    maxZoom: 13,
                    duration: 0,
                });
                map.resize();
            };

            renderSelectedSegment();
        };

        const closeTrackingAppointmentModal = () => {
            trackingDetailMapRenderRequestId++;
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
            document.getElementById('tracking_detail_starts_at').value = formatDateTimeLocalInput(event.start);
            document.getElementById('tracking_detail_duration_minutes').value = props.duration_minutes || '';
            document.getElementById('tracking_detail_address_input').value = props.address || '';
            document.getElementById('tracking_detail_latitude').value = props.latitude || '';
            document.getElementById('tracking_detail_longitude').value = props.longitude || '';
            setTrackingDetailsStatus('');
            updateTrackingReassignOptions(props.service_id, props.technician_id);
            setTrackingReassignStatus('');
            trackingInitialComment = props.comment || '';
            document.getElementById('tracking_detail_comment').value = trackingInitialComment;
            document.getElementById('tracking_comment_status').classList.add('hidden');
            document.getElementById('tracking-delete-appointment-btn').classList.toggle('hidden', Boolean(props.deleted_at));
            document.getElementById('tracking-restore-appointment-btn').classList.toggle('hidden', !props.deleted_at);
            trackingAppointmentModal.classList.remove('hidden');
            initTrackingDetailAddressAutocomplete();
            window.TechCalendarForms?.refresh(trackingDetailsForm);
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
            trackingCalendarLoadingDetail.textContent = `${technicianCount} technicien(s), ${chunkCount} paquet(s) à chargér.`;
            trackingCalendarLoading.classList.remove('hidden');
            trackingCalendarLoading.classList.add('flex');
        };

        const updateTrackingCalendarLoader = (loadedChunks, chunkCount, eventCount) => {
            const progress = chunkCount > 0 ? Math.round((loadedChunks / chunkCount) * 100) : 100;
            trackingCalendarProgressBar.style.width = `${progress}%`;
            trackingCalendarLoadingDetail.textContent = `Paquet ${loadedChunks}/${chunkCount} chargé - ${eventCount} RDV trouvé(s).`;
        };

        const hideTrackingCalendarLoader = () => {
            trackingCalendarLoading.classList.add('hidden');
            trackingCalendarLoading.classList.remove('flex');
        };

        const trackingCalendarFilters = () => ({
            service_id: trackingServiceFilter?.value || null,
            appointment_status: trackingStatusFilter?.value || 'all',
        });

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
                    ...trackingCalendarFilters(),
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

        const generatedTechnicianColor = (index) => {
            const hue = Math.round((index * 137.508) % 360);

            return `hsl(${hue} 74% 34%)`;
        };

        const trackingColorToRgb = (color) => {
            if (!color) return null;

            if (color.startsWith('#')) {
                const hex = color.replace('#', '');
                const normalized = hex.length === 3
                    ? hex.split('').map((part) => part + part).join('')
                    : hex;

                return {
                    r: parseInt(normalized.slice(0, 2), 16),
                    g: parseInt(normalized.slice(2, 4), 16),
                    b: parseInt(normalized.slice(4, 6), 16),
                };
            }

            const hslMatch = color.match(/hsl\((\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)%\s+(\d+(?:\.\d+)?)%\)/);
            if (!hslMatch) return null;

            const h = Number(hslMatch[1]) / 360;
            const s = Number(hslMatch[2]) / 100;
            const l = Number(hslMatch[3]) / 100;
            const hueToRgb = (p, q, t) => {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1 / 6) return p + (q - p) * 6 * t;
                if (t < 1 / 2) return q;
                if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;

                return p;
            };

            if (s === 0) {
                const grey = Math.round(l * 255);

                return { r: grey, g: grey, b: grey };
            }

            const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            const p = 2 * l - q;

            return {
                r: Math.round(hueToRgb(p, q, h + 1 / 3) * 255),
                g: Math.round(hueToRgb(p, q, h) * 255),
                b: Math.round(hueToRgb(p, q, h - 1 / 3) * 255),
            };
        };

        const trackingTextColorForBackground = (backgroundColor) => {
            const rgb = trackingColorToRgb(backgroundColor);
            if (!rgb) return '#ffffff';

            const luminance = ((0.299 * rgb.r) + (0.587 * rgb.g) + (0.114 * rgb.b)) / 255;

            return luminance > 0.58 ? '#17202a' : '#ffffff';
        };

        const trackingTechnicianColorMap = () => {
            const colors = {};
            const usedColors = new Set();

            technicianCheckboxes.forEach((checkbox, index) => {
                let color = routeColors[index] || generatedTechnicianColor(index);
                let fallbackIndex = index;

                while (usedColors.has(color)) {
                    fallbackIndex += routeColors.length;
                    color = generatedTechnicianColor(fallbackIndex);
                }

                usedColors.add(color);
                colors[Number(checkbox.value)] = color;
            });

            return colors;
        };

        const trackingTechnicianThemeMap = () => {
            const colors = trackingTechnicianColorMap();

            return Object.fromEntries(Object.entries(colors).map(([id, backgroundColor]) => [
                id,
                {
                    backgroundColor,
                    borderColor: backgroundColor,
                    textColor: trackingTextColorForBackground(backgroundColor),
                },
            ]));
        };

        const selectedTechnicianColorMap = () => trackingTechnicianColorMap();

        const updateLegend = () => {
            const themes = trackingTechnicianThemeMap();
            const selectedCheckboxes = technicianCheckboxes.filter((checkbox) => checkbox.checked);
            selectedTechCount.textContent = selectedCheckboxes.length;
            calendarHelper.textContent = selectedCheckboxes.length > 0
                ? 'Le calendrier affiche uniquement les techniciens cochés.'
                : 'Coche au moins un technicien pour afficher ses rendez-vous.';

            calendarLegend.innerHTML = selectedCheckboxes.map((checkbox) => `
                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold shadow-sm" style="border-color:${themes[Number(checkbox.value)]?.borderColor || 'var(--gc-border)'};background:${themes[Number(checkbox.value)]?.backgroundColor || '#31424c'};color:${themes[Number(checkbox.value)]?.textColor || '#ffffff'};">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:9999px;background:currentColor;opacity:.9;"></span>
                    ${trackingEscapeHtml(checkbox.dataset.name)}
                </span>
            `).join('');
        };

        const styleTrackingEvent = (event) => {
            const isDeleted = Boolean(event.extendedProps?.deleted_at);
            const themes = trackingTechnicianThemeMap();
            const theme = themes[event.extendedProps?.technician_id] || {
                backgroundColor: '#31424c',
                borderColor: '#31424c',
                textColor: '#ffffff',
            };

            return {
                ...event,
                backgroundColor: isDeleted ? 'rgba(190,18,60,0.14)' : theme.backgroundColor,
                borderColor: isDeleted ? '#be123c' : theme.borderColor,
                textColor: isDeleted ? '#7f1d1d' : theme.textColor,
                classNames: isDeleted ? ['appointment-soft-deleted'] : [],
            };
        };

        const applyTrackingEventStyle = (event) => {
            const isDeleted = Boolean(event.extendedProps?.deleted_at);
            const themes = trackingTechnicianThemeMap();
            const theme = themes[event.extendedProps?.technician_id] || {
                backgroundColor: '#31424c',
                borderColor: '#31424c',
                textColor: '#ffffff',
            };

            event.setProp('backgroundColor', isDeleted ? 'rgba(190,18,60,0.14)' : theme.backgroundColor);
            event.setProp('borderColor', isDeleted ? '#be123c' : theme.borderColor);
            event.setProp('textColor', isDeleted ? '#7f1d1d' : theme.textColor);
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
                slotMinTime: '07:00:00',
                slotMaxTime: '21:00:00',
                height: 780,
                nowIndicator: true,
                hiddenDays: [0],
                allDaySlot: false,
	                events: async (fetchInfo, succèssCallback, failureCallback) => {
	                    const technicianIds = selectedTechnicianIds();
	
	                    if (technicianIds.length === 0) {
	                        trackingEventsAbortController?.abort();
	                        trackingEventsRequestId++;
	                        hideTrackingCalendarLoader();
	                        succèssCallback([]);
	                        return;
	                    }
	
	                    try {
	                        const events = await loadTrackingEventsInBatches(technicianIds, fetchInfo);

	                        if (events === null) {
	                            succèssCallback([]);
	                            return;
	                        }

	                        succèssCallback(events.map(styleTrackingEvent));
	                    } catch (error) {
	                        if (error.name === 'AbortError') {
	                            succèssCallback([]);
	                            return;
	                        }

	                        trackingCalendarLoadingDetail.textContent = error.message || 'Impossible de chargér le calendrier.';
	                        failureCallback(error);
	                    }
	                },
                eventContent: (arg) => {
                    const props = arg.event.extendedProps || {};
                    const time = arg.timeText ? `<span class="font-semibold">${trackingEscapeHtml(arg.timeText)}</span>` : '';
                    const serviceType = trackingAppointmentServiceType(props);
                    const location = trackingAppointmentLocationLabel(props);

                    return {
                        html: `
                            <div class="min-w-0 leading-tight">
                                <div class="truncate text-[11px]">${time} ${trackingEscapeHtml(serviceType)}</div>
                                <div class="truncate text-[11px] font-semibold">${trackingEscapeHtml(props.customer_name || arg.event.title)}</div>
                                <div class="truncate text-[10px] opacity-90">${trackingEscapeHtml(location)}</div>
                            </div>
                        `,
                    };
                },
                eventDidMount: (info) => {
	                    const props = info.event.extendedProps;
	                    if (props.deleted_at) {
	                        info.el.style.opacity = '0.72';
                        info.el.style.borderWidth = '2px';
                    }

                    info.el.setAttribute('aria-label', [
                        props.deleted_at ? 'RDV soft-deleted' : null,
                        props.service_label,
                        props.customer_name,
                        props.customer_phone,
                        props.address,
	                        props.created_by_name ? `Créé par: ${props.created_by_name}` : null,
                        props.comment,
                    ].filter(Boolean).join(' - '));
                },
                eventMouseEnter: (info) => {
                    const moveHandler = (event) => moveTrackingAppointmentTooltip(event);

                    info.el._trackingAppointmentTooltipMoveHandler = moveHandler;
                    info.el.addEventListener('mousemove', moveHandler);
                    showTrackingAppointmentTooltip(info.jsEvent, info.event);
                },
                eventMouseLeave: (info) => {
                    const moveHandler = info.el._trackingAppointmentTooltipMoveHandler;

                    if (moveHandler) {
                        info.el.removeEventListener('mousemove', moveHandler);
                        delete info.el._trackingAppointmentTooltipMoveHandler;
                    }

                    hideTrackingAppointmentTooltip();
                },
                eventClick: (info) => {
                    info.jsEvent.preventDefault();
                    hideTrackingAppointmentTooltip();
                    openTrackingAppointmentModal(info.event);
                },
            });

            trackingCalendar.render();
        };

        technicianFilterInput.addEventListener('input', applyTechnicianFilter);
        technicianCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', refetchCalendar));
        trackingServiceFilter?.addEventListener('change', refetchCalendar);
        trackingStatusFilter?.addEventListener('change', refetchCalendar);
        trackingResetFilters?.addEventListener('click', () => {
            if (trackingServiceFilter) trackingServiceFilter.value = '';
            if (trackingStatusFilter) trackingStatusFilter.value = 'all';
            refetchCalendar();
        });
        trackingReassignSelect?.addEventListener('change', updateTrackingReassignButtonState);

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

        trackingDetailsForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const appointmentId = document.getElementById('tracking_detail_appointment_id').value;
            const startsAt = document.getElementById('tracking_detail_starts_at').value;
            const durationMinutes = document.getElementById('tracking_detail_duration_minutes').value;
            const address = document.getElementById('tracking_detail_address_input').value.trim();
            const latitude = document.getElementById('tracking_detail_latitude').value;
            const longitude = document.getElementById('tracking_detail_longitude').value;
            const button = document.getElementById('tracking-save-details-btn');

            if (!appointmentId) return;

            button.disabled = true;
            button.textContent = 'Enregistrement...';
            setTrackingDetailsStatus('');

            try {
                const response = await fetch(trackingDetailsUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': trackingCsrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        starts_at: startsAt,
                        duration_minutes: Number(durationMinutes),
                        address,
                        latitude: latitude === '' ? null : Number(latitude),
                        longitude: longitude === '' ? null : Number(longitude),
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : payload?.message;
                    throw new Error(firstError || 'Impossible de modifier le RDV.');
                }

                const updatedAppointment = payload.appointment || {};
                const calendarEvent = trackingCalendar?.getEventById(appointmentId);

                if (calendarEvent) {
                    calendarEvent.setStart(updatedAppointment.start);
                    calendarEvent.setEnd(updatedAppointment.end);
                    calendarEvent.setExtendedProp('duration_minutes', updatedAppointment.duration_minutes);
                    calendarEvent.setExtendedProp('address', updatedAppointment.address);
                    calendarEvent.setExtendedProp('latitude', updatedAppointment.latitude);
                    calendarEvent.setExtendedProp('longitude', updatedAppointment.longitude);
                    calendarEvent.setExtendedProp('postal_code', updatedAppointment.postal_code);
                    calendarEvent.setExtendedProp('city', updatedAppointment.city);
                    calendarEvent.setExtendedProp('location_label', updatedAppointment.location_label);
                    setText('tracking_detail_duration', updatedAppointment.duration_minutes ? `${updatedAppointment.duration_minutes} min` : '-');
                    setText('tracking_detail_start', formatDateTime(updatedAppointment.start));
                    setText('tracking_detail_end', formatDateTime(updatedAppointment.end));
                    setText('tracking_detail_address', updatedAppointment.address);
                    window.setTimeout(() => renderTrackingDetailMap(calendarEvent), 180);
                }

                setTrackingDetailsStatus('Rendez-vous mis à jour.');
                refetchCalendar();
            } catch (error) {
                setTrackingDetailsStatus(error.message || 'Impossible de modifier le RDV.', '#9f1239');
            } finally {
                button.disabled = false;
                button.textContent = 'Enregistrer les modifications';
            }
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

        trackingReassignForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const appointmentId = document.getElementById('tracking_detail_appointment_id').value;
            const technicianId = trackingReassignSelect?.value;

            if (!appointmentId || !technicianId || !trackingReassignButton) return;

            trackingReassignButton.disabled = true;
            trackingReassignButton.textContent = 'Reaffectation...';
            setTrackingReassignStatus('');

            try {
                const response = await fetch(trackingReassignUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ technician_id: Number(technicianId) }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : payload?.message;
                    throw new Error(firstError || 'Impossible de réaffecter le RDV.');
                }

                const technician = payload.technician || {};
                const targetCheckbox = technicianCheckboxes.find((checkbox) => String(checkbox.value) === String(technician.id));

                if (targetCheckbox && !targetCheckbox.checked) {
                    targetCheckbox.checked = true;
                }

                setText('tracking_detail_technician', technician.name);
                updateTrackingReassignOptions(trackingReassignSelect.dataset.serviceId, technician.id);

                const calendarEvent = trackingCalendar?.getEventById(appointmentId);
                if (calendarEvent) {
                    calendarEvent.setProp('title', `${technician.name || 'Technicien'} | ${calendarEvent.extendedProps.customer_name || ''}`);
                    calendarEvent.setExtendedProp('technician_id', technician.id);
                    calendarEvent.setExtendedProp('technician_name', technician.name);
                    calendarEvent.setExtendedProp('technician_address', technician.address);
                    calendarEvent.setExtendedProp('technician_latitude', technician.latitude);
                    calendarEvent.setExtendedProp('technician_longitude', technician.longitude);
                    calendarEvent.setExtendedProp('origin_latitude', technician.latitude);
                    calendarEvent.setExtendedProp('origin_longitude', technician.longitude);
                    calendarEvent.setExtendedProp('origin_name', technician.address || 'Domicile technicien');
                    calendarEvent.setExtendedProp('origin_label', 'Domicile');
                    applyTrackingEventStyle(calendarEvent);
                }

                setTrackingReassignStatus('Rendez-vous réaffecté.');
                refetchCalendar();

                if (calendarEvent) {
                    window.setTimeout(() => renderTrackingDetailMap(calendarEvent), 180);
                }
            } catch (error) {
                setTrackingReassignStatus(error.message || 'Impossible de réaffecter le RDV.', '#9f1239');
            } finally {
                trackingReassignButton.textContent = 'Réaffecter';
                updateTrackingReassignButtonState();
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
                status.textContent = 'Le commentaire doit être modifié avant de soft delete le RDV.';
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
                status.textContent = 'Un commentaire est obligatoire avant de réactiver le RDV.';
                status.classList.remove('hidden');
                return;
            }

            if (comment === trackingInitialComment.trim()) {
                status.style.color = '#9f1239';
                status.textContent = 'Le commentaire doit être modifié avant de réactiver le RDV.';
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
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Impossible de réactiver le RDV.';
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
                status.textContent = 'Rendez-vous réactivé.';
                status.classList.remove('hidden');
            } catch (error) {
                status.style.color = '#9f1239';
                status.textContent = error.message || 'Impossible de réactiver le RDV.';
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
