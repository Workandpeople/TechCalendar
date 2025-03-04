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
    <h1 class="h3 mb-0 text-gray-800">Gérer les services</h1>
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

    <!-- Affichage du calendrier -->
    @if(!empty($appointments) && count($appointments) > 0)
        @include('partials.tables.resultCalendar', [
            'selectedTechs' => $selectedTechs,
            'appointments'  => $appointments
        ])
    @endif

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

    // Construire $events
    $events = [];
    foreach (($appointments ?? []) as $appoint) {
        $clientFullname = $appoint->client_fname . ' ' . $appoint->client_lname;
        $techName = optional($appoint->tech->user)->prenom . ' ' . optional($appoint->tech->user)->nom;
        $serviceName = optional($appoint->service)->name;
        $comment = $appoint->comment ?? 'Aucun commentaire';
        $clientAddress = $appoint->client_adresse . ', ' . $appoint->client_zip_code . ' ' . $appoint->client_city;

        $events[] = [
            'id' => $appoint->id,
            'title' => $clientFullname,
            'start' => $appoint->start_at,
            'end' => $appoint->end_at,
            'backgroundColor' => $techColorMap[$appoint->tech_id]['color'] ?? '#cccccc',
            'extendedProps' => [
                'techName' => trim($techName) ?: 'Non spécifié',
                'serviceName' => $serviceName ?: 'Non spécifié',
                'comment' => $comment,
                'clientAddress' => $clientAddress
            ]
        ];
    }
