@extends('layouts.app')

@section('title', 'Gestion des services')

@section('css')
    <link href="{{ asset('css/manageProvider.css') }}" rel="stylesheet">
@endsection

@section('topbarSearch')
<form id="searchForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
    <div class="input-group">
        <input type="text" id="searchInput" class="form-control bg-light border-0 small" placeholder="Rechercher un service..."
            aria-label="Search" aria-describedby="search-addon">
    </div>
</form>
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Gérer les services</h1>
    <a href="#" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#serviceCreateModal">
        <i class="fas fa-plus fa-sm text-white-50"></i> Créer un service
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    <ul class="mb-0">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif
@endsection

@section('content')
    @include('partials.tables.servicesTable')
    @include('partials.modals.serviceCreate')
    @include('partials.modals.serviceEdit')
    @include('partials.modals.serviceDelete')
    @include('partials.modals.serviceRestore')
    @include('partials.modals.serviceHardDelete')
@endsection

@section('js')
<script>
// JS similaire à celui pour les utilisateurs, adapté pour "services"
$(document).ready(function () {
    $('#searchInput').on('input', function () {
        const query = $(this).val();
        showLoadingOverlay();

        $.ajax({
            url: '{{ route('manage-providers.search') }}',
            type: 'GET',
            data: { query: query },
            success: function (data) {
                const tbody = $('table tbody');
                tbody.empty();

                if (data.data.length === 0) {
                    tbody.append('<tr><td colspan="4" class="text-center">Aucun service trouvé</td></tr>');
                } else {
                    data.data.forEach(service => {
                        const isTrashed = service.deleted_at !== null;

                        const serviceRow = `
                            <tr class="${isTrashed ? 'table-danger' : ''}">
                                <td>${service.type}</td>
                                <td>${service.name}</td>
                                <td>${service.default_time} min</td>
                                <td>
                                    <div class="btn-group">
                                        ${isTrashed ? `
                                            <button class="btn btn-sm btn-success" data-id="${service.id}" data-toggle="modal" data-target="#serviceRestoreModal">Restaurer</button>
                                            <button class="btn btn-sm btn-danger" data-id="${service.id}" data-toggle="modal" data-target="#serviceHardDeleteModal">Supprimer définitivement</button>
                                        ` : `
                                            <button class="btn btn-sm btn-primary" data-id="${service.id}" data-toggle="modal" data-target="#serviceEditModal">Modifier</button>
                                            <button class="btn btn-sm btn-danger" data-id="${service.id}" data-toggle="modal" data-target="#serviceDeleteModal">Supprimer</button>
                                        `}
                                    </div>
                                </td>
                            </tr>
                        `;
                        tbody.append(serviceRow);
                    });
                }
            },
            error: function (xhr) {
                console.error('Erreur lors de la recherche :', xhr.responseText);
            },
            complete: function () {
                hideLoadingOverlay(); // Cacher le chargement
            }
        });
    });

    // Gestion de la fermeture des modals via les boutons "data-bs-dismiss"
    $('button[data-bs-dismiss="modal"]').on('click', function () {
        const modal = $(this).closest('.modal');
        if (modal.length) {
            console.log('Fermeture du modal:', modal.attr('id'));
            modal.modal('hide');
        }
    });

    // Charger les données dans la modale d'édition
    $('#serviceEditModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui déclenche l'ouverture
        const serviceId = button.data('id'); // Récupérer l'ID du service

        console.log('Ouverture du modal pour modifier le service ID:', serviceId);
        showLoadingOverlay();

        // Effectuer une requête AJAX pour récupérer les données du service
        $.ajax({
            url: `/manage-providers/${serviceId}/edit`,
            type: 'GET',
            success: function (data) {
                console.log('Données récupérées pour modification :', data);

                // Pré-remplir les champs du formulaire
                $('#edit-type').val(data.type);
                $('#edit-name').val(data.name);
                $('#edit-default_time').val(data.default_time);

                // Mettre à jour l'action du formulaire
                $('#editServiceForm').attr('action', `/manage-providers/${data.id}`);
            },
            complete: function () {
                hideLoadingOverlay(); // Cacher le chargement
            }
        });
    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#serviceEditModal').on('hidden.bs.modal', function () {
        $('#editServiceForm').attr('action', '');
        $('#edit-type').val('');
        $('#edit-name').val('');
        $('#edit-default_time').val('');
    });

    // Charger les données dans la modale de restauration
    $('#serviceDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui déclenche le modal
        const serviceId = button.data('id'); // Récupérer l'ID du service

        console.log('Ouverture du modal pour supprimer le service ID:', serviceId);

        // Mettre à jour l'action du formulaire avec l'URL correcte
        const formAction = '{{ route('manage-providers.destroy', ':id') }}'.replace(':id', serviceId);
        $('#deleteServiceForm').attr('action', formAction);

    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#serviceDeleteModal').on('hidden.bs.modal', function () {
        $('#deleteServiceForm').attr('action', '');
    });

    // Gestion de l'ouverture du modal de restauration
    $('#serviceRestoreModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui déclenche le modal
        const serviceId = button.data('id'); // ID du service

        console.log('Ouverture du modal pour restaurer le service ID:', serviceId);

        // Mettre à jour l'action du formulaire avec l'URL correcte
        const formAction = '{{ route('manage-providers.restore', ':id') }}'.replace(':id', serviceId);
        $('#restoreServiceForm').attr('action', formAction);

    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#serviceRestoreModal').on('hidden.bs.modal', function () {
        $('#restoreServiceForm').attr('action', '');
    });

    // Gestion de l'ouverture du modal de suppression définitive
    $('#serviceHardDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui déclenche l'ouverture du modal
        const serviceId = button.data('id'); // Récupérer l'ID du service

        console.log('Ouverture du modal de suppression définitive pour le service ID:', serviceId);

        // Modifier dynamiquement l'action du formulaire
        const formAction = '{{ route("manage-providers.hard-delete", ":id") }}'.replace(':id', serviceId);
        $('#hardDeleteServiceForm').attr('action', formAction);
    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#serviceHardDeleteModal').on('hidden.bs.modal', function () {
        $('#hardDeleteServiceForm').attr('action', '');
    });
});
</script>
@endsection
