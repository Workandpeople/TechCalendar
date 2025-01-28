@extends('layouts.app')

@section('title', 'Statistiques')

@section('pageHeading')
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Statistiques</h1>
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
        <form id="reportForm" method="GET" action="{{ route('stats.index') }}">
            <input type="hidden" name="search_tech_id" id="search_tech_id" value="{{ $search_tech_id }}">

            <div class="row align-items-end">
                <!-- Technicien -->
                <div class="col-md-4">
                    <label for="search_tech" class="form-label">Technicien</label>
                    <input type="text" class="form-control" id="search_tech" name="search_tech"
                           placeholder="Nom ou prénom du tech" autocomplete="off"
                           value="{{ $searchTech }}">
                    <!-- Dropdown suggestions -->
                    <div id="techSuggestions"
                         style="position: absolute; top: 100%; left: 0; right: 0;
                                background: #fff; border: 1px solid #ccc; z-index: 999;">
                    </div>
                </div>

                <!-- Date début -->
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Date de début</label>
                    <input type="date" class="form-control" id="start_date" name="start_date"
                           value="{{ $startDate }}">
                </div>

                <!-- Date fin -->
                <div class="col-md-3">
                    <label for="end_date" class="form-label">Date de fin</label>
                    <input type="date" class="form-control" id="end_date" name="end_date"
                           value="{{ $endDate }}">
                </div>

                <!-- Bouton Réinitialiser -->
                <div class="col-md-2">
                    <button type="button" class="btn btn-secondary w-100" id="resetFilters">
                        Réinitialiser
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@if($appointmentsPie->isEmpty() && $appointmentsServices->isEmpty() &&
    $appointmentsKmCost->isEmpty() && $appointmentsMonthlyLine->isEmpty())
    <div class="alert alert-info">
        Veuillez rechercher un tech pour en obtenir des stats.
    </div>
@else
    <div class="row">
        <div class="col-6 col-md-3 mb-4">
            @include('partials.charts.appointmentsPie')
        </div>
        <div class="col-6 col-md-3 mb-4">
            @include('partials.charts.servicesBreakdown')
        </div>
        <div class="col-12 col-md-6 mb-4">
            @include('partials.charts.kmCostsBar')
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @include('partials.charts.monthlyAppointmentsLine')
        </div>
    </div>
@endif
@endsection

@section('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form       = document.getElementById('reportForm');
    const techInput  = document.getElementById('search_tech');
    const techIdInput= document.getElementById('search_tech_id');
    const suggestions= document.getElementById('techSuggestions');

    const startInput = document.getElementById('start_date');
    const endInput   = document.getElementById('end_date');
    const resetBtn   = document.getElementById('resetFilters');

    // Changement de date => submit
    startInput.addEventListener('change', () => form.submit());
    endInput.addEventListener('change', () => form.submit());

    // Réinitialiser => vider l’ID du tech + nom + dates
    resetBtn.addEventListener('click', () => {
        techInput.value  = '';
        techIdInput.value= '';  // On vide l'id du tech
        startInput.value = '';
        endInput.value   = '';
        form.submit();
    });

    // Autocomplete
    let timer;
    techInput.addEventListener('input', () => {
        const query = techInput.value.trim();
        clearTimeout(timer);
        if (!query) {
            suggestions.innerHTML = '';
            techIdInput.value = ''; // plus de tech_id
            return;
        }
        timer = setTimeout(() => {
            fetchSuggestions(query);
        }, 300);
    });

    // Clique en dehors => on ferme
    document.addEventListener('click', (e) => {
        if (!techInput.contains(e.target)) {
            suggestions.innerHTML = '';
        }
    });

    function fetchSuggestions(query) {
        fetch('{{ route('stats.search') }}?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => renderSuggestions(data))
            .catch(console.error);
    }

    function renderSuggestions(list) {
        if (!list.length) {
            suggestions.innerHTML = '<div class="p-2 text-muted">Aucun résultat</div>';
            return;
        }
        let html = '';
        list.forEach(item => {
            html += `
                <div class="p-2 suggestion-item" style="cursor:pointer;">
                    ${item.fullname}
                </div>
            `;
        });
        suggestions.innerHTML = html;

        // Clic => on met le "fullname" dans techInput et l'id dans techIdInput
        const items = suggestions.querySelectorAll('.suggestion-item');
        items.forEach((el, index) => {
            el.addEventListener('click', () => {
                const chosen = list[index];
                techInput.value   = chosen.fullname;
                techIdInput.value = chosen.id;    // IMPORTANT : on stocke l'id du WAPetGCTech
                suggestions.innerHTML = '';
                form.submit();
            });
        });
    }
});
</script>
@endsection
