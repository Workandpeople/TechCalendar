<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color:var(--gc-text-soft);">Gerant</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Dashboard gerant</h1>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Efficacite planning, charge terrain et kilometres calcules puis caches en base.</p>
        </div>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                @php
                    $tones = [
                        'blue' => ['#dbeafe', '#1d4ed8'],
                        'green' => ['#dcfce7', '#15803d'],
                        'gold' => ['#fef3c7', '#b45309'],
                        'pink' => ['#ffe4e6', '#be123c'],
                    ];
                    [$toneBg, $toneText] = $tones[$stat['tone']] ?? ['var(--gc-accent-soft)', 'var(--gc-text)'];
                @endphp
                <article class="gc-card p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">{{ $stat['label'] }}</p>
                            <p class="mt-3 text-3xl font-semibold" style="color:var(--gc-text);">{{ $stat['value'] }}</p>
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $stat['detail'] }}</p>
                        </div>
                        <span class="rounded-2xl px-3 py-2 text-xs font-semibold" style="background:{{ $toneBg }};color:{{ $toneText }};">Semaine</span>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Assistantes planning</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Efficacite des placements</h2>
                </div>

                <div class="h-72">
                    <canvas id="planner-placements-chart"></canvas>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead style="color:var(--gc-text-soft);">
                            <tr>
                                <th class="py-2">Assistante</th>
                                <th class="py-2 text-right">RDV places</th>
                                <th class="py-2 text-right">Heures planifiees</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($plannerEfficiency as $planner)
                                <tr class="border-t" style="border-color:var(--gc-border);">
                                    <td class="py-3 font-medium" style="color:var(--gc-text);">{{ $planner['name'] }}</td>
                                    <td class="py-3 text-right">{{ $planner['appointments_count'] }}</td>
                                    <td class="py-3 text-right">{{ $planner['planned_hours'] }}h</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="py-4 text-sm" style="color:var(--gc-text-soft);">Aucun placement cette semaine.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Techniciens</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Kilometres terrain par jour</h2>
                </div>

                <div class="h-72">
                    <canvas id="daily-kilometers-chart"></canvas>
                </div>

                <p class="mt-4 rounded-xl border px-4 py-3 text-sm" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text-soft);">
                    Calcul cache: domicile -> premier RDV -> autres RDV de la journee -> retour domicile.
                </p>
            </article>
        </section>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Performance terrain</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Techniciens les plus charges en kilometres</h2>
                </div>
                <span class="rounded-full px-3 py-1 text-sm" style="background:#e0f2fe;color:#1d4ed8;">Cache BDD</span>
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1.1fr]">
                <div class="h-80">
                    <canvas id="technician-kilometers-chart"></canvas>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead style="color:var(--gc-text-soft);">
                            <tr>
                                <th class="py-2">Technicien</th>
                                <th class="py-2 text-right">RDV</th>
                                <th class="py-2 text-right">Km</th>
                                <th class="py-2 text-right">Km/RDV</th>
                                <th class="py-2 text-right">Route</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($technicianEfficiency->take(10) as $technician)
                                <tr class="border-t" style="border-color:var(--gc-border);">
                                    <td class="py-3 font-medium" style="color:var(--gc-text);">
                                        {{ $technician['name'] }} <span style="color:var(--gc-text-soft);">({{ $technician['department_code'] }})</span>
                                    </td>
                                    <td class="py-3 text-right">{{ $technician['appointment_count'] }}</td>
                                    <td class="py-3 text-right">{{ $technician['drive_distance_km'] }} km</td>
                                    <td class="py-3 text-right">{{ $technician['km_per_appointment'] }}</td>
                                    <td class="py-3 text-right">{{ $technician['drive_duration_hours'] }}h</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-sm" style="color:var(--gc-text-soft);">Aucune metrique terrain disponible cette semaine.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        const managerDashboardCharts = @json($charts);
        const pastelPalette = ['#bfdbfe', '#bbf7d0', '#fde68a', '#fecdd3', '#ddd6fe', '#bae6fd', '#fed7aa', '#c7d2fe'];
        const pastelBorders = ['#60a5fa', '#4ade80', '#f59e0b', '#fb7185', '#a78bfa', '#38bdf8', '#fb923c', '#818cf8'];

        const chartValues = (items) => ({
            labels: items.map((item) => item.label),
            values: items.map((item) => item.value),
        });

        const axisOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#31424c' },
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

        const plannerPlacements = chartValues(managerDashboardCharts.plannerPlacements || []);
        new Chart(document.getElementById('planner-placements-chart'), {
            type: 'bar',
            data: {
                labels: plannerPlacements.labels.length ? plannerPlacements.labels : ['Aucune donnee'],
                datasets: [{
                    label: 'RDV places',
                    data: plannerPlacements.values.length ? plannerPlacements.values : [0],
                    backgroundColor: pastelPalette,
                    borderColor: pastelBorders,
                    borderWidth: 2,
                    borderRadius: 12,
                }],
            },
            options: axisOptions,
        });

        const dailyKilometers = chartValues(managerDashboardCharts.dailyKilometers || []);
        new Chart(document.getElementById('daily-kilometers-chart'), {
            type: 'line',
            data: {
                labels: dailyKilometers.labels,
                datasets: [{
                    label: 'Km parcourus',
                    data: dailyKilometers.values,
                    fill: true,
                    tension: 0.35,
                    backgroundColor: 'rgba(191,219,254,0.45)',
                    borderColor: '#60a5fa',
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#60a5fa',
                    pointBorderWidth: 2,
                }],
            },
            options: axisOptions,
        });

        const technicianKilometers = chartValues(managerDashboardCharts.technicianKilometers || []);
        new Chart(document.getElementById('technician-kilometers-chart'), {
            type: 'bar',
            data: {
                labels: technicianKilometers.labels.length ? technicianKilometers.labels : ['Aucune donnee'],
                datasets: [{
                    label: 'Km semaine',
                    data: technicianKilometers.values.length ? technicianKilometers.values : [0],
                    backgroundColor: pastelPalette,
                    borderColor: pastelBorders,
                    borderWidth: 2,
                    borderRadius: 10,
                }],
            },
            options: {
                ...axisOptions,
                indexAxis: 'y',
            },
        });
    </script>
</x-layouts.app>
