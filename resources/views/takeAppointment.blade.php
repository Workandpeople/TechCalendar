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
        <a href="#" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#appointmentCreateModal2">
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
    @include('partials.modals.appointmentCreate2')

    <!-- Form de recherche (adresse, code postal, ville, etc.) -->
    @include('partials.forms.searchForm')

    <!-- Affichage du calendrier -->
    @include('partials.tables.resultCalendar', [
        'selectedTechs' => $selectedTechs,
        'appointments'  => $appointments
    ])
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
    foreach(($appointments ?? []) as $appoint) {
        $clientFullname = $appoint->client_fname.' '.$appoint->client_lname;
        $techId         = $appoint->tech_id;
        $events[] = [
            'id'               => $appoint->id,
            'title'            => $clientFullname,
            'start'            => $appoint->start_at,
            'end'              => $appoint->end_at,
            'backgroundColor'  => $techColorMap[$techId]['color'] ?? '#cccccc',
            'extendedProps' => [
                'serviceType' => $appoint->service->type ?? ''
            ]
        ];
    }
@endphp
<script>
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
    // Fermer le modal
    $('button[data-bs-dismiss="modal"]').on('click', function () {
        const modal = $(this).closest('.modal');
        if (modal.length) {
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
        $.ajax({
            url: '/tech-search?q=' + encodeURIComponent(query),
            type: 'GET',
            success: function(data) {
                renderTechSuggestions(data);
            },
            error: function(err) {
                console.error(err);
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

        // Pour chaque événement, on applique votre logique de style
        eventDidMount: function(info) {
            // Couleur de fond (technicien)
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
        }
    });

    calendar.render();
});
</script>
@endsection
