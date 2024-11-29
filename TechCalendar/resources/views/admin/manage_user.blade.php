@extends('layouts.app')

@section('content')
<!-- Content Wrapper -->
<div id="content-wrapper" class="d-flex flex-column">

    <!-- Main Content -->
    <div id="content">

        <!-- Topbar -->
        <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
            <!-- Sidebar Toggle (Topbar) -->
            <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                <i class="fa fa-bars"></i>
            </button>

            <!-- Topbar Search -->
            <form class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search" method="GET" action="{{ route('admin.manage_user') }}">
                <div class="input-group">
                    <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control bg-light border-0 small" placeholder="Rechercher un utilisateur..." aria-label="Search" aria-describedby="basic-addon2">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search fa-sm"></i>
                        </button>
                    </div>
                </div>
            </form>

            <!-- Topbar Navbar -->
            <ul class="navbar-nav ml-auto">
                <div class="topbar-divider d-none d-sm-block"></div>
                <li class="nav-item dropdown no-arrow">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="mr-2 d-none d-lg-inline text-gray-600 small">{{ Auth::user()->prenom }} {{ Auth::user()->nom }}</span>
                        <div class="img-profile rounded-circle d-flex align-items-center justify-content-center bg-primary text-white" style="width: 40px; height: 40px;">
                            {{ strtoupper(substr(Auth::user()->prenom, 0, 1)) }}{{ strtoupper(substr(Auth::user()->nom, 0, 1)) }}
                        </div>
                    </a>
                </li>
            </ul>
        </nav>
        <!-- End of Topbar -->

        <!-- Begin Page Content -->
        <div class="container-fluid">
            <div class="row">
                <div class="col-xl-12 col-lg-12">
                    <div class="card shadow mb-4">
                        <!-- Ajouter le bouton + à côté du titre Utilisateurs -->
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Utilisateurs</h6>
                            <button class="btn btn-sm btn-success" onclick="showCreateUser()">+ Ajouter</button>
                        </div>
                        <div class="card-body" style="height: 700px">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover text-center">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="col-1">#</th>
                                            <th class="col-3">Nom Prénom</th>
                                            <th class="col-2">Rôle</th>
                                            <th class="col-2">Voir Fiche</th>
                                            <th class="col-4">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($users as $index => $user)
                                        <tr>
                                            <td>{{ ($users->currentPage() - 1) * $users->perPage() + $index + 1 }}</td>
                                            <td>{{ $user->prenom }} {{ $user->nom }}</td>
                                            <td>{{ ucfirst($user->role->role ?? 'N/A') }}</td>
                                            <td>
                                                <button class="btn btn-sm btn-info" onclick="showUserDetails({{ json_encode($user) }})" title="Voir la fiche"><i class="fas fa-eye"></i></button>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-warning" title="Modifier" onclick="showEditUser({{ json_encode($user) }})"><i class="fas fa-edit"></i></button>
                                                    <button class="btn btn-sm btn-danger" title="Supprimer" onclick="deleteUser('{{ $user->id }}')"><i class="fas fa-trash-alt"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="d-flex justify-content-center mt-3">
                                    {{ $users->onEachSide(1)->links('pagination::bootstrap-4') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <!-- /.container-fluid -->

        <!-- Overlay Modal for User Details -->
        <div id="userModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeUserDetails()">&times;</button>
                <h3>Détails de l'utilisateur</h3>
                <p><strong>Nom :</strong> <span id="userName"></span></p>
                <p><strong>Email :</strong> <span id="userEmail"></span></p>
                <p><strong>Téléphone :</strong> <span id="userPhone"></span></p>
                <p><strong>Adresse :</strong> <span id="userAddress"></span></p>
                <p><strong>Code Postal :</strong> <span id="userPostalCode"></span></p>
                <p><strong>Ville :</strong> <span id="userCity"></span></p>
                <p><strong>Rôle :</strong> <span id="userRole"></span></p>
                <p><strong>Début par défaut :</strong> <span id="userDefaultStartAt"></span></p>
                <p><strong>Fin par défaut :</strong> <span id="userDefaultEndAt"></span></p>
                <p><strong>Temps de trajet maximal par défaut :</strong> <span id="userTrajectTime"></span> minutes</p>
                <p><strong>Temps de repos par défaut :</strong> <span id="userRestTime"></span> minutes</p>
                <!--<p><strong>Statut :</strong> <span id="userStatus"></span></p>-->
            </div>
        </div>

        <!-- Overlay Modal for Edit -->
        <div id="editUserModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeEditUser()">&times;</button>
                <h3>Modifier l'utilisateur</h3>
                <form id="editUserForm">
                    <input type="hidden" id="editUserId" name="user_id">
                    <div class="form-group">
                        <label for="editUserPrenom">Prénom :</label>
                        <input type="text" id="editUserPrenom" name="prenom" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserNom">Nom :</label>
                        <input type="text" id="editUserNom" name="nom" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserEmail">Email :</label>
                        <input type="email" id="editUserEmail" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserPhone">Téléphone :</label>
                        <input type="text" id="editUserPhone" name="telephone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserAddress">Adresse :</label>
                        <input type="text" id="editUserAddress" name="adresse" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserPostalCode">Code Postal :</label>
                        <input type="text" id="editUserPostalCode" name="code_postal" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserCity">Ville :</label>
                        <input type="text" id="editUserCity" name="ville" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserRole">Rôle :</label>
                        <select id="editUserRole" name="role" class="form-control">
                            <option value="administrateur">Administrateur</option>
                            <option value="assistante">Assistante</option>
                            <option value="technicien">Technicien</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editUserDefaultStartAt">Début par défaut :</label>
                        <input type="time" id="editUserDefaultStartAt" name="default_start_at" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserDefaultEndAt">Fin par défaut :</label>
                        <input type="time" id="editUserDefaultEndAt" name="default_end_at" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserTrajectTime">Temps de trajet par défaut (minutes) :</label>
                        <input type="number" id="editUserTrajectTime" name="default_traject_time" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserRestTime">Temps de repos par défaut (minutes) :</label>
                        <input type="number" id="editUserRestTime" name="default_rest_time" class="form-control">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="saveUserChanges()">Enregistrer</button>
                </form>
            </div>
        </div>

        <!-- Overlay Modal for Delete Confirmation -->
        <div id="deleteConfirmationModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeDeleteConfirmation()">&times;</button>
                <h3>Confirmer la suppression</h3>
                <p>Êtes-vous sûr de vouloir supprimer cette prestation ? Cette action est irréversible.</p>
                <button type="button" class="btn btn-danger" onclick="confirmDeleteUser()">Supprimer</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteConfirmation()">Annuler</button>
            </div>
        </div>

        <!-- Overlay Modal for Creating User -->
        <div id="createUserModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeCreateUser()">&times;</button>
                <h3>Créer un nouvel utilisateur</h3>
                <form id="createUserForm">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="createUserPrenom">Prénom :</label>
                            <input type="text" id="createUserPrenom" name="prenom" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="createUserNom">Nom :</label>
                            <input type="text" id="createUserNom" name="nom" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="createUserEmail">Email :</label>
                            <input type="email" id="createUserEmail" name="email" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="createUserPhone">Téléphone :</label>
                            <input type="text" id="createUserPhone" name="telephone" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="createUserRole">Rôle :</label>
                            <select id="createUserRole" name="role" class="form-control" onchange="toggleFieldsBasedOnRole()">
                                <option value="administrateur">Administrateur</option>
                                <option value="assistante">Assistante</option>
                                <option value="technicien">Technicien</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="createUserAddress">Adresse :</label>
                            <input type="text" id="createUserAddress" name="adresse" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="createUserPostalCode">Code Postal :</label>
                            <input type="text" id="createUserPostalCode" name="code_postal" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="createUserCity">Ville :</label>
                            <input type="text" id="createUserCity" name="ville" class="form-control">
                        </div>
                    </div>
                    <div id="techFields" style="display: none;">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="createUserDefaultStartAt">Début par défaut :</label>
                                <input type="time" id="createUserDefaultStartAt" name="default_start_at" class="form-control">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="createUserDefaultEndAt">Fin par défaut :</label>
                                <input type="time" id="createUserDefaultEndAt" name="default_end_at" class="form-control">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="createUserTrajectTime">Temps de trajet (min) :</label>
                                <input type="number" id="createUserTrajectTime" name="default_traject_time" class="form-control">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="createUserRestTime">Temps de repos (min) :</label>
                                <input type="number" id="createUserRestTime" name="default_rest_time" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="createUserPassword">Mot de passe :</label>
                            <input type="password" id="createUserPassword" name="password" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="createUserPasswordConfirm">Confirmation :</label>
                            <input type="password" id="createUserPasswordConfirm" name="password_confirmation" class="form-control">
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="saveNewUser()">Créer</button>
                </form>
            </div>
        </div>
    </div>
    <!-- End of Main Content -->

</div>
<!-- End of Content Wrapper -->

</div>
<!-- End of Page Wrapper -->

@endsection