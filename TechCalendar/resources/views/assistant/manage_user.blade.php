@extends('layouts.app')

@section('content')
<!-- Content Wrapper -->
<div id="content-wrapper" class="d-flex flex-column">

    <!-- Main Content -->
    <div id="content">

        <!-- Topbar -->
        <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
            <form class="form-inline mr-auto">
                <div class="input-group">
                    <input type="text" id="searchBar" class="form-control bg-light border-0 small" placeholder="Rechercher un utilisateur..." aria-label="Search">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="button">
                            <i class="fas fa-search fa-sm"></i>
                        </button>
                    </div>
                </div>
            </form>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item dropdown no-arrow">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button">
                        <span class="mr-2 text-gray-600 small">{{ Auth::user()->prenom }} {{ Auth::user()->nom }}</span>
                    </a>
                </li>
            </ul>
        </nav>
        <!-- End of Topbar -->

        <!-- Page Content -->
        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Utilisateurs</h6>
                    <button class="btn btn-sm btn-success" onclick="showCreateUser()">+ Ajouter</button>
                </div>
                <div class="card-body">
                    <div id="userTable" class="table-responsive">
                        @include('partials.user_table', ['users' => $users])
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


<!-- Modals -->
@include('partials.user_details_modal')
@include('partials.create_user_modal')
@include('partials.edit_user_modal')
@include('partials.delete_confirmation_modal')
@endsection

