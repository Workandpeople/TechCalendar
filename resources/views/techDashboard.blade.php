@extends('layouts.app')

@section('title', 'Dashboard')

@section('css')
<link rel="stylesheet" href="{{ asset('css/custom-dashboard.css') }}">
@endsection

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Votre Dashboard</h1>
    <a href="{{ route('tech-dashboard.index') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-calendar-alt fa-sm text-white-50"></i> Votre calendrier
    </a>
</div>
@endsection

@section('content')
<div class="container">
    <div class="row">
        <!-- Vérification si l'utilisateur a un tech_id -->
        @if(!is_null($techId))
            <!-- Statistiques -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                RDV effectués aujourd'hui</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvEffectuesAujd }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                RDV à venir aujourd'hui</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvAVenirAujd }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                RDV effectués ce mois-ci</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvEffectuesMois }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                RDV à venir ce mois-ci</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $rdvAVenirMois }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Calendrier -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Vos rendez-vous aujourd'hui</h5>
                </div>
                <div class="card-body">
                    <div id="calendar-container">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>

        @else
            <!-- Message d'erreur si l'utilisateur n'est pas un technicien -->
            <div class="col-12">
                <div class="alert alert-warning text-center p-4">
                    <h5>🚫 Vous n'êtes pas un technicien.</h5>
                    <p>Veuillez contacter votre administrateur pour gérer votre rôle.</p>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@section('js')
<script>
$(document).ready(function () {
    @if(!is_null($techId))
        // Charger uniquement les rendez-vous d'aujourd'hui
        $.ajax({
            url: '{{ route("tech-dashboard.appointments") }}',
            type: 'GET',
            success: function(response) {
                console.log("📅 RDV du mois chargés :", response.appointments);
            },
            error: function(xhr) {
                console.error("❌ Erreur AJAX :", xhr);
            }
        });
    @endif
});
</script>
@endsection
