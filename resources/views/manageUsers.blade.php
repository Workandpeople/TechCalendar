@extends('layouts.app')

@section('title', 'Gestion des utilisateurs')

@section('css')
    <link href="{{ asset('css/manageUser.css') }}" rel="stylesheet">
@endsection

@section('topbarSearch')
    <form id="searchForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
        <div class="input-group">
            <input type="text" id="searchInput" class="form-control bg-light border-0 small" placeholder="Par nom ou prénom..."
                aria-label="Search" aria-describedby="search-addon">
        </div>
    </form>
    <!-- Nouveau champ de recherche par département -->
    <form id="searchDepartmentForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
        <div class="input-group">
            <input type="text" id="searchDepartmentInput" class="form-control bg-light border-0 small" placeholder="Par département..."
                aria-label="Search Department" aria-describedby="search-department-addon" maxlength="2">
        </div>
    </form>
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Gérer les utilisateurs</h1>
    <a href="" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#userCreateModal">
        <i class="fas fa-plus fa-sm text-white-50"></i> Créer un utilisateur
    </a>
</div>

<!-- Affichage des messages de succès -->
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    {{ session('success') }}
</div>
@endif

<!-- Affichage des erreurs -->
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
    @include('partials.tables.usersTable')
    @include('partials.modals.userEditPassword')
    @include('partials.modals.userEdit')
    @include('partials.modals.userDelete')
    @include('partials.modals.userCreate')
    @include('partials.modals.userRestore')
    @include('partials.modals.userHardDelete')
@endsection

