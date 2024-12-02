@extends('layouts.app')

@section('title', 'Dashboard')

@php $activeLink = 'dashboard'; @endphp

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

                        <div id="dashboardCalendarContainer">
                            <!-- Agenda -->
                            <div class="col-12">
                                <div class="card shadow" style="height: 70vh; margin-top: 5vh">
                                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                        <h6 class="m-0 font-weight-bold text-primary">Votre Agenda</h6>
                                        <div class="d-flex align-items-center">
                                            <!-- Navigation pour les semaines -->
                                            <button id="prevWeekTech" class="btn btn-outline-primary btn-sm mx-2" onclick="onlyTechChangeWeek(-1)">&larr;</button>
                                            <span id="onlyTechWeekLabelTech" class="font-weight-bold">Semaine en cours</span>
                                            <button id="nextWeekTech" class="btn btn-outline-primary btn-sm mx-2" onclick="onlyTechChangeWeek(1)">&rarr;</button>
                                        </div>
                                    </div>
                                    <div class="card-body" style="height: 560px">
                                        <div id="techCalendarContainer"></div>
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