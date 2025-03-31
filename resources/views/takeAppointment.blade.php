@extends('layouts.app')

@section('css')
 <style>
    .fc-daygrid-day {
        cursor: pointer;
    }
</style>
@endsection

@section('title', 'Prise de RDV')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Prendre un RDV</h1>
    <div class="button-group">

        <!-- Bouton "Créer un RDV" -->
        <a href="#" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#appointmentCreateModal">
            <i class="fas fa-plus fa-sm text-white-50"></i> Créer un RDV manuellement
        </a>

        <!-- Bouton "Gérer les RDV" -->
        <a href="{{ route('manage-appointments.index') }}" class="btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-list-alt fa-sm text-white-50"></i> Gérer les RDV
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    {{ session('error') }}
</div>
@endif
@endsection

@section('content')
    <!-- Modal de création RDV -->
    @include('partials.modals.appointmentCreate')

    <!-- Modal de création RDV depuis le calendrier -->
    @include('partials.modals.appointmentCreateFromCalendarModal', [
        'services' => $services
    ])

    <!-- Form de recherche (adresse, code postal, ville, etc.) -->
    @include('partials.forms.searchForm')

    @if(
        request()->filled('client_adresse') ||
        request()->filled('client_zip_code') ||
        request()->filled('client_city')
     )
         @include('partials.tables.resultCalendar', [
             'selectedTechs' => $selectedTechs ?? [],
             'appointments'  => $appointments ?? []
         ])
     @endif

    @include('partials.modals.interactiveMap')

    <!-- Affichage des RDV -->
    @include('partials.modals.appointmentDetails')
@endsection

@section('js')
@php
    // 3 couleurs distinctes pour 3 tech
    $colors = ['#ff9999', '#99ff99', '#9999ff', '#ffcc99', '#99ccff'];
    $techColorMap = [];

    foreach(($selectedTechs ?? []) as $index => $techData){
        $tech = $techData['tech'];
        $fullname = ($tech->user->prenom ?? '').' '.($tech->user->nom ?? '');
        $techColorMap[$tech->id] = [
            'color'   => $colors[$index] ?? '#dddddd',
            'fullname' => trim($fullname) ?: 'Tech #'.($index+1),
        ];
    }

    // Construire $events (toujours là si vous voulez un affichage initial)
    $events = [];
    foreach (($appointments ?? []) as $appoint) {
        $clientFullname  = $appoint->client_fname . ' ' . $appoint->client_lname;
        $dept            = substr($appoint->client_zip_code, 0, 2) ?: 'XX'; // "34", "75", etc.
        $clientFullnameWithDept = $clientFullname.' ('.$dept.')';
        $techName        = optional($appoint->tech->user)->prenom . ' ' . optional($appoint->tech->user)->nom;
        $serviceName     = optional($appoint->service)->name;
        $comment         = $appoint->comment ?? 'Aucun commentaire';
        $clientAddress   = $appoint->client_adresse . ', ' . $appoint->client_zip_code . ' ' . $appoint->client_city;

        // Récupérer les distances calculées dans la propriété du modèle
        $distFromSearch = $appoint->distance_from_search ?? 0;
        $timeFromSearch = $appoint->time_from_search     ?? 0;

        $events[] = [
            'id'              => $appoint->id,
            'title'           => $clientFullnameWithDept,
            'start'           => $appoint->start_at,
            'end'             => $appoint->end_at,
            'backgroundColor' => $techColorMap[$appoint->tech_id]['color'] ?? '#cccccc',
            'extendedProps'   => [
                'tech_id'       => $appoint->tech_id,
                'techName'      => trim($techName) ?: 'Non spécifié',
                'serviceName'   => $serviceName ?: 'Non spécifié',
                'comment'       => $comment,
                'clientAddress' => $clientAddress,
                'clientPhone'   => $appoint->client_phone ?? 'Non spécifié',
                'clientName' => trim($appoint->client_fname . ' ' . $appoint->client_lname),

                // On utilise les variables locales que l'on vient de définir
                'distanceSearch' => $distFromSearch,
                'timeSearch'     => $timeFromSearch,
            ],
        ];
    }
