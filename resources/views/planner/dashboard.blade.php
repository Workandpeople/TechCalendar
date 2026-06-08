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
        const pastelPalette = ['#bfdbfe', '#bbf7d0', '#fde68a', '#fecdd3', '#ddd6fe', '#bae6fd'];
        const pastelBorders = ['#60a5fa', '#4ade80', '#f59e0b', '#fb7185', '#a78bfa', '#38bdf8'];

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