@section('head-js')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const createUserModal = new bootstrap.Modal(document.getElementById('createUserModal'));
    const editUserModal = new bootstrap.Modal(document.getElementById('editUserModal'));
    const deleteConfirmationModal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    const userDetailsModal = new bootstrap.Modal(document.getElementById('userDetailsModal'));

    let userIdToDelete = null;

    /**
     * Affiche les détails d'un utilisateur dans un modal.
     * @param {Object} user - Données de l'utilisateur.
     */
     window.showUserDetails = (user) => {
        const userDetailsList = document.getElementById('userDetailsList');
        const techDetailsList = document.getElementById('techDetailsList');
        const techDetailsTitle = document.getElementById('techDetailsTitle');

        userDetailsList.innerHTML = '';
        techDetailsList.innerHTML = '';
        techDetailsTitle.style.display = 'none';
        techDetailsList.style.display = 'none';

        // Mapping des champs pour des étiquettes plus lisibles
        const userLabels = {
            prenom: 'Prénom',
            nom: 'Nom',
            email: 'Email',
            role: 'Rôle'
        };

        const techLabels = {
            phone: 'Téléphone',
            adresse: 'Adresse',
            zip_code: 'Code Postal',
            city: 'Ville',
            default_start_at: 'Début par défaut',
            default_end_at: 'Fin par défaut',
            default_rest_time: 'Temps de repos'
        };

        // Affiche les détails de l'utilisateur avec des étiquettes lisibles
        Object.entries(user).forEach(([key, value]) => {
            if (value !== null && value !== '' && key !== 'tech' && userLabels[key]) {
                const listItem = document.createElement('li');
                listItem.classList.add('list-group-item');
                listItem.textContent = `${userLabels[key]} : ${value}`;
                userDetailsList.appendChild(listItem);
            }
        });

        // Affiche les détails du technicien si disponibles
        if (user.tech) {
            techDetailsTitle.style.display = 'block';
            techDetailsList.style.display = 'block';

            Object.entries(user.tech).forEach(([key, value]) => {
                if (value !== null && value !== '' && techLabels[key]) {
                    const listItem = document.createElement('li');
                    listItem.classList.add('list-group-item');
                    listItem.textContent = `${techLabels[key]} : ${value}`;
                    techDetailsList.appendChild(listItem);
                }
            });
        }

        userDetailsModal.show();
    };

    /**
     * Affiche la modal pour créer un utilisateur.
     */
    window.showCreateUser = () => {
        document.getElementById('createUserForm').reset();
        toggleTechFields('createUserForm', false);
        createUserModal.show();
    };

    /**
     * Affiche la modal pour modifier un utilisateur.
     * @param {Object} user - Données de l'utilisateur.
     */
    window.showEditUser = (user) => {
        const editForm = document.getElementById('editUserForm');
        editForm.reset();

        document.getElementById('editUserId').value = user.id;
        document.getElementById('editPrenom').value = user.prenom;
        document.getElementById('editNom').value = user.nom;
        document.getElementById('editEmail').value = user.email;
        document.getElementById('editRole').value = user.role;

        toggleTechFields('editUserForm', user.role === 'tech');

        if (user.tech) {
            document.getElementById('editPhone').value = user.tech.phone;
            document.getElementById('editAdresse').value = user.tech.adresse;
            document.getElementById('editZipCode').value = user.tech.zip_code;
            document.getElementById('editCity').value = user.tech.city;
            document.getElementById('editDefaultStartAt').value = user.tech.default_start_at;
            document.getElementById('editDefaultEndAt').value = user.tech.default_end_at;
            document.getElementById('editDefaultRestTime').value = user.tech.default_rest_time;
        }

        editUserModal.show();
    };

    /**
     * Affiche la modal de confirmation pour supprimer un utilisateur.
     * @param {string} id - ID de l'utilisateur à supprimer.
     */
    window.showDeleteConfirmation = (id) => {
        userIdToDelete = id;
        deleteConfirmationModal.show();
    };

    /**
     * Crée un nouvel utilisateur.
     */
    window.saveNewUser = () => {
        const formData = new FormData(document.getElementById('createUserForm'));

        fetch(`{{ route('assistant.create_user') }}`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        .then((response) => {
            if (response.ok) {
                location.reload();
            } else {
                return response.json().then((errors) => {
                    alert('Erreur : ' + Object.values(errors).join(', '));
                });
            }
        })
        .catch((err) => alert('Erreur lors de la création.'));
    };

    /**
     * Modifie un utilisateur existant.
     */
     window.saveUserChanges = () => {
        const userId = document.getElementById('editUserId').value;
        const formData = new FormData(document.getElementById('editUserForm'));

        // Ajouter le champ _method pour simuler une requête PUT
        formData.append('_method', 'PUT');

        fetch(`/assistant/manage-user/${userId}`, {
            method: 'POST', // Utiliser POST car _method=PUT sera pris en compte par Laravel
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
            .then((response) => {
                if (response.ok) {
                    location.reload();
                } else {
                    return response.json().then((errors) => {
                        alert('Erreur : ' + Object.values(errors).join(', '));
                    });
                }
            })
            .catch((err) => alert('Erreur lors de la mise à jour.'));
    };
    /**
     * Supprime un utilisateur.
     */
    window.confirmDeleteUser = () => {
        if (!userIdToDelete) return;

        fetch(`/assistant/manage-user/${userIdToDelete}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        .then((response) => {
            if (response.ok) {
                location.reload();
            } else {
                alert('Erreur lors de la suppression.');
            }
        })
        .catch((err) => alert('Erreur lors de la suppression.'));
    };

    /**
     * Affiche ou masque les champs pour les techniciens.
     * @param {string} formId - ID du formulaire.
     * @param {boolean} show - Indique si les champs doivent être affichés.
     */
    const toggleTechFields = (formId, show) => {
        const form = document.getElementById(formId);
        const techFields = form.querySelectorAll('#techFields, #editTechFields');
        techFields.forEach((field) => {
            field.style.display = show ? 'block' : 'none';
        });
    };

    /**
     * Ajoute un listener pour afficher/masquer les champs supplémentaires selon le rôle.
     */
    const addRoleChangeListener = (roleFieldId, formId) => {
        document.getElementById(roleFieldId)?.addEventListener('change', (e) => {
            toggleTechFields(formId, e.target.value === 'tech');
        });
    };

    addRoleChangeListener('role', 'createUserForm');
    addRoleChangeListener('editRole', 'editUserForm');

    /**
     * Capitalise la première lettre d'une chaîne.
     * @param {string} string - La chaîne à capitaliser.
     * @returns {string} - La chaîne avec la première lettre en majuscule.
     */
    const capitalizeFirstLetter = (string) => {
        return string.charAt(0).toUpperCase() + string.slice(1);
    };
});
</script>
@endsection