@endphp
<script>
    /**
     * On regroupe tout le code JS dans un seul bloc pour éviter les conflits et les erreurs
     * "Cannot read properties of null (reading 'addEventListener')".
     * On ajoute également des vérifications pour s'assurer que les éléments existent avant de les manipuler.
     */

    // ===========================================
    // ====== [ Nouveau : cache local JS ] =======
    // ===========================================
    /**
     * cachedEventsMap :
     *   {
     *     techId: {
     *       "2025-03-17|2025-03-24": [ ... events ...],
     *       "2025-03-24|2025-03-31": [ ... events ...]
     *     }
     *   }
     * Il permet de ne pas recharger plusieurs fois les mêmes techniciens pour la même plage.
     */
    let cachedEventsMap = {};

    function initFullCalendar() {
        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) return; // Sécurité si l'élément n'existe pas

        const initialEvents = {!! json_encode($events) !!}; // Données passées depuis PHP

        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'fr',
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'timeGridWeek,timeGridDay'
            },
            slotMinTime: '08:00:00',
            slotMaxTime: '21:00:00',
            hiddenDays: [0, 6],
            validRange: {
                start: new Date().toISOString().split('T')[0]
            },
            allDaySlot: false,
            events: initialEvents, // Injecter les événements déjà calculés
            dateClick: function(info) {
                let date = new Date(info.dateStr);
                let formattedDate = date.toISOString().slice(0, 16);

                let startAtField = document.getElementById('start_at_calendar');
                if (startAtField) startAtField.value = formattedDate;

                fetchRouteCalculation();
                updateEndTimeCalendar();

                let modalEl = document.getElementById('appointmentCreateFromCalendarModal');
                if (modalEl) {
                    let modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        });

        calendar.render();
    }

    $(document).ready(function () {
        // =======================
        // ===== [ Recherche ] ===
        // =======================
        const searchForm = $('#searchForm');
        const searchInputs = searchForm.find('input, select');
        const restoreButton = $('#restoreSearchBtn');

        // Vérifier s'il y a une recherche stockée et afficher le bouton de restauration
        if (localStorage.getItem('lastSearch')) {
            restoreButton.show();
        }

        // Sauvegarder la recherche lors de la soumission
        searchForm.on('submit', function () {
            let searchParams = {};
            searchInputs.each(function () {
                let name = $(this).attr('name');
                let value = $(this).val();
                if (name) {
                    searchParams[name] = value;
                }
            });
            localStorage.setItem('lastSearch', JSON.stringify(searchParams));
        });

        // Restaurer la dernière recherche
        restoreButton.on('click', function () {
            let savedSearch = localStorage.getItem('lastSearch');
            if (savedSearch) {
                savedSearch = JSON.parse(savedSearch);
                searchInputs.each(function () {
                    let name = $(this).attr('name');
                    if (savedSearch[name] !== undefined) {
                        $(this).val(savedSearch[name]).trigger('change');
                    }
                });
            }
        });

        let timer;
        // Soumission du formulaire de recherche
        $('#searchForm').on('submit', function () {
            showLoadingOverlay(); // Afficher le chargement
            $('#searchSubmitBtn').prop('disabled', true); // Désactiver le bouton pour éviter un double clic
        });

        // Quand la page est complètement chargée (après un rechargement)
        $(window).on('load', function () {
            hideLoadingOverlay();
            $('#searchSubmitBtn').prop('disabled', false);
        });

        // ================================
        // ===== [ Suggestions tech ] =====
        // ================================
        $('#tech_search_modal').on('input', function() {
            clearTimeout(timer);
            const query = $(this).val().trim();
            if (!query) {
                $('#techSuggestionsModal').html('');
                $('#tech_id_modal').val('');
                return;
            }
            timer = setTimeout(() => {
                fetchTechSuggestionsForModal(query, '#tech_search_modal', '#tech_id_modal', '#techSuggestionsModal');
            }, 300);
        });

        function fetchTechSuggestionsForModal(query, inputSelector, idSelector, outputSelector) {
            showLoadingOverlay();
            $.ajax({
                url: '/tech-search?q=' + encodeURIComponent(query),
                type: 'GET',
                success: function(data) {
                    let html = '';
                    if (data.length === 0) {
                        html = '<div class="p-2 text-muted">Aucun résultat</div>';
                    } else {
                        data.forEach(function(item) {
                            html += `
                                <div class="p-2 suggestion-item" style="cursor: pointer;">${item.fullname}</div>
                            `;
                        });
                    }
                    $(outputSelector).html(html);

                    // Au clic sur une suggestion
                    $(outputSelector).find('.suggestion-item').on('click', function(e) {
                        const index = $(this).index();
                        const chosen = data[index];
                        $(inputSelector).val(chosen.fullname);
                        $(idSelector).val(chosen.id);
                        $(outputSelector).html('');
                    });
                    hideLoadingOverlay();
                },
                error: function(err) {
                    console.error(err);
                    hideLoadingOverlay();
                }
            });
        }

        // ==============================================
        // ===== [ Modal de création RDV (manuel) ] =====
        // ==============================================
        function updateEndTime() {
            const startTime = $('#start_at').val();
            const duration = parseInt($('#duration').val(), 10);

            if (startTime && duration > 0) {
                let startDate = new Date(startTime);
                if (isNaN(startDate.getTime())) {
                    console.error("Impossible de parser la date");
                    return;
                }
                startDate.setMinutes(startDate.getMinutes() + duration);

                let day     = String(startDate.getDate()).padStart(2, '0');
                let month   = String(startDate.getMonth() + 1).padStart(2, '0');
                let year    = startDate.getFullYear();
                let hours   = String(startDate.getHours()).padStart(2, '0');
                let minutes = String(startDate.getMinutes()).padStart(2, '0');

                let endString = `${day}/${month}/${year} ${hours}:${minutes}`;
                $('#end_at').val(endString);
            } else {
                $('#end_at').val('');
            }
        }

        $('#service_id').on('change', function () {
            const selectedOption = $(this).find(':selected');
            const duration = selectedOption.data('duration');
            if (duration) {
                $('#duration').val(duration);
                updateEndTime();
            }
        });
        $('#start_at, #duration').on('input change keyup', function () {
            updateEndTime();
        });
        updateEndTime(); // init

        // Form 2 (si existant)
        const serviceSelect = document.getElementById('service_id2');
        const durationInput = document.getElementById('duration2');
        if (serviceSelect && durationInput) {
            serviceSelect.addEventListener('change', function() {
                const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
                const duration       = selectedOption.getAttribute('data-duration') || 0;
                durationInput.value  = duration;
            });
        }

        // =========================
        // ===== [ Divers JS ] =====
        // =========================
        $('button[data-bs-dismiss="modal"]').on('click', function () {
            const modal = $(this).closest('.modal');
            if (modal.length) {
                modal.modal('hide');
            }
        });

        $('#service_id').on('change', function () {
            const duration = $(this).find(':selected').data('duration');
            $('#duration').val(duration || '');
        });
        $('#service_id').trigger('change');

        // Suggestions tech (recherche)
        let timer2;
        $('#search_tech').on('input', function() {
            clearTimeout(timer2);
            const query = $(this).val().trim();
            if (!query) {
                $('#techSuggestions').html('');
                $('#search_tech_id').val('');
                return;
            }
            timer2 = setTimeout(() => {
                fetchTechSuggestions(query);
            }, 300);
        });

        function fetchTechSuggestions(query) {
            showLoadingOverlay();
            $.ajax({
                url: '/tech-search?q=' + encodeURIComponent(query),
                type: 'GET',
                success: function(data) {
                    renderTechSuggestions(data);
                    hideLoadingOverlay();
                },
                error: function(err) {
                    console.error(err);
                    hideLoadingOverlay();
                }
            });
        }
        function renderTechSuggestions(list) {
            if (!list.length) {
                $('#techSuggestions').html('<div class="p-2 text-muted">Aucun résultat</div>');
                return;
            }
            let html = '';
            list.forEach(function(item) {
                html += `<div class="p-2 suggestion-item" style="cursor: pointer;">${item.fullname}</div>`;
            });
            $('#techSuggestions').html(html);

            $('.suggestion-item').on('click', function(e) {
                const index = $(this).index();
                const chosen = list[index];
                $('#search_tech').val(chosen.fullname);
                $('#search_tech_id').val(chosen.id);
                $('#techSuggestions').html('');
            });
        }

        // ================================
        // ===== [ Calendrier + modal ] ===
        // ================================
        showLoadingOverlay();

        function fetchRouteCalculation() {
            let clientAdresse = {!! json_encode(request()->input('client_adresse', '')) !!};
            let clientZipCode = {!! json_encode(request()->input('client_zip_code', '')) !!};
            let clientCity    = {!! json_encode(request()->input('client_city', '')) !!};

            let addressField = document.getElementById('client_adresse_calendar');
            let zipField     = document.getElementById('client_zip_code_calendar');
            let cityField    = document.getElementById('client_city_calendar');

            if (addressField) addressField.value = clientAdresse;
            if (zipField)     zipField.value     = clientZipCode;
            if (cityField)    cityField.value    = clientCity;

            let techId  = document.getElementById('tech_id_modal_calendar')?.value;
            let startAt = document.getElementById('start_at_calendar')?.value;

            if (clientAdresse && clientZipCode && clientCity && techId && startAt) {
                $.ajax({
                    url: '/calculate-route',
                    type: 'GET',
                    data: {
                        client_adresse: clientAdresse,
                        client_zip_code: clientZipCode,
                        client_city: clientCity,
                        tech_id: techId,
                        date: startAt
                    },
                    success: function(response) {
                        console.log("🚗 Réponse de /calculate-route :", response); // 👈 ajoute ceci

                        if (response.distance_km && response.duration_minutes) {
                            let distanceField = document.getElementById('trajet_distance_calendar');
                            let timeField     = document.getElementById('trajet_time_calendar');
                            if (distanceField) distanceField.value = response.distance_km + " km";
                            if (timeField)     timeField.value     = response.duration_minutes + " min";
                        }
                    },
                    error: function() {
                        let distanceField = document.getElementById('trajet_distance_calendar');
                        let timeField     = document.getElementById('trajet_time_calendar');
                        if (distanceField) distanceField.value = "N/A";
                        if (timeField)     timeField.value     = "N/A";
                    }
                });
            }
        }

        // Fonction qui recharge les événements après la recherche
        function reloadCalendarEvents() {
            if (typeof calendar === 'undefined' || !calendar) {
                console.error("📌 Le calendrier n'est pas encore initialisé !");
                return;
            }

            showCalendarLoading(); // ✅ Afficher le loading du calendrier uniquement

            $.ajax({
                url: '{{ route("appointments.ajax") }}', // Assure-toi que cette route retourne bien les événements JSON
                type: 'GET',
                data: {
                    start: calendar.view?.currentStart?.toISOString().split('T')[0] || '',
                    end: calendar.view?.currentEnd?.toISOString().split('T')[0] || ''
                },
                success: function(responseEvents) {
                    console.log("📌 Événements mis à jour :", responseEvents);

                    calendar.removeAllEvents(); // Supprimer les anciens événements
                    calendar.addEventSource(responseEvents); // Ajouter les nouveaux événements

                    hideCalendarLoading(); // ✅ Cacher le loading du calendrier uniquement
                },
                error: function() {
                    hideCalendarLoading();
                    console.error("❌ Erreur lors du chargement AJAX des événements.");
                }
            });
        }

        // Après soumission du formulaire de recherche
        $('#searchForm').on('submit', function(event) {
            showLoadingOverlay();
        });

        const startAtCalendar     = document.getElementById('start_at_calendar');
        const techIdModalCalendar = document.getElementById('tech_id_modal_calendar');
        if (startAtCalendar) {
            startAtCalendar.addEventListener('change', fetchRouteCalculation);
        }
        if (techIdModalCalendar) {
            techIdModalCalendar.addEventListener('change', fetchRouteCalculation);
        }

        // ================================
        // ===== [ FullCalendar init ] ====
        // ================================
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {

            // 1) Au premier chargement, on affiche directement vos $events (ceux déjà calculés dans search()).
            //    Ainsi, PAS de requête AJAX la première fois.
            const initialEvents = {!! json_encode($events) !!};

            var calendar = new FullCalendar.Calendar(calendarEl, {
                locale: 'fr',
                initialView: 'timeGridWeek',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridWeek,timeGridDay'
                },
                slotMinTime: '08:00:00',
                slotMaxTime: '21:00:00',
                hiddenDays: [0, 6],
                validRange: {
                    start: new Date().toISOString().split('T')[0]
                },
                allDaySlot: false,
                // On injecte directement nos "events" calculés par search().
                events: initialEvents,

                dateClick: function(info) {
                    let date = new Date(info.dateStr);
                    let formattedDate = date.toISOString().slice(0, 16);

                    let startAtField   = document.getElementById('start_at_calendar');
                    let durationField  = document.getElementById('duration_calendar');
                    let firstTechField = document.getElementById('tech_id_modal_calendar');

                    if (startAtField) {
                        startAtField.value = formattedDate;
                    }
                    if (durationField) {
                        let selectedOption = document.querySelector('#service_id_calendar option:checked');
                        durationField.value = selectedOption ? selectedOption.dataset.duration || '' : '';
                    }
                    if (firstTechField) {
                        firstTechField.value = document.querySelector('#tech_id_modal_calendar option:first-child')?.value || '';
                    }

                    fetchRouteCalculation();
                    updateEndTimeCalendar();

                    let modalEl = document.getElementById('appointmentCreateFromCalendarModal');
                    if (modalEl) {
                        let modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    }
                },

                eventDidMount: function(info) {
                    const props = info.event.extendedProps;

                    // Si la distance est déjà calculée, on affiche le tooltip immédiatement
                    if (props.distanceSearch && props.timeSearch) {
                        attachTooltip(info.el, props);
                    } else {
                        info.el.addEventListener('mouseenter', function () {
                            if (info.event.extendedProps._calculating) return;
                            info.event.extendedProps._calculating = true;

                            const fullAddress = info.event.extendedProps.clientAddress;
                            let adresse = '', zip = '', city = '';

                            if (fullAddress.includes(',')) {
                                // Cas habituel avec virgule
                                const parts = fullAddress.split(',');
                                adresse = parts[0].trim();
                                const zipCity = parts[1].trim();
                                const zipMatch = zipCity.match(/^(\d{5})\s+(.*)$/);
                                if (!zipMatch) {
                                    console.warn("Code postal + ville mal formés :", zipCity);
                                    return;
                                }
                                zip = zipMatch[1];
                                city = zipMatch[2];
                            } else {
                                // Fallback sans virgule
                                const match = fullAddress.match(/^(.+?)\s+(\d{5})\s+(.+)$/);
                                if (!match) {
                                    console.warn("Adresse mal formée (sans virgule) :", fullAddress);
                                    return;
                                }
                                adresse = match[1].trim();
                                zip = match[2];
                                city = match[3].trim();
                            }

                            $.ajax({
                                url: '/calculate-route',
                                type: 'GET',
                                data: {
                                    tech_id: info.event.extendedProps.tech_id,
                                    date: info.event.startStr,
                                    client_adresse: adresse,
                                    client_zip_code: zip,
                                    client_city: city
                                },
                                success: function (res) {
                                    console.log("📦 Résultat AJAX du calcul de trajet :", res);
                                    if (res.distance_km && res.duration_minutes) {
                                        info.event.setExtendedProp('distanceSearch', res.distance_km);
                                        info.event.setExtendedProp('timeSearch', res.duration_minutes);

                                        attachTooltip(info.el, {
                                            ...info.event.extendedProps,
                                            distanceSearch: res.distance_km,
                                            timeSearch: res.duration_minutes
                                        });
                                    }
                                },
                                error: function () {
                                    console.warn('Erreur lors du calcul du trajet');

                                    attachTooltip(info.el, {
                                        clientName: info.event.extendedProps.clientName || 'Client',
                                        distanceSearch: 'Récupération impossible',
                                        timeSearch: ''
                                    });

                                    $(info.el).tooltip('show');
                                }
                            });
                        });
                    }
                },

                eventContent: function(arg) {
                    const distance = arg.event.extendedProps.distanceSearch || 0;
                    const time     = arg.event.extendedProps.timeSearch     || 0;

                    let html = `
                        <div class="fc-event-title">
                            ${arg.event.title}
                        </div>
                    `;
                    return { html: html };
                },

                eventClick: function(info) {
                    var event = info.event;
                    var props = event.extendedProps;

                    let modalEl = document.getElementById('appointmentModal');
                    if (modalEl) {
                        if (document.getElementById('modalClientName')) {
                            document.getElementById('modalClientName').textContent = event.title;
                        }
                        if (document.getElementById('modalTechName')) {
                            document.getElementById('modalTechName').textContent = props.techName || 'Non spécifié';
                        }
                        if (document.getElementById('modalService')) {
                            document.getElementById('modalService').textContent = props.serviceName || 'Non spécifié';
                        }
                        if (document.getElementById('modalDate')) {
                            document.getElementById('modalDate').textContent = new Date(event.start).toLocaleDateString();
                        }
                        if (document.getElementById('modalTime')) {
                            document.getElementById('modalTime').textContent =
                                new Date(event.start).toLocaleTimeString() + ' - ' +
                                new Date(event.end).toLocaleTimeString();
                        }
                        if (document.getElementById('modalClientPhone')) {
                            document.getElementById('modalClientPhone').textContent = props.clientPhone || 'Non spécifié';
                        }
                        if (document.getElementById('modalComment')) {
                            document.getElementById('modalComment').textContent = props.comment || 'Aucun commentaire';
                        }
                        if (document.getElementById('modalClientAddress')) {
                            document.getElementById('modalClientAddress').textContent = props.clientAddress || 'Adresse non disponible';
                        }

                        var modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    }
                },

                // Quand on change la plage (next/prev/today) :
                datesSet: function(info) {
                    let startStr = info.startStr; // ex "2025-03-17"
                    let endStr   = info.endStr;   // ex "2025-03-24"

                    loadAjaxEvents(startStr, endStr);
                }
            });

            function attachTooltip(el, props) {
                let distance = props.distanceSearch;
                let time     = props.timeSearch;

                if (distance === 'Récupération impossible') {
                    distance = 'Récupération impossible';
                    time = '';
                } else {
                    distance = distance !== undefined ? distance + ' km' : 'N/A';
                    time     = time !== undefined ? time + ' min' : 'N/A';
                }
                console.log("distance:", distance, "time:", time); // 👈 ici aussi

                const tooltipContent = `
                    <b>${props.clientName || 'Client'}</b><br/>
                    ${distance} – ${time}
                `;

                // Forcer la réinitialisation du tooltip s'il existait
                $(el).tooltip('dispose').tooltip({
                    title: tooltipContent,
                    html: true,
                    container: 'body',
                    placement: 'top'
                });
            }

            // 3) Render initial
            calendar.render();

            // ================================
            // [Nouveau] getCachedEventsForRange
            // ================================
            async function getCachedEventsForRange(selectedTechIds, start, end) {
                // rangeKey => identifie la plage
                let rangeKey = `${start}|${end}`;
                let techIdsToFetch = [];

                // Déterminer quels tech n'ont pas encore de cache pour cette plage
                selectedTechIds.forEach(tid => {
                    if (!cachedEventsMap[tid] || !cachedEventsMap[tid][rangeKey]) {
                        techIdsToFetch.push(tid);
                    }
                });

                // Si tous sont déjà en cache, on ne fetch pas
                if (!techIdsToFetch.length) {
                    return;
                }

                // Fetch seulement ceux qui manquent
                showCalendarLoading();
                try {
                    let response = await $.ajax({
                        url: '{{ route("appointments.ajax") }}',
                        type: 'GET',
                        data: {
                            start: start,
                            end: end,
                            client_adresse:  '{{ request()->input("client_adresse", "") }}',
                            client_zip_code: '{{ request()->input("client_zip_code", "") }}',
                            client_city:     '{{ request()->input("client_city", "") }}',
                            tech_ids: techIdsToFetch,
                        }
                    });

                    // Regrouper par tech_id
                    let eventsByTech = {};
                    response.forEach(evt => {
                        let tId = evt.extendedProps.tech_id;
                        if (!eventsByTech[tId]) {
                            eventsByTech[tId] = [];
                        }
                        eventsByTech[tId].push(evt);
                    });

                    // Stocker en cache
                    techIdsToFetch.forEach(tid => {
                        if (!cachedEventsMap[tid]) {
                            cachedEventsMap[tid] = {};
                        }
                        cachedEventsMap[tid][rangeKey] = eventsByTech[tid] || [];
                    });
                } catch (err) {
                    console.error("Erreur AJAX loadAjaxEvents:", err);
                }
                hideCalendarLoading();
            }

            // ================================
            // [Nouveau] refreshCalendarFromCache
            // ================================
            function refreshCalendarFromCache(selectedTechIds, start, end) {
                let rangeKey = `${start}|${end}`;
                // On retire tous les événements
                calendar.removeAllEvents();

                // Puis, pour chaque tech, on récupère ses events en cache
                selectedTechIds.forEach(tid => {
                    let arr = (cachedEventsMap[tid] && cachedEventsMap[tid][rangeKey]) || [];
                    calendar.addEventSource(arr);
                });
            }

            // ================================
            // [Modifié] loadAjaxEvents => utilise le cache
            // ================================
            async function loadAjaxEvents(start, end) {
                // Récupérer les IDs des tech cochés
                const selectedTechIds = [];
                document.querySelectorAll('.tech-visibility:checked').forEach(chk => {
                    selectedTechIds.push(chk.dataset.techId);
                });

                // 1) Charger en cache uniquement ceux qui manquent
                await getCachedEventsForRange(selectedTechIds, start, end);

                // 2) Mettre à jour l'affichage
                refreshCalendarFromCache(selectedTechIds, start, end);
            }

            // 4) Au clic sur les checkboxes, on recharge via le cache
            $('.tech-visibility').on('change', function() {
                let startStr = calendar.view?.currentStart?.toISOString().split('T')[0] || '';
                let endStr   = calendar.view?.currentEnd?.toISOString().split('T')[0] || '';
                loadAjaxEvents(startStr, endStr);
            });
        }

        hideLoadingOverlay();

        // ================================================
        // ===== [ Bloc 5 : Mise à jour fin modal #2 ] ====
        // ================================================
        function updateEndTimeCalendar() {
            let startAtField  = document.getElementById('start_at_calendar');
            let durationField = document.getElementById('duration_calendar');
            let endAtField    = document.getElementById('end_at_calendar');

            if (!startAtField || !durationField || !endAtField) return;

            let startTime = startAtField.value;
            let duration  = parseInt(durationField.value, 10) || 0;

            if (startTime && duration > 0) {
                let startDate = new Date(startTime);
                if (isNaN(startDate.getTime())) {
                    console.error("Impossible de parser la date (Calendrier).");
                    endAtField.value = "";
                    return;
                }
                startDate.setMinutes(startDate.getMinutes() + duration);

                let formattedEndDate = startDate.toLocaleDateString('fr-FR') + " à " +
                    String(startDate.getHours()).padStart(2, '0') + ":" +
                    String(startDate.getMinutes()).padStart(2, '0');
                endAtField.value = formattedEndDate;
            } else {
                endAtField.value = "";
            }
        }

        if (startAtCalendar) {
            startAtCalendar.addEventListener('input', updateEndTimeCalendar);
            startAtCalendar.addEventListener('change', updateEndTimeCalendar);
        }
        const durationCalendar = document.getElementById('duration_calendar');
        if (durationCalendar) {
            durationCalendar.addEventListener('input', updateEndTimeCalendar);
            durationCalendar.addEventListener('change', updateEndTimeCalendar);
        }

        const serviceSelectCalendar = document.getElementById('service_id_calendar');
        if (serviceSelectCalendar && durationCalendar) {
            serviceSelectCalendar.addEventListener('change', function() {
                const selectedOption = serviceSelectCalendar.options[serviceSelectCalendar.selectedIndex];
                if (selectedOption) {
                    let durVal = selectedOption.getAttribute('data-duration') || '0';
                    durationCalendar.value = durVal;
                    updateEndTimeCalendar();
                }
            });
        }
        updateEndTimeCalendar();
    });

    // =============================
    // ===== [ Mapbox en DOMContentLoaded ] ===
    // =============================
    document.addEventListener("DOMContentLoaded", function () {
    // Clé API Mapbox et désactivation de la télémétrie
    mapboxgl.accessToken = 'pk.eyJ1IjoiZGlubmljaGVydGwiLCJhIjoiY20zaGZ4dmc5MGJjdzJrcXpvcTU2ajg5ZiJ9.gfuUn87ezzfPm-hxtEDotw';
    mapboxgl.config.EVENTS_URL = null;

    // Récupération des informations client et techniciens depuis Laravel
    let clientAdresse = {!! json_encode(request()->input('client_adresse', '')) !!};
    let clientZipCode = {!! json_encode(request()->input('client_zip_code', '')) !!};
    let clientCity    = {!! json_encode(request()->input('client_city', '')) !!};
    let techsAdresses = {!! json_encode($selectedTechs ?? []) !!}; // Liste des 5 techniciens

    let fullAddressClient = `${clientAdresse}, ${clientZipCode} ${clientCity}`;
    console.log("Adresse client complète :", fullAddressClient);

    let mapContainer = document.getElementById('map');
    if (!mapContainer) {
        console.error("Aucun conteneur pour la carte trouvé.");
        return;
    }

    // Initialisation de la carte avec un centre par défaut sur Paris
    let map = new mapboxgl.Map({
        container: 'map',
        style: 'mapbox://styles/mapbox/dark-v11',
        center: [2.333333, 48.866667],
        zoom: 12
    });
    console.log("Carte initialisée.");

    // Géocodage de l'adresse du client et ajout d'un marqueur rouge
    fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(fullAddressClient)}.json?access_token=${mapboxgl.accessToken}`)
        .then(response => response.json())
        .then(clientData => {
            if (clientData.features && clientData.features.length > 0) {
                let clientCoords = clientData.features[0].center;
                map.setCenter(clientCoords);
                new mapboxgl.Marker({ color: "red" })
                    .setLngLat(clientCoords)
                    .setPopup(new mapboxgl.Popup().setText(`Client: ${fullAddressClient}`))
                    .addTo(map);
            } else {
                console.error("Aucune donnée de géocodage pour le client.");
            }
        })
        .catch(error => console.error("Erreur lors du géocodage du client :", error));

    // Variables globales pour les filtres
    let selectedDay = null;  // jour sélectionné, par ex. "Monday"
    let selectedHour = 8;    // heure sélectionnée via le curseur (par défaut 8)

    // Mise à jour du label du curseur et rechargement des RDV sur la carte
    $('#mapTimeSlider').on('input change', function () {
        selectedHour = parseFloat($(this).val());
        $('#mapTimeLabel').text(selectedHour + ":00");
        console.log("Heure sélectionnée :", selectedHour);
        loadMapAppointments();
    });

    // Gestion des boutons jour
    $('.map-day-btn').on('click', function () {
        $('.map-day-btn').removeClass('active');
        $(this).addClass('active');
        selectedDay = $(this).data('day');
        console.log("Jour sélectionné :", selectedDay);
        loadMapAppointments();
    });
    // Sélectionner par défaut le premier bouton (ex: Lundi)
    $('.map-day-btn').first().click();

    // Recharger la carte lorsque l'utilisateur modifie les cases à cocher de la légende
    $('.map-tech-visibility').on('change', function () {
        console.log("Modification de la légende, recharge de la carte.");
        loadMapAppointments();
    });

    // Fonction pour charger les rendez-vous et afficher les marqueurs sur la carte
    function loadMapAppointments() {
        let selectedTechIds = [];
        $('.map-tech-visibility:checked').each(function () {
            selectedTechIds.push($(this).data('techId'));
        });
        console.log("Tech IDs sélectionnés :", selectedTechIds);
        if (!selectedTechIds.length) {
            console.log("Aucun technicien sélectionné. Suppression des marqueurs.");
            if (window.mapMarkers && window.mapMarkers.length) {
                window.mapMarkers.forEach(marker => marker.remove());
                window.mapMarkers = [];
            }
            return;
        }
        let params = {
            tech_ids: selectedTechIds,
            day: selectedDay,
            start_hour: selectedHour,
            client_adresse: {!! json_encode(request()->input('client_adresse', '')) !!},
            client_zip_code: {!! json_encode(request()->input('client_zip_code', '')) !!},
            client_city: {!! json_encode(request()->input('client_city', '')) !!}
        };
        console.log("Paramètres envoyés à /map-appointments :", params);

        $.ajax({
            url: '/map-appointments',
            type: 'GET',
            data: params,
            success: function(responseAppointments) {
                console.log("Réponse des rendez-vous pour la carte :", responseAppointments);
                // Suppression des marqueurs existants
                if (window.mapMarkers && window.mapMarkers.length) {
                    window.mapMarkers.forEach(marker => marker.remove());
                }
                window.mapMarkers = [];
                responseAppointments.forEach(function(appt) {
                    if (appt.coords) {
                        let marker = new mapboxgl.Marker()
                            .setLngLat(appt.coords)
                            .setPopup(new mapboxgl.Popup().setHTML(`
                                <strong>${appt.title}</strong><br>
                                Début : ${appt.start}<br>
                                ${appt.techName}
                            `))
                            .addTo(map);
                        window.mapMarkers.push(marker);
                    } else {
                        console.warn("Aucune coordonnée pour le RDV ID :", appt.id);
                    }
                });
            },
            error: function(err) {
                console.error("Erreur lors du chargement des rendez-vous pour la carte :", err);
            }
        });
    }

    // Recharger la carte lors de l'ouverture du modal
    $('#mapModal').on('shown.bs.modal', function () {
        setTimeout(() => {
            map.resize();
            loadMapAppointments();
        }, 300);
    });
});
</script>
@endsection
