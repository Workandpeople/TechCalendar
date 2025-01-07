@extends('layouts.app')

@section('title', 'Agenda Technicien')

@section('head-css')
<style>
    .dropdown-menu {
        max-height: 200px;
        overflow-y: auto;
    }
    .dropdown-item {
        cursor: pointer;
    }
    .fc-event {
        cursor: pointer;
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
            <!-- Sidebar Toggle (Topbar) -->
            <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                <i class="fa fa-bars"></i>
            </button>
            <form class="form-inline mr-auto position-relative">
                <div class="input-group">
                    <input 
                        type="text" 
                        id="techSearchInput" 
                        class="form-control bg-light border-0 small" 
                        placeholder="Rechercher un technicien..." 
                        aria-label="Search"
                        aria-expanded="false"
                        autocomplete="off">
                    <div id="techSearchResults" class="dropdown-menu shadow animated--fade-in position-absolute w-100" style="display: none;"></div>
                </div>
            </form>
            @include('partials/simpleTopbar')
        </nav>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <div class="row align-items-center text-center">
                        <!-- Titre -->
                        <div class="col-12 col-md-4">
                            <h6 class="m-0 font-weight-bold text-primary">Agenda de Technicien</h6>
                        </div>
                        <!-- Légende -->
                        <div class="col-12 col-md-4 mt-2">
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
                        <!-- Boutons de navigation -->
                        <div class="col-12 col-md-4 mt-3">
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
                <h5 class="modal-title" id="appointmentModalLabel">Détails du Rendez-vous</h5>
                <button type="button" class="close" aria-label="Close" onclick="closeAppointmentModal()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p><strong>Client :</strong> <span id="modal-client"></span></p>
                <p><strong>Adresse :</strong> <span id="modal-adresse"></span></p>
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
    console.log('[JS] DOMContentLoaded - Initializing calendar...');
    
    const calendarEl = document.getElementById('calendar');
    const prevDayBtn = document.getElementById('prevDay');
    const todayBtn = document.getElementById('today');
    const nextDayBtn = document.getElementById('nextDay');
    const monthViewBtn = document.getElementById('monthView');
    const weekViewBtn = document.getElementById('weekView');
    const dayViewBtn = document.getElementById('dayView');

    // Initialisation de FullCalendar
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
            console.log('[JS] eventClick', info.event);

            document.getElementById('modal-client').textContent = info.event.title || 'Non spécifié';
            document.getElementById('modal-adresse').textContent = info.event.extendedProps.fullAddress || 'Non spécifiée';
            document.getElementById('modal-duree').textContent = info.event.extendedProps.durée || 'Non spécifiée';
            document.getElementById('modal-commentaire').textContent = info.event.extendedProps.commentaire || 'Non spécifié';

            $('#appointmentModal').modal('show');
        }
    });

    calendar.render();
    console.log('[JS] Calendar rendered');

    document.getElementById('sidebarToggleTop').addEventListener('click', () => {
        setTimeout(() => {
            calendar.render();
            console.log('[JS] Calendar re-rendered after sidebar toggle');
        }, 10);
    });

    // Navigation entre les jours
    prevDayBtn.addEventListener('click', () => {
        calendar.prev();
        console.log('[JS] prevDay clicked');
    });
    todayBtn.addEventListener('click', () => {
        calendar.today();
        console.log('[JS] today clicked');
    });
    nextDayBtn.addEventListener('click', () => {
        calendar.next();
        console.log('[JS] nextDay clicked');
    });

    // Boutons de changement de vue
    monthViewBtn.addEventListener('click', () => {
        calendar.changeView('dayGridMonth');
        console.log('[JS] monthView clicked');
    });
    weekViewBtn.addEventListener('click', () => {
        calendar.changeView('timeGridWeek');
        console.log('[JS] weekView clicked');
    });
    dayViewBtn.addEventListener('click', () => {
        calendar.changeView('timeGridDay');
        console.log('[JS] dayView clicked');
    });

    // Gestion de la recherche dynamique
    techSearchInput.addEventListener('input', function () {
        const query = this.value.trim();
        console.log('[JS] Searching for tech:', query);

        if (query.length < 2) {
            techSearchResults.style.display = 'none';
            console.log('[JS] Query too short, ignoring');
            return;
        }

        fetch(`{{ route('assistant.search_technicians') }}?query=${encodeURIComponent(query)}`)
            .then(response => {
                console.log('[JS] Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('[JS] Technicians search result:', data);
                techSearchResults.innerHTML = ''; 

                if (data.length > 0) {
                    data.forEach(tech => {
                        const resultItem = document.createElement('a');
                        resultItem.href = "#";
                        resultItem.className = "dropdown-item";
                        resultItem.textContent = `${tech.prenom} ${tech.nom}`;
                        resultItem.addEventListener('click', (event) => {
                            event.preventDefault();
                            console.log('[JS] Loading appointments for tech:', tech.id);
                            loadTechAppointments(tech.id);
                            techSearchInput.value = `${tech.prenom} ${tech.nom}`;
                            techSearchResults.style.display = 'none';
                        });
                        techSearchResults.appendChild(resultItem);
                    });
                    techSearchResults.style.display = 'block';
                } else {
                    const noResultItem = document.createElement('p');
                    noResultItem.className = "dropdown-item text-muted";
                    noResultItem.textContent = "Aucun résultat trouvé.";
                    techSearchResults.appendChild(noResultItem);
                    techSearchResults.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('[JS] Erreur lors de la recherche :', error);
            });
    });

    // Fonction pour charger les RDV
    window.loadTechAppointments = function(techId) {
        console.log('[JS] loadTechAppointments() for techId:', techId);
        fetch(`{{ route('assistant.tech_appointments') }}?tech_id=${encodeURIComponent(techId)}`)
            .then(response => {
                console.log('[JS] getTechAppointments response status:', response.status);
                return response.json();
            })
            .then(events => {
                console.log('[JS] Received events:', events);
                calendar.removeAllEvents();
                calendar.addEventSource(events);
            })
            .catch(error => {
                console.error('[JS] Erreur lors du chargement des RDV :', error);
            });
    };

    // Fermer le modal
    window.closeAppointmentModal = function () {
        console.log('[JS] closeAppointmentModal()');
        $('#appointmentModal').modal('hide');
    };
});
</script>
@endsection