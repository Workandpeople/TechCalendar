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
            
            <!-- HEADER -->
            <div class="modal-header">
                <h5 id="appointmentModalLabel" class="modal-title">Détails du Rendez-vous</h5>
                <button type="button" class="close" aria-label="Close" onclick="closeAppointmentModal()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            
            <!-- BLOC LECTURE SEULE -->
            <div class="modal-body" id="appointmentModalReadonly">
                <p><strong>Client :</strong> <span id="modal-client"></span></p>
                <p><strong>Adresse :</strong> <span id="modal-adresse"></span></p>
                <p><strong>Technicien :</strong> <span id="modal-tech"></span></p>
                <p><strong>Durée :</strong> <span id="modal-duree"></span></p>
                <p><strong>Commentaire :</strong> <span id="modal-commentaire"></span></p>
            </div>

            <!-- BLOC ÉDITION : caché par défaut via .d-none -->
            <div class="modal-body d-none" id="appointmentModalEditForm">
                <form id="editAppointmentForm">
                    <!-- ID caché -->
                    <input type="hidden" id="editAppId" name="id" />

                    <!-- Prénom, Nom, Téléphone -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="editClientFname">Prénom du client</label>
                            <!-- name="client_fname" => correspond à la validation -->
                            <input type="text" class="form-control" id="editClientFname" name="client_fname" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="editClientLname">Nom du client</label>
                            <!-- name="client_lname" -->
                            <input type="text" class="form-control" id="editClientLname" name="client_lname" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="editClientPhone">Téléphone du client</label>
                            <!-- name="client_phone" -->
                            <input type="text" class="form-control" id="editClientPhone" name="client_phone">
                        </div>
                    </div>

                    <!-- Adresse, CP, Ville -->
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="editClientAdresse">Adresse (Rue)</label>
                            <!-- name="client_adresse" -->
                            <input type="text" class="form-control" id="editClientAdresse" name="client_adresse" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="editClientZip">Code postal</label>
                            <!-- name="client_zip_code" -->
                            <input type="text" class="form-control" id="editClientZip" name="client_zip_code" required>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="editClientCity">Ville</label>
                            <!-- name="client_city" -->
                            <input type="text" class="form-control" id="editClientCity" name="client_city" required>
                        </div>
                    </div>

                    <!-- Technicien, Prestation -->
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="editTechId">Technicien</label>
                            <!-- name="techId" => "required|exists:WAPetGC_Tech,id" -->
                            <select id="editTechId" name="techId" class="form-control" required>
                                <option value="" disabled>Choisissez un technicien</option>
                                @foreach($technicians as $tech)
                                    <option value="{{ $tech->id }}">{{ $tech->user->prenom }} {{ $tech->user->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="editServiceId">Prestation</label>
                            <!-- name="serviceId" => "required|exists:WAPetGC_Services,id" -->
                            <select id="editServiceId" name="serviceId" class="form-control" required>
                                <option value="" disabled>Choisissez une prestation</option>
                                @foreach($services as $service)
                                    <option value="{{ $service->id }}" data-default-time="{{ $service->default_time }}">
                                        {{ $service->name }} ({{ $service->type }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Date, Heure début, Heure fin, Durée -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="editAppointmentDate">Date</label>
                            <!-- name="appointmentDate" => "required|date" -->
                            <input type="date" id="editAppointmentDate" name="appointmentDate" class="form-control" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="editStartTime">Heure de début</label>
                            <!-- name="startTime" => "required|date_format:H:i" -->
                            <input type="time" id="editStartTime" name="startTime" class="form-control" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="editEndTime">Heure de fin</label>
                            <!-- name="endTime" => "nullable|date_format:H:i" -->
                            <input type="time" id="editEndTime" name="endTime" class="form-control" readonly>
                        </div>
                    </div>

                    <!-- Durée + Commentaires -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="editDuration">Durée (minutes)</label>
                            <!-- name="duration" => "required|integer|min:1" -->
                            <input type="number" id="editDuration" name="duration" class="form-control" min="1" required>
                        </div>
                        <div class="form-group col-md-8">
                            <label for="editComment">Commentaires</label>
                            <!-- name="comment" => "nullable|string" -->
                            <textarea class="form-control" id="editComment" name="comment" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Boutons Enregistrer / Annuler -->
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                    <button type="button" class="btn btn-secondary" id="cancelEditBtn">Annuler</button>
                </form>
            </div>

            <!-- FOOTER (mode lecture seule) -->
            <div class="modal-footer" id="modalFooterButtons">
                <button type="button" class="btn btn-sm btn-warning" id="editButton">Editer</button>
                <button type="button" class="btn btn-sm btn-danger" id="deleteButton">Supprimer</button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('head-js')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        console.log('[JS] DOMContentLoaded - Initializing Comparative Calendar');
    
        // =====================
        // SÉLECTEURS DU DOM
        // =====================
        const calendarEl             = document.getElementById('calendar');
        const currentDateEl          = document.getElementById('currentDate');
        const prevDayBtn             = document.getElementById('prevDay');
        const todayBtn               = document.getElementById('today');
        const nextDayBtn             = document.getElementById('nextDay');
        const monthViewBtn           = document.getElementById('monthView');
        const weekViewBtn            = document.getElementById('weekView');
        const dayViewBtn             = document.getElementById('dayView');
        const techniciansList        = document.getElementById('techniciansList');
        const searchTechniciansInput = document.getElementById('searchTechnicians');
    
        // Éléments du modal (lecture seule + édition)
        const modalReadonly       = document.getElementById('appointmentModalReadonly');
        const modalEditFormWrap   = document.getElementById('appointmentModalEditForm');
        const modalFooter         = document.getElementById('modalFooterButtons');
        const editAppointmentForm = document.getElementById('editAppointmentForm');
    
        // Boutons du modal
        const cancelEditBtn   = document.getElementById('cancelEditBtn');
        const editButton      = document.getElementById('editButton');
        const deleteButton    = document.getElementById('deleteButton');
    
        // Champs du formulaire d'édition
        const editAppId       = document.getElementById('editAppId');
        const editClientFname = document.getElementById('editClientFname');
        const editClientLname = document.getElementById('editClientLname');
        const editClientPhone = document.getElementById('editClientPhone');
        const editClientAdresse= document.getElementById('editClientAdresse');
        const editClientZip   = document.getElementById('editClientZip');
        const editClientCity  = document.getElementById('editClientCity');
        const editTechId      = document.getElementById('editTechId');
        const editServiceId   = document.getElementById('editServiceId');
        const editAppointmentDate = document.getElementById('editAppointmentDate');
        const editStartTime   = document.getElementById('editStartTime');
        const editEndTime     = document.getElementById('editEndTime');
        const editDuration    = document.getElementById('editDuration');
        const editComment     = document.getElementById('editComment');
    
        // Variable pour stocker l'ID RDV + extendedProps complets
        let currentAppointmentId  = null;
        let currentExtendedProps  = {};
    
        // IDs pré-sélectionnés (si besoin)
        const preSelectedTechIds = @json($preSelectedTechIds ?? []);
    
        // =====================
        // INITIALISATION DU CALENDRIER
        // =====================
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'fr',
            headerToolbar: false,
            weekends: false,
            slotMinTime: '07:00:00',
            slotMaxTime: '21:00:00',
            allDaySlot: false,
            events: [],
    
            // =====================
            // EVENT CLICK (Clique sur un RDV)
            // =====================
            eventClick: function(info) {
                console.log('[JS] eventClick:', info.event);
    
                // On récupère les extendedProps
                const event = info.event;
                console.log('[JS] event:', event);
                const ext   = event.extendedProps;
                console.log('[JS] extendedProps:', ext);
    
                currentAppointmentId = ext.appId || null;
                currentExtendedProps = ext;
    
                // Mode lecture seule : on affiche le bloc "readonly", on masque le bloc "edit"
                modalReadonly.classList.remove('d-none');
                modalEditFormWrap.classList.add('d-none');
                modalFooter.style.display = 'block';  // Boutons Editer / Supprimer
    
                // Remplir la partie lecture seule
                document.getElementById('modal-client').textContent = event.title || 'Non spécifié';
                document.getElementById('modal-adresse').textContent= ext.fullAddress || 'Non spécifiée';
                document.getElementById('modal-tech').textContent   = ext.techName    || 'Non spécifié';
    
                const desc = ext.description || {};
                document.getElementById('modal-duree').textContent      = desc.durée       || 'Non spécifiée';
                document.getElementById('modal-commentaire').textContent= desc.commentaire || 'Non spécifié';
    
                // Ouvrir le modal
                $('#appointmentModal').modal('show');
            },
    
            // =====================
            // DATES SET (Changement de vue, jour, etc.)
            // =====================
            datesSet: function() {
                const displayDate = calendar.getDate().toLocaleDateString('fr-FR', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                });
                currentDateEl.textContent = displayDate;
                console.log('[JS] datesSet - current date is:', displayDate);
            },
        });
    
        // On lance le rendu
        calendar.render();
        console.log('[JS] Calendar rendered');
    
        // =====================
        // SIDEBAR TOGGLE
        // =====================
        const sidebarToggle = document.getElementById('sidebarToggleTop');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                setTimeout(() => {
                    calendar.render();
                    console.log('[JS] Calendar re-rendered after sidebar toggle');
                }, 10);
            });
        }
    
        // =====================
        // BOUTONS DE NAVIGATION
        // =====================
        prevDayBtn.addEventListener('click', () => calendar.prev());
        todayBtn.addEventListener('click', () => calendar.today());
        nextDayBtn.addEventListener('click', () => calendar.next());
    
        // =====================
        // BOUTONS DE VUE (Mois, Semaine, Jour)
        // =====================
        monthViewBtn.addEventListener('click', () => calendar.changeView('dayGridMonth'));
        weekViewBtn.addEventListener('click', () => calendar.changeView('timeGridWeek'));
        dayViewBtn.addEventListener('click', () => calendar.changeView('timeGridDay'));
    
        // =====================
        // RECHERCHE TECHNICIENS
        // =====================
        searchTechniciansInput.addEventListener('input', (event) => {
            const searchTerm = event.target.value.toLowerCase();
            document.querySelectorAll('#techniciansList .form-check').forEach(item => {
                const techName = item.textContent.toLowerCase();
                item.style.display = techName.includes(searchTerm) ? '' : 'none';
            });
        });
    
        // =====================
        // CASES À COCHER (Techniciens)
        // =====================
        techniciansList.addEventListener('change', (event) => {
            if (event.target.classList.contains('tech-checkbox')) {
                reloadCalendarEvents();
            }
        });
    
        // =====================
        // FONCTION DE RECHARGEMENT DES ÉVÉNEMENTS
        // =====================
        function reloadCalendarEvents() {
            const checkedTechIds = Array.from(document.querySelectorAll('.tech-checkbox:checked'))
                .map(checkbox => checkbox.value);
    
            console.log('[JS] reloadCalendarEvents - tech_ids:', checkedTechIds);
    
            fetch("{{ route('assistant.calendar_events') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({ tech_ids: checkedTechIds }),
            })
            .then(response => response.json())
            .then(data => {
                console.log('[JS] Received events:', data);
                calendar.removeAllEvents();
                calendar.addEventSource(data);
            })
            .catch(error => {
                console.error('[JS] Erreur lors du chargement des événements :', error);
            });
        }
    
        // =====================
        // PRÉ-COCHER CERTAINS TECHS & RELOAD
        // =====================
        if (preSelectedTechIds.length > 0) {
            preSelectedTechIds.forEach(id => {
                const checkbox = document.getElementById(`tech-${id}`);
                if (checkbox) checkbox.checked = true;
            });
            reloadCalendarEvents();
        }
    
        // =====================
        // BOUTON : SUPPRIMER
        // =====================
        deleteButton.addEventListener('click', () => {
            if (!currentAppointmentId) {
                alert('Impossible de supprimer : ID RDV introuvable.');
                return;
            }
            if (!confirm('Voulez-vous vraiment supprimer ce rendez-vous ?')) {
                return;
            }
    
            fetch("{{ url('/assistant/appointments') }}/" + currentAppointmentId, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            })
            .then(resp => resp.json())
            .then(data => {
                if (data.success) {
                    console.log('[JS] Rendez-vous supprimé avec succès');
                    $('#appointmentModal').modal('hide');
                    calendar.refetchEvents();
                } else {
                    console.error('[JS] Erreur suppression :', data);
                    alert('Erreur lors de la suppression : ' + (data.error || ''));
                }
            })
            .catch(err => {
                console.error('[JS] Erreur fetch delete :', err);
                alert('Erreur lors de la suppression : ' + err);
            });
        });
    
        // =====================
        // BOUTON : ÉDITER
        // =====================
        editButton.addEventListener('click', () => {
            if (!currentAppointmentId) {
                alert('Impossible d\'éditer : ID RDV introuvable.');
                return;
            }

            // Afficher la partie "édition", masquer la partie "lecture seule"
            modalReadonly.classList.add('d-none');
            modalEditFormWrap.classList.remove('d-none');
            modalFooter.style.display = 'none';

            // On pré-remplit avec currentExtendedProps
            editAppId.value          = currentAppointmentId;
            editClientFname.value    = currentExtendedProps.clientFirstName || '';
            editClientLname.value    = currentExtendedProps.clientLastName  || '';
            editClientPhone.value    = currentExtendedProps.clientPhone      || '';
            editClientAdresse.value  = currentExtendedProps.clientAddressStreet || '';
            editClientZip.value      = currentExtendedProps.clientAddressPostalCode || '';
            editClientCity.value     = currentExtendedProps.clientAddressCity || '';
            editComment.value        = currentExtendedProps.comments || '';

            // Technicien / Service (pour les <select>)
            editTechId.value    = currentExtendedProps.techId    || '';
            editServiceId.value = currentExtendedProps.serviceId || '';

            // Date / Heure début / Heure fin / Durée
            editAppointmentDate.value = currentExtendedProps.appointmentDate || '';
            editStartTime.value       = currentExtendedProps.startTime       || '';
            editEndTime.value         = currentExtendedProps.endTime         || '';
            editDuration.value        = currentExtendedProps.duration        || 1;

            // Ensuite, on peut recalculer l'heure de fin si besoin
            recalcEndTime();
        });
    
        // =====================
        // LISTENERS : CHANGER DE SERVICE
        // =====================
        editServiceId.addEventListener('change', () => {
            const selectedOption = editServiceId.selectedOptions[0];
            if (selectedOption && selectedOption.dataset.defaultTime) {
                const defaultTime = parseInt(selectedOption.dataset.defaultTime, 10);
                if (!isNaN(defaultTime)) {
                    editDuration.value = defaultTime;
                    recalcEndTime();
                }
            }
        });
    
        // =====================
        // RECALCUL DE L'HEURE DE FIN
        // =====================
        function recalcEndTime() {
            const startValue = editStartTime.value;
            const durationValue = parseInt(editDuration.value, 10);
    
            if (!startValue || isNaN(durationValue)) {
                editEndTime.value = '';
                return;
            }
            // startValue = "HH:MM"
            const [hh, mm] = startValue.split(':').map(Number);
            let endMinutes = hh * 60 + mm + durationValue;
            // Gérer éventuel dépassement (jour suivant) si tu le souhaites
            let endH = Math.floor(endMinutes / 60) % 24;
            let endM = endMinutes % 60;
    
            // Format "HH:MM"
            editEndTime.value = String(endH).padStart(2, '0') + ':' + String(endM).padStart(2, '0');
        }
        // Chaque fois qu'on modifie l'heure de début ou la durée, on recalcule
        editStartTime.addEventListener('input', recalcEndTime);
        editDuration.addEventListener('input', recalcEndTime);
    
        // =====================
        // BOUTON : ANNULER L'ÉDITION
        // =====================
        cancelEditBtn.addEventListener('click', () => {
            modalEditFormWrap.classList.add('d-none');
            modalReadonly.classList.remove('d-none');
            modalFooter.style.display = 'block';
        });
    
        // =====================
        // SUBMIT ÉDITION
        // =====================
        editAppointmentForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const formData = new FormData(editAppointmentForm);
            console.log('[JS] Submitting editAppointment form data:', formData);
            const id = editAppId.value;

            // Log ce qu'on envoie
            console.log('[JS] Submitting edit for appointment id:', id);
            console.log('[JS] FormData content:');
            for (let [key, value] of formData.entries()) {
                console.log(' -', key, ':', value);
            }

            fetch(`{{ url('/assistant/appointments') }}/${id}`, {
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: formData,
            })
            .then(resp => {
                console.log('[JS] Response status:', resp.status);
                return resp.json();
            })
            .then(data => {
                console.log('[JS] editAppointment response data:', data);

                if (data.success) {
                console.log('[JS] Rendez-vous édité avec succès');
                $('#appointmentModal').modal('hide');
                calendar.refetchEvents();
                } else {
                console.error('[JS] Erreur update :', data);
                alert('Erreur lors de la mise à jour : ' + (data.error || ''));
                }
            })
            .catch(err => {
                console.error('[JS] Erreur fetch update :', err);
                alert('Erreur lors de la mise à jour : ' + err);
            });
        });
        // =====================
        // FERMER LE MODAL
        // =====================
        window.closeAppointmentModal = function() {
            console.log('[JS] closeAppointmentModal()');
            $('#appointmentModal').modal('hide');
        };
    });
</script>
@endsection