@extends('layouts.app')

@section('title', 'Calendrier Comparatif')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Calendrier comparatif</h1>
    <div>
        <a href="#" class="btn btn-sm btn-success shadow-sm ms-2" data-toggle="modal" data-target="#appointmentCreateModal">
            <i class="fas fa-plus fa-sm text-white-50"></i> Créer un RDV manuellement
        </a>
        <a href="{{ route('appointment.index') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-bookmark fa-sm text-white-50"></i> Prendre un RDV
        </a>
    </div>
</div>
@endsection

@section('content')
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
<!-- Affichage des RDV -->
@include('partials.modals.appointmentDetails')
@include('partials.modals.appointmentCreate')
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
        <div class="col-12 my-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title">Sélectionner les techniciens</h5>
                </div>
                <div class="card-body">
                    <input type="text" id="search_tech" class="form-control mb-2" placeholder="Rechercher un technicien...">
                    <!-- Switch pour cocher/décocher tous les techniciens -->
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="toggleAllTechs">
                        <label class="form-check-label" for="toggleAllTechs">Tout sélectionner</label>
                    </div>
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
    let currentStart = null;
    let currentEnd = null;

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
            allDaySlot: false,
            events: [], // Aucun événement au chargement
            // À chaque navigation, on récupère le début et la fin de la vue
            datesSet: function(info) {
                currentStart = info.startStr;
                currentEnd = info.endStr;
                console.log("Nouvelle plage :", currentStart, currentEnd);
                updateCalendar();
            },
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
            },
            dateClick: function(info) {
                let date = new Date(info.dateStr);
                let formattedDate = date.toISOString().slice(0, 16);

                let startAtField = document.getElementById('start_at');
                if (startAtField) startAtField.value = formattedDate;

                let durationSelect = document.getElementById('service_id');
                if (durationSelect) {
                    let selectedOption = durationSelect.options[durationSelect.selectedIndex];
                    let duration = selectedOption ? selectedOption.getAttribute('data-duration') : null;
                    if (duration) {
                        document.getElementById('duration').value = duration;
                    }
                }

                const evt = new Event('change');
                startAtField?.dispatchEvent(evt);

                let modalEl = document.getElementById('appointmentCreateModal');
                if (modalEl) {
                    let modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            }
        });
        calendar.render();
    }

    /**
     * Mise à jour du calendrier.
     * Envoie en AJAX la liste des tech sélectionnés ainsi que la plage de dates (currentStart / currentEnd)
     * pour n'obtenir que les rendez-vous correspondants à la vue actuelle.
     */
    function updateCalendar() {
        let selectedTechs = $('.tech-checkbox:checked').map(function () {
            return $(this).val();
        }).get();

        console.log("🔄 RDV pour techs :", selectedTechs);
        console.log("Plage de dates :", currentStart, currentEnd);

        if (selectedTechs.length === 0) {
            $("#calendar-container").hide();
            if (calendar) {
                calendar.removeAllEvents();
            }
            hideLoadingOverlay();
            return;
        } else {
            $("#calendar-container").show();
        }

        showLoadingOverlay();

        $.ajax({
            url: '/api/calendar',
            type: 'GET',
            data: {
                techs: selectedTechs,
                start: currentStart,
                end: currentEnd
            },
            success: function(response) {
                if (response.success) {
                    console.log("✅ RDV chargés :", response.appointments);
                    calendar.removeAllEvents();
                    response.appointments.forEach(event => {
                        calendar.addEvent(event);
                    });
                } else {
                    console.error("❌ Erreur lors du chargement :", response.message);
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

    /**
     * Gestion du switch "Tout sélectionner".
     */
    $('#toggleAllTechs').on('change', function () {
        let isChecked = $(this).prop('checked');
        $('.tech-checkbox').prop('checked', isChecked);
        setTimeout(function(){
            showLoadingOverlay();
            updateCalendar();
        }, 50);
    });

    /**
     * Gestion de la sélection individuelle.
     */
    $('.tech-checkbox').on('change', function () {
        setTimeout(function(){
            showLoadingOverlay();
            updateCalendar();
        }, 50);
    });

    /**
     * Recherche dynamique dans la liste des techniciens.
     */
    $('#search_tech').on('input', function () {
        let query = $(this).val().toLowerCase().trim();
        $('.tech-checkbox-label').each(function () {
            const name = $(this).text().toLowerCase();
            const show = name.includes(query);
            $(this).closest('.form-check').toggle(show);
        });
    });

    // Initialisation
    initFullCalendar();
    updateCalendar();

    /**
     * Met à jour automatiquement le champ "Se termine à" en fonction de "Débute à" et "Durée"
     */
    function updateEndTimeField() {
        const startInput = document.getElementById('start_at');
        const durationInput = document.getElementById('duration');
        const endInput = document.getElementById('end_at');

        if (!startInput || !durationInput || !endInput) return;

        const startVal = startInput.value;
        const durationVal = parseInt(durationInput.value, 10);

        if (!startVal || isNaN(durationVal)) {
            endInput.value = '';
            return;
        }

        const startDate = new Date(startVal);
        if (isNaN(startDate.getTime())) {
            endInput.value = '';
            return;
        }

        startDate.setMinutes(startDate.getMinutes() + durationVal);

        const endFormatted = startDate.toLocaleDateString('fr-FR') + " à " +
            String(startDate.getHours()).padStart(2, '0') + ":" +
            String(startDate.getMinutes()).padStart(2, '0');

        endInput.value = endFormatted;
    }

    // Lier la mise à jour automatique
    const startAtEl = document.getElementById('start_at');
    const durationEl = document.getElementById('duration');

    if (startAtEl) {
        startAtEl.addEventListener('change', updateEndTimeField);
        startAtEl.addEventListener('input', updateEndTimeField);
    }
    if (durationEl) {
        durationEl.addEventListener('change', updateEndTimeField);
        durationEl.addEventListener('input', updateEndTimeField);
    }

    updateEndTimeField(); // Initialiser au chargement
});
    /**
     * Autocomplétion AJAX pour la recherche de technicien dans le modal de création.
     */
    const techInputModal   = document.getElementById('tech_search_modal');
    const techIdInputModal = document.getElementById('tech_id_modal');
    const suggestionsModal = document.getElementById('techSuggestionsModal');

    if (techInputModal) {
        let timer;
        techInputModal.addEventListener('input', () => {
            const query = techInputModal.value.trim();
            clearTimeout(timer);
            if (!query) {
                suggestionsModal.innerHTML = '';
                techIdInputModal.value = '';
                return;
            }
            timer = setTimeout(() => fetchSuggestions(query), 300);
        });

        document.addEventListener('click', (e) => {
            if (!techInputModal.contains(e.target)) {
                suggestionsModal.innerHTML = '';
            }
        });

        function fetchSuggestions(query) {
            fetch('{{ route('stats.search') }}?q=' + encodeURIComponent(query))
                .then(res => res.json())
                .then(data => renderSuggestions(data))
                .catch(console.error);
        }

        function renderSuggestions(list) {
            if (!list.length) {
                suggestionsModal.innerHTML = '<div class="p-2 text-muted">Aucun résultat</div>';
                return;
            }
            let html = '';
            list.forEach(item => {
                html += `
                    <div class="p-2 suggestion-item" style="cursor:pointer;">
                        ${item.fullname}
                    </div>
                `;
            });
            suggestionsModal.innerHTML = html;

            const items = suggestionsModal.querySelectorAll('.suggestion-item');
            items.forEach((el, index) => {
                el.addEventListener('click', () => {
                    const chosen = list[index];
                    techInputModal.value   = chosen.fullname;
                    techIdInputModal.value = chosen.id;
                    suggestionsModal.innerHTML = '';
                });
            });
        }
    }
</script>
@endsection
