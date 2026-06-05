<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Planning</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Prise de rdv intelligent</h1>
        </div>

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#fecaca;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Imports CRM</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Rendez-vous à placer</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Demandes incomplètes récupérées depuis les CRM externes.</p>
                </div>
                <button id="refresh-crm-appointments-btn" type="button" class="gc-btn-primary">Refresh CRM</button>
            </div>

            <div id="crm-appointments-feedback" class="mb-4 hidden rounded-lg border px-4 py-3 text-sm"></div>
            <div id="crm-appointments-list" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5"></div>
        </section>

        <section class="gc-card p-5">
            <div class="mb-4 flex items-center gap-2 text-sm font-medium">
                <span class="rounded-full px-3 py-1" style="background:var(--gc-accent-soft);color:var(--gc-text);">Etape 1</span>
                <span style="color:var(--gc-text-soft);">Informations du rendez-vous</span>
            </div>

            <form id="planner-booking-form" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label" for="appointment_first_name">Prenom</label>
                        <input id="appointment_first_name" type="text" class="gc-input" placeholder="Prenom client" required />
                    </div>
                    <div>
                        <label class="gc-label" for="appointment_last_name">Nom</label>
                        <input id="appointment_last_name" type="text" class="gc-input" placeholder="Nom client" required />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label" for="appointment_phone">Telephone</label>
                        <input id="appointment_phone" type="text" class="gc-input" placeholder="06..." required />
                    </div>
                    <div>
                        <label class="gc-label" for="appointment_date">Date cible <span class="font-normal" style="color:var(--gc-text-soft);">(optionnel)</span></label>
                        <input id="appointment_date" type="date" class="gc-input" />
                    </div>
                </div>

                <div class="relative">
                    <label class="gc-label" for="appointment_address">Adresse</label>
                    <input id="appointment_address" type="text" class="gc-input" placeholder="Saisir une adresse" required />
                    <input id="appointment_latitude" type="hidden" />
                    <input id="appointment_longitude" type="hidden" />
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button id="find-technicians-btn" type="submit" class="gc-btn-primary">Calculer les techniciens les plus proches</button>
                </div>
            </form>

            <div id="planner-booking-feedback" class="mt-4 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <div id="planner-booking-results" class="mt-6 hidden">
                <h2 id="closest-driving-title" class="mb-3 text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Top 4 (tries par temps voiture)</h2>
                <div id="closest-driving-list" class="grid grid-cols-1 gap-3 md:grid-cols-2"></div>
                <div class="mt-5">
                    <h3 id="planner-routes-map-title" class="mb-2 text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Carte des 4 trajets</h3>
                    <div id="planner-routes-map" class="overflow-hidden rounded-xl border" style="height:620px;border-color:var(--gc-border);"></div>
                </div>
            </div>
        </section>

        <section id="planner-calendar-section" class="gc-card hidden p-5">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Calendrier des rendez-vous</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">RDV existants des techniciens retournes par la recherche.</p>
                </div>
            </div>

            <div id="planner-calendar"></div>
        </section>
    </div>

    <div id="create-appointment-modal" class="gc-modal hidden">
        <div class="gc-modal-panel">
            <h2 class="text-lg font-semibold">Placer le rendez-vous</h2>
            <form id="create-appointment-form" method="POST" action="{{ route('planner.book.store') }}" class="mt-4 space-y-4">
                @csrf

                <input id="modal_technician_id" name="technician_id" type="hidden" />
                <input id="modal_latitude" name="latitude" type="hidden" />
                <input id="modal_longitude" name="longitude" type="hidden" />

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label">Technicien</label>
                        <input id="modal_technician_name" type="text" class="gc-input" readonly />
                    </div>
                    <div>
                        <label class="gc-label">Telephone tech</label>
                        <input id="modal_technician_phone" type="text" class="gc-input" readonly />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label">Prenom client</label>
                        <input id="modal_customer_first_name" name="customer_first_name" type="text" class="gc-input" readonly />
                    </div>
                    <div>
                        <label class="gc-label">Nom client</label>
                        <input id="modal_customer_last_name" name="customer_last_name" type="text" class="gc-input" readonly />
                    </div>
                </div>

                <div>
                    <label class="gc-label">Telephone client</label>
                    <input id="modal_customer_phone" name="customer_phone" type="text" class="gc-input" readonly />
                </div>

                <div>
                    <label class="gc-label">Adresse du rdv</label>
                    <input id="modal_address" name="address" type="text" class="gc-input" readonly />
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label" for="modal_service_id">Prestation</label>
                        <select id="modal_service_id" name="service_id" class="gc-input" required>
                            <option value="">Selectionner</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}" data-duration="{{ $service->average_duration_minutes }}">{{ $service->type }} - {{ $service->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="gc-label" for="modal_starts_at">Date et heure du rdv</label>
                        <input id="modal_starts_at" name="starts_at" type="datetime-local" class="gc-input" required />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="gc-label" for="modal_duration_minutes">Duree (minutes)</label>
                        <input id="modal_duration_minutes" name="duration_minutes" type="number" min="15" max="600" class="gc-input" required />
                    </div>
                    <div>
                        <label class="gc-label" for="modal_ends_at">Fini a</label>
                        <input id="modal_ends_at" type="text" class="gc-input" readonly />
                    </div>
                </div>

                <div>
                    <label class="gc-label" for="modal_comment">Commentaire</label>
                    <textarea id="modal_comment" name="comment" rows="3" class="gc-input" style="min-height:96px;"></textarea>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" class="gc-link" data-modal-close="create-appointment-modal">Annuler</button>
                    <button type="submit" class="gc-btn-primary">Creer le rdv</button>
                </div>
            </form>
        </div>
    </div>

    <div id="appointment-detail-modal" class="gc-modal hidden">
        <div class="gc-modal-panel max-w-6xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Rendez-vous</p>
                    <h2 id="detail_service" class="text-lg font-semibold" style="color:var(--gc-text);"></h2>
                </div>
                <button type="button" class="gc-link" data-modal-close="appointment-detail-modal">Fermer</button>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-[0.9fr_1.1fr]">
                <div class="space-y-4">
                    <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Infos RDV</h3>
                        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                            <div>
                                <dt style="color:var(--gc-text-soft);">Client</dt>
                                <dd id="detail_customer" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Telephone client</dt>
                                <dd id="detail_customer_phone" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Technicien</dt>
                                <dd id="detail_technician" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Telephone tech</dt>
                                <dd id="detail_technician_phone" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Debut</dt>
                                <dd id="detail_starts_at" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Fin</dt>
                                <dd id="detail_ends_at" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div class="md:col-span-2">
                                <dt style="color:var(--gc-text-soft);">Cree par</dt>
                                <dd id="detail_created_by" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div class="md:col-span-2">
                                <dt style="color:var(--gc-text-soft);">Adresse RDV</dt>
                                <dd id="detail_address" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                        </dl>
                    </div>

                    <form id="appointment-comment-form" class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <input id="detail_appointment_id" type="hidden" />
                        <label class="gc-label" for="detail_comment">Commentaires</label>
                        <textarea id="detail_comment" rows="6" class="gc-input" style="min-height:150px;" placeholder="Ajouter un commentaire au rendez-vous"></textarea>
                        <div id="detail_comment_status" class="mt-2 hidden text-sm"></div>
                        <div class="mt-3 flex justify-end">
                            <button id="save-appointment-comment-btn" type="submit" class="gc-btn-primary">Enregistrer le commentaire</button>
                        </div>
                    </form>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Trajet technicien vers RDV</h3>
                        <span class="text-xs" style="color:var(--gc-text-soft);">Carte verrouillee</span>
                    </div>
                    <div id="appointment-route-map" class="overflow-hidden rounded-xl border" style="height:520px;border-color:var(--gc-border);"></div>
                    <div id="appointment-route-map-status" class="mt-2 hidden rounded-lg border px-3 py-2 text-sm"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="slot-suggestion-modal" class="gc-modal hidden">
        <div class="gc-modal-panel max-w-6xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Suggestion depuis le planning</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Techniciens les plus proches du créneau</h2>
                    <p id="slot_suggestion_subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>
                </div>
                <button type="button" class="gc-link" data-modal-close="slot-suggestion-modal">Fermer</button>
            </div>

            <div id="slot_suggestion_feedback" class="mt-4 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-[0.9fr_1.1fr]">
                <div>
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Ordre recalculé</h3>
                    <div id="slot_suggestion_list" class="space-y-3"></div>
                </div>
                <div>
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Trajets vers le RDV</h3>
                    <div id="slot-suggestion-map" class="overflow-hidden rounded-xl border" style="height:560px;border-color:var(--gc-border);"></div>
                </div>
            </div>
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const MAPBOX_TOKEN = @json($mapboxToken);

        const addressInput = document.getElementById('appointment_address');
        const latInput = document.getElementById('appointment_latitude');
        const lngInput = document.getElementById('appointment_longitude');
        const firstNameInput = document.getElementById('appointment_first_name');
        const lastNameInput = document.getElementById('appointment_last_name');
        const phoneInput = document.getElementById('appointment_phone');
        const appointmentDateInput = document.getElementById('appointment_date');
        const crmAppointmentsList = document.getElementById('crm-appointments-list');
        const crmAppointmentsFeedback = document.getElementById('crm-appointments-feedback');
        const refreshCrmAppointmentsButton = document.getElementById('refresh-crm-appointments-btn');
        const bookingForm = document.getElementById('planner-booking-form');
        const createAppointmentForm = document.getElementById('create-appointment-form');
        const feedbackBox = document.getElementById('planner-booking-feedback');
        const resultsBox = document.getElementById('planner-booking-results');
        const closestDrivingTitle = document.getElementById('closest-driving-title');
        const routesMapTitle = document.getElementById('planner-routes-map-title');
        const drivingList = document.getElementById('closest-driving-list');
        const mapContainer = document.getElementById('planner-routes-map');
        const calendarSection = document.getElementById('planner-calendar-section');
        const appointmentMapContainer = document.getElementById('appointment-route-map');
        const appointmentMapStatus = document.getElementById('appointment-route-map-status');
        const appointmentCommentForm = document.getElementById('appointment-comment-form');
        const appointmentCommentUrlTemplate = @json(route('planner.book.appointments.comment', ['appointment' => '__APPOINTMENT__']));
        const slotSuggestionList = document.getElementById('slot_suggestion_list');
        const slotSuggestionFeedback = document.getElementById('slot_suggestion_feedback');
        const slotSuggestionSubtitle = document.getElementById('slot_suggestion_subtitle');
        const slotSuggestionMapContainer = document.getElementById('slot-suggestion-map');
        let routesMap = null;
        let appointmentRouteMap = null;
        let slotSuggestionMap = null;
        let mapInitialized = false;
        let appointmentMapInitialized = false;
        let slotSuggestionMapInitialized = false;
        let plannerCalendar = null;
        let selectedTechnicianIds = [];
        let selectedTechnicianColors = {};
        let selectedSlotStart = null;

        const bookingSearchStorageKey = 'tech-calendar:planner-booking-search:v1';
        const routeColors = ['#1d4ed8', '#0f766e', '#b45309', '#7e22ce', '#be123c', '#15803d'];

        @if (session('status') === 'Rendez-vous cree avec succes.')
            localStorage.removeItem(bookingSearchStorageKey);
        @endif

        const setFeedback = (message, type = 'info') => {
            feedbackBox.classList.remove('hidden');
            if (type === 'error') {
                feedbackBox.style.borderColor = '#fecaca';
                feedbackBox.style.background = '#fff1f2';
                feedbackBox.style.color = '#9f1239';
            } else {
                feedbackBox.style.borderColor = '#e3d3a0';
                feedbackBox.style.background = '#fcf8ea';
                feedbackBox.style.color = '#31424c';
            }
            feedbackBox.textContent = message;
        };

        const setCrmFeedback = (message, type = 'info') => {
            crmAppointmentsFeedback.classList.remove('hidden');
            crmAppointmentsFeedback.style.borderColor = type === 'error' ? '#fecaca' : '#e3d3a0';
            crmAppointmentsFeedback.style.background = type === 'error' ? '#fff1f2' : '#fcf8ea';
            crmAppointmentsFeedback.style.color = type === 'error' ? '#9f1239' : '#31424c';
            crmAppointmentsFeedback.textContent = message;
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const openModal = (id) => {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('hidden');
        };

        const formatDateTime = (value) => {
            if (!value) return '';

            return new Date(value).toLocaleString('fr-FR', {
                weekday: 'short',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        };

        const setElementText = (id, value) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value || '-';
        };

        const formatDateTimeLocal = (date) => {
            const pad = (value) => String(value).padStart(2, '0');

            return [
                date.getFullYear(),
                pad(date.getMonth() + 1),
                pad(date.getDate()),
            ].join('-') + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
        };

        const clearBookingSearchState = () => {
            localStorage.removeItem(bookingSearchStorageKey);
        };

        const saveBookingSearchState = (technicians, resultCount = technicians.length) => {
            localStorage.setItem(bookingSearchStorageKey, JSON.stringify({
                form: {
                    first_name: firstNameInput.value,
                    last_name: lastNameInput.value,
                    phone: phoneInput.value,
                    appointment_date: appointmentDateInput.value,
                    address: addressInput.value,
                    latitude: latInput.value,
                    longitude: lngInput.value,
                },
                technicians,
                result_count: resultCount,
                saved_at: new Date().toISOString(),
            }));
        };

        const bindSearchResultButtons = () => {
            document.querySelectorAll('.place-rdv-btn').forEach((button) => {
                button.addEventListener('click', () => {
                    const tech = JSON.parse(decodeURIComponent(button.dataset.tech));
                    openAppointmentModal(tech);
                });
            });
        };

        const renderSearchResults = (technicians, options = {}) => {
            const resultCount = Math.min(options.resultCount || technicians.length, technicians.length);
            closestDrivingTitle.textContent = `Top ${resultCount} (tries par temps voiture)`;
            routesMapTitle.textContent = `Carte des ${resultCount} trajets`;
            drivingList.innerHTML = technicians.map((item) => renderTechCard(item)).join('');
            bindSearchResultButtons();
            resultsBox.classList.remove('hidden');

            if (options.feedback !== false) {
                setFeedback(options.feedbackMessage || 'Recherche restauree depuis la session locale.');
            }

            ensureRoutesMap();
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    if (routesMap) routesMap.resize();
                    drawRoutesMap(
                        { lat: Number(latInput.value), lng: Number(lngInput.value) },
                        technicians.map((item) => ({
                            id: item.id,
                            latitude: Number(item.origin_latitude ?? item.latitude),
                            longitude: Number(item.origin_longitude ?? item.longitude),
                        }))
                    );
                });
            });

            showCalendarForTechnicians(technicians);
        };

        const restoreBookingSearchState = () => {
            const rawState = localStorage.getItem(bookingSearchStorageKey);
            if (!rawState) return;

            try {
                const state = JSON.parse(rawState);

                if (!state?.form || !Array.isArray(state.technicians) || state.technicians.length === 0) {
                    clearBookingSearchState();
                    return;
                }

                firstNameInput.value = state.form.first_name || '';
                lastNameInput.value = state.form.last_name || '';
                phoneInput.value = state.form.phone || '';
                appointmentDateInput.value = state.form.appointment_date || '';
                addressInput.value = state.form.address || '';
                latInput.value = state.form.latitude || '';
                lngInput.value = state.form.longitude || '';

                renderSearchResults(state.technicians, {
                    resultCount: state.result_count || state.technicians.length,
                    feedbackMessage: 'Recherche restauree: les donnees sont conservees tant que le RDV n est pas place.',
                });
            } catch (error) {
                clearBookingSearchState();
            }
        };

        const renderCrmAppointments = (appointments) => {
            crmAppointmentsList.innerHTML = appointments.map((appointment) => `
                <article class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="rounded-full px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text-soft);">${escapeHtml(appointment.source)}</span>
                            <h3 class="mt-3 font-semibold" style="color:var(--gc-text);">${escapeHtml(appointment.last_name)} ${escapeHtml(appointment.first_name)}</h3>
                        </div>
                    </div>
                    <div class="mt-3 space-y-1 text-sm" style="color:var(--gc-text-soft);">
                        <div>${escapeHtml(appointment.phone)}</div>
                        <div>${escapeHtml(appointment.address)}</div>
                    </div>
                    <button type="button" class="crm-find-tech-btn gc-btn-primary mt-4 w-full" data-crm="${encodeURIComponent(JSON.stringify(appointment))}">
                        Trouver un tech
                    </button>
                </article>
            `).join('');

            document.querySelectorAll('.crm-find-tech-btn').forEach((button) => {
                button.addEventListener('click', () => {
                    const appointment = JSON.parse(decodeURIComponent(button.dataset.crm));
                    useCrmAppointment(appointment, button);
                });
            });
        };

        const loadCrmAppointments = async () => {
            refreshCrmAppointmentsButton.disabled = true;
            refreshCrmAppointmentsButton.textContent = 'Refresh...';
            setCrmFeedback('Récupération des demandes CRM en cours...');

            try {
                const response = await fetch('{{ route('planner.book.crm-appointments') }}', {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload?.message || 'Erreur CRM.');
                }

                renderCrmAppointments(payload.appointments || []);
                setCrmFeedback('5 demandes CRM disponibles.');
            } catch (error) {
                setCrmFeedback('Impossible de récupérer les demandes CRM.', 'error');
            } finally {
                refreshCrmAppointmentsButton.disabled = false;
                refreshCrmAppointmentsButton.textContent = 'Refresh CRM';
            }
        };

        const geocodeCrmAddress = async (address) => {
            if (!MAPBOX_TOKEN) {
                throw new Error('Token Mapbox manquant.');
            }

            const url = new URL(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(address)}.json`);
            url.searchParams.set('access_token', MAPBOX_TOKEN);
            url.searchParams.set('language', 'fr');
            url.searchParams.set('country', 'fr');
            url.searchParams.set('limit', '1');

            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            const feature = payload?.features?.[0];

            if (!response.ok || !feature?.center?.[0] || !feature?.center?.[1]) {
                throw new Error('Adresse CRM impossible à géocoder.');
            }

            return {
                address: feature.place_name || address,
                longitude: feature.center[0],
                latitude: feature.center[1],
            };
        };

        const useCrmAppointment = async (appointment, button) => {
            if (button) {
                button.disabled = true;
                button.textContent = 'Recherche...';
            }
            setCrmFeedback('Géocodage de l’adresse CRM puis recherche des techniciens...');

            try {
                const geocoded = await geocodeCrmAddress(appointment.address);

                firstNameInput.value = appointment.first_name || '';
                lastNameInput.value = appointment.last_name || '';
                phoneInput.value = appointment.phone || '';
                addressInput.value = geocoded.address;
                lngInput.value = geocoded.longitude;
                latInput.value = geocoded.latitude;

                clearBookingSearchState();
                setFeedback('Demande CRM chargée, calcul des techniciens en cours...');
                bookingForm.requestSubmit();
            } catch (error) {
                setCrmFeedback(error.message || 'Impossible de lancer la recherche depuis cette demande CRM.', 'error');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Trouver un tech';
                }
            }
        };

        const getCrmAppointmentFromQuery = () => {
            const params = new URLSearchParams(window.location.search);

            if (!params.has('crm_address')) {
                return null;
            }

            return {
                source: params.get('crm_source') || 'CRM',
                first_name: params.get('crm_first_name') || '',
                last_name: params.get('crm_last_name') || '',
                phone: params.get('crm_phone') || '',
                address: params.get('crm_address') || '',
            };
        };

        const ensureRoutesMap = () => {
            if (!MAPBOX_TOKEN || !window.mapboxgl || !mapContainer || mapInitialized) return;

            window.mapboxgl.accessToken = MAPBOX_TOKEN;
            routesMap = new window.mapboxgl.Map({
                container: 'planner-routes-map',
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [2.3522, 48.8566],
                zoom: 10,
            });

            mapInitialized = true;
        };

        const ensureAppointmentRouteMap = () => {
            if (!MAPBOX_TOKEN || !window.mapboxgl || !appointmentMapContainer || appointmentMapInitialized) return;

            window.mapboxgl.accessToken = MAPBOX_TOKEN;
            appointmentRouteMap = new window.mapboxgl.Map({
                container: 'appointment-route-map',
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [2.3522, 48.8566],
                zoom: 10,
                interactive: false,
            });

            appointmentMapInitialized = true;
        };

        const ensureSlotSuggestionMap = () => {
            if (!MAPBOX_TOKEN || !window.mapboxgl || !slotSuggestionMapContainer || slotSuggestionMapInitialized) return;

            window.mapboxgl.accessToken = MAPBOX_TOKEN;
            slotSuggestionMap = new window.mapboxgl.Map({
                container: 'slot-suggestion-map',
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [2.3522, 48.8566],
                zoom: 10,
            });

            slotSuggestionMapInitialized = true;
        };

        const clearRoutesMapLayers = () => {
            if (!routesMap) return;

            const style = routesMap.getStyle();
            if (!style || !style.layers) return;

            style.layers
                .filter((layer) => layer.id.startsWith('route-line-') || layer.id.startsWith('route-point-'))
                .forEach((layer) => {
                    if (routesMap.getLayer(layer.id)) routesMap.removeLayer(layer.id);
                });

            Object.keys(style.sources || {})
                .filter((sourceId) => sourceId.startsWith('route-line-') || sourceId.startsWith('route-point-'))
                .forEach((sourceId) => {
                    if (routesMap.getSource(sourceId)) routesMap.removeSource(sourceId);
                });
        };

        const clearAppointmentRouteMapLayers = () => {
            if (!appointmentRouteMap) return;

            const style = appointmentRouteMap.getStyle();
            if (!style || !style.layers) return;

            style.layers
                .filter((layer) => layer.id.startsWith('appointment-route-'))
                .forEach((layer) => {
                    if (appointmentRouteMap.getLayer(layer.id)) appointmentRouteMap.removeLayer(layer.id);
                });

            Object.keys(style.sources || {})
                .filter((sourceId) => sourceId.startsWith('appointment-route-'))
                .forEach((sourceId) => {
                    if (appointmentRouteMap.getSource(sourceId)) appointmentRouteMap.removeSource(sourceId);
                });
        };

        const clearSlotSuggestionMapLayers = () => {
            if (!slotSuggestionMap) return;

            const style = slotSuggestionMap.getStyle();
            if (!style || !style.layers) return;

            style.layers
                .filter((layer) => layer.id.startsWith('slot-route-'))
                .forEach((layer) => {
                    if (slotSuggestionMap.getLayer(layer.id)) slotSuggestionMap.removeLayer(layer.id);
                });

            Object.keys(style.sources || {})
                .filter((sourceId) => sourceId.startsWith('slot-route-'))
                .forEach((sourceId) => {
                    if (slotSuggestionMap.getSource(sourceId)) slotSuggestionMap.removeSource(sourceId);
                });
        };

        const decodePolyline = (encoded) => {
            let index = 0;
            const length = encoded.length;
            let lat = 0;
            let lng = 0;
            const coordinates = [];

            while (index < length) {
                let b;
                let shift = 0;
                let result = 0;

                do {
                    b = encoded.charCodeAt(index++) - 63;
                    result |= (b & 0x1f) << shift;
                    shift += 5;
                } while (b >= 0x20);

                const dlat = (result & 1) ? ~(result >> 1) : (result >> 1);
                lat += dlat;

                shift = 0;
                result = 0;

                do {
                    b = encoded.charCodeAt(index++) - 63;
                    result |= (b & 0x1f) << shift;
                    shift += 5;
                } while (b >= 0x20);

                const dlng = (result & 1) ? ~(result >> 1) : (result >> 1);
                lng += dlng;

                coordinates.push([lng / 1e5, lat / 1e5]);
            }

            return coordinates;
        };

        const drawRoutesMap = async (origin, technicians) => {
            if (!MAPBOX_TOKEN || !window.mapboxgl || !routesMap) return;

            if (!routesMap.isStyleLoaded()) {
                routesMap.once('load', () => drawRoutesMap(origin, technicians));
                return;
            }

            clearRoutesMapLayers();

            const bounds = new window.mapboxgl.LngLatBounds();
            bounds.extend([origin.lng, origin.lat]);

            const originSourceId = 'route-point-origin';
            routesMap.addSource(originSourceId, {
                type: 'geojson',
                data: {
                    type: 'FeatureCollection',
                    features: [{
                        type: 'Feature',
                        geometry: { type: 'Point', coordinates: [origin.lng, origin.lat] },
                        properties: {},
                    }],
                },
            });
            routesMap.addLayer({
                id: originSourceId,
                type: 'circle',
                source: originSourceId,
                paint: {
                    'circle-radius': 10,
                    'circle-color': '#f43f5e',
                    'circle-stroke-width': 3,
                    'circle-stroke-color': '#ffffff',
                    'circle-opacity': 0.95,
                },
            });

            await Promise.all(technicians.map(async (tech, index) => {
                const color = routeColors[index % routeColors.length];
                const routeId = `route-line-${tech.id}`;
                const pointId = `route-point-${tech.id}`;

                bounds.extend([tech.longitude, tech.latitude]);

                try {
                    const directionsUrl = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${tech.longitude},${tech.latitude};${origin.lng},${origin.lat}`);
                    directionsUrl.searchParams.set('access_token', MAPBOX_TOKEN);
                    directionsUrl.searchParams.set('alternatives', 'false');
                    directionsUrl.searchParams.set('geometries', 'polyline');
                    directionsUrl.searchParams.set('overview', 'full');

                    const response = await fetch(directionsUrl.toString(), { headers: { Accept: 'application/json' } });
                    const data = await response.json();
                    const geometry = data?.routes?.[0]?.geometry;
                    if (!geometry) return;

                    const coordinates = decodePolyline(geometry);

                    routesMap.addSource(routeId, {
                        type: 'geojson',
                        data: {
                            type: 'Feature',
                            geometry: { type: 'LineString', coordinates },
                            properties: {},
                        },
                    });
                    routesMap.addLayer({
                        id: routeId,
                        type: 'line',
                        source: routeId,
                        paint: {
                            'line-color': color,
                            'line-width': 4,
                            'line-opacity': 0.75,
                        },
                    });
                } catch (error) {
                    console.error('Route draw error', error);
                }

                routesMap.addSource(pointId, {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: [{
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: [tech.longitude, tech.latitude] },
                            properties: {},
                        }],
                    },
                });
                routesMap.addLayer({
                    id: pointId,
                    type: 'circle',
                    source: pointId,
                    paint: {
                        'circle-radius': 6,
                        'circle-color': color,
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#ffffff',
                    },
                });
            }));

            routesMap.fitBounds(bounds, { padding: 50, maxZoom: 12 });
        };

        const setAppointmentMapStatus = (message, type = 'info') => {
            appointmentMapStatus.classList.remove('hidden');
            appointmentMapStatus.style.borderColor = type === 'error' ? '#fecaca' : '#e3d3a0';
            appointmentMapStatus.style.background = type === 'error' ? '#fff1f2' : '#fcf8ea';
            appointmentMapStatus.style.color = type === 'error' ? '#9f1239' : '#31424c';
            appointmentMapStatus.textContent = message;
        };

        const hideAppointmentMapStatus = () => {
            appointmentMapStatus.classList.add('hidden');
            appointmentMapStatus.textContent = '';
        };

        const drawAppointmentRouteMap = async (appointment) => {
            if (!MAPBOX_TOKEN || !window.mapboxgl) {
                setAppointmentMapStatus('Token Mapbox manquant: impossible d afficher le trajet.', 'error');
                return;
            }

            ensureAppointmentRouteMap();

            if (!appointmentRouteMap) return;

            if (!appointmentRouteMap.isStyleLoaded()) {
                appointmentRouteMap.once('load', () => drawAppointmentRouteMap(appointment));
                return;
            }

            hideAppointmentMapStatus();
            clearAppointmentRouteMapLayers();
            appointmentRouteMap.resize();

            const techLng = Number(appointment.technician_longitude);
            const techLat = Number(appointment.technician_latitude);
            const appointmentLng = Number(appointment.longitude);
            const appointmentLat = Number(appointment.latitude);

            if ([techLng, techLat, appointmentLng, appointmentLat].some((coordinate) => Number.isNaN(coordinate))) {
                setAppointmentMapStatus('Coordonnees incompletes pour dessiner le trajet.', 'error');
                return;
            }

            const bounds = new window.mapboxgl.LngLatBounds();
            bounds.extend([techLng, techLat]);
            bounds.extend([appointmentLng, appointmentLat]);

            try {
                const directionsUrl = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${techLng},${techLat};${appointmentLng},${appointmentLat}`);
                directionsUrl.searchParams.set('access_token', MAPBOX_TOKEN);
                directionsUrl.searchParams.set('alternatives', 'false');
                directionsUrl.searchParams.set('geometries', 'polyline');
                directionsUrl.searchParams.set('overview', 'full');

                const response = await fetch(directionsUrl.toString(), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                const route = data?.routes?.[0];

                if (!route?.geometry) {
                    setAppointmentMapStatus('Mapbox n a pas retourne d itineraire pour ce rendez-vous.', 'error');
                    return;
                }

                const coordinates = decodePolyline(route.geometry);
                coordinates.forEach((coordinate) => bounds.extend(coordinate));

                appointmentRouteMap.addSource('appointment-route-line', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        geometry: { type: 'LineString', coordinates },
                        properties: {},
                    },
                });
                appointmentRouteMap.addLayer({
                    id: 'appointment-route-line',
                    type: 'line',
                    source: 'appointment-route-line',
                    paint: {
                        'line-color': '#0f766e',
                        'line-width': 5,
                        'line-opacity': 0.82,
                    },
                });
            } catch (error) {
                setAppointmentMapStatus('Erreur pendant le chargement de l itineraire.', 'error');
            }

            [
                { id: 'appointment-route-tech', coordinates: [techLng, techLat], color: '#31424c', radius: 7 },
                { id: 'appointment-route-destination', coordinates: [appointmentLng, appointmentLat], color: '#f43f5e', radius: 11 },
            ].forEach((point) => {
                appointmentRouteMap.addSource(point.id, {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: [{
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: point.coordinates },
                            properties: {},
                        }],
                    },
                });
                appointmentRouteMap.addLayer({
                    id: point.id,
                    type: 'circle',
                    source: point.id,
                    paint: {
                        'circle-radius': point.radius,
                        'circle-color': point.color,
                        'circle-stroke-width': 3,
                        'circle-stroke-color': '#ffffff',
                    },
                });
            });

            appointmentRouteMap.fitBounds(bounds, { padding: 70, maxZoom: 13, duration: 0 });
        };

        const drawSlotSuggestionMap = async (destination, technicians) => {
            if (!MAPBOX_TOKEN || !window.mapboxgl || !slotSuggestionMap) return;

            if (!slotSuggestionMap.isStyleLoaded()) {
                slotSuggestionMap.once('load', () => drawSlotSuggestionMap(destination, technicians));
                return;
            }

            clearSlotSuggestionMapLayers();
            slotSuggestionMap.resize();

            const bounds = new window.mapboxgl.LngLatBounds();
            bounds.extend([destination.lng, destination.lat]);

            slotSuggestionMap.addSource('slot-route-destination', {
                type: 'geojson',
                data: {
                    type: 'FeatureCollection',
                    features: [{
                        type: 'Feature',
                        geometry: { type: 'Point', coordinates: [destination.lng, destination.lat] },
                        properties: {},
                    }],
                },
            });
            slotSuggestionMap.addLayer({
                id: 'slot-route-destination',
                type: 'circle',
                source: 'slot-route-destination',
                paint: {
                    'circle-radius': 11,
                    'circle-color': '#f43f5e',
                    'circle-stroke-width': 3,
                    'circle-stroke-color': '#ffffff',
                },
            });

            await Promise.all(technicians.map(async (tech, index) => {
                const color = routeColors[index % routeColors.length];
                const routeId = `slot-route-line-${tech.id}`;
                const pointId = `slot-route-origin-${tech.id}`;
                const originLng = Number(tech.origin_longitude);
                const originLat = Number(tech.origin_latitude);

                bounds.extend([originLng, originLat]);

                try {
                    const directionsUrl = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${originLng},${originLat};${destination.lng},${destination.lat}`);
                    directionsUrl.searchParams.set('access_token', MAPBOX_TOKEN);
                    directionsUrl.searchParams.set('alternatives', 'false');
                    directionsUrl.searchParams.set('geometries', 'polyline');
                    directionsUrl.searchParams.set('overview', 'full');

                    const response = await fetch(directionsUrl.toString(), { headers: { Accept: 'application/json' } });
                    const data = await response.json();
                    const geometry = data?.routes?.[0]?.geometry;
                    if (!geometry) return;

                    const coordinates = decodePolyline(geometry);
                    coordinates.forEach((coordinate) => bounds.extend(coordinate));

                    slotSuggestionMap.addSource(routeId, {
                        type: 'geojson',
                        data: {
                            type: 'Feature',
                            geometry: { type: 'LineString', coordinates },
                            properties: {},
                        },
                    });
                    slotSuggestionMap.addLayer({
                        id: routeId,
                        type: 'line',
                        source: routeId,
                        paint: {
                            'line-color': color,
                            'line-width': 4,
                            'line-opacity': 0.75,
                        },
                    });
                } catch (error) {
                    console.error('Slot route draw error', error);
                }

                slotSuggestionMap.addSource(pointId, {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: [{
                            type: 'Feature',
                            geometry: { type: 'Point', coordinates: [originLng, originLat] },
                            properties: {},
                        }],
                    },
                });
                slotSuggestionMap.addLayer({
                    id: pointId,
                    type: 'circle',
                    source: pointId,
                    paint: {
                        'circle-radius': 7,
                        'circle-color': color,
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#ffffff',
                    },
                });
            }));

            slotSuggestionMap.fitBounds(bounds, { padding: 60, maxZoom: 12 });
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('hidden');
        };

        const computeEndDate = () => {
            const startsAt = document.getElementById('modal_starts_at').value;
            const duration = Number(document.getElementById('modal_duration_minutes').value || 0);
            const endField = document.getElementById('modal_ends_at');

            if (!startsAt || !duration) {
                endField.value = '';
                return;
            }

            const startDate = new Date(startsAt);
            const endDate = new Date(startDate.getTime() + (duration * 60000));
            const datePart = endDate.toLocaleDateString('fr-FR');
            const timePart = endDate.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            endField.value = `${datePart} ${timePart}`;
        };

        const openAppointmentModal = (tech, options = {}) => {
            document.getElementById('modal_technician_id').value = tech.id;
            document.getElementById('modal_technician_name').value = tech.full_name;
            document.getElementById('modal_technician_phone').value = tech.phone || '';
            document.getElementById('modal_customer_first_name').value = firstNameInput.value;
            document.getElementById('modal_customer_last_name').value = lastNameInput.value;
            document.getElementById('modal_customer_phone').value = phoneInput.value;
            document.getElementById('modal_address').value = addressInput.value;
            document.getElementById('modal_latitude').value = latInput.value;
            document.getElementById('modal_longitude').value = lngInput.value;
            document.getElementById('modal_comment').value = '';

            const serviceSelect = document.getElementById('modal_service_id');
            serviceSelect.selectedIndex = 0;
            document.getElementById('modal_duration_minutes').value = '';
            document.getElementById('modal_starts_at').value = options.startsAt ? formatDateTimeLocal(new Date(options.startsAt)) : '';
            document.getElementById('modal_ends_at').value = '';

            openModal('create-appointment-modal');
        };

        const openAppointmentDetailModal = (calendarEvent) => {
            const props = calendarEvent.extendedProps;

            document.getElementById('detail_appointment_id').value = calendarEvent.id;
            setElementText('detail_service', props.service_label || calendarEvent.title);
            setElementText('detail_customer', props.customer_name);
            setElementText('detail_customer_phone', props.customer_phone);
            setElementText('detail_technician', props.technician_name);
            setElementText('detail_technician_phone', props.technician_phone);
            setElementText('detail_starts_at', formatDateTime(calendarEvent.start));
            setElementText('detail_ends_at', formatDateTime(calendarEvent.end));
            setElementText('detail_created_by', props.created_by_name);
            setElementText('detail_address', props.address);
            document.getElementById('detail_comment').value = props.comment || '';
            document.getElementById('detail_comment_status').classList.add('hidden');

            openModal('appointment-detail-modal');

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    drawAppointmentRouteMap({
                        technician_latitude: props.technician_latitude,
                        technician_longitude: props.technician_longitude,
                        latitude: props.latitude,
                        longitude: props.longitude,
                    });
                });
            });
        };

        const renderTechCard = (item) => {
            const durationLabel = item.drive_duration_minutes !== null ? `${item.drive_duration_minutes} min` : 'Temps indisponible';
            const distanceLabel = item.drive_distance_km !== null ? `${item.drive_distance_km} km en voiture` : 'Distance voiture indisponible';
            const color = routeColors[(item.route_rank - 1) % routeColors.length];
            const originLabel = item.origin_type === 'day_appointment'
                ? `Calcul depuis RDV du jour: ${item.origin_appointment?.customer_name || 'client'}`
                : 'Calcul depuis domicile tech';
            const originMeta = item.origin_appointment
                ? `${item.origin_appointment.service_label || 'Prestation'} · ${formatDateTime(item.origin_appointment.starts_at)}`
                : '';

            return `<div class="rounded-lg border p-3" style="border-color:var(--gc-border);">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="font-medium" style="color:var(--gc-text);">${escapeHtml(item.full_name)}</div>
                        <div class="text-sm" style="color:var(--gc-text-soft);">${durationLabel} · ${distanceLabel}</div>
                        <div class="mt-1 text-xs" style="color:var(--gc-text-soft);">Domicile: ${escapeHtml(item.address ?? 'Adresse non renseignee')}</div>
                        <div class="mt-2 rounded-md px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                            ${escapeHtml(originLabel)}
                            <div class="mt-1" style="color:var(--gc-text-soft);">${escapeHtml(item.origin_address || item.address || 'Origine non renseignee')}</div>
                            ${originMeta ? `<div class="mt-1" style="color:var(--gc-text-soft);">${escapeHtml(originMeta)}</div>` : ''}
                        </div>
                        <div class="mt-2 inline-flex items-center gap-2 rounded-md px-2 py-1 text-xs" style="background:#f8f8f8;color:var(--gc-text-soft);">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:9999px;background:${color};"></span>
                            Itineraire #${item.route_rank}
                        </div>
                    </div>
                    <button type="button" class="gc-btn-primary place-rdv-btn" data-tech="${encodeURIComponent(JSON.stringify(item))}">Placer le rdv</button>
                </div>
            </div>`;
        };

        const renderSlotSuggestionCard = (item) => {
            const durationLabel = item.drive_duration_minutes !== null ? `${item.drive_duration_minutes} min` : 'Temps indisponible';
            const distanceLabel = item.drive_distance_km !== null ? `${item.drive_distance_km} km` : 'Distance indisponible';
            const color = routeColors[(item.route_rank - 1) % routeColors.length];
            const originLabel = item.origin_type === 'previous_appointment'
                ? `Depuis RDV precedent: ${item.previous_appointment?.customer_name || 'client'}`
                : 'Depuis domicile tech';
            const originTime = item.previous_appointment?.ends_at
                ? `Fin precedente: ${formatDateTime(item.previous_appointment.ends_at)}`
                : '';

            return `<div class="rounded-lg border p-3" style="border-color:var(--gc-border);">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:9999px;background:${color};"></span>
                            <div class="font-medium" style="color:var(--gc-text);">#${item.route_rank} ${item.full_name}</div>
                        </div>
                        <div class="mt-1 text-sm" style="color:var(--gc-text-soft);">${durationLabel} · ${distanceLabel} en voiture</div>
                        <div class="mt-1 text-xs" style="color:var(--gc-text-soft);">${originLabel}</div>
                        <div class="mt-1 text-xs" style="color:var(--gc-text-soft);">${item.origin_address || 'Origine non renseignee'}</div>
                        ${originTime ? `<div class="mt-1 text-xs" style="color:var(--gc-text-soft);">${originTime}</div>` : ''}
                    </div>
                    <button type="button" class="gc-btn-primary choose-slot-tech-btn" data-tech="${encodeURIComponent(JSON.stringify(item))}">Choisir</button>
                </div>
            </div>`;
        };

        const initAddressAutocomplete = () => {
            if (!MAPBOX_TOKEN || !addressInput) return;

            const list = document.createElement('div');
            list.className = 'gc-mapbox-suggestions hidden';
            addressInput.parentElement.appendChild(list);

            let debounceTimer;
            addressInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);

                const query = addressInput.value.trim();
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

                    const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                    const data = await response.json();
                    const features = Array.isArray(data.features) ? data.features : [];

                    if (features.length === 0) {
                        list.innerHTML = '';
                        list.classList.add('hidden');
                        return;
                    }

                    list.innerHTML = features
                        .map((feature) => `<button type="button" class="gc-mapbox-item" data-address="${feature.place_name}" data-lng="${feature.center?.[0] ?? ''}" data-lat="${feature.center?.[1] ?? ''}">${feature.place_name}</button>`)
                        .join('');
                    list.classList.remove('hidden');

                    list.querySelectorAll('.gc-mapbox-item').forEach((item) => {
                        item.addEventListener('click', () => {
                            addressInput.value = item.dataset.address || '';
                            lngInput.value = item.dataset.lng || '';
                            latInput.value = item.dataset.lat || '';
                            list.innerHTML = '';
                            list.classList.add('hidden');
                        });
                    });
                }, 250);
            });
        };

        const setSlotSuggestionFeedback = (message, type = 'info') => {
            slotSuggestionFeedback.classList.remove('hidden');
            slotSuggestionFeedback.style.borderColor = type === 'error' ? '#fecaca' : '#e3d3a0';
            slotSuggestionFeedback.style.background = type === 'error' ? '#fff1f2' : '#fcf8ea';
            slotSuggestionFeedback.style.color = type === 'error' ? '#9f1239' : '#31424c';
            slotSuggestionFeedback.textContent = message;
        };

        const openSlotSuggestionModal = async (date) => {
            if (selectedTechnicianIds.length === 0) {
                setFeedback('Lance d abord une recherche de techniciens avant de choisir un creneau.', 'error');
                return;
            }

            if (!firstNameInput.value || !lastNameInput.value || !phoneInput.value) {
                setFeedback('Renseigne prenom, nom et telephone du client avant de choisir un creneau.', 'error');
                return;
            }

            if (!latInput.value || !lngInput.value) {
                setFeedback('Selectionne une adresse RDV dans les suggestions Mapbox avant de choisir un creneau.', 'error');
                return;
            }

            selectedSlotStart = date;
            slotSuggestionSubtitle.textContent = `Creneau clique: ${formatDateTime(date)} · destination: ${addressInput.value}`;
            slotSuggestionList.innerHTML = '';
            setSlotSuggestionFeedback('Recalcul des trajets depuis le dernier RDV precedent de chaque tech...', 'info');
            openModal('slot-suggestion-modal');

            try {
                const response = await fetch('{{ route('planner.book.suggest-technicians-for-slot') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        technician_ids: selectedTechnicianIds,
                        latitude: latInput.value,
                        longitude: lngInput.value,
                        starts_at: date.toISOString(),
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Erreur pendant le recalcul du creneau.';
                    setSlotSuggestionFeedback(firstError, 'error');
                    return;
                }

                const rankedTechnicians = (payload.closest_driving || []).map((item, index) => ({
                    ...item,
                    route_rank: index + 1,
                }));

                if (rankedTechnicians.length === 0) {
                    setSlotSuggestionFeedback('Aucun technicien disponible sur ce creneau.', 'error');
                    return;
                }

                slotSuggestionList.innerHTML = rankedTechnicians.map((item) => renderSlotSuggestionCard(item)).join('');
                setSlotSuggestionFeedback('Ordre recalcule selon le point de depart reel au moment du creneau.', 'info');

                document.querySelectorAll('.choose-slot-tech-btn').forEach((button) => {
                    button.addEventListener('click', () => {
                        const tech = JSON.parse(decodeURIComponent(button.dataset.tech));
                        closeModal('slot-suggestion-modal');
                        openAppointmentModal(tech, { startsAt: selectedSlotStart });
                    });
                });

                ensureSlotSuggestionMap();
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        drawSlotSuggestionMap(
                            { lat: Number(latInput.value), lng: Number(lngInput.value) },
                            rankedTechnicians
                        );
                    });
                });
            } catch (error) {
                setSlotSuggestionFeedback('Erreur reseau pendant le recalcul du creneau.', 'error');
            }
        };

        bookingForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!firstNameInput.value || !lastNameInput.value || !phoneInput.value) {
                setFeedback('Renseigne prenom, nom et telephone du client.', 'error');
                return;
            }

            if (!latInput.value || !lngInput.value) {
                setFeedback('Selectionne une adresse dans les suggestions Mapbox.', 'error');
                return;
            }

            const submitButton = document.getElementById('find-technicians-btn');
            submitButton.disabled = true;
            submitButton.textContent = 'Calcul en cours...';
            clearBookingSearchState();

            try {
                const response = await fetch('{{ route('planner.book.suggest-technicians') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        address: addressInput.value,
                        latitude: latInput.value,
                        longitude: lngInput.value,
                        appointment_date: appointmentDateInput.value || null,
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Erreur pendant le calcul des techniciens.';
                    setFeedback(firstError, 'error');
                    return;
                }

                const resultCount = payload.result_limit || (appointmentDateInput.value ? 6 : 4);
                const closestTechnicians = payload.closest_driving.slice(0, resultCount).map((item, index) => ({
                    ...item,
                    route_rank: index + 1,
                }));

                saveBookingSearchState(closestTechnicians, resultCount);
                renderSearchResults(closestTechnicians, {
                    resultCount,
                    feedbackMessage: appointmentDateInput.value
                        ? 'Calcul date termine: domicile et RDV du jour pris en compte. Recherche conservee localement jusqu au placement du RDV.'
                        : 'Calcul termine: recherche conservee localement jusqu au placement du RDV.',
                });
            } catch (error) {
                setFeedback('Erreur reseau pendant le calcul.', 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Calculer les techniciens les plus proches';
            }
        });

        document.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.dataset.modalClose));
        });

        refreshCrmAppointmentsButton.addEventListener('click', loadCrmAppointments);

        document.getElementById('create-appointment-modal').addEventListener('click', (event) => {
            if (event.target.id === 'create-appointment-modal') closeModal('create-appointment-modal');
        });

        document.getElementById('appointment-detail-modal').addEventListener('click', (event) => {
            if (event.target.id === 'appointment-detail-modal') closeModal('appointment-detail-modal');
        });

        document.getElementById('slot-suggestion-modal').addEventListener('click', (event) => {
            if (event.target.id === 'slot-suggestion-modal') closeModal('slot-suggestion-modal');
        });

        appointmentCommentForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const appointmentId = document.getElementById('detail_appointment_id').value;
            const comment = document.getElementById('detail_comment').value;
            const status = document.getElementById('detail_comment_status');
            const button = document.getElementById('save-appointment-comment-btn');

            if (!appointmentId) return;

            button.disabled = true;
            button.textContent = 'Enregistrement...';
            status.classList.add('hidden');

            try {
                const response = await fetch(appointmentCommentUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ comment }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload?.message || 'Erreur pendant la sauvegarde.');
                }

                const event = plannerCalendar?.getEventById(appointmentId);
                if (event) event.setExtendedProp('comment', payload.comment || '');

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

        const serviceSelect = document.getElementById('modal_service_id');
        const durationInput = document.getElementById('modal_duration_minutes');
        const startsAtInput = document.getElementById('modal_starts_at');

        serviceSelect.addEventListener('change', () => {
            const option = serviceSelect.options[serviceSelect.selectedIndex];
            const duration = option.dataset.duration || '';
            durationInput.value = duration;
            computeEndDate();
        });

        durationInput.addEventListener('input', computeEndDate);
        startsAtInput.addEventListener('change', computeEndDate);

        const initCalendar = () => {
            const calendarEl = document.getElementById('planner-calendar');
            if (!calendarEl || !window.FullCalendar || plannerCalendar) return;

            plannerCalendar = new window.FullCalendar.Calendar(calendarEl, {
                initialView: 'timeGridWeek',
                locale: 'fr',
                firstDay: 1,
                buttonText: {
                    today: "Aujourd'hui",
                    month: 'Mois',
                    week: 'Semaine',
                    day: 'Jour',
                    list: 'Liste',
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
                slotMaxTime: '20:00:00',
                height: 700,
                nowIndicator: true,
                weekends: false,
                allDaySlot: false,
                allDayText: '',
                events: async (fetchInfo, successCallback, failureCallback) => {
                    if (selectedTechnicianIds.length === 0) {
                        successCallback([]);
                        return;
                    }

                    try {
                        const response = await fetch('{{ route('planner.book.calendar-events') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                technician_ids: selectedTechnicianIds,
                                start: fetchInfo.startStr,
                                end: fetchInfo.endStr,
                            }),
                        });

                        const payload = await response.json();

                        if (!response.ok) {
                            throw new Error(payload?.message || 'Calendar events error');
                        }

                        successCallback((payload.events || []).map((event) => {
                            const color = selectedTechnicianColors[event.extendedProps?.technician_id] || '#31424c';
                            const isDeleted = Boolean(event.extendedProps?.deleted_at);

                            return {
                                ...event,
                                backgroundColor: isDeleted ? 'rgba(190,18,60,0.14)' : color,
                                borderColor: isDeleted ? '#be123c' : color,
                                textColor: isDeleted ? '#7f1d1d' : '#ffffff',
                                classNames: isDeleted ? ['appointment-soft-deleted'] : [],
                            };
                        }));
                    } catch (error) {
                        failureCallback(error);
                    }
                },
                eventDidMount: (info) => {
                    const customer = info.event.extendedProps.customer_name || '';
                    const address = info.event.extendedProps.address || '';
                    const createdBy = info.event.extendedProps.created_by_name || '';
                    if (info.event.extendedProps.deleted_at) {
                        info.el.style.opacity = '0.72';
                        info.el.style.borderWidth = '2px';
                    }
                    info.el.title = [
                        info.event.extendedProps.deleted_at ? 'RDV soft-deleted' : null,
                        info.event.title,
                        customer,
                        address,
                        createdBy ? `Cree par: ${createdBy}` : null,
                    ].filter(Boolean).join('\n');
                },
                eventClick: (info) => {
                    info.jsEvent.preventDefault();
                    openAppointmentDetailModal(info.event);
                },
                dateClick: (info) => {
                    openSlotSuggestionModal(info.date);
                },
            });

            plannerCalendar.render();
        };

        const showCalendarForTechnicians = (technicians) => {
            selectedTechnicianIds = technicians.map((tech) => tech.id);
            selectedTechnicianColors = technicians.reduce((colors, tech, index) => ({
                ...colors,
                [tech.id]: routeColors[index % routeColors.length],
            }), {});

            calendarSection.classList.remove('hidden');
            initCalendar();

            requestAnimationFrame(() => {
                if (!plannerCalendar) return;

                plannerCalendar.refetchEvents();
                plannerCalendar.updateSize();
            });
        };

        initAddressAutocomplete();
        loadCrmAppointments();
        const crmAppointmentFromQuery = getCrmAppointmentFromQuery();
        if (crmAppointmentFromQuery) {
            clearBookingSearchState();
            useCrmAppointment(crmAppointmentFromQuery, null);
        } else {
            restoreBookingSearchState();
        }
    </script>
</x-layouts.app>
