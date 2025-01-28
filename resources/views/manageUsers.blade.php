@extends('layouts.app')

@section('title', 'Gestion des utilisateurs')

@section('css')
    <link href="{{ asset('css/manageUser.css') }}" rel="stylesheet">
@endsection

@section('topbarSearch')
    <form id="searchForm" class="d-inline-block form-inline ml-md-3 my-2 my-md-0 mw-100">
        <div class="input-group">
            <input type="text" id="searchInput" class="form-control bg-light border-0 small" placeholder="Rechercher un utilisateur..."
                aria-label="Search" aria-describedby="search-addon">
        </div>
    </form>
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Gérer les utilisateurs</h1>
    <a href="#" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#userCreateModal">
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
    console.log('Page de gestion des utilisateurs chargée');

    $('#searchInput').on('input', function () {
        const query = $(this).val();
        console.log('Recherche déclenchée avec :', query);

        $.ajax({
            url: '{{ route('manage-users.search') }}',
            type: 'GET',
            data: { query: query },
            success: function (data) {
                console.log('Résultats de la recherche :', data);

                const tbody = $('table tbody');
                tbody.empty();

                if (data.data.length === 0) {
                    tbody.append('<tr><td colspan="5" class="text-center">Aucun utilisateur trouvé</td></tr>');
                } else {
                    data.data.forEach(user => {
                        const isTrashed = user.deleted_at !== null;

                        const userRow = `
                            <tr class="${isTrashed ? 'table-danger' : ''}">
                                <td><strong>${user.nom.toUpperCase()}</strong> ${user.prenom}</td>
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
                                            <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#userDeleteModal" data-id="${user.id}">Supprimer</button>
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
                    $('#edit-default_start_at').val(data.tech.default_start_at || '08:30');
                    $('#edit-default_end_at').val(data.tech.default_end_at || '17:30');
                    $('#edit-default_rest_time').val(data.tech.default_rest_time || '60');
                } else {
                    console.log('Utilisateur n\'est pas un technicien. Masquage des champs spécifiques.');
                    $('#edit-tech-fields').addClass('d-none');
                }

                // Mettre à jour l'action du formulaire
                $('#edit-user-form').attr('action', '/manage-users/' + data.id);
            },
            error: function (xhr) {
                console.error('Erreur lors de la récupération des données utilisateur:', xhr.responseText);
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
