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
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Utilisateurs</h6>
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
                                                    <a href="#" class="btn btn-sm btn-warning" title="Modifier" onclick="showEditUser({{ json_encode($user) }})"><i class="fas fa-edit"></i></a>
                                                    <a href="#" class="btn btn-sm btn-danger" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');"><i class="fas fa-trash-alt"></i></a>
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

        <!-- Overlay Modal -->
        <div id="userModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeUserDetails()">&times;</button>
                <h3>Détails de l'utilisateur</h3>
                <p><strong>Nom :</strong> <span id="userName"></span></p>
                <p><strong>Email :</strong> <span id="userEmail"></span></p>
                <p><strong>Téléphone :</strong> <span id="userPhone"></span></p>
                <p><strong>Adresse :</strong> <span id="userAddress"></span></p>
                <p><strong>Rôle :</strong> <span id="userRole"></span></p>
                <p><strong>Statut :</strong> <span id="userStatus"></span></p>
            </div>
        </div>

        <!-- Overlay Modal for Edit -->
        <div id="editUserModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeEditUser()">&times;</button>
                <h3>Modifier l'utilisateur</h3>
                <form id="editUserForm">
                    <div class="form-group">
                        <label for="editUserName">Nom Prénom :</label>
                        <input type="text" id="editUserName" name="name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserEmail">Email :</label>
                        <input type="email" id="editUserEmail" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editUserRole">Rôle :</label>
                        <select id="editUserRole" name="role" class="form-control">
                            <option value="administrateur">Administrateur</option>
                            <option value="assistant">Assistant</option>
                            <option value="technicien">Technicien</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="saveUserChanges()">Enregistrer</button>
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