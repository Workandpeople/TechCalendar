@extends('layouts.app')

@section('title', 'Calendrier')

@section('css')
<style>
    /* Optionnel : style pour la pastille orange ajoutée sur un RDV soft deleted */
    .deleted-dot {
        background-color: orange;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        position: absolute;
        top: 2px;
        right: 2px;
    }
    /* Style pour le modal en soft delete */
    .modal-softdeleted {
        background-color: #ffe6cc !important;
    }
</style>
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
        <div class="modal-content" id="appointmentModalContent">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentModalLabel">Détails du rendez-vous</h5>
                <!-- Bouton de fermeture Bootstrap 4 -->
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p><strong>Client :</strong> <span id="modalClientName"></span></p>
                <p><strong>Service :</strong> <span id="modalService"></span></p>
                <p><strong>Date :</strong> <span id="modalDate"></span></p>
                <p><strong>Heure :</strong> <span id="modalTime"></span></p>
                <p><strong>Adresse :</strong> <span id="modalClientAddress"></span></p>
                <p><strong>Téléphone :</strong> <span id="modalClientPhone"></span></p>
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

    /**
     * Initialisation du calendrier FullCalendar.
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
            // Ajout d'un callback pour modifier l'affichage des événements soft deleted
            eventDidMount: function(info) {
                if (info.event.extendedProps.isDeleted) {
                    // Ajouter une pastille orange sur l'événement
                    let dot = document.createElement('span');
                    dot.className = 'deleted-dot';
                    info.el.style.position = 'relative';
                    info.el.appendChild(dot);
                }
            },
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
                document.getElementById('modalClientPhone').textContent = props.clientPhone || 'Non renseigné';
                document.getElementById('modalComment').textContent = props.comment || 'Aucun commentaire';

                // Si le RDV est soft deleted, appliquer un fond orange clair au modal
                if (props.isDeleted) {
                    document.getElementById('appointmentModalContent').classList.add('modal-softdeleted');
                } else {
                    document.getElementById('appointmentModalContent').classList.remove('modal-softdeleted');
                }

                var modal = new bootstrap.Modal(document.getElementById('appointmentModal'));
                modal.show();
            }
        });
        calendar.render();
    }

    /**
     * Chargement des rendez-vous du technicien connecté.
     */
    function loadTechAppointments() {
        showLoadingOverlay();
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
            },
            complete: function () {
                hideLoadingOverlay();
            }
        });
    }

    // Initialisation du calendrier et chargement des rendez-vous si l'utilisateur est technicien.
    let isTech = @json($techId) !== null;
    if (isTech) {
        initFullCalendar();
        loadTechAppointments();
    }
});
</script>
@endsection
