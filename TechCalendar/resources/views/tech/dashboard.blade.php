@extends('layouts.appTech')

@section('title', 'Dashboard')

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
            @include('partials/simpleTopbar')
        </nav>

        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <div class="row align-items-center text-center">
                        <!-- Titre -->
                        <div class="col-12 col-md-4">
                            <h6 class="m-0 font-weight-bold text-primary">Mon Agenda</h6>
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
    const calendarEl = document.getElementById('calendar');
    const prevDayBtn = document.getElementById('prevDay');
    const todayBtn = document.getElementById('today');
    const nextDayBtn = document.getElementById('nextDay');

    // Initialisation de FullCalendar
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridDay',
        locale: 'fr',
        headerToolbar: false, // Pas de boutons de navigation intégrés
        weekends: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '21:00:00',
        allDaySlot: false,
        events: [], // Chargement initial vide
        eventClick: function (info) {
            const event = info.event.extendedProps;

            // Remplir le modal avec les informations du rendez-vous
            document.getElementById('modal-client').textContent = info.event.title || 'Non spécifié';
            document.getElementById('modal-adresse').textContent = event.adresse || 'Non spécifiée';
            document.getElementById('modal-duree').textContent = event.durée || 'Non spécifiée';
            document.getElementById('modal-commentaire').textContent = event.commentaire || 'Non spécifié';

            $('#appointmentModal').modal('show');
        }
    });

    calendar.render();

    // Navigation entre les jours
    prevDayBtn.addEventListener('click', () => calendar.prev());
    todayBtn.addEventListener('click', () => calendar.today());
    nextDayBtn.addEventListener('click', () => calendar.next());

    // Fermer le modal
    window.closeAppointmentModal = function () {
        $('#appointmentModal').modal('hide');
    };
});
</script>
@endsection