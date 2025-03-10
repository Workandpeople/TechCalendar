@extends('layouts.app')

@section('title', 'Gestion des rendez-vous')

@section('css')
    <link href="{{ asset('css/manageAppointment.css') }}" rel="stylesheet">
@endsection

@section('topbarSearch')
<form id="searchTechForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
    <div class="input-group">
        <input type="text" id="searchTechInput" class="form-control bg-light border-0 small"
            placeholder="Par technicien..." aria-label="Search Tech">
    </div>
</form>

<form id="searchForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
    <div class="input-group">
        <input type="text" id="searchInput" class="form-control bg-light border-0 small"
            placeholder="Par client..." aria-label="Search">
    </div>
</form>

<form id="searchDepartmentForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
    <div class="input-group">
        <input type="text" id="searchDepartmentInput" class="form-control bg-light border-0 small"
            placeholder="Par département (ex: 75)..." aria-label="Search Department" maxlength="2">
    </div>
</form>
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Gérer les RDV</h1>
    <div class="button-group">
        <!-- Bouton "Voir le calendrier" -->
        <a href="{{ route('calendar.index') }}#" class="btn btn-sm btn-info shadow-sm">
            <i class="fas fa-calendar-alt fa-sm text-white-50"></i> Voir le calendrier
        </a>

        <!-- Bouton "Créer un RDV" -->
        <a href="{{ route('appointment.index') }}" class="btn btn-sm btn-success shadow-sm">
            <i class="fas fa-plus fa-sm text-white-50"></i> Créer un RDV
        </a>
    </div>
</div>

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
@endsection

@section('content')
    @include('partials.tables.appointmentTable')
    @include('partials.modals.appointmentCreate')
    @include('partials.modals.appointmentEdit')
    @include('partials.modals.appointmentDelete')
    @include('partials.modals.appointmentRestore')
    @include('partials.modals.appointmentHardDelete')
    @include('partials.modals.reassignTech')
    @include('partials.modals.viewClient')
@endsection

