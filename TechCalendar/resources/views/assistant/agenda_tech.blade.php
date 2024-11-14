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
            <form
                class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search">
                <div class="input-group">
                    <input type="text" class="form-control bg-light border-0 small" placeholder="Rechercher un utilisateur..."
                        aria-label="Search" aria-describedby="basic-addon2">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="button">
                            <i class="fas fa-search fa-sm"></i>
                        </button>
                    </div>
                </div>
            </form>

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

                <!-- Area Chart -->
                <div class="col-xl-12 col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card">
                            <!-- Card Header -->
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Agenda des Techniciens</h6>
                                <div class="d-flex align-items-center">
                                    <!-- Navigation pour les semaines -->
                                    <button id="prevWeek" class="btn btn-outline-primary btn-sm mx-2">&larr;</button>
                                    <span id="weekLabel" class="font-weight-bold">Semaine du XX/XX/XXXX</span>
                                    <button id="nextWeek" class="btn btn-outline-primary btn-sm mx-2">&rarr;</button>
                                </div>
                            </div>
                        
                            <!-- Card Body (Calendrier) -->
                            <div class="card-body" style="height: 700px">
                                <div id="calendarContainer"></div>
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
                <div class="input-group mt-2">
                    <input type="text" id="technicianSearch" class="form-control" placeholder="Rechercher un technicien...">
                </div>
            </div>
            <div class="card-body">
                <ul id="technicianList" class="list-group">
                    @foreach($techniciens as $technicien)
                        <li class="list-group-item d-flex justify-content-between align-items-center tech-item"
                            data-name="{{ strtolower($technicien->prenom . ' ' . $technicien->nom) }}"
                            data-postal-code="{{ $technicien->code_postal }}">
                            {{ $technicien->prenom }} {{ $technicien->nom }}
                            <input type="checkbox" class="ml-2">
                        </li>
                    @endforeach
                </ul>
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