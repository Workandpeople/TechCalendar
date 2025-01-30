@extends('layouts.app')

@section('title', 'Dashboard')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
    <div class="button-group">
        <a href="#" class="btn btn-sm btn-success shadow-sm" data-toggle="modal" data-target="#appointmentCreateModal">
            <i class="fas fa-plus fa-sm text-white-50"></i> Générer un rapport
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    {{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif
@endsection


@section('content')
<div class="card mb-4">
    <div class="card-body">
        <form id="reportForm" method="GET" action="{{ route('home.index') }}">
            <div class="row">
                <div class="col-md-5 mb-3">
                    <label for="start_date" class="form-label">Date de début</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="{{ $startDate }}">
                </div>
                <div class="col-md-5 mb-3">
                    <label for="end_date" class="form-label">Date de fin</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="{{ $endDate }}">
                </div>
                <div class="col-md-2 d-flex justify-content-end">
                    <button type="button" class="btn btn-secondary w-100" id="resetFilters">
                        Réinitialiser
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row">
    <!-- Graphique RDV effectués et à venir -->
    <div class="col-6 col-md-3 mb-4">
        @include('partials.charts.appointmentsPie')
    </div>
    <!-- Répartition des services -->
    <div class="col-6 col-md-3 mb-4">
        @include('partials.charts.servicesBreakdown')
    </div>
    <!-- Coûts kilométriques -->
    <div class="col-12 col-md-6 mb-4">
        @include('partials.charts.kmCostsBar')
    </div>
</div>

<div class="row">
    <!-- RDV menés à bien par mois -->
    <div class="col-md-12">
        @include('partials.charts.monthlyAppointmentsLine')
    </div>
</div>
@endsection


@section('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const startInput = document.getElementById('start_date');
        const endInput   = document.getElementById('end_date');
        const form       = document.getElementById('reportForm');
        const resetBtn   = document.getElementById('resetFilters');

        startInput.addEventListener('change', () => {
            showLoadingOverlay(); // Affiche le chargement
            form.submit();
        });

        endInput.addEventListener('change', () => {
            showLoadingOverlay();
            form.submit();
        });

        resetBtn.addEventListener('click', () => {
            showLoadingOverlay();
            startInput.value = '';
            endInput.value   = '';
            form.submit();
        });
    });
</script>
@endsection
