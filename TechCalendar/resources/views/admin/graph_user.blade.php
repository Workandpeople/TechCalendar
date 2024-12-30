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
                <div class="col-xl-8 col-lg-8">
                    <div class="card shadow mb-4">
                        <!-- Titre et filtres -->
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Statistiques des Techniciens</h6>
                            <!-- Bouton pour ouvrir l'overlay -->
                            <button class="btn btn-primary" id="openFilters">Filtres</button>
                        </div>
                        <!-- Graphiques -->
                        <div class="card-body" style="height: 700px">
                            <div class="row h-100">
                                <div class="col-lg-6 col-md-12 mb-4">
                                    <canvas id="appointmentChart"></canvas>
                                </div>
                                <div class="col-lg-6 col-md-12 mb-4">
                                    <canvas id="distanceChart"></canvas>
                                </div>
                                <div class="col-lg-6 col-md-12 mb-4">
                                    <canvas id="timeSpentChart"></canvas>
                                </div>
                                <div class="col-lg-6 col-md-12 mb-4">
                                    <canvas id="costChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-4">
                    <div class="card shadow mb-4">
                        <!-- Titre et filtres -->
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Liste des Techniciens</h6>
                            <input type="text" id="searchTechnicians" class="form-control" placeholder="Rechercher un technicien">
                        </div>
                        <!-- Liste des techniciens -->
                        <div class="card-body" style="height: 700px">
                            <div id="technicianList" class="list-group">
                                <!-- Les techniciens seront ajoutés dynamiquement via JS -->
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

@section('head-js')
<script>
    // Gestion de l'ouverture et de la fermeture de l'overlay
    const overlayId = 'filterOverlay';

    function openFilterOverlay() {
        console.log('Opening overlay');
        
        let overlay = document.getElementById(overlayId);

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = overlayId;
            overlay.className = 'modal-overlay';
            overlay.innerHTML = `
                <div class="modal-content">
                    <button class="close-btn" onclick="closeFilterOverlay()">&times;</button>
                    <h3 class="text-center">Filtres</h3>
                    <form id="filterForm">
                        <div class="form-group">
                            <label for="techSearch">Rechercher un technicien</label>
                            <input type="text" id="techSearch" class="form-control" placeholder="Nom, Prénom ou Département">
                        </div>
                        <div class="form-group">
                            <label for="startDate">Date de début</label>
                            <input type="date" id="startDate" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="endDate">Date de fin</label>
                            <input type="date" id="endDate" class="form-control">
                        </div>
                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-primary">Appliquer</button>
                            <button type="button" class="btn btn-secondary" onclick="closeFilterOverlay()">Annuler</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        overlay.style.display = 'flex';
    }

    function closeFilterOverlay() {
        const overlay = document.getElementById(overlayId);
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    // Ajouter un événement pour ouvrir l'overlay
    document.getElementById('openFilters').addEventListener('click', openFilterOverlay);

    // Exemple de techniciens
    const technicians = [
        { id: 1, name: 'John Doe' },
        { id: 2, name: 'Jane Smith' },
        { id: 3, name: 'Albert Johnson' }
    ];

    // Remplir la liste des techniciens dynamiquement
    const technicianList = document.getElementById('technicianList');
    technicians.forEach(tech => {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = `tech-${tech.id}`;
        checkbox.className = 'form-check-input';

        const label = document.createElement('label');
        label.htmlFor = `tech-${tech.id}`;
        label.className = 'form-check-label';
        label.innerText = tech.name;

        const div = document.createElement('div');
        div.className = 'form-check';
        div.appendChild(checkbox);
        div.appendChild(label);

        technicianList.appendChild(div);
    });
</script>
@endsection

@endsection