@endphp
<script>
    /**
     * On regroupe tout le code JS dans un seul bloc pour éviter les conflits et les erreurs
     * "Cannot read properties of null (reading 'addEventListener')".
     * On ajoute également des vérifications pour s'assurer que les éléments existent avant de les manipuler.
     */
    $(document).ready(function () {
        // ===========================================
        // ========== [ Bloc 1 : Recherche ] =========
        // ===========================================
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

        // Recherche dynamique pour le modal de création de rendez-vous
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

        // -- Fonction utilitaire de suggestion (pour le "tech_search_modal") --
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

        // ================================================
        // =========== [ Bloc 1-bis : Modal #1 ] ==========
        // ================================================
        // Fonction pour calculer l'heure de fin (modal de création MANUELLE)
        function updateEndTime() {
            const startTime = $('#start_at').val();
            console.log("startTime =", startTime);

            const duration = parseInt($('#duration').val(), 10);
            console.log("duration =", duration);

            if (startTime && duration > 0) {
                // Tentative de parsing
                let startDate = new Date(startTime);
                console.log("startDate =", startDate.toString());  // Voir si c'est "Invalid Date"

                if (isNaN(startDate.getTime())) {
                    console.error("Impossible de parser la date");
                    return;
                }

                // Si c'est une vraie date
                startDate.setMinutes(startDate.getMinutes() + duration);

                let day     = String(startDate.getDate()).padStart(2, '0');
                let month   = String(startDate.getMonth() + 1).padStart(2, '0');
                let year    = startDate.getFullYear();
                let hours   = String(startDate.getHours()).padStart(2, '0');
                let minutes = String(startDate.getMinutes()).padStart(2, '0');

                let endString = `${day}/${month}/${year} ${hours}:${minutes}`;
                console.log("end_at =", endString);

                $('#end_at').val(endString);
            } else {
                $('#end_at').val('');
            }
        }

        // Déclencheurs (changement de service ou de champs) dans le premier formulaire
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
        // Initialisation (modal 1)
        updateEndTime();


        // ================================================
        // ========== [ Bloc 2 : Durée service #2 ] =======
        // ================================================
        // Mise à jour de la durée en fonction du service choisi (2ème form, si existant)
        const serviceSelect = document.getElementById('service_id2');
        const durationInput = document.getElementById('duration2');
        if (serviceSelect && durationInput) {
            serviceSelect.addEventListener('change', function() {
                const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
                const duration       = selectedOption.getAttribute('data-duration') || 0;
                durationInput.value  = duration;
            });
        }

        // ===========================================
        // ========== [ Bloc 3 : Divers JS ] =========
        // ===========================================
        // Fermeture des modals via data-bs-dismiss
        $('button[data-bs-dismiss="modal"]').on('click', function () {
            const modal = $(this).closest('.modal');
            if (modal.length) {
                console.log('Fermeture du modal:', modal.attr('id'));
                modal.modal('hide');
            }
        });

        // Mise à jour de la durée du service dans le 1er formulaire
        $('#service_id').on('change', function () {
            const duration = $(this).find(':selected').data('duration');
            $('#duration').val(duration || '');
        });
        // On déclenche manuellement pour initialiser
        $('#service_id').trigger('change');

        // Recherche dynamique tech (auto-complétion) pour un autre champ (#search_tech)
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

        // -- Fonction utilitaire de suggestion (pour le "search_tech") --
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

            // Au clic sur une suggestion
            $('.suggestion-item').on('click', function(e) {
                const index = $(this).index();
                const chosen = list[index];
                $('#search_tech').val(chosen.fullname);
                $('#search_tech_id').val(chosen.id);
                $('#techSuggestions').html('');
            });
        }

        // ================================================
        // ========== [ Bloc 4 : Calendrier + modal ] =====
        // ================================================
        showLoadingOverlay();

        // Fonction pour le calcul de trajet
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

        // Récupération des éléments potentiels
        const startAtCalendar     = document.getElementById('start_at_calendar');
        const techIdModalCalendar = document.getElementById('tech_id_modal_calendar');
        if (startAtCalendar) {
            // Événements pour lancer le calcul de trajet
            startAtCalendar.addEventListener('change', fetchRouteCalculation);
        }
        if (techIdModalCalendar) {
            techIdModalCalendar.addEventListener('change', fetchRouteCalculation);
        }

        // Si le calendrier existe dans la page
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {
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
                    start: new Date().toISOString().split('T')[0] // Date d'aujourd'hui
                },
                events: {!! json_encode($events) !!},

                dateClick: function(info) {
                    let date = new Date(info.dateStr);
                    let formattedDate = date.toISOString().slice(0, 16); // ex: "2025-03-04T14:00"

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
                        // Définit la valeur par défaut au premier technicien dispo (ou vide si inexistant)
                        firstTechField.value = document.querySelector('#tech_id_modal_calendar option:first-child')?.value || '';
                    }

                    // On calcule la route et on met à jour la fin
                    fetchRouteCalculation();
                    updateEndTimeCalendar();

                    // Ouvrir le modal
                    let modalEl = document.getElementById('appointmentCreateFromCalendarModal');
                    if (modalEl) {
                        let modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    }
                },

                eventDidMount: function(info) {
                    info.el.style.backgroundColor = info.event.backgroundColor;
                    const type = info.event.extendedProps.serviceType;
                    if (type === 'mar') {
                        info.el.style.border = '2px solid red';
                    } else if (type === 'audit') {
                        info.el.style.border = '2px solid green';
                    } else if (type === 'cofrac') {
                        info.el.style.border = '2px solid blue';
                    }
                },

                eventClick: function(info) {
                    var event = info.event;
                    var props = event.extendedProps;

                    console.log("RDV sélectionné :", event);

                    let modalEl = document.getElementById('appointmentModal');
                    if (modalEl) {
                        // Remplir les données du modal (si les éléments existent)
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
                        if (document.getElementById('modalComment')) {
                            document.getElementById('modalComment').textContent = props.comment || 'Aucun commentaire';
                        }
                        if (document.getElementById('modalClientAddress')) {
                            document.getElementById('modalClientAddress').textContent = props.clientAddress || 'Adresse non disponible';
                        }

                        // Afficher le modal
                        var modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    }
                }
            });
            calendar.render();
        }

        hideLoadingOverlay();

        // ================================================
        // ========== [ Bloc 5 : Maj fin modal #2 ] =======
        // ================================================
        // Mise à jour "Se termine à" pour le formulaire dans le modal calendrier
        function updateEndTimeCalendar() {
            let startAtField  = document.getElementById('start_at_calendar');
            let durationField = document.getElementById('duration_calendar');
            let endAtField    = document.getElementById('end_at_calendar');

            if (!startAtField || !durationField || !endAtField) return;

            let startTime = startAtField.value; // ex: "2025-03-04T14:00"
            let duration  = parseInt(durationField.value, 10) || 0;

            if (startTime && duration > 0) {
                let startDate = new Date(startTime);
                // Vérifier parsing
                if (isNaN(startDate.getTime())) {
                    console.error("Impossible de parser la date (Calendrier).");
                    endAtField.value = "";
                    return;
                }
                // Calcul
                startDate.setMinutes(startDate.getMinutes() + duration);

                // Format final
                let formattedEndDate = startDate.toLocaleDateString('fr-FR') + " à " +
                    String(startDate.getHours()).padStart(2, '0') + ":" +
                    String(startDate.getMinutes()).padStart(2, '0');

                endAtField.value = formattedEndDate;
            } else {
                endAtField.value = "";
            }
        }

        // Écouteurs pour la date/heure & durée du modal calendrier
        if (startAtCalendar) {
            startAtCalendar.addEventListener('input', updateEndTimeCalendar);
            startAtCalendar.addEventListener('change', updateEndTimeCalendar);
        }
        const durationCalendar = document.getElementById('duration_calendar');
        if (durationCalendar) {
            durationCalendar.addEventListener('input', updateEndTimeCalendar);
            durationCalendar.addEventListener('change', updateEndTimeCalendar);
        }

        // Écouteur sur #service_id_calendar pour affecter la durée + recalcul
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

        // Mise à jour initiale (cas où des valeurs seraient déjà présentes)
        updateEndTimeCalendar();
    });
</script>
@endsection
