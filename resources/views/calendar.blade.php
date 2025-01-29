@extends('layouts.app')

@section('title', 'Calendrier Comparatif')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Calendrier comparatif</h1>
    <a href="{{ route('appointment.index') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-bookmark fa-sm text-white-50"></i> Prendre un RDV
    </a>
</div>
@endsection

@section('content')
<!-- Affichage des RDV -->
@include('partials.modals.appointmentDetails')
<div class="container">
    <div class="row">
        <!-- Calendrier -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Calendrier des rendez-vous</h5>
                </div>
                <div class="card-body">
                    <div id="calendar-container">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Liste des techniciens -->
        <div class="col-12 mt-3">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title">Sélectionner les techniciens</h5>

                    <!-- Switch pour cocher/décocher tous les techniciens -->
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="toggleAllTechs">
                        <label class="form-check-label" for="toggleAllTechs">Tout sélectionner</label>
                    </div>
                </div>
                <div class="card-body">
                    <input type="text" id="search_tech" class="form-control mb-2" placeholder="Rechercher un technicien...">
                    <div id="tech-list">
                        @foreach($technicians as $tech)
                            <div class="form-check">
                                <input class="form-check-input tech-checkbox" type="checkbox" value="{{ $tech->id }}" id="tech-{{ $tech->id }}">
                                <label class="form-check-label tech-checkbox-label" for="tech-{{ $tech->id }}">
                                    {{ $tech->user->prenom }} {{ $tech->user->nom }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
$(document).ready(function () {
    let calendar;

    /**
     * Initialisation du calendrier FullCalendar
     */
    function initFullCalendar() {
        let calendarEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'fr',
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            slotMinTime: '08:00:00',
            slotMaxTime: '21:00:00',
            hiddenDays: [0, 6],
            events: [], // Pas d'événements au chargement
            eventClick: function(info) {
                var event = info.event;
                var props = event.extendedProps;

                console.log("📌 RDV sélectionné :", event);

                let clientAddress = props.clientAddress || "Adresse non disponible";

                document.getElementById('modalClientName').textContent = event.title || 'Inconnu';
                document.getElementById('modalTechName').textContent = props.techName || 'Non spécifié';
                document.getElementById('modalService').textContent = props.serviceName || 'Non spécifié';
                document.getElementById('modalDate').textContent = event.start ? new Date(event.start).toLocaleDateString() : 'Non défini';
                document.getElementById('modalTime').textContent = event.start
                    ? `${new Date(event.start).toLocaleTimeString()} - ${new Date(event.end).toLocaleTimeString()}`
                    : 'Non défini';
                document.getElementById('modalClientAddress').textContent = clientAddress;
                document.getElementById('modalComment').textContent = props.comment || 'Aucun commentaire';

                var modal = new bootstrap.Modal(document.getElementById('appointmentModal'));
                modal.show();
            }
        });

        calendar.render();
    }

    /**
     * Récupération des rendez-vous des techniciens sélectionnés
     */
    function updateCalendar() {
        let selectedTechs = $('.tech-checkbox:checked').map(function () {
            return $(this).val();
        }).get();

        console.log("🔄 Mise à jour du calendrier pour les techniciens :", selectedTechs);

        $.ajax({
            url: '/api/calendar',
            type: 'GET',
            data: { techs: selectedTechs },
            success: function(response) {
                if (response.success) {
                    console.log("✅ RDV chargés :", response.appointments);

                    calendar.removeAllEvents();
                    response.appointments.forEach(event => {
                        calendar.addEvent(event);
                    });

                } else {
                    console.error("❌ Erreur lors du chargement des RDV :", response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error("❌ Erreur AJAX :", xhr);
            }
        });
    }

    /**
     * Gestion du switch "Tout sélectionner"
     */
    $('#toggleAllTechs').on('change', function () {
        let isChecked = $(this).prop('checked');
        $('.tech-checkbox').prop('checked', isChecked);
        updateCalendar();
    });

    /**
     * Gestion de la sélection des techniciens
     */
    $('.tech-checkbox').on('change', function () {
        updateCalendar();
    });

    /**
     * Recherche dynamique dans la liste des techniciens
     */
    $('#search_tech').on('input', function () {
        let query = $(this).val().toLowerCase().trim();
        $('.tech-checkbox-label').each(function () {
            const name = $(this).text().toLowerCase();
            $(this).toggle(name.includes(query));
        });
    });

    // Initialisation du calendrier au chargement
    initFullCalendar();
});
</script>
@endsection
