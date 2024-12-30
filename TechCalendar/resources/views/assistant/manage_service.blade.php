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
@include('partials.delete_confirmation_modal')
@endsection

@section('head-js')
<script>
// Ajouter vos scripts JS pour la gestion des prestations ici
</script>
@endsection