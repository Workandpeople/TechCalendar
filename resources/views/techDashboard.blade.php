@extends('layouts.app')

@section('title', 'Dashboard')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Votre Dashboard</h1>
    {{-- <a href="{{ route('tech-calendar.index') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-calendar-alt fa-sm text-white-50"></i> Votre calendrier
    </a> --}}
    <div class="mt-2">
        <button class="btn btn-outline-dark btn-sm" data-toggle="modal" data-target="#syncCalendarModal">
            <i class="fab fa-google me-1"></i>
            <i class="fab fa-apple me-1"></i>
            Synchroniser mon calendrier
        </button>
    </div>
</div>
@endsection

@section('content')
<div class="container p-0">
        <!-- Vérification si l'utilisateur a un tech_id -->
        @if(!is_null($techId))
            <!-- Statistiques -->
            <div class="row justify-content-center mb-4">
                <div class="col-6 col-md-3 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                RDV effectués aujourd'hui</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvEffectuesAujd }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                RDV à venir aujourd'hui</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvAVenirAujd }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                RDV effectués ce mois-ci</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvEffectuesMois }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                RDV à venir ce mois-ci</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvAVenirMois }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- liste -->
            <div class="card w-100 mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-2 text-center">Vos rendez-vous</h5>
                    <div class="d-flex justify-content-center">
                        <button class="btn btn-sm btn-outline-primary me-1" id="prev-day"><i class="fas fa-chevron-left"></i></button>
                        <span id="current-date" class="fw-bold mx-2"></span>
                        <button class="btn btn-sm btn-outline-primary ms-1" id="next-day"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="card-body p-0" style="height: 65vh;">
                    <div id="calendar-tech" style="height: 100%;"></div>
                </div>
            </div>

            @include('partials.modals.appointmentDetails')

            <!-- Modal de synchronisation calendrier -->
            <div class="modal fade" id="syncCalendarModal" tabindex="-1" role="dialog" aria-labelledby="syncCalendarModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="syncCalendarModalLabel">Synchroniser mon calendrier</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="background: none; border: none;">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            Tous les rendez-vous du mois en cours seront ajoutés à votre calendrier.<br>
                            Êtes-vous sûr de vouloir continuer ?<br><br>
                            <strong>Note :</strong> Cliquez sur le fichier téléchargé pour l'ouvrir dans votre application de calendrier par défaut.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary" id="confirmSyncBtn">
                                Oui, synchroniser
                            </button>
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
@endsection

@section('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar-tech');
    if (!calendarEl) return;

    const calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'fr',
        initialView: 'timeGridDay',
        headerToolbar: false, // aucune barre d’outil
        slotMinTime: '08:00:00',
        slotMaxTime: '20:00:00',
        allDaySlot: false,
        hiddenDays: [0, 6],
        height: '100%',
        events: function(fetchInfo, successCallback, failureCallback) {
            fetch('{{ route('tech-dashboard.appointments') }}')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        successCallback(data.appointments);
                    } else {
                        failureCallback('Erreur lors du chargement');
                    }
                })
                .catch(error => {
                    console.error('Erreur AJAX:', error);
                    failureCallback(error);
                });
        },
        eventClick: function(info) {
            var event = info.event;
            var props = event.extendedProps;

            document.getElementById('modalClientName').textContent = event.title || 'Inconnu';
            document.getElementById('modalTechName').textContent = props.techName || 'Non spécifié';
            document.getElementById('modalService').textContent = props.serviceName || 'Non spécifié';
            document.getElementById('modalDate').textContent = event.start ? new Date(event.start).toLocaleDateString('fr-FR') : 'Non défini';
            document.getElementById('modalTime').textContent = event.start
                ? `${new Date(event.start).toLocaleTimeString('fr-FR')} - ${new Date(event.end).toLocaleTimeString('fr-FR')}`
                : 'Non défini';
            document.getElementById('modalClientAddress').textContent = props.clientAddress || 'Adresse non disponible';
            document.getElementById('modalClientPhone').textContent = props.clientPhone || 'Non communiqué';
            document.getElementById('modalComment').textContent = props.comment || 'Aucun commentaire';

            var modal = new bootstrap.Modal(document.getElementById('appointmentModal'));
            modal.show();
        }
    });

    calendar.render();

    function updateCurrentDateDisplay() {
        const currentDate = calendar.getDate();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        document.getElementById('current-date').textContent = currentDate.toLocaleDateString('fr-FR', options);
    }

    document.getElementById('prev-day').addEventListener('click', function () {
        calendar.prev();
        updateCurrentDateDisplay();
    });

    document.getElementById('next-day').addEventListener('click', function () {
        calendar.next();
        updateCurrentDateDisplay();
    });

    updateCurrentDateDisplay();

    document.getElementById('confirmSyncBtn').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        btn.textContent = 'Synchronisation en cours...';

        fetch('{{ route('tech-dashboard.sync') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(response => {
            // Ajout d'un log côté serveur (à faire dans la route Laravel)
            // On suppose que le endpoint retourne un fichier ICS (type text/calendar)
            return response.blob();
        })
        .then(blob => {
            // Simuler le téléchargement d'un fichier .ics
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'rendez-vous.ics';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            btn.textContent = 'Oui, synchroniser';
            btn.disabled = false;

            bootstrap.Modal.getInstance(document.getElementById('syncCalendarModal')).hide();
            console.log('✅ Fichier ICS généré et téléchargé.');
        })
        .catch(error => {
            btn.textContent = 'Oui, synchroniser';
            btn.disabled = false;
            console.error('❌ Erreur lors de la synchronisation :', error);
        });
    });
});
</script>
@endsection
