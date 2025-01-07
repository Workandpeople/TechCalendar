@extends('layouts.app')

@section('title', 'Agenda Comparatif')

@section('head-css')
<style>
    .fc-event {
        cursor: pointer;
    }
    .dropdown-menu {
        max-height: 200px;
        overflow-y: auto;
    }
    @media (max-width: 768px) {
        .card-body {
            height: 70vh; /* Hauteur de card-body */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        #calendar {
            flex-grow: 1; /* S'assure que le calendrier occupe toute la hauteur disponible */
        }
    }
</style>
@endsection

@section('content')
<!-- Content Wrapper -->
<div id="content-wrapper" class="d-flex flex-column">

    <!-- Main Content -->
    <div id="content">

        <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
            <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                <i class="fa fa-bars"></i>
            </button>
            @include('partials/simpleTopbar')
        </nav>

        <div class="container-fluid">
            <!-- Carte du calendrier -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <div class="row align-items-center text-center">
                        <div class="col-12 col-md-3">
                            <h6 class="m-0 font-weight-bold text-primary">Agenda Comparatif</h6>
                        </div>
                        <div class="col-12 col-md-3">
                            <p id="currentDate" class="text-muted mt-2"></p>
                        </div>
                        <div class="col-12 col-md-3 mt-sm-2">
                            <div class="legend d-flex justify-content-center flex-wrap">
                                <div class="legend-item d-flex align-items-center mx-2">
                                    <span class="legend-color" style="width: 20px; height: 20px; background-color: #007bff; margin-right: 5px; border: 1px solid #000;"></span>
                                    <span>MAR</span>
                                </div>
                                <div class="legend-item d-flex align-items-center mx-2">
                                    <span class="legend-color" style="width: 20px; height: 20px; background-color: #28a745; margin-right: 5px; border: 1px solid #000;"></span>
                                    <span>AUDIT</span>
                                </div>
                                <div class="legend-item d-flex align-items-center mx-2">
                                    <span class="legend-color" style="width: 20px; height: 20px; background-color: #ffc107; margin-right: 5px; border: 1px solid #000;"></span>
                                    <span>COFRAC</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3 mt-sm-3">
                            <div class="d-flex justify-content-center">
                                <button class="btn btn-sm btn-outline-primary mx-1" id="prevDay">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-primary mx-1" id="today">Aujourd'hui</button>
                                <button class="btn btn-sm btn-outline-primary mx-1" id="nextDay">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                            <div class="d-flex justify-content-center mt-2">
                                <button class="btn btn-sm btn-outline-primary mx-1" id="monthView">Mois</button>
                                <button class="btn btn-sm btn-outline-primary mx-1" id="weekView">Semaine</button>
                                <button class="btn btn-sm btn-outline-primary mx-1" id="dayView">Jour</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="calendar"></div>
                </div>
            </div>
        
            <!-- Liste des techniciens -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <div class="row align-items-center text-center">
                        <div class="col-12 col-md-4">
                            <h6 class="m-0 font-weight-bold text-primary">Liste des Techniciens</h6>
                        </div>
                        <div class="col-12 col-md-8">
                            <input type="text" id="searchTechnicians" class="form-control" placeholder="Rechercher un technicien..." style="width: 100%;">
                        </div>
                    </div>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <div id="techniciansList">
                        @foreach($technicians as $tech)
                            <div class="form-check">
                                <input 
                                    class="form-check-input tech-checkbox" 
                                    type="checkbox" 
                                    value="{{ $tech->id }}" 
                                    id="tech-{{ $tech->id }}" 
                                    {{ in_array($tech->id, $preSelectedTechIds ?? []) ? 'checked' : '' }}>
                                <label class="form-check-label" for="tech-{{ $tech->id }}">
                                    {{ $tech->user->prenom }} {{ $tech->user->nom }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- End of Main Content -->

</div>
<!-- End of Content Wrapper -->

</div>
<!-- End of Page Wrapper -->

<!-- Modal -->
<div class="modal fade" id="appointmentModal" tabindex="-1" role="dialog" aria-labelledby="appointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="appointmentModalLabel" class="modal-title">Détails du Rendez-vous</h5>
                <button type="button" class="close" aria-label="Close" onclick="closeAppointmentModal()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p><strong>Client :</strong> <span id="modal-client"></span></p>
                <p><strong>Adresse :</strong> <span id="modal-adresse"></span></p>
                <p><strong>Technicien :</strong> <span id="modal-tech"></span></p>
                <p><strong>Durée :</strong> <span id="modal-duree"></span></p>
                <p><strong>Commentaire :</strong> <span id="modal-commentaire"></span></p>
            </div>
        </div>
    </div>
</div>

@endsection

@section('head-js')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    console.debug('[JS] DOMContentLoaded - Initializing Comparative Calendar');

    const calendarEl = document.getElementById('calendar');
    const currentDateEl = document.getElementById('currentDate');
    const prevDayBtn = document.getElementById('prevDay');
    const todayBtn = document.getElementById('today');
    const nextDayBtn = document.getElementById('nextDay');
    const monthViewBtn = document.getElementById('monthView');
    const weekViewBtn = document.getElementById('weekView');
    const dayViewBtn = document.getElementById('dayView');
    const techniciansList = document.getElementById('techniciansList');
    const searchTechniciansInput = document.getElementById('searchTechnicians');

    // Récupération d’un tableau d'IDs pré-sélectionnés depuis la page Blade
    // (Assure-toi que la variable preSelectedTechIds est définie quelque part dans la vue ou mets un fallback)
    const preSelectedTechIds = @json($preSelectedTechIds ?? []);

    // Initialisation du calendrier FullCalendar
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'fr',
        headerToolbar: false,
        weekends: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '21:00:00',
        allDaySlot: false,
        events: [],
        eventClick: function (info) {
            console.debug('[JS] eventClick: ', info.event);
            const event = info.event;
            const description = event.extendedProps.description || {};
            const techName = event.extendedProps.techName || 'Non spécifié';

            // Récupération de l'adresse complète
            const fullAddress = event.extendedProps.fullAddress || 'Non spécifiée';

            document.getElementById('modal-client').textContent    = event.title || 'Non spécifié';
            document.getElementById('modal-adresse').textContent   = fullAddress;
            document.getElementById('modal-tech').textContent      = techName;
            document.getElementById('modal-duree').textContent     = description.durée || 'Non spécifiée';
            document.getElementById('modal-commentaire').textContent = description.commentaire || 'Non spécifié';

            $('#appointmentModal').modal('show');
        },
        datesSet: function () {
            // Mettre à jour la date affichée
            const displayDate = calendar.getDate().toLocaleDateString('fr-FR', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            });
            currentDateEl.textContent = displayDate;
            console.debug('[JS] datesSet - current date is: ', displayDate);
        },
    });

    calendar.render();
    console.debug('[JS] Calendar rendered');

    // Recharger le rendu du calendrier si la sidebar est togglée
    const sidebarToggle = document.getElementById('sidebarToggleTop');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            setTimeout(() => {
                calendar.render();
                console.debug('[JS] Calendar re-rendered after sidebar toggle');
            }, 10);
        });
    }

    // Boutons de navigation
    prevDayBtn.addEventListener('click', () => {
        calendar.prev();
        console.debug('[JS] prevDay clicked');
    });
    todayBtn.addEventListener('click', () => {
        calendar.today();
        console.debug('[JS] today clicked');
    });
    nextDayBtn.addEventListener('click', () => {
        calendar.next();
        console.debug('[JS] nextDay clicked');
    });

    // Boutons de vue
    monthViewBtn.addEventListener('click', () => {
        calendar.changeView('dayGridMonth');
        console.debug('[JS] monthView clicked');
    });
    weekViewBtn.addEventListener('click', () => {
        calendar.changeView('timeGridWeek');
        console.debug('[JS] weekView clicked');
    });
    dayViewBtn.addEventListener('click', () => {
        calendar.changeView('timeGridDay');
        console.debug('[JS] dayView clicked');
    });

    // Fonction de rechargement du calendrier
    function reloadCalendarEvents() {
        const checkedTechIds = Array.from(document.querySelectorAll('.tech-checkbox:checked'))
            .map(checkbox => checkbox.value);

        console.debug('[JS] reloadCalendarEvents - tech_ids:', checkedTechIds);

        fetch("{{ route('assistant.calendar_events') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({ tech_ids: checkedTechIds }),
        })
        .then(response => {
            console.debug('[JS] getCalendarEvents response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.debug('[JS] Received events:', data);
            calendar.removeAllEvents();
            calendar.addEventSource(data);
        })
        .catch(error => {
            console.error('[JS] Erreur lors du chargement des événements :', error);
        });
    }

    // Pré-cocher les techniciens et charger leurs rendez-vous
    if (preSelectedTechIds.length > 0) {
        console.debug('[JS] Preselected tech IDs: ', preSelectedTechIds);
        preSelectedTechIds.forEach(id => {
            const checkbox = document.getElementById(`tech-${id}`);
            if (checkbox) {
                checkbox.checked = true;
            }
        });
        reloadCalendarEvents();
    }

    // Gestion des cases à cocher
    techniciansList.addEventListener('change', (event) => {
        if (event.target.classList.contains('tech-checkbox')) {
            reloadCalendarEvents();
        }
    });

    // Recherche de techniciens
    searchTechniciansInput.addEventListener('input', (event) => {
        const searchTerm = event.target.value.toLowerCase();
        console.debug('[JS] Searching technicians with: ', searchTerm);

        document.querySelectorAll('#techniciansList .form-check').forEach((item) => {
            const techName = item.textContent.toLowerCase();
            item.style.display = techName.includes(searchTerm) ? '' : 'none';
        });
    });

    // Fonction pour fermer le modal
    window.closeAppointmentModal = function () {
        console.debug('[JS] closeAppointmentModal()');
        $('#appointmentModal').modal('hide');
    };
});
</script>
@endsection