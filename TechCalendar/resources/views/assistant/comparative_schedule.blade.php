@extends('layouts.app')

@section('title', 'Agenda Comparatif')

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
                <div class="card-header py-3 d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Agenda Comparatif</h6>
                    <div class="legend d-flex">
                        <div class="legend-item d-flex align-items-center mr-3">
                            <span class="legend-color" style="display: inline-block; width: 20px; height: 20px; background-color: #007bff; margin-right: 5px; border: 1px solid #000;"></span>
                            <span>MAR</span>
                        </div>
                        <div class="legend-item d-flex align-items-center mr-3">
                            <span class="legend-color" style="display: inline-block; width: 20px; height: 20px; background-color: #28a745; margin-right: 5px; border: 1px solid #000;"></span>
                            <span>AUDIT</span>
                        </div>
                        <div class="legend-item d-flex align-items-center">
                            <span class="legend-color" style="display: inline-block; width: 20px; height: 20px; background-color: #ffc107; margin-right: 5px; border: 1px solid #000;"></span>
                            <span>COFRAC</span>
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
                <p><strong>Technicien :</strong> <span id="modal-tech"></span></p>
                <p><strong>Durée :</strong> <span id="modal-duree"></span></p>
                <p><strong>Commentaire :</strong> <span id="modal-commentaire"></span></p>
            </div>
        </div>
    </div>
</div>

@endsection

@section('head-css')
<style>
    .fc-event {
        cursor: pointer; /* Affiche un pointeur clic sur les cellules des événements */
    }
</style>
@endsection

@section('head-js')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var calendarEl = document.getElementById('calendar');

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            locale: 'fr',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'timeGridWeek,dayGridMonth,timeGridDay'
            },
            weekends: false,
            slotMinTime: '07:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            events: {!! json_encode($events->toArray()) !!},
            eventClick: function(info) {
                var event = info.event.extendedProps;

                // Remplir les informations du modal
                document.getElementById('modal-client').textContent = `${info.event.title}`;
                document.getElementById('modal-adresse').textContent = event.adresse || 'Non spécifiée';
                document.getElementById('modal-tech').textContent = event.techName || 'Non spécifié';
                document.getElementById('modal-duree').textContent = event.durée || 'Non spécifiée';
                document.getElementById('modal-commentaire').textContent = event.commentaire || 'Non spécifié';

                // Afficher le modal
                $('#appointmentModal').modal('show');
            }
        });

        calendar.render();
    });

    // Fonction pour fermer le modal
    function closeAppointmentModal() {
        $('#appointmentModal').modal('hide'); // Ferme le modal avec Bootstrap
    }
</script>
@endsection