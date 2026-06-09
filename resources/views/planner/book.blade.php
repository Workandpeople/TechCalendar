<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Planning</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Prise de rdv</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Selectionne une demande CRM ou saisis un RDV manuel pour identifier les techniciens eligibles.</p>
            </div>
            <button id="manual-booking-toggle" type="button" class="gc-btn-primary self-start md:self-auto">
                RDV manuel
            </button>
        </div>

        <section id="booking-placement-confirmation" class="gc-card hidden overflow-hidden p-0">
            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div class="p-6">
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" style="background:#dcfce7;color:#15803d;">
                        Placement confirme
                    </span>
                    <h2 class="mt-4 text-2xl font-semibold" style="color:var(--gc-text);">Le rendez-vous a bien ete place</h2>
                    <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                        La prise de RDV est verrouillee sur cette page pour eviter un double placement accidentel.
                    </p>

                    <dl class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Reference</dt>
                            <dd id="booking_confirmation_reference" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Date</dt>
                            <dd id="booking_confirmation_date" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Client</dt>
                            <dd id="booking_confirmation_customer" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Technicien</dt>
                            <dd id="booking_confirmation_technician" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4 md:col-span-2" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Adresse</dt>
                            <dd id="booking_confirmation_address" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                    </dl>
                </div>

                <aside class="flex flex-col justify-between gap-6 p-6" style="background:linear-gradient(145deg,#31424c 0%,#1f2d35 100%);">
                    <div>
                        <p class="text-sm font-semibold" style="color:#f5df9a;">Prochaine action</p>
                        <p class="mt-2 text-sm leading-6 text-white/80">
                            Tu peux maintenant verifier le rendez-vous dans le suivi ou repartir sur une nouvelle prise de RDV propre.
                        </p>
                    </div>
                    <div class="flex flex-col gap-3">
                        <a id="booking-confirmation-track-link" href="{{ route('planner.tracking') }}" class="gc-btn-primary justify-center text-center">
                            Suivre le RDV
                        </a>
                        <button id="booking-confirmation-new" type="button" class="gc-btn-soft justify-center">
                            Placer un autre RDV
                        </button>
                    </div>
                </aside>
            </div>
        </section>

        <section id="crm-booking-section" class="gc-card p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV a placer depuis les CRM</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Le service est optionnel: s'il est absent, seuls les departements couverts filtrent les techniciens.</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm" style="background:var(--gc-accent-soft);color:var(--gc-text);">{{ $crmAppointments->count() }} demande(s)</span>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($crmAppointments as $appointment)
                    <button
                        type="button"
                        class="crm-appointment-card rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md"
                        style="border-color:var(--gc-border);background:linear-gradient(135deg,#ffffff 0%,#fcf8ea 100%);"
                        data-crm-id="{{ $appointment['id'] }}"
                    >
                        <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#e0f2fe;color:#1d4ed8;">{{ $appointment['source'] }}</span>
                        <h3 class="mt-3 font-semibold" style="color:var(--gc-text);">{{ $appointment['last_name'] }} {{ $appointment['first_name'] }}</h3>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $appointment['phone'] }}</p>
                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $appointment['address'] }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">Dept. {{ $appointment['department_code'] }}</span>
                            @if ($appointment['service'])
                                <span class="rounded-lg px-2 py-1 text-xs" style="background:#dcfce7;color:#15803d;">{{ $appointment['service']['type'] }}</span>
                            @else
                                <span class="rounded-lg px-2 py-1 text-xs" style="background:#fee2e2;color:#be123c;">Service non renseigne</span>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        </section>

        <section id="manual-booking-section" class="gc-card hidden p-5">
            <div class="mb-4">
                <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV manuel</h2>
                <p class="text-sm" style="color:var(--gc-text-soft);">Saisie rapide d'un client hors CRM. L'adresse doit etre selectionnee via Mapbox pour recuperer le departement et les coordonnees.</p>
            </div>

            <form id="manual-booking-form" class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_last_name">Nom client</label>
                    <input id="manual_last_name" type="text" class="gc-input" maxlength="120" required />
                </div>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_first_name">Prenom client</label>
                    <input id="manual_first_name" type="text" class="gc-input" maxlength="120" required />
                </div>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_phone">Telephone</label>
                    <input id="manual_phone" type="tel" class="gc-input" maxlength="30" required />
                </div>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_service_id">Prestation</label>
                    <select id="manual_service_id" class="gc-input" required>
                        <option value="">Selectionner</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}">
                                {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="relative xl:col-span-12">
                    <label class="gc-label" for="manual_address">Adresse du RDV</label>
                    <input id="manual_address" type="text" class="gc-input" maxlength="255" autocomplete="off" required />
                    <input id="manual_department_code" type="hidden" />
                    <input id="manual_latitude" type="hidden" />
                    <input id="manual_longitude" type="hidden" />
                </div>

                <div class="xl:col-span-12">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <p id="manual-booking-status" class="hidden text-sm"></p>
                        <button id="manual-booking-submit" type="submit" class="gc-btn-primary self-start md:self-auto">
                            Trouver un technicien
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <section id="booking-analysis-section" class="hidden space-y-6">
            <div id="booking-feedback" class="hidden rounded-xl border px-4 py-3 text-sm"></div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
                <section class="gc-card flex h-[560px] flex-col p-5 xl:h-[640px]">
                    <div class="mb-4 shrink-0 space-y-4">
                        <p class="text-sm" style="color:var(--gc-text-soft);">Techniciens eligibles</p>
                        <h2 id="analysis-title" class="text-lg font-semibold" style="color:var(--gc-text);"></h2>
                        <p id="analysis-subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>

                        <div class="rounded-xl border p-3" style="border-color:var(--gc-border);background:#ffffff;">
                            <label class="gc-label" for="eligible-technician-search">Ajouter un technicien manuellement</label>
                            <input id="eligible-technician-search" type="search" class="gc-input" placeholder="Nom, prenom, telephone, departement..." autocomplete="off" />
                            <p id="eligible-technician-search-status" class="mt-2 hidden text-xs"></p>
                            <div id="eligible-technician-search-results" class="mt-3 hidden space-y-2"></div>
                        </div>

                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span id="eligible-technician-selection-count" style="color:var(--gc-text-soft);"></span>
                            <button id="eligible-technician-select-all" type="button" class="gc-link">Tout cocher</button>
                        </div>
                    </div>
                    <div id="eligible-technicians-list" class="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1"></div>
                </section>

                <section class="gc-card flex h-[560px] flex-col overflow-hidden p-5 xl:h-[640px]">
                    <div class="mb-4 flex shrink-0 items-center justify-between gap-3">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">Carte</p>
                            <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Trajets domicile vers RDV</h2>
                        </div>
                    </div>
                    <div id="booking-map" class="min-h-0 flex-1 overflow-hidden rounded-2xl border" style="border-color:var(--gc-border);"></div>
                </section>
            </div>

            <section class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Disponibilites visuelles</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV existants des techniciens retournes</h2>
                </div>
                <div id="booking-calendar-loader" class="mb-4 hidden rounded-xl border px-4 py-3 text-sm" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text);">
                    Calcul des propositions pour la semaine affichee...
                </div>
                <div id="booking-calendar"></div>
            </section>
        </section>
    </div>

    <div id="booking-appointment-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p id="booking_modal_kind" class="text-sm" style="color:var(--gc-text-soft);">Rendez-vous</p>
                    <h2 id="booking_modal_title" class="text-xl font-semibold" style="color:var(--gc-text);"></h2>
                    <p id="booking_modal_subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>
                </div>
                <button type="button" id="booking-modal-close" class="gc-link">Fermer</button>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.15fr)_420px]">
                <section>
                    <div id="booking-detail-map" class="h-[420px] overflow-hidden rounded-2xl border" style="border-color:var(--gc-border);"></div>
                    <div id="booking_route_summary" class="mt-3 rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text);"></div>
                </section>

                <section class="space-y-4">
                    <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <dl class="grid grid-cols-1 gap-3 text-sm">
                            <div>
                                <dt style="color:var(--gc-text-soft);">Technicien</dt>
                                <dd id="booking_detail_technician" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Client</dt>
                                <dd id="booking_detail_customer" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Telephone</dt>
                                <dd id="booking_detail_phone" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Prestation</dt>
                                <dd id="booking_detail_service" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Adresse</dt>
                                <dd id="booking_detail_address" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Origine trajet</dt>
                                <dd id="booking_detail_origin" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                        </dl>
                    </div>

                    <form id="booking-detail-form" class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <input id="booking_detail_appointment_id" type="hidden" />
                        <input id="booking_detail_crm_id" type="hidden" />
                        <input id="booking_detail_technician_id" type="hidden" />

                        <div id="booking_detail_technician_select_wrap" class="mb-4 hidden">
                            <label class="gc-label" for="booking_detail_technician_select">Technicien</label>
                            <select id="booking_detail_technician_select" class="gc-input"></select>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="gc-label" for="booking_detail_starts_at">Date et heure</label>
                                <input id="booking_detail_starts_at" type="datetime-local" class="gc-input" />
                            </div>
                            <div>
                                <label class="gc-label" for="booking_detail_duration">Duree prestation</label>
                                <input id="booking_detail_duration" type="number" min="30" max="480" step="5" class="gc-input" />
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="gc-label" for="booking_detail_comment">Commentaire</label>
                            <textarea id="booking_detail_comment" rows="5" class="gc-input" style="min-height:120px;"></textarea>
                        </div>

                        <div id="booking_detail_status" class="mt-3 hidden text-sm"></div>

                        <div class="mt-4 flex flex-wrap justify-end gap-2">
                            <button id="booking-save-comment-btn" type="button" class="gc-btn-soft">Enregistrer commentaire</button>
                            <button id="booking-confirm-suggestion-btn" type="button" class="gc-btn-primary">Valider la prise du RDV</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales-all.global.min.js"></script>
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const bookingAnalyzeUrl = @json(route('planner.book.analyze'));
        const bookingTechnicianSearchUrl = @json(route('planner.book.technicians.search'));
        const bookingCalendarWindowUrl = @json(route('planner.book.calendar-window'));
        const bookingStoreUrl = @json(route('planner.book.appointments.store'));
        const bookingCommentUrlTemplate = @json(route('planner.tracking.appointments.comment', ['appointment' => '__APPOINTMENT__']));
        const bookingTrackingUrl = @json(route('planner.tracking'));
        const bookingCsrfToken = @json(csrf_token());
        const bookingMapboxToken = @json($mapboxToken);
        const routeColors = ['#1d4ed8', '#0f766e', '#b45309', '#7e22ce', '#be123c', '#475569', '#a16207', '#0369a1'];
        let bookingMap = null;
        let bookingCalendar = null;
        let bookingMapMarkers = [];
        let currentTechnicianColors = {};
        let currentCrmAppointmentId = null;
        let currentAnalysisPayload = null;
        let currentAppointmentRequest = null;
        let currentTechnicians = [];
        let selectedTechnicianIds = new Set();
        let currentFilters = null;
        let currentCalendarEvents = [];
        let currentCalendarSuggestions = [];
        let technicianSearchAbortController = null;
        let technicianSearchTimer = null;
        let calendarWindowAbortController = null;
        let calendarWindowRequestId = 0;
        let lastCalendarDateInfo = null;
        let mapRenderRequestId = 0;
        let shouldFetchCalendarWindow = false;
        let detailMap = null;
        let detailMapMarkers = [];
        let selectedCalendarEvent = null;

        const mapboxTokenPreview = () => {
            if (!bookingMapboxToken) return null;

            return `${String(bookingMapboxToken).slice(0, 10)}...${String(bookingMapboxToken).slice(-6)}`;
        };

        const mapboxStatus = () => ({
            token_found: Boolean(bookingMapboxToken),
            token_preview: mapboxTokenPreview(),
            mapboxgl_found: Boolean(window.mapboxgl),
            mapboxgl_version: window.mapboxgl?.version || null,
            script_tag_found: Boolean(document.querySelector('script[src*="mapbox-gl.js"]')),
            css_tag_found: Boolean(document.querySelector('link[href*="mapbox-gl.css"]')),
        });

        const sanitizeMapboxUrl = (url) => {
            const clone = new URL(url.toString());
            if (clone.searchParams.has('access_token')) {
                clone.searchParams.set('access_token', '[masked]');
            }

            return clone.toString();
        };

        const mapboxDebug = (message, context = {}) => {
            console.log('[Mapbox Debug][Planner Book]', message, {
                ...mapboxStatus(),
                ...context,
            });
        };

        mapboxDebug('script bootstrap');
        window.addEventListener('load', () => mapboxDebug('window loaded'));
        window.addEventListener('error', (event) => {
            console.error('[Mapbox Debug][Planner Book] window error', {
                message: event.message,
                source: event.filename,
                line: event.lineno,
                column: event.colno,
                error: event.error,
            });
        });
        window.addEventListener('unhandledrejection', (event) => {
            console.error('[Mapbox Debug][Planner Book] unhandled promise rejection', event.reason);
        });

        const showMapboxUnavailable = (containerId, reason) => {
            const container = document.getElementById(containerId);
            if (!container) return;

            container.innerHTML = `
                <div class="flex h-full min-h-[260px] items-center justify-center rounded-2xl border border-dashed px-5 text-center" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text);">
                    <div>
                        <p class="font-semibold">Mapbox indisponible</p>
                        <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">${escapeHtml(reason)}</p>
                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">token: ${bookingMapboxToken ? 'present' : 'absent'} · mapboxgl: ${window.mapboxgl ? 'present' : 'absent'}</p>
                    </div>
                </div>
            `;
        };

        const analysisSection = document.getElementById('booking-analysis-section');
        const crmBookingSection = document.getElementById('crm-booking-section');
        const placementConfirmationSection = document.getElementById('booking-placement-confirmation');
        const bookingFeedback = document.getElementById('booking-feedback');
        const techniciansList = document.getElementById('eligible-technicians-list');
        const technicianSearchInput = document.getElementById('eligible-technician-search');
        const technicianSearchResults = document.getElementById('eligible-technician-search-results');
        const technicianSearchStatus = document.getElementById('eligible-technician-search-status');
        const technicianSelectionCount = document.getElementById('eligible-technician-selection-count');
        const technicianSelectAllButton = document.getElementById('eligible-technician-select-all');
        const bookingAppointmentModal = document.getElementById('booking-appointment-modal');
        const bookingDetailStatus = document.getElementById('booking_detail_status');
        const manualBookingSection = document.getElementById('manual-booking-section');
        const manualBookingStatus = document.getElementById('manual-booking-status');
        const manualBookingToggle = document.getElementById('manual-booking-toggle');
        const confirmationTrackLink = document.getElementById('booking-confirmation-track-link');

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const showFeedback = (message, type = 'info') => {
            bookingFeedback.textContent = message;
            bookingFeedback.classList.remove('hidden');
            bookingFeedback.style.borderColor = type === 'error' ? '#fecdd3' : 'var(--gc-border)';
            bookingFeedback.style.background = type === 'error' ? '#fff1f2' : 'var(--gc-accent-soft)';
            bookingFeedback.style.color = type === 'error' ? '#be123c' : 'var(--gc-text)';
        };

        const clearFeedback = () => {
            bookingFeedback.textContent = '';
            bookingFeedback.classList.add('hidden');
        };

        const showCalendarLoader = () => {
            document.getElementById('booking-calendar-loader')?.classList.remove('hidden');
        };

        const hideCalendarLoader = () => {
            document.getElementById('booking-calendar-loader')?.classList.add('hidden');
        };

        const setManualStatus = (message, type = 'info') => {
            manualBookingStatus.textContent = message;
            manualBookingStatus.style.color = type === 'error' ? '#be123c' : '#0f766e';
            manualBookingStatus.classList.remove('hidden');
        };

        const clearManualStatus = () => {
            manualBookingStatus.textContent = '';
            manualBookingStatus.classList.add('hidden');
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

        const initManualAddressAutocomplete = () => {
            const addressInput = document.getElementById('manual_address');
            const departmentCodeInput = document.getElementById('manual_department_code');
            const latInput = document.getElementById('manual_latitude');
            const lngInput = document.getElementById('manual_longitude');

            mapboxDebug('init manual address autocomplete', {
                address_input_found: Boolean(addressInput),
                department_input_found: Boolean(departmentCodeInput),
                lat_input_found: Boolean(latInput),
                lng_input_found: Boolean(lngInput),
            });

            if (!addressInput || !departmentCodeInput || !latInput || !lngInput) return;

            let list = addressInput.parentElement.querySelector('.gc-mapbox-suggestions');
            if (!list) {
                list = document.createElement('div');
                list.className = 'gc-mapbox-suggestions hidden';
                addressInput.parentElement.appendChild(list);
            }

            if (!bookingMapboxToken) {
                mapboxDebug('manual autocomplete blocked: token missing');
                addressInput.addEventListener('focus', () => setManualStatus('Token Mapbox absent: impossible de recuperer les coordonnees automatiquement.', 'error'));
                return;
            }

            if (addressInput.dataset.mapboxBound === '1') {
                mapboxDebug('manual autocomplete already bound');
                return;
            }
            addressInput.dataset.mapboxBound = '1';
            mapboxDebug('manual autocomplete bound');

            let debounceTimer;
            addressInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                const query = addressInput.value.trim();
                departmentCodeInput.value = '';
                latInput.value = '';
                lngInput.value = '';

                if (query.length < 3) {
                    list.innerHTML = '';
                    list.classList.add('hidden');
                    return;
                }

                debounceTimer = setTimeout(async () => {
                    const url = new URL(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json`);
                    url.searchParams.set('access_token', bookingMapboxToken);
                    url.searchParams.set('autocomplete', 'true');
                    url.searchParams.set('language', 'fr');
                    url.searchParams.set('country', 'fr');
                    url.searchParams.set('limit', '5');

                    try {
                        mapboxDebug('geocoding request', {
                            query,
                            url: sanitizeMapboxUrl(url),
                        });
                        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                        const data = await response.json();
                        const features = Array.isArray(data.features) ? data.features : [];

                        mapboxDebug('geocoding response', {
                            ok: response.ok,
                            status: response.status,
                            status_text: response.statusText,
                            features_count: features.length,
                            mapbox_code: data.code || null,
                            mapbox_message: data.message || null,
                        });

                        if (features.length === 0) {
                            list.innerHTML = '';
                            list.classList.add('hidden');
                            return;
                        }

                        list.innerHTML = features.map((feature) => {
                            const address = escapeHtml(feature.place_name || '');
                            const departmentCode = escapeHtml(extractDepartmentCode(feature));
                            const lng = escapeHtml(feature.center?.[0] ?? '');
                            const lat = escapeHtml(feature.center?.[1] ?? '');

                            return `<button type="button" class="gc-mapbox-item" data-address="${address}" data-department-code="${departmentCode}" data-lng="${lng}" data-lat="${lat}">${address}</button>`;
                        }).join('');
                        list.classList.remove('hidden');

                        list.querySelectorAll('.gc-mapbox-item').forEach((item) => {
                            item.addEventListener('click', () => {
                                addressInput.value = item.dataset.address || '';
                                departmentCodeInput.value = item.dataset.departmentCode || '';
                                lngInput.value = item.dataset.lng || '';
                                latInput.value = item.dataset.lat || '';
                                list.innerHTML = '';
                                list.classList.add('hidden');
                                clearManualStatus();
                            });
                        });
                    } catch (error) {
                        console.error('[Mapbox Debug][Planner Book] geocoding error', error);
                        setManualStatus('Erreur Mapbox pendant la recherche adresse.', 'error');
                    }
                }, 280);
            });
        };

        const initBookingMap = () => {
            mapboxDebug('init booking map', {
                container_found: Boolean(document.getElementById('booking-map')),
                existing_map: Boolean(bookingMap),
            });

            if (!bookingMapboxToken || !window.mapboxgl) {
                mapboxDebug('booking map blocked: missing token or mapboxgl');
                showMapboxUnavailable('booking-map', !bookingMapboxToken
                    ? 'Token Mapbox absent côté Laravel.'
                    : 'La librairie Mapbox GL JS n est pas chargee. Verifie la CSP script-src/script-src-elem et le chargement du CDN.');
                return null;
            }

            window.mapboxgl.accessToken = bookingMapboxToken;

            if (bookingMap) {
                bookingMap.resize();
                mapboxDebug('booking map reused and resized');
                return bookingMap;
            }

            bookingMap = new window.mapboxgl.Map({
                container: 'booking-map',
                style: 'mapbox://styles/mapbox/light-v11',
                center: [2.4, 46.7],
                zoom: 5,
            });
            bookingMap.on('load', () => mapboxDebug('booking map load event'));
            bookingMap.on('error', (event) => console.error('[Mapbox Debug][Planner Book] booking map error', event?.error || event));
            mapboxDebug('booking map instance created');

            return bookingMap;
        };

        const markerElement = (color, label = '') => {
            const element = document.createElement('div');
            element.className = 'flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-[10px] font-bold shadow-lg';
            element.style.background = color;
            element.style.color = '#fff';
            element.textContent = label;
            return element;
        };

        const clearMap = () => {
            bookingMapMarkers.forEach((marker) => marker.remove());
            bookingMapMarkers = [];

            if (!bookingMap) return;

            [...bookingMap.getStyle().layers]
                .filter((layer) => layer.id.startsWith('tech-route-'))
                .forEach((layer) => bookingMap.removeLayer(layer.id));

            Object.keys(bookingMap.getStyle().sources)
                .filter((source) => source.startsWith('tech-route-'))
                .forEach((source) => bookingMap.removeSource(source));
        };

        const straightRoute = (technician, crmAppointment) => ({
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: [
                    [Number(technician.longitude), Number(technician.latitude)],
                    [Number(crmAppointment.longitude), Number(crmAppointment.latitude)],
                ],
            },
        });

        const fetchRoute = async (technician, crmAppointment) => {
            if (!bookingMapboxToken) {
                mapboxDebug('route request skipped: token missing, using straight route', {
                    technician_id: technician.id,
                });
                return straightRoute(technician, crmAppointment);
            }

            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${technician.longitude},${technician.latitude};${crmAppointment.longitude},${crmAppointment.latitude}`);
            url.searchParams.set('access_token', bookingMapboxToken);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');

            try {
                mapboxDebug('directions request for technician route', {
                    technician_id: technician.id,
                    technician_name: technician.name,
                    url: sanitizeMapboxUrl(url),
                });
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                const route = data.routes?.[0]?.geometry;

                mapboxDebug('directions response for technician route', {
                    technician_id: technician.id,
                    ok: response.ok,
                    status: response.status,
                    status_text: response.statusText,
                    routes_count: Array.isArray(data.routes) ? data.routes.length : 0,
                    has_geometry: Boolean(route),
                    mapbox_code: data.code || null,
                    mapbox_message: data.message || null,
                });

                return route ? { type: 'Feature', geometry: route } : straightRoute(technician, crmAppointment);
            } catch (error) {
                console.error('[Mapbox Debug][Planner Book] directions technician route error', error);
                return straightRoute(technician, crmAppointment);
            }
        };

        const fetchRouteBetween = async (origin, destination) => {
            const fallback = () => {
                const distanceKm = haversineDistanceKm(origin.lat, origin.lng, destination.lat, destination.lng);

                return {
                    feature: {
                        type: 'Feature',
                        geometry: {
                            type: 'LineString',
                            coordinates: [[origin.lng, origin.lat], [destination.lng, destination.lat]],
                        },
                    },
                    distance_km: distanceKm,
                    duration_minutes: Math.max(1, Math.round((distanceKm / 65) * 60)),
                    source: 'estimation interne',
                };
            };

            if (!bookingMapboxToken) {
                mapboxDebug('route between skipped: token missing, using fallback', { origin, destination });
                return fallback();
            }

            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${origin.lng},${origin.lat};${destination.lng},${destination.lat}`);
            url.searchParams.set('access_token', bookingMapboxToken);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');

            try {
                mapboxDebug('directions request between points', {
                    origin,
                    destination,
                    url: sanitizeMapboxUrl(url),
                });
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                const route = data.routes?.[0];

                mapboxDebug('directions response between points', {
                    ok: response.ok,
                    status: response.status,
                    status_text: response.statusText,
                    routes_count: Array.isArray(data.routes) ? data.routes.length : 0,
                    has_geometry: Boolean(route?.geometry),
                    distance_m: route?.distance || null,
                    duration_s: route?.duration || null,
                    mapbox_code: data.code || null,
                    mapbox_message: data.message || null,
                });

                return route?.geometry ? {
                    feature: { type: 'Feature', geometry: route.geometry },
                    distance_km: Number(route.distance || 0) / 1000,
                    duration_minutes: Math.max(1, Math.round(Number(route.duration || 0) / 60)),
                    source: 'Mapbox voiture',
                } : fallback();
            } catch (error) {
                console.error('[Mapbox Debug][Planner Book] directions between points error', error);
                return fallback();
            }
        };

        const haversineDistanceKm = (fromLat, fromLng, toLat, toLng) => {
            const earthRadiusKm = 6371;
            const toRadians = (value) => value * Math.PI / 180;
            const latDelta = toRadians(toLat - fromLat);
            const lngDelta = toRadians(toLng - fromLng);
            const a = Math.sin(latDelta / 2) ** 2
                + Math.cos(toRadians(fromLat)) * Math.cos(toRadians(toLat)) * Math.sin(lngDelta / 2) ** 2;

            return earthRadiusKm * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
        };

        const formatRouteDuration = (minutes) => {
            const safeMinutes = Math.max(0, Math.round(Number(minutes || 0)));
            const hours = Math.floor(safeMinutes / 60);
            const remainingMinutes = safeMinutes % 60;

            if (hours === 0) {
                return `${remainingMinutes} min`;
            }

            return remainingMinutes > 0 ? `${hours}h${String(remainingMinutes).padStart(2, '0')}` : `${hours}h`;
        };

        const addMinutes = (date, minutes) => new Date(date.getTime() + (Number(minutes || 0) * 60000));

        const requestDurationMinutes = () => Math.max(30, Number(currentAppointmentRequest?.service?.average_duration_minutes || 60));

        const technicianById = (technicianId) => currentTechnicians.find((technician) => String(technician.id) === String(technicianId));

        const selectedTechnicians = () => currentTechnicians
            .filter((technician) => selectedTechnicianIds.has(String(technician.id)));

        const selectedTechnicianIdsArray = () => selectedTechnicians()
            .map((technician) => Number(technician.id))
            .filter((id) => Number.isFinite(id));

        const visibleCalendarItems = (items) => (items || [])
            .filter((item) => selectedTechnicianIds.has(String(item.extendedProps?.technician_id || '')));

        const refreshTechnicianColors = () => {
            const existingColors = currentTechnicianColors;

            currentTechnicianColors = Object.fromEntries(
                currentTechnicians.map((technician, index) => {
                    const technicianId = String(technician.id);

                    return [technicianId, existingColors[technicianId] || routeColors[index % routeColors.length]];
                })
            );
        };

        const technicianColor = (technicianId) => {
            const normalizedTechnicianId = String(technicianId);
            const technicianIndex = currentTechnicians.findIndex((technician) => String(technician.id) === normalizedTechnicianId);

            return currentTechnicianColors[normalizedTechnicianId]
                || routeColors[Math.max(0, technicianIndex) % routeColors.length]
                || '#31424c';
        };

        const technicianOrdinal = (technicianId) => {
            const technicianIndex = currentTechnicians.findIndex((technician) => String(technician.id) === String(technicianId));

            return technicianIndex >= 0 ? technicianIndex + 1 : null;
        };

        const updateTechnicianSelectionCount = () => {
            if (!technicianSelectionCount) return;

            technicianSelectionCount.textContent = `${selectedTechnicianIds.size}/${currentTechnicians.length} technicien(s) affiche(s)`;
            technicianSelectAllButton?.classList.toggle('hidden', currentTechnicians.length === selectedTechnicianIds.size);
        };

        const upsertTechnician = (technician) => {
            const technicianId = String(technician.id);
            const existingIndex = currentTechnicians.findIndex((currentTechnician) => String(currentTechnician.id) === technicianId);

            if (existingIndex >= 0) {
                currentTechnicians[existingIndex] = {
                    ...currentTechnicians[existingIndex],
                    ...technician,
                };
            } else {
                currentTechnicians.push(technician);
            }

            selectedTechnicianIds.add(technicianId);
        };

        const sameLocalDay = (leftDate, rightDate) => leftDate?.toDateString?.() === rightDate?.toDateString?.();

        const previousAppointmentForTechnician = (technicianId, startsAt) => {
            if (!bookingCalendar) return null;

            return bookingCalendar.getEvents()
                .filter((event) => {
                    const props = event.extendedProps || {};

                    return String(props.technician_id || '') === String(technicianId)
                        && !props.is_suggestion
                        && !props.deleted_at
                        && event.end
                        && event.end <= startsAt
                        && sameLocalDay(event.start, startsAt);
                })
                .sort((left, right) => right.end - left.end)
                .at(0) || null;
        };

        const routeOriginForTechnician = (technician, startsAt) => {
            const previousAppointment = previousAppointmentForTechnician(technician.id, startsAt);

            if (previousAppointment) {
                const props = previousAppointment.extendedProps || {};

                return {
                    label: 'rdv precedent',
                    name: props.customer_name || previousAppointment.title || 'RDV precedent',
                    lat: Number(props.latitude),
                    lng: Number(props.longitude),
                };
            }

            return {
                label: 'domicile',
                name: 'Domicile',
                lat: Number(technician.latitude),
                lng: Number(technician.longitude),
            };
        };

        const serviceLabelForRequest = () => currentAppointmentRequest?.service
            ? `${currentAppointmentRequest.service.type} - ${currentAppointmentRequest.service.name}`
            : 'Prestation non renseignee';

        const draftPropsForTechnician = (technician, startsAt) => {
            const origin = routeOriginForTechnician(technician, startsAt);

            return {
                technician_id: technician.id,
                technician_name: technician.name,
                is_suggestion: true,
                is_calendar_click: true,
                allow_technician_change: true,
                origin_label: origin.label,
                origin_latitude: origin.lat,
                origin_longitude: origin.lng,
                origin_name: origin.name,
                latitude: Number(currentAppointmentRequest.latitude),
                longitude: Number(currentAppointmentRequest.longitude),
                address: currentAppointmentRequest.address,
                customer_name: `${currentAppointmentRequest.first_name || ''} ${currentAppointmentRequest.last_name || ''}`.trim(),
                customer_phone: currentAppointmentRequest.phone,
                service_label: serviceLabelForRequest(),
                crm_appointment_id: currentAppointmentRequest.id,
                can_validate: Boolean(currentAppointmentRequest.service),
                duration_minutes: requestDurationMinutes(),
                comment: '',
            };
        };

        const applyTechnicianToDraftEvent = (event, technicianId) => {
            const technician = technicianById(technicianId);

            if (!technician) return;

            event.extendedProps = {
                ...(event.extendedProps || {}),
                ...draftPropsForTechnician(technician, event.start),
                duration_minutes: Number(document.getElementById('booking_detail_duration')?.value || event.extendedProps?.duration_minutes || requestDurationMinutes()),
                comment: document.getElementById('booking_detail_comment')?.value || event.extendedProps?.comment || '',
            };

            document.getElementById('booking_detail_technician').textContent = technician.name || '-';
            document.getElementById('booking_detail_origin').textContent = `${event.extendedProps.origin_label || '-'}${event.extendedProps.origin_name ? ` (${event.extendedProps.origin_name})` : ''}`;
            document.getElementById('booking_detail_technician_id').value = technician.id;
            renderRouteSummary(event.extendedProps, null, true);
            requestAnimationFrame(() => renderDetailMap(event));
        };

        const syncDraftEventFromInputs = () => {
            if (!selectedCalendarEvent?.extendedProps?.is_calendar_click) return;

            const startValue = document.getElementById('booking_detail_starts_at').value;
            const durationMinutes = Number(document.getElementById('booking_detail_duration').value || requestDurationMinutes());
            const technicianId = document.getElementById('booking_detail_technician_select').value;

            if (startValue) {
                selectedCalendarEvent.start = new Date(startValue);
                selectedCalendarEvent.end = addMinutes(selectedCalendarEvent.start, durationMinutes);
            }

            selectedCalendarEvent.extendedProps.duration_minutes = durationMinutes;
            applyTechnicianToDraftEvent(selectedCalendarEvent, technicianId);
        };

        const openCalendarSlotModal = (info) => {
            const activeTechnicians = selectedTechnicians();

            if (!currentAppointmentRequest || activeTechnicians.length === 0) {
                showFeedback('Lance d abord une recherche CRM ou manuelle avant de placer un RDV depuis le calendrier.', 'error');
                return;
            }

            const startsAt = new Date(info.date);

            if (info.allDay) {
                startsAt.setHours(8, 0, 0, 0);
            }

            const durationMinutes = requestDurationMinutes();
            const technician = activeTechnicians[0];
            const draftEvent = {
                id: `calendar-draft-${Date.now()}`,
                title: `Placement | ${technician.name}`,
                start: startsAt,
                end: addMinutes(startsAt, durationMinutes),
                extendedProps: draftPropsForTechnician(technician, startsAt),
            };

            openBookingAppointmentModal(draftEvent);
        };

        const renderRouteSummary = (props, route = null, isLoading = false) => {
            const summary = document.getElementById('booking_route_summary');
            const originLabel = props.origin_label || 'origine';
            const originName = props.origin_name ? ` (${props.origin_name})` : '';

            if (isLoading) {
                summary.innerHTML = `
                    <div class="flex items-center justify-between gap-3">
                        <span style="color:var(--gc-text-soft);">Calcul du trajet depuis ${escapeHtml(originLabel)}${escapeHtml(originName)}...</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:#ffffff;color:var(--gc-text);">En cours</span>
                    </div>
                `;
                return;
            }

            if (!route) {
                summary.innerHTML = '<span style="color:var(--gc-text-soft);">Trajet indisponible pour ce rendez-vous.</span>';
                return;
            }

            summary.innerHTML = `
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Origine</p>
                        <p class="mt-1 font-medium">${escapeHtml(originLabel)}${escapeHtml(originName)}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Distance</p>
                        <p class="mt-1 font-medium">${Number(route.distance_km || 0).toFixed(1)} km</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Temps estime</p>
                        <p class="mt-1 font-medium">${escapeHtml(formatRouteDuration(route.duration_minutes))}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Calcul</p>
                        <p class="mt-1 font-medium">${escapeHtml(route.source)}</p>
                    </div>
                </div>
            `;
        };

        const initDetailMap = () => {
            mapboxDebug('init detail map', {
                container_found: Boolean(document.getElementById('booking-detail-map')),
                existing_map: Boolean(detailMap),
            });

            if (!bookingMapboxToken || !window.mapboxgl) {
                mapboxDebug('detail map blocked: missing token or mapboxgl');
                showMapboxUnavailable('booking-detail-map', !bookingMapboxToken
                    ? 'Token Mapbox absent côté Laravel.'
                    : 'La librairie Mapbox GL JS n est pas chargee. Verifie la CSP script-src/script-src-elem et le chargement du CDN.');
                return null;
            }

            window.mapboxgl.accessToken = bookingMapboxToken;

            if (detailMap) {
                detailMap.resize();
                mapboxDebug('detail map reused and resized');
                return detailMap;
            }

            detailMap = new window.mapboxgl.Map({
                container: 'booking-detail-map',
                style: 'mapbox://styles/mapbox/light-v11',
                center: [2.4, 46.7],
                zoom: 5,
                dragPan: false,
                scrollZoom: false,
                boxZoom: false,
                dragRotate: false,
                keyboard: false,
                doubleClickZoom: false,
                touchZoomRotate: false,
            });
            detailMap.on('load', () => mapboxDebug('detail map load event'));
            detailMap.on('error', (event) => console.error('[Mapbox Debug][Planner Book] detail map error', event?.error || event));
            mapboxDebug('detail map instance created');

            return detailMap;
        };

        const clearDetailMap = () => {
            detailMapMarkers.forEach((marker) => marker.remove());
            detailMapMarkers = [];

            if (!detailMap) return;

            [...detailMap.getStyle().layers]
                .filter((layer) => layer.id.startsWith('detail-route'))
                .forEach((layer) => detailMap.removeLayer(layer.id));

            Object.keys(detailMap.getStyle().sources)
                .filter((source) => source.startsWith('detail-route'))
                .forEach((source) => detailMap.removeSource(source));
        };

        const renderDetailMap = async (event) => {
            const props = event.extendedProps;
            const origin = {
                lat: Number(props.origin_latitude),
                lng: Number(props.origin_longitude),
            };
            const destination = {
                lat: Number(props.latitude),
                lng: Number(props.longitude),
            };

            mapboxDebug('render detail map called', {
                event_id: event.id,
                technician_id: props.technician_id,
                origin,
                destination,
            });

            if (!Number.isFinite(origin.lat) || !Number.isFinite(origin.lng) || !Number.isFinite(destination.lat) || !Number.isFinite(destination.lng)) {
                mapboxDebug('render detail map blocked: invalid coordinates', { origin, destination });
                renderRouteSummary(props);
                return;
            }

            const route = await fetchRouteBetween(origin, destination);
            renderRouteSummary(props, route);

            const map = initDetailMap();
            if (!map) {
                mapboxDebug('render detail map aborted: map unavailable');
                return;
            }

            const render = async () => {
                mapboxDebug('render detail map drawing route');
                clearDetailMap();

                const color = currentTechnicianColors[String(props.technician_id)] || '#31424c';
                const bounds = new window.mapboxgl.LngLatBounds();
                bounds.extend([origin.lng, origin.lat]);
                bounds.extend([destination.lng, destination.lat]);

                detailMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement(color, 'D') })
                    .setLngLat([origin.lng, origin.lat])
                    .addTo(map));
                detailMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement('#31424c', 'R') })
                    .setLngLat([destination.lng, destination.lat])
                    .addTo(map));

                map.addSource('detail-route', { type: 'geojson', data: route.feature });
                map.addLayer({
                    id: 'detail-route',
                    type: 'line',
                    source: 'detail-route',
                    layout: { 'line-cap': 'round', 'line-join': 'round' },
                    paint: {
                        'line-color': color,
                        'line-width': 5,
                        'line-opacity': 0.78,
                    },
                });
                map.fitBounds(bounds, { padding: 70, maxZoom: 12 });
            };

            if (map.loaded()) {
                await render();
            } else {
                mapboxDebug('detail map waiting for load event');
                map.once('load', render);
            }
        };

        const formatDateTimeForInput = (value) => {
            if (!value) return '';
            const date = new Date(value);
            const offsetDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
            return offsetDate.toISOString().slice(0, 16);
        };

        const formatDateTimeForConfirmation = (value) => {
            if (!value) return '-';

            return new Intl.DateTimeFormat('fr-FR', {
                dateStyle: 'full',
                timeStyle: 'short',
            }).format(new Date(value));
        };

        const showPlacementConfirmation = (data, payload, event) => {
            const props = event?.extendedProps || {};
            const technician = technicianById(payload.technician_id);
            const startsAt = payload.starts_at || event?.start || null;

            calendarWindowAbortController?.abort();
            technicianSearchAbortController?.abort();
            shouldFetchCalendarWindow = false;
            selectedCalendarEvent = null;

            crmBookingSection?.classList.add('hidden');
            manualBookingSection?.classList.add('hidden');
            analysisSection?.classList.add('hidden');
            manualBookingToggle?.classList.add('hidden');
            placementConfirmationSection?.classList.remove('hidden');

            document.getElementById('booking_confirmation_reference').textContent = data.appointment_id
                ? `RDV #${data.appointment_id}`
                : 'RDV cree';
            document.getElementById('booking_confirmation_date').textContent = formatDateTimeForConfirmation(startsAt);
            document.getElementById('booking_confirmation_customer').textContent = props.customer_name || '-';
            document.getElementById('booking_confirmation_technician').textContent = technician?.name || props.technician_name || '-';
            document.getElementById('booking_confirmation_address').textContent = props.address || '-';

            const trackingUrl = new URL(bookingTrackingUrl, window.location.origin);
            trackingUrl.searchParams.set('technician_id', payload.technician_id);

            if (data.appointment_id) {
                trackingUrl.searchParams.set('appointment_id', data.appointment_id);
            }

            if (startsAt) {
                trackingUrl.searchParams.set('date', String(startsAt).slice(0, 10));
            }

            confirmationTrackLink.href = trackingUrl.toString();

            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        const closeBookingAppointmentModal = () => {
            bookingAppointmentModal.classList.add('hidden');
            selectedCalendarEvent = null;
        };

        const showDetailStatus = (message, type = 'info') => {
            bookingDetailStatus.textContent = message;
            bookingDetailStatus.style.color = type === 'error' ? '#be123c' : '#0f766e';
            bookingDetailStatus.classList.remove('hidden');
        };

        const openBookingAppointmentModal = async (event) => {
            selectedCalendarEvent = event;
            const props = event.extendedProps;
            const isSuggestion = Boolean(props.is_suggestion);
            const allowTechnicianChange = Boolean(props.allow_technician_change);
            const technicianSelectWrap = document.getElementById('booking_detail_technician_select_wrap');
            const technicianSelect = document.getElementById('booking_detail_technician_select');
            const startsAtInput = document.getElementById('booking_detail_starts_at');
            const durationInput = document.getElementById('booking_detail_duration');

            bookingDetailStatus.classList.add('hidden');
            document.getElementById('booking_modal_kind').textContent = props.is_calendar_click
                ? 'Placement depuis le calendrier'
                : (isSuggestion ? 'Proposition de rendez-vous' : 'Rendez-vous place');
            document.getElementById('booking_modal_title').textContent = props.service_label || event.title;
            document.getElementById('booking_modal_subtitle').textContent = props.is_calendar_click
                ? 'Le RDV courant est repris; tu peux changer le technicien, l heure et la duree avant validation.'
                : (isSuggestion
                ? 'Tu peux ajuster l heure et la duree avant validation.'
                : 'Detail du rendez-vous deja place.');
            document.getElementById('booking_detail_technician').textContent = props.technician_name || '-';
            document.getElementById('booking_detail_customer').textContent = props.customer_name || '-';
            document.getElementById('booking_detail_phone').textContent = props.customer_phone || '-';
            document.getElementById('booking_detail_service').textContent = props.service_label || '-';
            document.getElementById('booking_detail_address').textContent = props.address || '-';
            document.getElementById('booking_detail_origin').textContent = `${props.origin_label || '-'}${props.origin_name ? ` (${props.origin_name})` : ''}`;
            document.getElementById('booking_detail_appointment_id').value = isSuggestion ? '' : event.id;
            document.getElementById('booking_detail_crm_id').value = props.crm_appointment_id || '';
            document.getElementById('booking_detail_technician_id').value = props.technician_id || '';
            startsAtInput.value = formatDateTimeForInput(event.start);
            durationInput.value = props.duration_minutes || Math.max(30, Math.round(((event.end || addMinutes(event.start, requestDurationMinutes())) - event.start) / 60000));
            document.getElementById('booking_detail_comment').value = props.comment || '';
            renderRouteSummary(props, null, true);

            technicianSelectWrap.classList.toggle('hidden', !allowTechnicianChange);
            technicianSelect.innerHTML = selectedTechnicians().map((technician) => `
                <option value="${escapeHtml(technician.id)}">${escapeHtml(technician.name)} · ${escapeHtml(technician.driving_duration_minutes)} min</option>
            `).join('');
            technicianSelect.value = props.technician_id || '';
            technicianSelect.onchange = allowTechnicianChange ? syncDraftEventFromInputs : null;
            startsAtInput.onchange = allowTechnicianChange ? syncDraftEventFromInputs : null;
            durationInput.oninput = allowTechnicianChange ? syncDraftEventFromInputs : null;

            startsAtInput.disabled = !isSuggestion;
            durationInput.disabled = !isSuggestion;
            document.getElementById('booking-save-comment-btn').classList.toggle('hidden', isSuggestion);
            document.getElementById('booking-confirm-suggestion-btn').classList.toggle('hidden', !isSuggestion);
            document.getElementById('booking-confirm-suggestion-btn').disabled = isSuggestion && !props.can_validate;

            if (isSuggestion && !props.can_validate) {
                showDetailStatus('Validation impossible: aucune prestation CRM n est renseignee.', 'error');
            }

            bookingAppointmentModal.classList.remove('hidden');
            requestAnimationFrame(() => renderDetailMap(event));
        };

        const renderMap = async (crmAppointment, technicians) => {
            const renderRequestId = ++mapRenderRequestId;

            mapboxDebug('render booking map called', {
                crm_appointment_id: crmAppointment?.id,
                technicians_count: technicians.length,
                appointment_coordinates: {
                    lat: crmAppointment?.latitude,
                    lng: crmAppointment?.longitude,
                },
            });
            const map = initBookingMap();
            if (!map) {
                mapboxDebug('render booking map aborted: map unavailable');
                return;
            }

            const render = async () => {
                if (renderRequestId !== mapRenderRequestId) return;

                mapboxDebug('render booking map drawing', {
                    technicians_count: technicians.length,
                    map_loaded: map.loaded(),
                });
                clearMap();

                const bounds = new window.mapboxgl.LngLatBounds();
                const appointmentCoordinates = [Number(crmAppointment.longitude), Number(crmAppointment.latitude)];
                bounds.extend(appointmentCoordinates);

                bookingMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement('#31424c', 'R') })
                    .setLngLat(appointmentCoordinates)
                    .setPopup(new window.mapboxgl.Popup().setHTML(`<strong>RDV</strong><br>${escapeHtml(crmAppointment.address)}`))
                    .addTo(map));

                for (const [index, technician] of technicians.entries()) {
                    const color = technicianColor(technician.id);
                    const markerLabel = String(technicianOrdinal(technician.id) || index + 1);
                    const techCoordinates = [Number(technician.longitude), Number(technician.latitude)];
                    bounds.extend(techCoordinates);

                    bookingMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement(color, markerLabel) })
                        .setLngLat(techCoordinates)
                        .setPopup(new window.mapboxgl.Popup().setHTML(`<strong>${escapeHtml(technician.name)}</strong>`))
                        .addTo(map));

                    const route = await fetchRoute(technician, crmAppointment);
                    if (renderRequestId !== mapRenderRequestId) return;

                    const sourceId = `tech-route-${technician.id}`;
                    map.addSource(sourceId, { type: 'geojson', data: route });
                    map.addLayer({
                        id: sourceId,
                        type: 'line',
                        source: sourceId,
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': color,
                            'line-width': 4,
                            'line-opacity': 0.72,
                        },
                    });

                }

                if (technicians.length > 0) {
                    map.fitBounds(bounds, { padding: 70, maxZoom: 10 });
                } else {
                    map.flyTo({ center: appointmentCoordinates, zoom: 9 });
                }
            };

            if (map.loaded()) {
                await render();
            } else {
                mapboxDebug('booking map waiting for load event');
                map.once('load', render);
            }
        };

        const renderTechnicians = (technicians, crmAppointment, filters) => {
            refreshTechnicianColors();
            updateTechnicianSelectionCount();

            const activeTechnicians = selectedTechnicians();
            document.getElementById('analysis-title').textContent = `${activeTechnicians.length}/${technicians.length} technicien(s) affiche(s)`;
            const serviceLabel = crmAppointment.service
                ? `${crmAppointment.service.type} - ${crmAppointment.service.name}`
                : null;
            const availabilityLabel = filters.preferred_starts_at
                ? ` Creneau client: ${new Date(filters.preferred_starts_at).toLocaleString('fr-FR')}.`
                : '';
            document.getElementById('analysis-subtitle').textContent = filters.service_required
                ? `Departement ${filters.department_code} + prestation ${serviceLabel}.${availabilityLabel}`
                : `Departement ${filters.department_code}; aucun service renseigne, pas de filtre prestation.${availabilityLabel}`;

            if (technicians.length === 0) {
                techniciansList.innerHTML = '<div class="rounded-xl border p-4 text-sm" style="border-color:#fecdd3;background:#fff1f2;color:#be123c;">Aucun technicien ne couvre ce departement avec ces criteres.</div>';
                return;
            }

            techniciansList.innerHTML = technicians.map((technician, index) => {
                const coverageBadge = technician.covers_requested_department
                    ? '<span class="rounded-lg px-2 py-1 text-xs" style="background:#dcfce7;color:#15803d;">Departement couvert</span>'
                    : '<span class="rounded-lg px-2 py-1 text-xs" style="background:#fee2e2;color:#be123c;">Fallback proximite</span>';
                const technicianId = String(technician.id);
                const isSelected = selectedTechnicianIds.has(technicianId);
                const color = technicianColor(technicianId);
                const rankLabel = technicianOrdinal(technicianId) || index + 1;

                return `
                    <article class="rounded-xl border p-4 transition" style="border-color:${isSelected ? 'var(--gc-border)' : '#e2e8f0'};opacity:${isSelected ? '1' : '0.58'};">
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                class="eligible-technician-checkbox mt-1 h-4 w-4 rounded"
                                style="accent-color:${color};"
                                data-technician-id="${escapeHtml(technician.id)}"
                                ${isSelected ? 'checked' : ''}
                            />
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style="background:${color};">${rankLabel}</span>
                            <div class="min-w-0">
                                <h3 class="font-semibold" style="color:var(--gc-text);">${escapeHtml(technician.name)}</h3>
                                <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">${escapeHtml(technician.phone || 'Telephone non renseigne')}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">${escapeHtml(technician.driving_distance_km)} km voiture</span>
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">${escapeHtml(technician.driving_duration_minutes)} min</span>
                                    ${coverageBadge}
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            }).join('');

            techniciansList.querySelectorAll('.eligible-technician-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', async () => {
                    const technicianId = String(checkbox.dataset.technicianId || '');

                    if (checkbox.checked) {
                        selectedTechnicianIds.add(technicianId);
                    } else {
                        selectedTechnicianIds.delete(technicianId);
                    }

                    await refreshBookingOutputs({ fetchCalendar: checkbox.checked });
                });
            });
        };

        const colorizedEvents = (events, suggestions = []) => [...visibleCalendarItems(events), ...visibleCalendarItems(suggestions)].map((event) => {
            const technicianId = String(event.extendedProps?.technician_id || '');
            const color = currentTechnicianColors[technicianId] || '#31424c';
            const isDeleted = Boolean(event.extendedProps?.deleted_at);
            const isSuggestion = Boolean(event.extendedProps?.is_suggestion);

            return {
                ...event,
                backgroundColor: isSuggestion || isDeleted ? `${color}26` : color,
                borderColor: isDeleted ? '#be123c' : color,
                textColor: isSuggestion ? color : '#ffffff',
                classNames: [
                    ...(isDeleted ? ['appointment-soft-deleted'] : []),
                    ...(isSuggestion ? ['appointment-suggestion'] : []),
                ],
            };
        });

        const refreshCalendarWindow = async (dateInfo, options = {}) => {
            lastCalendarDateInfo = dateInfo;

            const force = Boolean(options.force);

            if ((!force && !shouldFetchCalendarWindow) || !currentAnalysisPayload || !bookingCalendar) return;

            if (selectedTechnicianIds.size === 0) {
                bookingCalendar.removeAllEvents();
                hideCalendarLoader();
                return;
            }

            calendarWindowAbortController?.abort();
            calendarWindowAbortController = new AbortController();
            const requestId = ++calendarWindowRequestId;

            showCalendarLoader();

            try {
                const response = await fetch(bookingCalendarWindowUrl, {
                    method: 'POST',
                    signal: calendarWindowAbortController.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({
                        ...currentAnalysisPayload,
                        start: dateInfo.startStr,
                        end: dateInfo.endStr,
                        technician_ids: selectedTechnicianIdsArray(),
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Calcul des propositions impossible.');
                }

                if (requestId !== calendarWindowRequestId) return;

                currentCalendarEvents = payload.events || [];
                currentCalendarSuggestions = payload.suggestions || [];
                bookingCalendar.removeAllEvents();
                bookingCalendar.addEventSource(colorizedEvents(currentCalendarEvents, currentCalendarSuggestions));
            } catch (error) {
                if (error.name !== 'AbortError') {
                    showFeedback(error.message || 'Erreur pendant le calcul des propositions.', 'error');
                }
            } finally {
                if (requestId === calendarWindowRequestId) {
                    hideCalendarLoader();
                }
            }
        };

        const renderCalendar = (events, suggestions = [], focusDate = null, shouldAutoFocus = true) => {
            const calendarElement = document.getElementById('booking-calendar');
            const preparedEvents = colorizedEvents(events, suggestions);
            const firstSuggestionStart = shouldAutoFocus
                ? visibleCalendarItems(suggestions).find((suggestion) => suggestion?.start)?.start || null
                : null;
            const calendarFocusDate = focusDate || firstSuggestionStart;

            if (bookingCalendar) {
                bookingCalendar.removeAllEvents();
                bookingCalendar.addEventSource(preparedEvents);
                if (calendarFocusDate) {
                    bookingCalendar.gotoDate(calendarFocusDate);
                }
                return;
            }

            bookingCalendar = new FullCalendar.Calendar(calendarElement, {
                locale: 'fr',
                initialView: 'timeGridWeek',
                firstDay: 1,
                hiddenDays: [0, 6],
                allDaySlot: false,
                height: 'auto',
                nowIndicator: true,
                slotMinTime: '08:00:00',
                slotMaxTime: '21:00:00',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridWeek,dayGridMonth',
                },
                buttonText: {
                    today: 'Aujourd hui',
                    month: 'Mois',
                    week: 'Semaine',
                },
                eventClick: (info) => openBookingAppointmentModal(info.event),
                dateClick: openCalendarSlotModal,
                datesSet: refreshCalendarWindow,
                events: preparedEvents,
            });

            bookingCalendar.render();

            if (calendarFocusDate) {
                bookingCalendar.gotoDate(calendarFocusDate);
            }
        };

        const refreshBookingOutputs = async ({ fetchCalendar = false } = {}) => {
            if (!currentAppointmentRequest || !currentFilters) return;

            refreshTechnicianColors();
            renderTechnicians(currentTechnicians, currentAppointmentRequest, currentFilters);
            await renderMap(currentAppointmentRequest, selectedTechnicians());
            renderCalendar(currentCalendarEvents, currentCalendarSuggestions, null, false);

            if (fetchCalendar && lastCalendarDateInfo) {
                await refreshCalendarWindow(lastCalendarDateInfo, { force: true });
            }
        };

        const analyzeAppointment = async (payload, sourceLabel = 'RDV') => {
            currentAnalysisPayload = payload;
            currentCrmAppointmentId = payload.crm_appointment_id || null;
            analysisSection.classList.remove('hidden');
            clearFeedback();
            techniciansList.innerHTML = '<div class="rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Analyse en cours...</div>';

            try {
                const response = await fetch(bookingAnalyzeUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Analyse impossible.');
                }

                const suggestions = data.suggestions || [];
                currentAppointmentRequest = data.crm_appointment;
                currentTechnicians = data.technicians || [];
                currentTechnicianColors = {};
                selectedTechnicianIds = new Set(currentTechnicians.map((technician) => String(technician.id)));
                currentFilters = data.filters || null;
                currentCalendarEvents = data.events || [];
                currentCalendarSuggestions = suggestions;
                technicianSearchInput.value = '';
                clearTechnicianSearch();
                shouldFetchCalendarWindow = false;
                calendarWindowAbortController?.abort();
                hideCalendarLoader();

                renderTechnicians(currentTechnicians, currentAppointmentRequest, currentFilters);
                await renderMap(currentAppointmentRequest, selectedTechnicians());
                renderCalendar(currentCalendarEvents, currentCalendarSuggestions, currentFilters?.preferred_starts_at || null);
                window.setTimeout(() => {
                    shouldFetchCalendarWindow = true;
                }, 250);

                if (data.technicians.length === 0) {
                    showFeedback(`Aucun technicien eligible pour ce ${sourceLabel}.`, 'error');
                } else if (suggestions.length === 0) {
                    showFeedback('Aucune proposition de placement calculee avec les contraintes actuelles.', 'error');
                } else if (sourceLabel === 'RDV manuel') {
                    showFeedback(`${suggestions.length} proposition(s) de placement calculee(s) pour ce RDV manuel.`);
                }

                return true;
            } catch (error) {
                showFeedback(error.message || 'Erreur pendant l analyse du RDV.', 'error');
                return false;
            }
        };

        const analyzeCrmAppointment = async (crmId) => analyzeAppointment({ crm_appointment_id: crmId }, 'RDV CRM');

        const manualAppointmentPayload = () => ({
            first_name: document.getElementById('manual_first_name').value.trim(),
            last_name: document.getElementById('manual_last_name').value.trim(),
            phone: document.getElementById('manual_phone').value.trim(),
            service_id: document.getElementById('manual_service_id').value,
            address: document.getElementById('manual_address').value.trim(),
            department_code: document.getElementById('manual_department_code').value.trim(),
            latitude: document.getElementById('manual_latitude').value,
            longitude: document.getElementById('manual_longitude').value,
        });

        const setTechnicianSearchStatus = (message, type = 'info') => {
            technicianSearchStatus.textContent = message;
            technicianSearchStatus.style.color = type === 'error' ? '#be123c' : 'var(--gc-text-soft)';
            technicianSearchStatus.classList.remove('hidden');
        };

        const clearTechnicianSearch = () => {
            technicianSearchStatus.textContent = '';
            technicianSearchStatus.classList.add('hidden');
            technicianSearchResults.innerHTML = '';
            technicianSearchResults.classList.add('hidden');
        };

        const renderTechnicianSearchResults = (technicians) => {
            if (technicians.length === 0) {
                technicianSearchResults.innerHTML = '<div class="rounded-xl border p-3 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucun technicien trouve.</div>';
                technicianSearchResults.classList.remove('hidden');
                return;
            }

            technicianSearchResults.innerHTML = technicians.map((technician) => {
                const technicianId = String(technician.id);
                const isKnown = currentTechnicians.some((currentTechnician) => String(currentTechnician.id) === technicianId);
                const isSelected = selectedTechnicianIds.has(technicianId);
                const actionLabel = isSelected ? 'Deja affiche' : (isKnown ? 'Recocher' : 'Ajouter');
                const coverageLabel = technician.covers_requested_department ? 'Dept. couvert' : 'Hors dept.';

                return `
                    <article class="flex items-center justify-between gap-3 rounded-xl border p-3" style="border-color:var(--gc-border);">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold" style="color:var(--gc-text);">${escapeHtml(technician.name)}</p>
                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">${escapeHtml(technician.driving_distance_km)} km · ${escapeHtml(technician.driving_duration_minutes)} min · ${coverageLabel}</p>
                        </div>
                        <button
                            type="button"
                            class="${isSelected ? 'gc-btn-soft opacity-60' : 'gc-btn-primary'} shrink-0 px-3 py-2 text-xs"
                            data-search-technician-id="${escapeHtml(technician.id)}"
                            ${isSelected ? 'disabled' : ''}
                        >
                            ${actionLabel}
                        </button>
                    </article>
                `;
            }).join('');
            technicianSearchResults.classList.remove('hidden');

            technicianSearchResults.querySelectorAll('[data-search-technician-id]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const technician = technicians.find((candidate) => String(candidate.id) === String(button.dataset.searchTechnicianId));

                    if (!technician) return;

                    button.disabled = true;
                    button.textContent = 'Ajout...';
                    upsertTechnician(technician);
                    await refreshBookingOutputs({ fetchCalendar: true });
                    technicianSearchInput.value = '';
                    clearTechnicianSearch();
                    setTechnicianSearchStatus(`${technician.name} ajoute a la selection.`);
                });
            });
        };

        const searchTechnicians = async (query) => {
            const normalizedQuery = query.trim();

            if (normalizedQuery.length < 2) {
                clearTechnicianSearch();
                return;
            }

            if (!currentAnalysisPayload) {
                setTechnicianSearchStatus('Lance d abord une analyse CRM ou manuelle.', 'error');
                return;
            }

            technicianSearchAbortController?.abort();
            technicianSearchAbortController = new AbortController();
            setTechnicianSearchStatus('Recherche en cours...');

            try {
                const response = await fetch(bookingTechnicianSearchUrl, {
                    method: 'POST',
                    signal: technicianSearchAbortController.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({
                        ...currentAnalysisPayload,
                        query: normalizedQuery,
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Recherche technicien impossible.');
                }

                technicianSearchStatus.classList.add('hidden');
                renderTechnicianSearchResults(payload.technicians || []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setTechnicianSearchStatus(error.message || 'Recherche technicien impossible.', 'error');
                }
            }
        };

        document.querySelectorAll('.crm-appointment-card').forEach((card) => {
            card.addEventListener('click', () => analyzeCrmAppointment(card.dataset.crmId));
        });

        technicianSearchInput.addEventListener('input', () => {
            window.clearTimeout(technicianSearchTimer);
            technicianSearchTimer = window.setTimeout(() => searchTechnicians(technicianSearchInput.value), 320);
        });

        technicianSelectAllButton.addEventListener('click', async () => {
            currentTechnicians.forEach((technician) => selectedTechnicianIds.add(String(technician.id)));
            await refreshBookingOutputs({ fetchCalendar: true });
        });

        document.getElementById('booking-confirmation-new').addEventListener('click', () => {
            window.location.reload();
        });

        document.getElementById('manual-booking-toggle').addEventListener('click', () => {
            manualBookingSection.classList.toggle('hidden');
            if (!manualBookingSection.classList.contains('hidden')) {
                initManualAddressAutocomplete();
                document.getElementById('manual_last_name').focus();
            }
        });

        document.getElementById('manual-booking-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            clearManualStatus();

            const submitButton = document.getElementById('manual-booking-submit');
            const manualPayload = manualAppointmentPayload();

            if (!manualPayload.department_code || !manualPayload.latitude || !manualPayload.longitude) {
                setManualStatus('Selectionne une adresse Mapbox pour recuperer le departement et les coordonnees.', 'error');
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Recherche...';

            try {
                const analyzed = await analyzeAppointment({ manual_appointment: manualPayload }, 'RDV manuel');
                if (analyzed) {
                    setManualStatus('Recherche lancee.');
                }
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Trouver un technicien';
            }
        });

        document.getElementById('booking-modal-close').addEventListener('click', closeBookingAppointmentModal);
        bookingAppointmentModal.addEventListener('click', (event) => {
            if (event.target === bookingAppointmentModal) closeBookingAppointmentModal();
        });

        document.getElementById('booking-save-comment-btn').addEventListener('click', async () => {
            const appointmentId = document.getElementById('booking_detail_appointment_id').value;
            const comment = document.getElementById('booking_detail_comment').value;
            const button = document.getElementById('booking-save-comment-btn');

            if (!appointmentId) return;

            button.disabled = true;
            button.textContent = 'Enregistrement...';

            try {
                const response = await fetch(bookingCommentUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({ comment }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Enregistrement impossible.';
                    throw new Error(firstError);
                }

                selectedCalendarEvent?.setExtendedProp('comment', payload.comment || comment);
                showDetailStatus('Commentaire enregistre.');
            } catch (error) {
                showDetailStatus(error.message || 'Enregistrement impossible.', 'error');
            } finally {
                button.disabled = false;
                button.textContent = 'Enregistrer commentaire';
            }
        });

        document.getElementById('booking-confirm-suggestion-btn').addEventListener('click', async () => {
            const button = document.getElementById('booking-confirm-suggestion-btn');
            const payload = {
                ...(currentAnalysisPayload || { crm_appointment_id: document.getElementById('booking_detail_crm_id').value }),
                technician_id: document.getElementById('booking_detail_technician_id').value,
                starts_at: document.getElementById('booking_detail_starts_at').value,
                duration_minutes: document.getElementById('booking_detail_duration').value,
                comment: document.getElementById('booking_detail_comment').value,
            };

            button.disabled = true;
            button.textContent = 'Validation...';

            try {
                const response = await fetch(bookingStoreUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();

                if (!response.ok) {
                    const firstError = data?.errors ? Object.values(data.errors)[0][0] : data.message || 'Creation impossible.';
                    throw new Error(firstError);
                }

                const confirmedEvent = selectedCalendarEvent;
                closeBookingAppointmentModal();
                showPlacementConfirmation(data, payload, confirmedEvent);
            } catch (error) {
                showDetailStatus(error.message || 'Creation impossible.', 'error');
            } finally {
                button.disabled = false;
                button.textContent = 'Valider la prise du RDV';
            }
        });

        window.addEventListener('techcalendar:layout-resized', () => {
            bookingMap?.resize();
            detailMap?.resize();
            bookingCalendar?.updateSize();
        });
    </script>
</x-layouts.app>
