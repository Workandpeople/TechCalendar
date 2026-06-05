<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Admin</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Sante du site</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Monitoring applicatif, erreurs, jobs, disque, storage et configuration.</p>
            </div>
            <div class="rounded-xl px-4 py-3 text-sm font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                Statut: {{ strtoupper($latestSnapshot->overall_status) }}
            </div>
        </div>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                @php
                    $tones = [
                        'ok' => ['#dcfce7', '#15803d'],
                        'warn' => ['#fef3c7', '#b45309'],
                        'fail' => ['#ffe4e6', '#be123c'],
                    ];
                    [$toneBg, $toneText] = $tones[$stat['tone']] ?? ['#dbeafe', '#1d4ed8'];
                @endphp
                <article class="gc-card p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">{{ $stat['label'] }}</p>
                            <p class="mt-3 text-3xl font-semibold" style="color:var(--gc-text);">{{ $stat['value'] }}</p>
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $stat['detail'] }}</p>
                        </div>
                        <span class="rounded-2xl px-3 py-2 text-xs font-semibold" style="background:{{ $toneBg }};color:{{ $toneText }};">{{ strtoupper($stat['tone']) }}</span>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Historique</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Score des derniers checks</h2>
                </div>
                <div class="h-72">
                    <canvas id="admin-health-score-chart"></canvas>
                </div>
            </article>

            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Dernier snapshot</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Repartition des checks</h2>
                </div>
                <div class="h-72">
                    <canvas id="admin-health-status-chart"></canvas>
                </div>
            </article>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1fr]">
            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Checks</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Derniere execution</h2>
                </div>

                <div class="space-y-3">
                    @foreach ($checks as $check)
                        @php
                            $colors = [
                                'ok' => ['#dcfce7', '#15803d'],
                                'warn' => ['#fef3c7', '#b45309'],
                                'fail' => ['#ffe4e6', '#be123c'],
                            ];
                            [$bg, $text] = $colors[$check->status] ?? ['#dbeafe', '#1d4ed8'];
                        @endphp
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold" style="color:var(--gc-text);">{{ $check->label }}</p>
                                    <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $check->message }}</p>
                                    <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">{{ $check->duration_ms }} ms · {{ $check->value }}</p>
                                </div>
                                <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $bg }};color:{{ $text }};">{{ strtoupper($check->status) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Logs</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Dernieres erreurs detectees</h2>
                </div>

                <div class="space-y-3">
                    @forelse ($recentErrors as $event)
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold" style="color:var(--gc-text);">{{ $event->source }} · {{ $event->severity }}</p>
                                    <p class="mt-2 line-clamp-3 text-sm" style="color:var(--gc-text-soft);">{{ $event->message }}</p>
                                    <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">Derniere occurrence: {{ $event->last_seen_at?->diffForHumans() }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold" style="background:#ffe4e6;color:#be123c;">x{{ $event->occurrences }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucune erreur recente agregee.</p>
                    @endforelse
                </div>
            </article>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        const adminHealthCharts = @json($charts);

        const scoreTrend = adminHealthCharts.scoreTrend || [];
        new Chart(document.getElementById('admin-health-score-chart'), {
            type: 'line',
            data: {
                labels: scoreTrend.map((item) => item.label),
                datasets: [{
                    label: 'Score',
                    data: scoreTrend.map((item) => item.value),
                    fill: true,
                    tension: 0.35,
                    backgroundColor: 'rgba(191,219,254,0.45)',
                    borderColor: '#60a5fa',
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#60a5fa',
                    pointBorderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: '#6b7780' }, grid: { display: false } },
                    y: { min: 0, max: 100, ticks: { color: '#6b7780' }, grid: { color: 'rgba(107,119,128,0.14)' } },
                },
            },
        });

        const statusSplit = adminHealthCharts.statusSplit || [];
        new Chart(document.getElementById('admin-health-status-chart'), {
            type: 'doughnut',
            data: {
                labels: statusSplit.map((item) => item.label),
                datasets: [{
                    data: statusSplit.map((item) => item.value),
                    backgroundColor: ['#bbf7d0', '#fde68a', '#fecdd3'],
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
    </script>
</x-layouts.app>
