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

            <!-- Topbar Navbar -->
            <ul class="navbar-nav ml-auto">

                <div class="topbar-divider d-none d-sm-block"></div>                        

                <!-- Nav Item - User Information -->
                <li class="nav-item dropdown no-arrow">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                        data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="mr-2 d-none d-lg-inline text-gray-600 small">{{ Auth::user()->prenom }} {{ Auth::user()->nom }}</span>
                        
                        <!-- Rond avec les initiales -->
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

                <!-- Card -->
                <div class="col-xl-12 col-lg-12">
                    <div class="card shadow mb-4">
                        <!-- Card Header -->
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Rechercher un technicien</h6>
                        </div>
                        
                        <!-- Card Body -->
                        <div class="card-body d-flex">
                            <!-- Formulaire à gauche -->
                            <div class="col-md-6">
                                <form id="newRdvForm">
                                    <!-- Dropdown pour les prestations avec recherche dynamique -->
                                    <div class="form-group">
                                        <label for="prestationSearch">Rechercher une prestation</label>
                                        <input type="text" id="prestationSearch" class="form-control mb-2" placeholder="Rechercher une prestation...">
                                        <select id="prestationDropdown" class="form-control">
                                            @foreach($prestations as $prestation)
                                                <option value="{{ $prestation->id }}" data-default-time="{{ $prestation->default_time }}">
                                                    {{ $prestation->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <!-- Champs d'adresse, code postal et ville -->
                                    <div class="form-group">
                                        <label for="address">Adresse</label>
                                        <input type="text" id="address" class="form-control" placeholder="Adresse">
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="postalCode">Code postal</label>
                                            <input type="text" id="postalCode" class="form-control" placeholder="Code postal">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="city">Ville</label>
                                            <input type="text" id="city" class="form-control" placeholder="Ville">
                                        </div>
                                    </div>
                                    <!-- Boutons de soumission -->
                                    <button type="button" class="btn btn-primary" onclick="searchTechnicians()">Rechercher des techniciens disponibles</button>
                                </form>
                            </div>

                            <!-- Tableau des résultats à droite -->
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Nom & Prénom</th>
                                            <th>Distance (km)</th>
                                            <th>Temps de trajet (min)</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="technicianResults">
                                        <!-- Les résultats seront ajoutés ici via JavaScript -->
                                    </tbody>
                                </table>
                            </div>

                            <div id="calendarOverlay" class="modal-overlay" style="display: none;">
                                <div class="modal-content">
                                    <button class="close-btn" onclick="closeCalendar()">&times;</button>
                                    <h3>Calendrier</h3>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <button id="prevWeek" class="btn btn-outline-primary btn-sm" onclick="changeWeek(-1)">&larr;</button>
                                        <span id="weekLabel" class="font-weight-bold">Semaine du XX/XX/XXXX</span>
                                        <button id="nextWeek" class="btn btn-outline-primary btn-sm" onclick="changeWeek(1)">&rarr;</button>
                                    </div>
                                    <div id="calendarContainer" style="height: 500px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <!-- /.container-fluid -->

    </div>
    <!-- End of Main Content -->

</div>
<!-- End of Content Wrapper -->

</div>
<!-- End of Page Wrapper -->

@endsection