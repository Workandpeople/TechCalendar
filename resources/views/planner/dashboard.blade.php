<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Planning</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Dashboard planning</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Pilotage des placements existants et de la charge equipe.</p>
            </div>
            <a href="{{ route('planner.book') }}" class="gc-btn-primary self-start md:self-auto">Prendre un rdv</a>
        </div>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                @php
                    $tones = [
                        'blue' => ['#e0f2fe', '#2563eb'],
                        'green' => ['#dcfce7', '#15803d'],
                        'gold' => ['#fef3c7', '#b45309'],
                        'pink' => ['#ffe4e6', '#be123c'],
                    ];
                    [$toneBg, $toneText] = $tones[$stat['tone']] ?? ['var(--gc-accent-soft)', 'var(--gc-text)'];
                @endphp
                <article class="gc-card overflow-hidden p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">{{ $stat['label'] }}</p>
                            <p class="mt-3 text-3xl font-semibold" style="color:var(--gc-text);">{{ $stat['value'] }}</p>
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $stat['detail'] }}</p>
                        </div>
                        <span class="rounded-2xl px-3 py-2 text-xs font-semibold" style="background:{{ $toneBg }};color:{{ $toneText }};">Live</span>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">CRM simules</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV a placer</h2>
                    <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Clique sur une demande pour lancer directement la recherche de techniciens.</p>
                </div>
                <div class="flex flex-col items-start gap-2 md:items-end">
                    <span id="dashboard-crm-count" class="rounded-full px-3 py-1 text-sm" style="background:var(--gc-accent-soft);color:var(--gc-text);">{{ $crmAppointments->count() }} demande(s)</span>
                    <a href="{{ route('planner.book') }}" class="gc-link">Voir toutes les demandes</a>
                </div>
            </div>

            <div class="mb-4">
                <label class="gc-label" for="dashboard_crm_search">Recherche client</label>
                <input id="dashboard_crm_search" type="search" class="gc-input" placeholder="Nom ou prenom du client" autocomplete="off" />
            </div>

            <div id="dashboard-crm-empty" class="hidden rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                Aucun RDV CRM ne correspond a cette recherche.
            </div>

            <div id="dashboard-crm-grid" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($crmAppointments as $appointment)
                    <a
                        href="{{ route('planner.book', ['crm_appointment_id' => $appointment['id']]) }}"
                        class="dashboard-crm-card group rounded-2xl border p-4 transition hover:-translate-y-0.5 hover:shadow-md"
                        style="border-color:var(--gc-border);background:linear-gradient(135deg,#ffffff 0%,#fcf8ea 100%);"
                        data-client="{{ str($appointment['last_name'].' '.$appointment['first_name'])->lower() }}"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#e0f2fe;color:#1d4ed8;">{{ $appointment['source'] }}</span>
                            <span class="text-xs font-semibold transition group-hover:translate-x-0.5" style="color:var(--gc-primary);">Trouver</span>
                        </div>
                        <h3 class="mt-3 font-semibold" style="color:var(--gc-text);">{{ $appointment['last_name'] }} {{ $appointment['first_name'] }}</h3>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $appointment['phone'] }}</p>
                        <p class="mt-2 line-clamp-2 text-xs" style="color:var(--gc-text-soft);">{{ $appointment['address'] }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">Dept. {{ $appointment['department_code'] }}</span>
                            @if ($appointment['service'])
                                <span class="rounded-lg px-2 py-1 text-xs" style="background:#dcfce7;color:#15803d;">{{ $appointment['service']['type'] }}</span>
                            @else
                                <span class="rounded-lg px-2 py-1 text-xs" style="background:#fee2e2;color:#be123c;">Service a definir</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="grid grid-cols-1 gap-6">
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.35fr_1fr]">
                <div class="gc-card p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">Efficacite</p>
                            <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV places sur 6 semaines</h2>
                        </div>
                    </div>
                    <div class="h-72">
                        <canvas id="weekly-trend-chart"></canvas>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="gc-card p-5">
                        <p class="text-sm" style="color:var(--gc-text-soft);">Mix prestations</p>
                        <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Types planifies cette semaine</h2>
                        <div class="mt-4 h-64">
                            <canvas id="service-types-chart"></canvas>
                        </div>
                    </div>

                    <div class="gc-card p-5">
                        <p class="text-sm" style="color:var(--gc-text-soft);">Equipe planning</p>
                        <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Placements par assistante</h2>
                        <div class="mt-4 h-64">
                            <canvas id="planner-efficiency-chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        const plannerDashboardCharts = @json($charts);
        const dashboardCrmSearch = document.getElementById('dashboard_crm_search');
        const dashboardCrmCards = Array.from(document.querySelectorAll('.dashboard-crm-card'));
        const dashboardCrmCount = document.getElementById('dashboard-crm-count');
        const dashboardCrmEmpty = document.getElementById('dashboard-crm-empty');
        const pastelPalette = ['#bfdbfe', '#bbf7d0', '#fde68a', '#fecdd3', '#ddd6fe', '#bae6fd'];
        const pastelBorders = ['#60a5fa', '#4ade80', '#f59e0b', '#fb7185', '#a78bfa', '#38bdf8'];

        const filterDashboardCrmCards = () => {
            const query = dashboardCrmSearch.value.trim().toLowerCase();
            let visibleCount = 0;

            dashboardCrmCards.forEach((card) => {
                const isVisible = query === '' || card.dataset.client.includes(query);
                card.classList.toggle('hidden', !isVisible);
                if (isVisible) visibleCount++;
            });

            dashboardCrmCount.textContent = `${visibleCount} demande(s)`;
            dashboardCrmEmpty.classList.toggle('hidden', visibleCount > 0);
        };

        dashboardCrmSearch.addEventListener('input', filterDashboardCrmCards);

        const buildDataset = (items) => ({
            labels: items.map((item) => item.label),
            values: items.map((item) => item.value),
        });

        const baseOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#31424c',
                        font: { size: 12 },
                    },
                },
            },
            scales: {
                x: {
                    ticks: { color: '#6b7780' },
                    grid: { display: false },
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: '#6b7780', precision: 0 },
                    grid: { color: 'rgba(107,119,128,0.14)' },
                },
            },
        };

        const weeklyTrend = buildDataset(plannerDashboardCharts.weeklyTrend || []);
        new Chart(document.getElementById('weekly-trend-chart'), {
            type: 'bar',
            data: {
                labels: weeklyTrend.labels,
                datasets: [{
                    label: 'RDV places',
                    data: weeklyTrend.values,
                    backgroundColor: pastelPalette,
                    borderColor: pastelBorders,
                    borderWidth: 2,
                    borderRadius: 12,
                }],
            },
            options: baseOptions,
        });

        const serviceTypes = buildDataset(plannerDashboardCharts.serviceTypes || []);
        new Chart(document.getElementById('service-types-chart'), {
            type: 'doughnut',
            data: {
                labels: serviceTypes.labels.length ? serviceTypes.labels : ['Aucune donnee'],
                datasets: [{
                    data: serviceTypes.values.length ? serviceTypes.values : [1],
                    backgroundColor: pastelPalette,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#31424c' },
                    },
                },
            },
        });

        const plannerEfficiency = buildDataset(plannerDashboardCharts.plannerEfficiency || []);
        new Chart(document.getElementById('planner-efficiency-chart'), {
            type: 'bar',
            data: {
                labels: plannerEfficiency.labels.length ? plannerEfficiency.labels : ['Aucune donnee'],
                datasets: [{
                    label: 'RDV crees',
                    data: plannerEfficiency.values.length ? plannerEfficiency.values : [0],
                    backgroundColor: ['#fecdd3', '#ddd6fe', '#bae6fd', '#bbf7d0', '#fde68a'],
                    borderColor: ['#fb7185', '#a78bfa', '#38bdf8', '#4ade80', '#f59e0b'],
                    borderWidth: 2,
                    borderRadius: 10,
                }],
            },
            options: {
                ...baseOptions,
                indexAxis: 'y',
            },
        });
    </script>
</x-layouts.app>