@section('js')
<script>
$(document).ready(function () {
    // Initialisation valeurs par défaut pour le modal de création
    $('#create_start_hour').val('08');
    $('#create_start_minute').val('30');
    $('#create_end_hour').val('17');
    $('#create_end_minute').val('30');
    updateTimeHiddenFields('create');
    bindTimeSelectors('create');

    // Fonction de mise à jour du champ hidden (create/edit)
    function updateTimeHiddenFields(context) {
        const sh = $(`#${context}_start_hour`).val();
        const sm = $(`#${context}_start_minute`).val();
        const eh = $(`#${context}_end_hour`).val();
        const em = $(`#${context}_end_minute`).val();

        $(`#${context}_default_start_at`).val(`${sh}:${sm}`);
        $(`#${context}_default_end_at`).val(`${eh}:${em}`);
    }

    // Ajout listeners aux selects
    function bindTimeSelectors(context) {
        $(`#${context}_start_hour, #${context}_start_minute`).on('change', () => updateTimeHiddenFields(context));
        $(`#${context}_end_hour, #${context}_end_minute`).on('change', () => updateTimeHiddenFields(context));
    }

    // Ouverture du modal d'édition
    $('#userEditModal').on('shown.bs.modal', function () {
        bindTimeSelectors('edit');

        const startVal = $('#edit_default_start_at').val() || '08:30';
        const endVal = $('#edit_default_end_at').val() || '17:30';

        const [sh, sm] = startVal.split(':');
        const [eh, em] = endVal.split(':');

        $('#edit_start_hour').val(sh);
        $('#edit_start_minute').val(sm);
        $('#edit_end_hour').val(eh);
        $('#edit_end_minute').val(em);

        updateTimeHiddenFields('edit');
    });

    // Mise à jour avant soumission du formulaire de création
    $('form[action="{{ route('manage-users.store') }}"]').on('submit', function () {
        updateTimeHiddenFields('create');
    });

    $('#role').on('change', function() {
        if ($(this).val() === 'tech') {
            $('#tech-fields').removeClass('d-none');
        } else {
            $('#tech-fields').addClass('d-none');
        }
    });

    $('#searchInput, #searchDepartmentInput').on('input', function () {
        const query = $('#searchInput').val();
        const department = $('#searchDepartmentInput').val();

        $.ajax({
            url: '{{ route('manage-users.search') }}',
            type: 'GET',
            data: { query: query, department: department },
            success: function (data) {
                const tbody = $('table tbody');
                tbody.empty();

                if (data.data.length === 0) {
                    tbody.append('<tr><td colspan="5" class="text-center">Aucun utilisateur trouvé</td></tr>');
                } else {
                    data.data.forEach(user => {
                        const isTrashed = user.deleted_at !== null;
                        const department = user.department ? `(${user.department})` : '';

                        const userRow = `
                            <tr class="${isTrashed ? 'table-danger' : ''}">
                                <td><strong>${user.nom.toUpperCase()}</strong> ${user.prenom} ${department}</td>
                                <td class="d-none d-md-table-cell">${user.email}</td>
                                <td>
                                    ${isTrashed ? '' : `
                                        <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#userEditPasswordModal" data-id="${user.id}">
                                            Modifier
                                        </button>
                                    `}
                                </td>
                                <td class="d-none d-md-table-cell">${user.role}</td>
                                <td>
                                    <div class="btn-group">
                                        ${isTrashed ? `
                                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#userRestoreModal" data-id="${user.id}">Restaurer</button>
                                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#userHardDeleteModal" data-id="${user.id}">Supprimer définitivement</button>
                                        ` : `
                                            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#userEditModal" data-id="${user.id}">Modifier</button>
                                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#userDeleteModal" data-id="${user.id}">Désactiver</button>
                                        `}
                                    </div>
                                </td>
                            </tr>
                        `;
                        tbody.append(userRow);
                    });
                }
            },
            error: function (xhr) {
                console.error('Erreur lors de la recherche :', xhr.responseText);
            }
        });
    });

    // Fonction pour charger les données utilisateur dans le modal d'édition
    function loadUserData(userId) {
        console.log('Chargement des données pour l\'utilisateur ID:', userId);

        $.ajax({
            url: '/manage-users/' + userId + '/edit',
            type: 'GET',
            success: function (data) {
                console.log('Données utilisateur récupérées avec succès:', data);

                // Remplir les champs du formulaire
                $('#edit-id').val(data.id);
                $('#edit-nom').val(data.nom);
                $('#edit-prenom').val(data.prenom);
                $('#edit-email').val(data.email);
                $('#edit-role').val(data.role);

                // Gérer les champs spécifiques pour les techniciens
                if (data.role === 'tech' && data.tech) {
                    console.log('Utilisateur est un technicien. Affichage des champs spécifiques.');
                    $('#edit-tech-fields').removeClass('d-none');

                    $('#edit-phone').val(data.tech.phone || '');
                    $('#edit-adresse').val(data.tech.adresse || '');
                    $('#edit-zip_code').val(data.tech.zip_code || '');
                    $('#edit-city').val(data.tech.city || '');
                    $('#edit-default_rest_time').val(data.tech.default_rest_time || '60');

                    // Ajout ICI : remplissage heures/minutes depuis default_start_at
                    const [sh, sm] = (data.tech.default_start_at || '08:30').split(':');
                    const [eh, em] = (data.tech.default_end_at || '17:30').split(':');

                    $('#edit_start_hour').val(sh);
                    $('#edit_start_minute').val(sm);
                    $('#edit_end_hour').val(eh);
                    $('#edit_end_minute').val(em);

                    $('#edit_default_start_at').val(`${sh}:${sm}`);
                    $('#edit_default_end_at').val(`${eh}:${em}`);
                } else {
                    console.log('Utilisateur n\'est pas un technicien. Masquage des champs spécifiques.');
                    $('#edit-tech-fields').addClass('d-none');
                }

                // Mettre à jour l'action du formulaire
                $('#edit-user-form').attr('action', '/manage-users/' + data.id);
            },
            error: function (xhr) {
                console.error('Erreur lors de la récupération des données utilisateur:', xhr.responseText);
            },
            complete: function() {
            }
        });
    }

    // Gestion de l'ouverture du modal d'édition
    $('#userEditModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget);
        const userId = button.data('id');

        console.log('Ouverture du modal d\'édition pour l\'utilisateur ID:', userId);

        // Charger les données utilisateur
        loadUserData(userId);
    });

    // Gestion des changements dans le champ rôle
    $('#edit-role').on('change', function () {
        const selectedRole = $(this).val();
        toggleTechFields(selectedRole);
    });

    // Fonction pour basculer l'affichage des champs spécifiques en fonction du rôle
    function toggleTechFields(role) {
        console.log('Changement de rôle détecté:', role);
        const techFields = $('#edit-tech-fields');

        if (role === 'tech') {
            console.log('Affichage des champs spécifiques pour technicien.');
            techFields.removeClass('d-none');
        } else {
            console.log('Masquage des champs spécifiques pour technicien.');
            techFields.addClass('d-none');
        }
    }

    // Gestion de la fermeture des modals via les boutons "data-bs-dismiss"
    $('button[data-bs-dismiss="modal"]').on('click', function () {
        const modal = $(this).closest('.modal');
        if (modal.length) {
            console.log('Fermeture du modal:', modal.attr('id'));
            modal.modal('hide');
        }
    });

    // Gestion de l'ouverture du modal de modification de mot de passe
    $('#userEditPasswordModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui déclenche l'ouverture
        const userId = button.data('id'); // ID utilisateur

        console.log('Ouverture du modal de modification de mot de passe pour l\'utilisateur ID:', userId);

        // Mettre à jour l'action du formulaire avec l'ID utilisateur
        const formAction = $('#editPasswordForm').attr('action').replace(':id', userId);
        $('#editPasswordForm').attr('action', formAction);
    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#userEditPasswordModal').on('hidden.bs.modal', function () {
        $('#editPasswordForm').attr('action', '{{ route('manage-users.updatePassword', ':id') }}');
    });

    // Gestion de l'ouverture du modal de suppression
    $('#userDeleteModal').on('show.bs.modal', function (e) {
        const button = $(e.relatedTarget); // Bouton qui déclenche le modal
        const userId = button.data('id'); // ID utilisateur

        console.log('Ouverture du modal de suppression pour l\'utilisateur ID:', userId);

        // Mettre à jour l'action du formulaire avec l'ID utilisateur
        const formAction = $(this).find('form').attr('action').replace(':id', userId);
        $(this).find('form').attr('action', formAction);
    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#userDeleteModal').on('hidden.bs.modal', function () {
        $(this).find('form').attr('action', '{{ route('manage-users.destroy', ':id') }}');
    });

    $('#userRestoreModal').on('show.bs.modal', function (e) {
    const button = $(e.relatedTarget);
    const userId = button.data('id');

    console.log('Ouverture du modal de restauration pour l\'utilisateur ID:', userId);

    // Modifier l'action du formulaire
    const formAction = $(this).find('form').attr('action').replace(':id', userId);
        $(this).find('form').attr('action', formAction);
    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#userRestoreModal').on('hidden.bs.modal', function () {
        $(this).find('form').attr('action', '{{ route('manage-users.restore', ':id') }}');
    });

    $('#userHardDeleteModal').on('show.bs.modal', function (e) {
    const button = $(e.relatedTarget);
    const userId = button.data('id');

    console.log('Ouverture du modal de suppression définitive pour l\'utilisateur ID:', userId);

    // Modifier l'action du formulaire
    const formAction = $(this).find('form').attr('action').replace(':id', userId);
        $(this).find('form').attr('action', formAction);
    });

    // Réinitialiser l'action du formulaire lorsque le modal est fermé
    $('#userHardDeleteModal').on('hidden.bs.modal', function () {
        $(this).find('form').attr('action', '{{ route('manage-users.hard-delete', ':id') }}');
    });
});
</script>
@endsection