@section('js')
<script>
$(document).ready(function () {
    const techSearchInput = document.getElementById('tech-search');
    const newTechIdHidden = document.getElementById('new-tech-id-hidden');
    const suggestions = document.getElementById('reassignTechSuggestions');
    let timer;

    techSearchInput.addEventListener('input', () => {
        const query = techSearchInput.value.trim();
        clearTimeout(timer);
        if (!query) {
            suggestions.innerHTML = '';
            newTechIdHidden.value = '';
            return;
        }
        timer = setTimeout(() => {
            fetch('{{ route('stats.search') }}?q=' + encodeURIComponent(query))
                .then(res => res.json())
                .then(data => renderSuggestions(data))
                .catch(console.error);
        }, 300);
    });

    // Clic en dehors => fermeture des suggestions
    document.addEventListener('click', (e) => {
        if (!techSearchInput.contains(e.target)) {
            suggestions.innerHTML = '';
        }
    });

    function renderSuggestions(list) {
        if (!list.length) {
            suggestions.innerHTML = '<div class="p-2 text-muted">Aucun résultat</div>';
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
        suggestions.innerHTML = html;

        // Au clic sur une suggestion, on remplit le champ de recherche et le champ caché
        const items = suggestions.querySelectorAll('.suggestion-item');
        items.forEach((el, index) => {
            el.addEventListener('click', () => {
                const chosen = list[index];
                techSearchInput.value = chosen.fullname;
                newTechIdHidden.value = chosen.id;
                suggestions.innerHTML = '';
            });
        });
    }

    // Déclaration initiale (les valeurs par défaut proviennent de la requête)
    let currentSort = '{{ request("sort", "start_at") }}';
    let currentDirection = '{{ request("direction", "asc") }}';

    // Gestion du clic sur un en-tête triable
    $(document).on('click', '.sortable', function(e) {
        e.preventDefault();
        const clickedSort = $(this).data('sort');
        if (currentSort === clickedSort) {
            currentDirection = (currentDirection === 'asc') ? 'desc' : 'asc';
        } else {
            currentSort = clickedSort;
            currentDirection = 'asc';
        }
        performSearch(1);
    });

    // === FERMETURE DES MODALS VIA data-bs-dismiss ===
    $('button[data-bs-dismiss="modal"]').on('click', function () {
        const modal = $(this).closest('.modal');
        if (modal.length) {
            console.log('Fermeture du modal:', modal.attr('id'));
            modal.modal('hide');
        }
    });

    // === FILTRE DYNAMIQUE DANS LE FORMULAIRE DE CREATION D'UN RDV (tech) ===
    $('#tech_search').on('input', function () {
        const query = $(this).val().toLowerCase();
        $('#tech_id option').each(function () {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(query));
        });
    });

    // === MISE À JOUR AUTOMATIQUE DE LA DURÉE EN FONCTION DU SERVICE ===
    $('#service_id').on('change', function () {
        const duration = $(this).find(':selected').data('duration');
        $('#duration').val(duration || '');
        calculateEndTime();
    });

    // === CALCUL AUTOMATIQUE DE L'HEURE DE FIN ===
    $('#start_at, #duration').on('input change', function () {
        calculateEndTime();
    });

    function calculateEndTime() {
        const startAt = $('#start_at').val();
        const duration = parseInt($('#duration').val());

        if (startAt && duration) {
            const endTime = new Date(new Date(startAt).getTime() + duration * 60000);
            const formattedEndTime = endTime.toISOString().slice(0, 16).replace('T', ' à ');
            $('#end_at').val(formattedEndTime);
        } else {
            $('#end_at').val('');
        }
    }

    // Au chargement, on pré-remplit la durée par défaut du premier service
    $('#service_id').trigger('change');

    // CALCUL AUTOMATIQUE DE L'HEURE DE FIN (FORMULAIRE #2)
    $('#start_at, #duration').on('input change', function () {
        calculateEndTime2();
    });

    function calculateEndTime2() {
        const startAt = $('#start_at').val();
        const duration = parseInt($('#duration').val());
        if (startAt && duration) {
            const endTime = new Date(new Date(startAt).getTime() + duration * 60000);
            const formattedEndTime = endTime.toISOString().slice(0, 16).replace('T', ' ');
            $('#end_at').val(formattedEndTime);
        } else {
            $('#end_at').val('');
        }
    }

    // === RECHERCHE PAR CLIENT / TECH / DEPARTEMENT (AJAX) ===
    $('#searchInput, #searchTechInput, #searchDepartmentInput').on('input', function () {
        performSearch(1);
    });

    // === PAGINATION (clique sur la pagination) ===
    $(document).on('click', '.pagination a', function (e) {
        e.preventDefault();
        const page = $(this).attr('href').split('page=')[1];
        performSearch(page);
    });

    // === FONCTION AJAX DE RECHERCHE + FILTRES ===
    function performSearch(page = 1) {
        const query = $('#searchInput').val();
        const techSearch = $('#searchTechInput').val();
        const department = $('#searchDepartmentInput').val();

        console.log('Recherche avec :', { query, techSearch, department, sort: currentSort, direction: currentDirection });

        $.ajax({
            url: '{{ route("manage-appointments.search") }}',
            type: 'GET',
            data: {
                query: query,
                tech_search: techSearch,
                department: department,
                page: page,
                sort: currentSort,
                direction: currentDirection
            },
            success: function (data) {
                const tbody = $('table tbody');
                tbody.empty();
                if (data.appointments.length === 0) {
                    tbody.append('<tr><td colspan="5" class="text-center">Aucun rendez-vous trouvé</td></tr>');
                } else {
                    data.appointments.forEach(appointment => {
                        const isTrashed = appointment.deleted_at !== null;
                        const departmentTech = appointment.tech?.zip_code ? appointment.tech.zip_code.substring(0,2) : 'N/A';
                        const departmentClient = appointment.client_zip_code ? appointment.client_zip_code.substring(0,2) : 'N/A';
                        const row = `
                            <tr class="${isTrashed ? 'table-warning' : ''}">
                                <td>
                                    <strong>${appointment.tech?.user?.nom?.toUpperCase() || 'Non attribué'}</strong>
                                    ${appointment.tech?.user?.prenom || ''} (${departmentTech})
                                </td>
                                <td>${appointment.service?.type || 'N/A'} - ${appointment.service?.name || 'Aucun service'}</td>
                                <td>
                                    <strong>${appointment.client_fname} ${appointment.client_lname}</strong>
                                    (${departmentClient})
                                </td>
                                <td>
                                    Le ${appointment.start_at_formatted.date}
                                    du ${appointment.start_at_formatted.time}
                                    au ${appointment.end_at_formatted.time}
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#appointmentEditModal" data-id="${appointment.id}">Modifier</button>
                                        <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#appointmentDeleteModal" data-id="${appointment.id}">Mettre en attente</button>
                                    </div>
                                </td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                }
                $('.pagination-container').html(data.pagination);
            },
            error: function (xhr) {
                console.error('Erreur lors de la recherche :', xhr.responseText);
            }
        });
    }

    // === RÉATTRIBUTION D'UN TECH ===
    $('#reassignTechModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const appointmentId = button.data('id');
        const currentTech   = button.data('tech');

        $('#reassignTechForm').data('id', appointmentId);
        $('#appointment-id').val(appointmentId);
        $('#current-tech').text(currentTech || 'Non attribué');
    });

    // Filtre dynamique sur la liste des tech (dans le modal Reassign)
    $('#tech-search').on('input', function () {
        const query = $(this).val().toLowerCase();
        $('#new-tech-id option').each(function () {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(query));
        });
    });

    // Soumission du formulaire de réattribution
    $('#reassignTechForm').on('submit', function (e) {
        e.preventDefault();

        const appointmentId = $(this).data('id');
        const techId = $('#new-tech-id-hidden').val();

        console.log('Submitting reassign tech form', { appointmentId, techId });
        showLoadingOverlay();

        $.ajax({
            url: `/manage-appointments/${appointmentId}/reassign-tech`,
            type: 'POST',
            data: {
                tech_id: techId,
                _method: 'PUT',
                _token: '{{ csrf_token() }}',
            },
            success: function () {
                window.location.href = '{{ route("manage-appointments.index") }}';
            },
            error: function (xhr) {
                console.error('Error reassigning tech:', xhr.responseText);
                $('#reassignTechModal').modal('hide');
                window.location.href = '{{ route("manage-appointments.index") }}';
            },
        });
    });

    // === AFFICHAGE DES DÉTAILS DU CLIENT ===
    $('#viewClientModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const appointmentId = button.data('id');

        showLoadingOverlay();

        // Nettoyer le modal avant de le remplir
        $('#client-fname, #client-lname, #client-adresse, #client-zip-code, #client-city, #client-phone').text('Chargement...');

        $.ajax({
            url: `/manage-appointments/${appointmentId}/view-client`,
            type: 'GET',
            success: function (response) {
                if (response.success) {
                    $('#client-fname').text(response.data.fname);
                    $('#client-lname').text(response.data.lname);
                    $('#client-adresse').text(response.data.adresse);
                    $('#client-zip-code').text(response.data.zip_code);
                    $('#client-city').text(response.data.city);
                    $('#client-phone').text(response.data.phone);
                } else {
                    console.error('Erreur:', response.message);
                    $('#viewClientModal .modal-body')
                        .html('<p class="text-danger">Impossible de charger les détails du client.</p>');
                }
            },
            error: function (xhr) {
                console.error('Erreur lors du chargement des détails client:', xhr.responseText);
                $('#viewClientModal .modal-body')
                    .html('<p class="text-danger">Une erreur est survenue.</p>');
            },
            complete: function () {
                hideLoadingOverlay();
            },
        });
    });

    // === ÉDITION D'UN RDV (MODAL) ===
    $('#appointmentEditModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const appointmentId = button.data('id');

        showLoadingOverlay();
        $('#editAppointmentForm').attr('action', `/manage-appointments/${appointmentId}`);
        $('#editAppointmentForm')[0].reset();

        $.ajax({
            url: `/manage-appointments/${appointmentId}/edit`,
            type: 'GET',
            success: function (response) {
                if (response.success) {
                    const data = response.data;
                    $('#edit-service_id').val(data.service_id);
                    $('#edit-client_fname').val(data.client_fname);
                    $('#edit-client_lname').val(data.client_lname);
                    $('#edit-client_adresse').val(data.client_adresse);
                    $('#edit-client_zip_code').val(data.client_zip_code);
                    $('#edit-client_city').val(data.client_city);
                    $('#edit-start_at').val(data.start_at);
                    $('#edit-duration').val(data.duration);
                    $('#edit-comment').val(data.comment || '');
                    calculateEndTimeEdit();
                } else {
                    console.error('Erreur:', response.message);
                }
            },
            error: function (xhr) {
                console.error('Erreur lors du chargement des détails:', xhr.responseText);
            },
            complete: function () {
                hideLoadingOverlay();
            },
        });
    });

    // Calcul de l'heure de fin (modal Edit)
    $('#edit-start_at, #edit-duration').on('input change', function () {
        calculateEndTimeEdit();
    });

    function calculateEndTimeEdit() {
        const startAt = $('#edit-start_at').val();
        const duration = parseInt($('#edit-duration').val());
        if (startAt && duration) {
            const endTime = new Date(new Date(startAt).getTime() + duration * 60000);
            const formattedEndTime = endTime.toISOString().slice(0, 16).replace('T', ' ');
            $('#edit-end_at').val(formattedEndTime);
        } else {
            $('#edit-end_at').val('');
        }
    }

    // Service change => met à jour la durée par défaut (modal Edit)
    $('#edit-service_id').on('change', function () {
        const selectedService = $(this).find(':selected');
        const duration = selectedService.data('duration');
        $('#edit-duration').val(duration || '');
        calculateEndTimeEdit();
    });

    // === DELETE RDV (METTRE EN ATTENTE) ===
    $('#appointmentDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const appointmentId = button.data('id');
        $('#deleteAppointmentForm').attr('action', `/manage-appointments/${appointmentId}`);
    });

    // === RESTORE RDV ===
    $('#appointmentRestoreModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const appointmentId = button.data('id');
        $('#restoreAppointmentForm').attr('action', `/manage-appointments/${appointmentId}/restore`);
    });

    // === HARD DELETE RDV ===
    $('#appointmentHardDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const appointmentId = button.data('id');
        $('#hardDeleteAppointmentForm').attr('action', `/manage-appointments/${appointmentId}/hard-delete`);
    });
});
</script>
@endsection
