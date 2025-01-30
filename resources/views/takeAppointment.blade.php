@extends('layouts.app')

@section('title', 'Prise de RDV')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Gérer les services</h1>
    <div class="button-group">
        <!-- Bouton "Voir le calendrier" -->
        <a href="#" class="btn btn-sm btn-info shadow-sm">
            <i class="fas fa-calendar-alt fa-sm text-white-50"></i> Voir le calendrier
        </a>

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

    <!-- Form de recherche (adresse, code postal, ville, etc.) -->
    @include('partials.forms.searchForm')

    <!-- Affichage du calendrier -->
    @include('partials.tables.resultCalendar', [
        'selectedTechs' => $selectedTechs,
        'appointments'  => $appointments
    ])

    <!-- Affichage des RDV -->
    @include('partials.modals.appointmentDetails')
@endsection

@section('js')
@php
    // 3 couleurs distinctes pour 3 tech
    $colors = ['#ff9999', '#99ff99', '#9999ff'];
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
$(document).ready(function () {
    let timer;

    $('#searchForm').on('submit', function () {
        showLoadingOverlay(); // Afficher le chargement

        // Désactiver le bouton pour éviter un double clic
        $('#searchSubmitBtn').prop('disabled', true);
    });

    // Quand la page est complètement chargée (après le rechargement)
    $(window).on('load', function () {
        hideLoadingOverlay(); // Masquer le chargement
        $('#searchSubmitBtn').prop('disabled', false); // Réactiver le bouton
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
            fetchTechSuggestions(query, '#tech_search_modal', '#tech_id_modal', '#techSuggestionsModal');
        }, 300);
    });

    // Fonction pour calculer l'heure de fin
    function updateEndTime() {
        const startTime = $('#start_at').val();
        const duration = parseInt($('#duration').val(), 10);

        if (startTime && duration > 0) {
            let startDate = new Date(startTime); // Date en format ISO

            startDate.setMinutes(startDate.getMinutes() + duration); // Ajoute la durée

            // Formatage de la date en JJ/MM/YYYY HH:MM
            let day = String(startDate.getDate()).padStart(2, '0');
            let month = String(startDate.getMonth() + 1).padStart(2, '0'); // Mois commence à 0
            let year = startDate.getFullYear();
            let hours = String(startDate.getHours()).padStart(2, '0');
            let minutes = String(startDate.getMinutes()).padStart(2, '0');

            let formattedEndDate = `${day}/${month}/${year} ${hours}:${minutes}`;
            $('#end_at').val(formattedEndDate);
        } else {
            $('#end_at').val(''); // Réinitialise si vide
        }
    }

    // ✅ Ajout d'un déclencheur au changement du service
    $('#service_id').on('change', function () {
        const selectedOption = $(this).find(':selected');
        const duration = selectedOption.data('duration');

        if (duration) {
            $('#duration').val(duration);
            updateEndTime();
        }
    });

    // ✅ Mise à jour en temps réel
    $('#start_at, #duration').on('input change keyup', function () {
        updateEndTime();
    });

    // ✅ Initialisation au chargement
    updateEndTime();
});

document.addEventListener('DOMContentLoaded', function() {
    // Mise à jour de la durée en fonction du service choisi
    const serviceSelect = document.getElementById('service_id2');
    const durationInput = document.getElementById('duration2');

    serviceSelect.addEventListener('change', function() {
        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        const duration       = selectedOption.getAttribute('data-duration') || 0;
        durationInput.value  = duration;
    });
});

$(document).ready(function () {
    // Gestion de la fermeture des modals via les boutons "data-bs-dismiss"
    $('button[data-bs-dismiss="modal"]').on('click', function () {
        const modal = $(this).closest('.modal');
        if (modal.length) {
            console.log('Fermeture du modal:', modal.attr('id'));
            modal.modal('hide');
        }
    });

    // Mettre à jour la durée du service dans le 2ème formulaire (si besoin)
    $('#service_id').on('change', function () {
        const duration = $(this).find(':selected').data('duration');
        $('#duration').val(duration || '');
    });
    // Trigger au chargement
    $('#service_id').trigger('change');

    // Recherche dynamique tech (auto-complétion)
    let timer;
    $('#search_tech').on('input', function() {
        clearTimeout(timer);
        const query = $(this).val().trim();
        if (!query) {
            $('#techSuggestions').html('');
            $('#search_tech_id').val('');
            return;
        }
        timer = setTimeout(() => {
            fetchTechSuggestions(query);
        }, 300);
    });

    function fetchTechSuggestions(query) {
        showLoadingOverlay();  // 🔹 AFFICHER LE CHARGEMENT ICI

        $.ajax({
            url: '/tech-search?q=' + encodeURIComponent(query),
            type: 'GET',
            success: function(data) {
                renderTechSuggestions(data);
                hideLoadingOverlay();  // 🔹 CACHER LE CHARGEMENT APRÈS SUCCÈS
            },
            error: function(err) {
                console.error(err);
                hideLoadingOverlay();  // 🔹 CACHER LE CHARGEMENT EN CAS D'ERREUR
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
            html += `
                <div class="p-2 suggestion-item" style="cursor: pointer;">
                    ${item.fullname}
                </div>
            `;
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
});

document.addEventListener('DOMContentLoaded', function() {
    showLoadingOverlay();
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        // Langue
        locale: 'fr',

        // Vue par défaut
        initialView: 'timeGridWeek',

        // On affiche les boutons pour la MonthView, la WeekView et la DayView
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

        // Les événements générés côté serveur
        events: {!! json_encode($events) !!},

        // Appliquer le style à chaque événement
        eventDidMount: function(info) {
            info.el.style.backgroundColor = info.event.backgroundColor;

            // Bordure selon le type de service
            const type = info.event.extendedProps.serviceType;
            if (type === 'mar') {
                info.el.style.border = '2px solid red';
            } else if (type === 'audit') {
                info.el.style.border = '2px solid green';
            } else if (type === 'cofrac') {
                info.el.style.border = '2px solid blue';
            }
        },

        // Lorsqu'on clique sur un rendez-vous, on affiche le modal
        eventClick: function(info) {
            var event = info.event;
            var props = event.extendedProps;

            console.log("RDV sélectionné :", event);

            // Remplir les données du modal
            document.getElementById('modalClientName').textContent = event.title;
            document.getElementById('modalTechName').textContent = props.techName || 'Non spécifié';
            document.getElementById('modalService').textContent = props.serviceName || 'Non spécifié';
            document.getElementById('modalDate').textContent = new Date(event.start).toLocaleDateString();
            document.getElementById('modalTime').textContent = new Date(event.start).toLocaleTimeString() + ' - ' + new Date(event.end).toLocaleTimeString();
            document.getElementById('modalComment').textContent = props.comment || 'Aucun commentaire';
            document.getElementById('modalClientAddress').textContent = props.clientAddress || 'Adresse non disponible';

            // Afficher le modal
            var modal = new bootstrap.Modal(document.getElementById('appointmentModal'));
            modal.show();
        }
    });

    calendar.render();
    hideLoadingOverlay();
});
</script>
@endsection
