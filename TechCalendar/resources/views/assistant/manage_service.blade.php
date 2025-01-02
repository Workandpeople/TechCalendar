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
                    <input type="text" id="searchBar" class="form-control bg-light border-0 small" placeholder="Rechercher une prestation..." aria-label="Search">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="button">
                            <i class="fas fa-search fa-sm"></i>
                        </button>
                    </div>
                </div>
            </form>
            @include('partials/simpleTopbar')
        </nav>
        <!-- End of Topbar -->

        <!-- Begin Page Content -->
        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Prestations</h6>
                    <button class="btn btn-sm btn-success" onclick="showCreateService()">+ Ajouter</button>
                </div>
                <div class="card-body">
                    <div id="serviceTable" class="table-responsive">
                        @include('partials.service_table', ['services' => $services])
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


@include('partials.service_details_modal')
@include('partials.create_service_modal')
@include('partials.edit_service_modal')
@include('partials.delete_service_confirmation_modal')
@endsection

@section('head-js')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        console.log("Initializing modals...");
    
        const createServiceModal = new bootstrap.Modal(document.getElementById('createServiceModal'), {});
        const editServiceModal = new bootstrap.Modal(document.getElementById('editServiceModal'), {});
        const deleteConfirmationModal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'), {});
        const serviceDetailsModal = new bootstrap.Modal(document.getElementById('serviceDetailsModal'), {});

        console.log("Modals initialized successfully.");

        const searchBar = document.getElementById('searchBar');
        const serviceTable = document.getElementById('serviceTable');


        let serviceIdToDelete = null;

        // Recherche dynamique
        searchBar.addEventListener('input', () => {
            const searchQuery = searchBar.value;

            fetch(`/assistant/manage-service?search=${encodeURIComponent(searchQuery)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Erreur lors de la recherche');
                }
                return response.text();
            })
            .then((html) => {
                serviceTable.innerHTML = html; // Met à jour la table avec les résultats
            })
            .catch((error) => {
                console.error('Erreur lors de la recherche dynamique :', error);
            });
        });

        /**
         * Affiche les détails d'une prestation dans un modal.
         * @param {Object} service - Données de la prestation.
         */
        window.showServiceDetails = (service) => {
            const serviceDetailsList = document.getElementById('serviceDetailsList');
            serviceDetailsList.innerHTML = ''; // Nettoyer les anciens détails

            // Afficher uniquement les informations utiles
            const fieldsMapping = {
                type: 'Type de prestation',
                name: 'Nom',
                default_time: 'Durée par défaut (minutes)',
            };

            Object.entries(service).forEach(([key, value]) => {
                if (value !== null && fieldsMapping[key]) {
                    const listItem = document.createElement('li');
                    listItem.classList.add('list-group-item');
                    listItem.textContent = `${fieldsMapping[key]} : ${value}`;
                    serviceDetailsList.appendChild(listItem);
                }
            });

            // Afficher le modal
            serviceDetailsModal.show();
        };

        /**
         * Affiche la modal pour créer une prestation.
         */
         window.showCreateService = () => {
            console.log("showCreateService called");
            document.getElementById('createServiceForm').reset();
            createServiceModal.show();
        };

        /**
         * Affiche la modal pour modifier une prestation.
         * @param {Object} service - Données de la prestation.
         */
         window.showEditService = (service) => {
            console.log("Editing service:", service);

            const editForm = document.getElementById('editServiceForm');
            editForm.reset();

            document.getElementById('editServiceId').value = service.id;
            document.getElementById('editType').value = service.type; // ID corrigé ici
            document.getElementById('editName').value = service.name;
            document.getElementById('editDefaultTime').value = service.default_time;

            editServiceModal.show();
        };

        /**
         * Affiche la modal de confirmation pour supprimer une prestation.
         * @param {string} id - ID de la prestation à supprimer.
         */
        window.showDeleteConfirmation = (id) => {
            serviceIdToDelete = id;
            deleteConfirmationModal.show();
        };

        /**
         * Crée une nouvelle prestation.
         */
        window.saveNewService = () => {
            const formData = new FormData(document.getElementById('createServiceForm'));

            fetch(`{{ route('assistant.create_service') }}`, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur : ' + Object.values(data.errors || {}).join(', '));
                }
            })
            .catch(() => alert('Erreur lors de la création.'));
        };

        /**
         * Modifie une prestation existante.
         */
        window.saveServiceChanges = () => {
            const serviceId = document.getElementById('editServiceId').value;
            const formData = new FormData(document.getElementById('editServiceForm'));
            formData.append('_method', 'PUT');

            fetch(`/assistant/manage-service/${serviceId}`, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur : ' + Object.values(data.errors || {}).join(', '));
                }
            })
            .catch(() => alert('Erreur lors de la mise à jour.'));
        };

        /**
         * Supprime une prestation.
         */
        window.confirmDeleteService = () => {
            if (!serviceIdToDelete) return;

            fetch(`/assistant/manage-service/${serviceIdToDelete}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur lors de la suppression.');
                }
            })
            .catch(() => alert('Erreur lors de la suppression.'));
        };
    });
</script>
@endsection