@extends('layouts.app')

@section('title', 'Calendrier')

@section('css')
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Votre calendrier</h1>
    <a href="#" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-tachometer fa-sm text-white-50"></i> Dashboard
    </a>
</div>
@endsection

@section('content')
<div class="container">
    <div class="row">
        <!-- Vérification si l'utilisateur a un tech_id -->
        @if($techId)
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">Calendrier de vos rendez-vous</h5>
                    </div>
                    <div class="card-body">
                        <div id="calendar-container">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <!-- Message d'erreur si l'utilisateur n'est pas un technicien -->
            <div class="col-12">
                <div class="alert alert-warning text-center p-4">
                    <h5>🚫 Vous n'êtes pas un technicien.</h5>
                    <p>Veuillez contacter votre administrateur pour gérer votre rôle.</p>
                </div>
            </div>
        @endif
    </div>
</div>

<!-- Modal pour afficher les détails du RDV -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentModalLabel">Détails du rendez-vous</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><strong>Client :</strong> <span id="modalClientName"></span></p>
                <p><strong>Service :</strong> <span id="modalService"></span></p>
                <p><strong>Date :</strong> <span id="modalDate"></span></p>
                <p><strong>Heure :</strong> <span id="modalTime"></span></p>
                <p><strong>Adresse :</strong> <span id="modalClientAddress"></span></p>
                <p><strong>Commentaire :</strong> <span id="modalComment"></span></p>
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
$(document).ready(function () {
    let calendar;

    // Vérifier si l'utilisateur est un technicien
    let isTech = @json($techId) !== null;

    if (isTech) {
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
                    right: 'timeGridWeek,timeGridDay'
                },
                slotMinTime: '08:00:00',
                slotMaxTime: '21:00:00',
                hiddenDays: [0, 6],
                events: [],
                eventClick: function(info) {
                    var event = info.event;
                    var props = event.extendedProps;

                    console.log("📌 RDV sélectionné :", event);

                    let clientAddress = props.clientAddress || "Adresse non disponible";

                    document.getElementById('modalClientName').textContent = event.title || 'Inconnu';
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
         * Récupération des rendez-vous du technicien connecté
         */
        function loadTechAppointments() {
            $.ajax({
                url: '{{ route("tech-calendar.appointments") }}',
                type: 'GET',
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

        // Initialisation du calendrier et chargement des RDV si l'utilisateur est un technicien
        initFullCalendar();
        loadTechAppointments();
    }
});
</script>
@endsection
