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
            <form class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search" method="GET" action="{{ route('admin.manage_presta') }}">
                <div class="input-group">
                    <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control bg-light border-0 small" placeholder="Rechercher une prestation..." aria-label="Search" aria-describedby="basic-addon2">
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
                            <h6 class="m-0 font-weight-bold text-primary">Prestations</h6>
                            <button class="btn btn-sm btn-success" onclick="showCreatePresta()">+ Ajouter</button>
                        </div>
                        <div class="card-body" style="height: 700px">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover text-center">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="col-1">#</th>
                                            <th class="col-3">Type</th>
                                            <th class="col-4">Nom</th>
                                            <th class="col-2">Temps par défaut (min)</th>
                                            <th class="col-2">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($prestations as $index => $presta)
                                        <tr>
                                            <td>{{ ($prestations->currentPage() - 1) * $prestations->perPage() + $index + 1 }}</td>
                                            <td>{{ $presta->type }}</td>
                                            <td>{{ $presta->name }}</td>
                                            <td>{{ $presta->default_time }}</td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-warning" title="Modifier" onclick="showEditPresta({{ json_encode($presta) }})"><i class="fas fa-edit"></i></button>
                                                    <button class="btn btn-sm btn-danger" title="Supprimer" onclick="deletePresta('{{ $presta->id }}')"><i class="fas fa-trash-alt"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="d-flex justify-content-center mt-3">
                                    {{ $prestations->onEachSide(1)->links('pagination::bootstrap-4') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <!-- /.container-fluid -->

        <!-- Overlay Modal for Edit Prestation -->
        <div id="editPrestaModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeEditPresta()">&times;</button>
                <h3>Modifier la prestation</h3>
                <form id="editPrestaForm">
                    <input type="hidden" id="editPrestaId" name="presta_id">
                    <div class="form-group">
                        <label for="editPrestaType">Type :</label>
                        <select id="editPrestaType" name="type" class="form-control">
                            <option value="" disabled selected>Choisissez le type</option>
                            <option value="MAR">MAR</option>
                            <option value="AUDIT">AUDIT</option>
                            <option value="COFRAC">COFRAC</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editPrestaName">Nom :</label>
                        <input type="text" id="editPrestaName" name="name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editPrestaDefaultTime">Temps par défaut (min) :</label>
                        <input type="number" id="editPrestaDefaultTime" name="default_time" class="form-control">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="savePrestaChanges()">Enregistrer</button>
                </form>
            </div>
        </div>

        <!-- Overlay Modal for Delete Confirmation -->
        <div id="deleteConfirmationModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeDeleteConfirmation()">&times;</button>
                <h3>Confirmer la suppression</h3>
                <p>Êtes-vous sûr de vouloir supprimer cette prestation ? Cette action est irréversible.</p>
                <button type="button" class="btn btn-danger" onclick="confirmDeletePresta()">Supprimer</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteConfirmation()">Annuler</button>
            </div>
        </div>

        <!-- Overlay Modal for Creating Prestation -->
        <div id="createPrestaModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <button class="close-btn" onclick="closeCreatePresta()">&times;</button>
                <h3>Créer une nouvelle prestation</h3>
                <form id="createPrestaForm">
                    <div class="form-group">
                        <label for="createPrestaType">Type :</label>
                        <select id="createPrestaType" name="type" class="form-control">
                            <option value="MAR">MAR</option>
                            <option value="AUDIT">AUDIT</option>
                            <option value="COFRAC">COFRAC</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="createPrestaName">Nom :</label>
                        <input type="text" id="createPrestaName" name="name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="createPrestaDefaultTime">Temps par défaut (min) :</label>
                        <input type="number" id="createPrestaDefaultTime" name="default_time" class="form-control">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="saveNewPresta()">Créer</button>
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