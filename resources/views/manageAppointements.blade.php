@extends('layouts.app')

@section('title', 'Gestion des rendez-vous')

@section('css')
    <link href="{{ asset('css/manageAppointment.css') }}" rel="stylesheet">
@endsection

@section('topbarSearch')
<form id="searchForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
    <div class="input-group">
        <input type="text" id="searchInput" class="form-control bg-light border-0 small" placeholder="Rechercher par nom de client ou technicien..."
            aria-label="Search" aria-describedby="search-addon">
    </div>
</form>
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Gérer les services</h1>
    <div class="button-group">
        <!-- Bouton "Voir le calendrier" -->
        <a href="{{--{{ route('calendar.view') }}--}}#" class="btn btn-sm btn-info shadow-sm">
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
// Ajouter les interactions AJAX similaires aux vues précédentes (services)
$(document).ready(function () {
    // Gestion de la fermeture des modals via les boutons "data-bs-dismiss"
    $('button[data-bs-dismiss="modal"]').on('click', function () {
        const modal = $(this).closest('.modal');
        if (modal.length) {
            console.log('Fermeture du modal:', modal.attr('id'));
            modal.modal('hide');
        }
    });

    // Filtrage dynamique des techniciens
    $('#tech_search').on('input', function () {
        const query = $(this).val().toLowerCase();
        $('#tech_id option').each(function () {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(query));
        });
    });

    // Mise à jour automatique de la durée en fonction du service sélectionné
    $('#service_id').on('change', function () {
        const duration = $(this).find(':selected').data('duration');
        $('#duration').val(duration || '');
        calculateEndTime();
    });

    // Calcul automatique de l'heure de fin
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

    // Met à jour la durée lorsqu'un service est sélectionné
    $('#service_id').on('change', function () {
        const selectedService = $(this).find(':selected');
        const duration = selectedService.data('duration');
        $('#duration').val(duration || '');
        calculateEndTime();
    });

    // Calcul automatique de l'heure de fin
    $('#start_at, #duration').on('input change', function () {
        calculateEndTime();
    });

    function calculateEndTime() {
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

    // Pré-remplir la durée par défaut du premier service au chargement
    $('#service_id').trigger('change');

    $('#searchInput').on('input', function () {
        performSearch(1);
    });

    $(document).on('click', '.pagination a', function (e) {
        e.preventDefault();
        const page = $(this).attr('href').split('page=')[1];
        performSearch(page);
    });

    function performSearch(page = 1) {
        const query = $('#searchInput').val();

        showLoadingOverlay();

        $.ajax({
            url: '{{ route("manage-appointments.search") }}',
            type: 'GET',
            data: { query: query, page: page },
            success: function (data) {
                const tbody = $('table tbody');
                tbody.empty();

                if (data.appointments.length === 0) {
                    tbody.append('<tr><td colspan="5" class="text-center">Aucun rendez-vous trouvé</td></tr>');
                } else {
                    data.appointments.forEach(appointment => {
                        const isTrashed = appointment.deleted_at !== null;

                        const row = `
                            <tr class="${isTrashed ? 'table-warning' : ''}">
                                <td>
                                    <strong>${appointment.tech?.user?.nom?.toUpperCase() || 'Non attribué'}</strong> ${appointment.tech?.user?.prenom || ''}
                                    <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#reassignTechModal" data-id="${appointment.id}">
                                        Réattribuer
                                    </button>
                                </td>
                                <td>${appointment.service?.type || 'N/A'} - ${appointment.service?.name || 'Aucun service'}</td>
                                <td>
                                    <strong>${appointment.client_fname} ${appointment.client_lname}</strong>
                                    <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#viewClientModal" data-id="${appointment.id}">
                                        Voir
                                    </button>
                                </td>
                                <td>
                                    Le ${appointment.start_at_formatted.date} du ${appointment.start_at_formatted.time} au ${appointment.end_at_formatted.time}
                                </td>
                                <td>
                                    <div class="btn-group">
                                        ${isTrashed ? `
                                            <button class="btn btn-sm btn-success" data-id="${appointment.id}" data-toggle="modal" data-target="#appointmentRestoreModal">Restaurer</button>
                                            <button class="btn btn-sm btn-danger" data-id="${appointment.id}" data-toggle="modal" data-target="#appointmentHardDeleteModal">Supprimer définitivement</button>
                                        ` : `
                                            <button class="btn btn-sm btn-primary" data-id="${appointment.id}" data-toggle="modal" data-target="#appointmentEditModal">Modifier</button>
                                            <button class="btn btn-sm btn-danger" data-id="${appointment.id}" data-toggle="modal" data-target="#appointmentDeleteModal">Mettre en attente</button>
                                        `}
                                    </div>
                                </td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                }

                // Mettre à jour la pagination
                $('.pagination-container').html(data.pagination);
            },
            error: function (xhr) {
                console.error('Erreur lors de la recherche :', xhr.responseText);
            },
            complete: function () {
                hideLoadingOverlay(); // Cacher le chargement
            }
        });
    }

    $('#reassignTechModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui a ouvert le modal
        const appointmentId = button.data('id'); // Récupère l'ID du rendez-vous
        const currentTech = button.data('tech'); // Récupère le technicien actuel

        // Remplit les champs du modal
        $('#reassignTechForm').data('id', appointmentId); // Associe l'ID au formulaire
        $('#appointment-id').val(appointmentId); // Associe l'ID caché si nécessaire
        $('#current-tech').text(currentTech || 'Non attribué'); // Affiche le technicien actuel
    });

    // Recherche dynamique dans la liste des techniciens
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

        const appointmentId = $(this).data('id'); // Récupère l'ID du rendez-vous depuis le formulaire
        const techId = $('#new-tech-id').val(); // Récupère l'ID du technicien sélectionné

        console.log('Submitting reassign tech form');
        console.log('Appointment ID:', appointmentId);
        console.log('New Tech ID:', techId);

        showLoadingOverlay();

        $.ajax({
            url: `/manage-appointments/${appointmentId}/reassign-tech`,
            type: 'POST',
            data: {
                tech_id: techId,
                _method: 'PUT', // Simule une requête PUT
                _token: '{{ csrf_token() }}',
            },
            success: function () {
                // Recharge la page pour afficher le message de succès/erreur
                location.reload();
            },
            error: function (xhr) {
                console.error('Error reassigning tech:', xhr.responseText);

                // Ferme le modal, mais la page ne se recharge pas
                $('#reassignTechModal').modal('hide');

                // Affiche un message d'erreur temporaire
                const errorMessage = `
                    <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                        Une erreur est survenue lors de la réattribution.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                $('#pageHeading').after(errorMessage);
            },
        });
    });

    // Affichage des détails du client
    $('#viewClientModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui a ouvert le modal
        const appointmentId = button.data('id'); // ID du rendez-vous

        showLoadingOverlay();

        // Vide les champs pour éviter d'afficher d'anciennes données
        $('#client-fname, #client-lname, #client-adresse, #client-zip-code, #client-city, #client-phone').text('Chargement...');

        // Requête AJAX pour récupérer les détails du client
        $.ajax({
            url: `/manage-appointments/${appointmentId}/view-client`,
            type: 'GET',
            success: function (response) {
                if (response.success) {
                    // Met à jour les informations dans le modal
                    $('#client-fname').text(response.data.fname);
                    $('#client-lname').text(response.data.lname);
                    $('#client-adresse').text(response.data.adresse);
                    $('#client-zip-code').text(response.data.zip_code);
                    $('#client-city').text(response.data.city);
                    $('#client-phone').text(response.data.phone);
                } else {
                    console.error('Erreur:', response.message);
                    $('#viewClientModal .modal-body').html('<p class="text-danger">Impossible de charger les détails du client.</p>');
                }
            },
            error: function (xhr) {
                console.error('Erreur lors du chargement des détails client:', xhr.responseText);
                $('#viewClientModal .modal-body').html('<p class="text-danger">Une erreur est survenue.</p>');
            },
            complete: function () {
                hideLoadingOverlay(); // Cacher le chargement
            },
        });
    });

    $('#appointmentEditModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui a ouvert le modal
        const appointmentId = button.data('id'); // ID du rendez-vous

        showLoadingOverlay();

        // Ajoutez dynamiquement l'URL au formulaire
        $('#editAppointmentForm').attr('action', `/manage-appointments/${appointmentId}`);
        // Réinitialiser les champs
        $('#editAppointmentForm')[0].reset();

        // Requête AJAX pour récupérer les données
        $.ajax({
            url: `/manage-appointments/${appointmentId}/edit`,
            type: 'GET',
            success: function (response) {
                if (response.success) {
                    const data = response.data;

                    // Remplit les champs avec les données existantes
                    $('#edit-service_id').val(data.service_id);
                    $('#edit-client_fname').val(data.client_fname);
                    $('#edit-client_lname').val(data.client_lname);
                    $('#edit-client_adresse').val(data.client_adresse);
                    $('#edit-client_zip_code').val(data.client_zip_code);
                    $('#edit-client_city').val(data.client_city);
                    $('#edit-start_at').val(data.start_at);
                    $('#edit-duration').val(data.duration);
                    $('#edit-comment').val(data.comment || '');

                    // Calculer et afficher l'heure de fin
                    calculateEndTime();
                } else {
                    console.error('Erreur:', response.message);
                }
            },
            error: function (xhr) {
                console.error('Erreur lors du chargement des détails:', xhr.responseText);
            },
            complete: function () {
                hideLoadingOverlay(); // Cacher le chargement
            },
        });
    });

    // Calcul automatique de l'heure de fin
    $('#edit-start_at, #edit-duration').on('input change', function () {
        calculateEndTime();
    });

    function calculateEndTime() {
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

    // Mettre à jour la durée lorsqu'un service est sélectionné
    $('#edit-service_id').on('change', function () {
        const selectedService = $(this).find(':selected'); // Trouve l'option sélectionnée
        const duration = selectedService.data('duration'); // Récupère la durée par défaut du service
        $('#edit-duration').val(duration || ''); // Met à jour le champ durée
        calculateEndTime(); // Recalcule l'heure de fin
    });

    // Soumission du formulaire de modification
    $('#appointmentDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui a ouvert le modal
        const appointmentId = button.data('id'); // ID du rendez-vous

        // Met à jour l'attribut action du formulaire pour inclure l'ID du rendez-vous
        $('#deleteAppointmentForm').attr('action', `/manage-appointments/${appointmentId}`);
    });

    // Soumission du formulaire de restauration
    $('#appointmentRestoreModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui a ouvert le modal
        const appointmentId = button.data('id'); // ID du rendez-vous

        // Met à jour l'attribut action du formulaire pour inclure l'ID du rendez-vous
        $('#restoreAppointmentForm').attr('action', `/manage-appointments/${appointmentId}/restore`);
    });

    // Soumission du formulaire de suppression définitive
    $('#appointmentHardDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui a ouvert le modal
        const appointmentId = button.data('id'); // ID du rendez-vous

        // Met à jour l'action du formulaire pour inclure l'ID
        $('#hardDeleteAppointmentForm').attr('action', `/manage-appointments/${appointmentId}/hard-delete`);
    });
});
</script>
@endsection
