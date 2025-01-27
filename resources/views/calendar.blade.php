@extends('layouts.app') <!-- Indique que cette vue utilise le layout app -->

@section('title', 'Calendrier des techniciens') <!-- Titre de la page -->

@section('css') <!-- Section pour les styles spécifiques à cette page -->
<link rel="stylesheet" href="{{ asset('css/custom-dashboard.css') }}">
@endsection

@section('pageHeading') <!-- Titre ou en-tête de la page -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
    <a href="#" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-download fa-sm text-white-50"></i> Generate Report
    </a>
</div>
@endsection

@section('content') <!-- Contenu principal de la page -->
<div class="row">

    <!-- Earnings (Monthly) Card Example -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Earnings (Monthly)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">$40,000</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-calendar fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Other Content Goes Here -->

</div>
@endsection

@section('js') <!-- Section pour les scripts spécifiques à cette page -->
<script>
    console.log('Dashboard Page Loaded');
</script>
@endsection
