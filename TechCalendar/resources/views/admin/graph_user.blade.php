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
                <div class="col-xl-12 col-lg-12">
                    <div class="card shadow mb-4">
                        <!-- Titre et filtres -->
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Statistiques des Techniciens</h6>
                            <form method="GET" action="{{ route('admin.graph_user') }}">
                                <div class="form-row">
                                    <div class="col">
                                        <input type="date" name="start_date" class="form-control" value="{{ $startDate }}">
                                    </div>
                                    <div class="col">
                                        <input type="date" name="end_date" class="form-control" value="{{ $endDate }}">
                                    </div>
                                    <div class="col">
                                        <button type="submit" class="btn btn-primary">Filtrer</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <!-- Graphiques -->
                        <div class="card-body" style="height: 700px">
                            <div class="row" style="height: 100%">
                                <div class="col-lg-6 mb-4">
                                    <canvas id="distanceChart"></canvas>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <canvas id="rdvChart"></canvas>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <canvas id="travelTimeChart"></canvas>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <canvas id="appointmentTimeChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste des techniciens -->
                <div class="row">
                    <div class="col-xl-12 col-lg-12">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Liste des Techniciens</h6>
                            </div>
                            <div class="card-body">
                                <div class="mt-1">
                                    <!-- Champ de recherche -->
                                    <input type="text" id="searchTechniciansInGraph" class="form-control mb-3" placeholder="Rechercher par nom ou département">
                                    
                                    <!-- Liste des techniciens -->
                                    <ul id="technicianList" class="list-group">
                                        @foreach($techniciens as $technicien)
                                            <li class="list-group-item d-flex justify-content-between align-items-center tech-item"
                                                data-id="{{ $technicien->id }}"
                                                data-name="{{ strtolower($technicien->prenom . ' ' . $technicien->nom) }}"
                                                data-rdv="{{ json_encode($technicien->rendezvous->map(function($rdv) {
                                                    return [
                                                        'date' => $rdv->date,
                                                        'distance' => $rdv->traject_distance,
                                                        'travelTime' => $rdv->traject_time,
                                                        'appointmentTime' => $rdv->duree,
                                                    ];
                                                })) }}">
                                                <div>
                                                    <strong>{{ $technicien->prenom }} {{ $technicien->nom }}</strong>
                                                    <small class="d-block text-muted">
                                                        {{ $technicien->rendezvous->count() }} rendez-vous au total dont 
                                                        <span class="current-period-count" data-id="{{ $technicien->id }}">
                                                            {{ $technicien->rendezvous->filter(function ($rdv) use ($startDate, $endDate) {
                                                                return $rdv->date >= $startDate && $rdv->date <= $endDate;
                                                            })->count() }}
                                                        </span>
                                                        sur la période en cours
                                                    </small>
                                                </div>
                                                <input type="checkbox" class="ml-2 tech-checkbox" onchange="updateCharts()">
                                            </li>
                                        @endforeach
                                    </ul>
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