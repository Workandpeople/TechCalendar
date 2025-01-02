@extends('layouts.app')

@section('content')
<!-- Content Wrapper -->
<div id="content-wrapper" class="d-flex flex-column">

    <!-- Main Content -->
    <div id="content">

        <!-- Topbar -->
        <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
            @include('partials/simpleTopbar')
        </nav>
        <!-- End of Topbar -->

        <!-- Begin Page Content -->
        <div class="container-fluid">
            <div class="row">
                <!-- Graphiques -->
                <div class="col-xl-8 col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Statistiques des Techniciens</h6>
                            <div class="form-inline">
                                <label for="dateFrom" class="mr-2">Du :</label>
                                <input type="date" id="dateFrom" class="form-control mr-2">
                                <label for="dateTo" class="mr-2">Au :</label>
                                <input type="date" id="dateTo" class="form-control">
                            </div>
                        </div>
                        <div class="card-body" style="height: 80vh">
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

                <!-- Liste des Techniciens -->
                <div class="col-xl-4 col-lg-4">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Liste des Techniciens</h6>
                            <input type="text" id="techSearch" class="form-control" placeholder="Rechercher un technicien">
                        </div>
                        <div class="card-body" style="height: 80vh; overflow-y: auto;">
                            <div id="technicianList" class="list-group">
                                <!-- Dynamique via JS -->
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const technicianList = document.getElementById('technicianList');
        const techSearch = document.getElementById('techSearch');
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');

        // Chargement initial des techniciens
        const loadTechnicians = (search = '') => {
            fetch(`/api/technicians?search=${encodeURIComponent(search)}`)
            .then(response => response.json())
            .then(data => {
                console.log('Fetched technicians:', data.technicians);
                technicianList.innerHTML = '';
                data.technicians.forEach(tech => {
                    if (tech.tech) { // Vérifie que la relation "tech" existe
                        const item = document.createElement('div');
                        item.className = 'list-group-item d-flex align-items-center';
                        item.innerHTML = `
                            <input type="checkbox" class="mr-3" value="${tech.tech.id}" id="tech_${tech.tech.id}">
                            <label for="tech_${tech.tech.id}" class="mb-0">${tech.prenom} ${tech.nom}</label>
                        `;
                        technicianList.appendChild(item);
                    }
                });
            })
            .catch(error => {
                console.error('Error fetching technicians:', error);
                alert('Erreur lors de la récupération des techniciens.');
            });
        };

        // Recherche dynamique
        techSearch.addEventListener('input', () => loadTechnicians(techSearch.value));

        // Mise à jour des graphiques
        const updateCharts = () => {
            const selectedTechIds = Array.from(technicianList.querySelectorAll('input:checked')).map(input => input.value);
            const from = dateFrom.value;
            const to = dateTo.value;

            fetch(`/api/technician-stats`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ techIds: selectedTechIds, from, to }),
            })
                .then(response => response.json())
                .then(data => {
                    updateChart(appointmentChart, data.appointments);
                    updateChart(distanceChart, data.distances);
                    updateChart(timeSpentChart, data.timeSpent);
                    updateChart(costChart, data.costs);
                });
        };

        // Initialisation des graphiques
        const createChart = (ctx, label) => new Chart(ctx, {
            type: 'bar',
            data: { labels: [], datasets: [{ label, data: [] }] },
            options: { responsive: true },
        });

        const appointmentChart = createChart(document.getElementById('appointmentChart'), 'Nombre de RDV');
        const distanceChart = createChart(document.getElementById('distanceChart'), 'Distance parcourue (km)');
        const timeSpentChart = createChart(document.getElementById('timeSpentChart'), 'Temps de trajet (h)');
        const costChart = createChart(document.getElementById('costChart'), 'Forfait kilométrique (€)');

        const updateChart = (chart, data) => {
            chart.data.labels = data.labels;
            chart.data.datasets[0].data = data.values;
            chart.update();
        };

        // Écoute des événements pour la mise à jour des graphiques
        [techSearch, dateFrom, dateTo].forEach(input => input.addEventListener('change', updateCharts));
        technicianList.addEventListener('change', updateCharts);

        // Chargement initial
        loadTechnicians();
        updateCharts();
    });
</script>
@endsection
@endsection