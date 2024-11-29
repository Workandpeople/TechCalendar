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
            <form class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search" method="GET" action="{{ route('admin.graph_user') }}">
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
                            <div class="col-md-6">
                                @if(isset($search) && !empty($search))
                                    <h6 class="m-0 font-weight-bold text-primary">Utilisateurs : {{ $users->first()->prenom ?? '' }} {{ $users->first()->nom ?? '' }}</h6>
                                @else
                                    <h6 class="m-0 font-weight-bold text-primary">Utilisateurs</h6>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <form method="GET" action="{{ route('admin.graph_user') }}">
                                    <div class="form-row">
                                        <div class="col">
                                            <input type="date" name="start_date" class="form-control" value="{{ request()->get('start_date') }}">
                                        </div>
                                        <div class="col">
                                            <input type="date" name="end_date" class="form-control" value="{{ request()->get('end_date') }}">
                                        </div>
                                        <div class="col">
                                            <button type="submit" class="btn btn-primary">Filtrer</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="card-body" style="height: 700px">
                            <div class="row" style="height: 100%">
                                <div class="col-lg-6 mb-4">
                                    <div class="card shadow h-100">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">Nombre de km parcourus</h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="distanceChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <div class="card shadow h-100">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">Nombre de RDV</h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="rdvChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <div class="card shadow h-100">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">Temps de trajet effectué</h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="travelTimeChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6 mb-4">
                                    <div class="card shadow h-100">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">Temps de RDV effectué</h6>
                                        </div>
                                        <div class="card-body">
                                            <canvas id="appointmentTimeChart"></canvas>
                                        </div>
                                    </div>
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