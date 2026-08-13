<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Admin</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Santé du site</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Monitoring applicatif, erreurs, jobs, disque, storage et configuration.</p>
            </div>
            <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center">
                <form method="POST" action="{{ route('admin.dashboard.health.run') }}">
                    @csrf
                    <button type="submit" class="gc-btn-primary">Relancer les checks</button>
                </form>
                <div class="rounded-xl px-4 py-3 text-sm font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                    Statut: {{ strtoupper($latestSnapshot->overall_status) }}
                </div>
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

        @if (session('status'))
            <div class="rounded-xl border px-4 py-3 text-sm" style="border-color:#bbf7d0;background:#f0fdf4;color:#15803d;">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border px-4 py-3 text-sm" style="border-color:#fecdd3;background:#fff1f2;color:#be123c;">
                {{ $errors->first() }}
            </div>
        @endif

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

        <section class="grid min-w-0 grid-cols-1 gap-6 xl:grid-cols-[1fr_1fr]">
            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Checks</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Dernière execution</h2>
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

            <article class="gc-card min-w-0 p-5">
                <div class="mb-4 flex min-w-0 flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm" style="color:var(--gc-text-soft);">Logs</p>
                        <h2 class="break-words text-lg font-semibold" style="color:var(--gc-text);">Dernières erreurs détectées</h2>
                    </div>
                    <form method="POST" action="{{ route('admin.dashboard.logs.clear') }}" class="shrink-0" onsubmit="return confirm('Vider les fichiers de logs applicatifs et l historique agrégé ?');">
                        @csrf
                        <button type="submit" class="gc-btn-danger">Vider les logs</button>
                    </form>
                </div>

                <div class="min-w-0 space-y-3">
                    @forelse ($recentErrors as $event)
                        <div class="min-w-0 rounded-xl border p-4" style="border-color:var(--gc-border);">
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-words text-sm font-semibold" style="color:var(--gc-text);overflow-wrap:anywhere;">{{ $event->source }} · {{ $event->severity }}</p>
                                    <p class="mt-2 line-clamp-3 break-words text-sm" style="color:var(--gc-text-soft);overflow-wrap:anywhere;">{{ $event->message }}</p>
                                    <p class="mt-2 break-words text-xs" style="color:var(--gc-text-soft);overflow-wrap:anywhere;">Dernière occurrence: {{ $event->last_seen_at?->diffForHumans() }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold" style="background:#ffe4e6;color:#be123c;">x{{ $event->occurrences }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucune erreur récente agrégée.</p>
                    @endforelse
                </div>
            </article>

            <article class="gc-card min-w-0 p-5 xl:col-span-2">
                <div class="mb-4 flex min-w-0 flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm" style="color:var(--gc-text-soft);">Coffrac</p>
                        <h2 class="break-words text-lg font-semibold" style="color:var(--gc-text);">Laravel log distant</h2>
                        <p class="mt-1 max-w-3xl break-words text-sm" style="color:var(--gc-text-soft);overflow-wrap:anywhere;">
                            Lecture sécurisée des dernières lignes du log Coffrac via l’API TechCalendar. Utile pour diagnostiquer les créations de dossiers et les passages en problème RDV.
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-end">
                        <div>
                            <label class="gc-label" for="coffrac_log_lines">Lignes</label>
                            <select id="coffrac_log_lines" class="gc-input">
                                <option value="100">100</option>
                                <option value="250" selected>250</option>
                                <option value="500">500</option>
                                <option value="1000">1000</option>
                            </select>
                        </div>
                        <button
                            id="coffrac_logs_load_btn"
                            type="button"
                            class="gc-btn-primary"
                            data-url="{{ route('admin.dashboard.coffrac.logs') }}"
                        >
                            Charger les logs Coffrac
                        </button>
                    </div>
                </div>

                <div id="coffrac_logs_status" class="mb-3 min-w-0 rounded-xl border px-4 py-3 text-sm" style="display:none;border-color:var(--gc-border);color:var(--gc-text-soft);overflow-wrap:anywhere;"></div>
                <pre id="coffrac_logs_output" class="max-h-[32rem] min-w-0 max-w-full overflow-auto rounded-xl border p-4 text-xs leading-relaxed" style="display:none;border-color:var(--gc-border);background:#111827;color:#f9fafb;white-space:pre-wrap;overflow-wrap:anywhere;"></pre>
            </article>
        </section>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Qualité</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Tests applicatifs</h2>
                    <p class="mt-1 max-w-3xl text-sm" style="color:var(--gc-text-soft);">
                        Les tests sont lancés en arrière-plan via la queue pour éviter de bloquer PHP-FPM. Sur production, garde ça pour les contrôles post-déploiement ou les diagnostics ponctuels.
                    </p>
                </div>

                <form method="POST" action="{{ route('admin.dashboard.tests.run') }}" class="flex flex-col gap-2 sm:flex-row sm:items-end" onsubmit="return confirm('Lancer les tests applicatifs en arrière-plan ?');">
                    @csrf
                    <div>
                        <label class="gc-label" for="admin_test_suite">Suite</label>
                        <select id="admin_test_suite" name="suite" class="gc-input" @disabled($activeTestRun)>
                            <option value="all">Tout</option>
                            <option value="feature">Feature</option>
                            <option value="unit">Unit</option>
                        </select>
                    </div>
                    <button type="submit" class="gc-btn-primary" @disabled($activeTestRun)>
                        {{ $activeTestRun ? 'Tests en cours' : 'Lancer les tests' }}
                    </button>
                </form>
            </div>

            @php
                $testStatusColors = [
                    'queued' => ['#dbeafe', '#1d4ed8', 'En attente'],
                    'running' => ['#fef3c7', '#b45309', 'En cours'],
                    'passed' => ['#dcfce7', '#15803d', 'OK'],
                    'failed' => ['#ffe4e6', '#be123c', 'Échec'],
                    'error' => ['#ffe4e6', '#be123c', 'Erreur'],
                ];
                $latestStatus = $latestTestRun?->status ?? 'queued';
                [$latestBg, $latestText, $latestLabel] = $testStatusColors[$latestStatus] ?? ['#f1f5f9', '#475569', strtoupper($latestStatus)];
            @endphp

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[0.85fr_1.15fr]">
                <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">Dernière exécution</p>
                            <p class="mt-2 text-xl font-semibold" style="color:var(--gc-text);">
                                {{ $latestTestRun ? strtoupper($latestTestRun->suite) : 'Aucune' }}
                            </p>
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">
                                @if ($latestTestRun)
                                    Lancée par {{ $latestTestRun->triggeredBy?->full_name ?? 'Admin' }}
                                    @if ($latestTestRun->finished_at)
                                        · terminée {{ $latestTestRun->finished_at->diffForHumans() }}
                                    @elseif ($latestTestRun->started_at)
                                        · démarrée {{ $latestTestRun->started_at->diffForHumans() }}
                                    @else
                                        · en attente
                                    @endif
                                @else
                                    Aucun lancement depuis le dashboard.
                                @endif
                            </p>
                        </div>
                        @if ($latestTestRun)
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:{{ $latestBg }};color:{{ $latestText }};">{{ $latestLabel }}</span>
                        @endif
                    </div>

                    @if ($latestTestRun?->error_message)
                        <p class="mt-3 rounded-lg border p-3 text-sm" style="border-color:#fecdd3;background:#fff1f2;color:#be123c;">{{ $latestTestRun->error_message }}</p>
                    @endif
                </div>

                <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="font-semibold" style="color:var(--gc-text);">Historique récent</p>
                        <p class="text-xs" style="color:var(--gc-text-soft);">Rafraîchis la page pour suivre une exécution en cours.</p>
                    </div>

                    <div class="space-y-2">
                        @forelse ($recentTestRuns as $run)
                            @php
                                [$statusBg, $statusText, $statusLabel] = $testStatusColors[$run->status] ?? ['#f1f5f9', '#475569', strtoupper($run->status)];
                            @endphp
                            <div class="flex flex-col gap-2 rounded-lg border px-3 py-2 sm:flex-row sm:items-center sm:justify-between" style="border-color:var(--gc-border);">
                                <div>
                                    <p class="text-sm font-semibold" style="color:var(--gc-text);">{{ strtoupper($run->suite) }} · {{ $run->created_at->format('d/m/Y H:i') }}</p>
                                    <p class="text-xs" style="color:var(--gc-text-soft);">
                                        {{ $run->finished_at ? 'Durée: '.$run->started_at?->diffInSeconds($run->finished_at).'s' : 'Non terminé' }}
                                        @if ($run->exit_code !== null)
                                            · code {{ $run->exit_code }}
                                        @endif
                                    </p>
                                </div>
                                <span class="w-fit rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $statusBg }};color:{{ $statusText }};">{{ $statusLabel }}</span>
                            </div>
                        @empty
                            <p class="rounded-lg border p-3 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucun test lancé depuis le dashboard.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            @if ($latestTestRun?->output)
                <details class="mt-4 rounded-xl border p-4" style="border-color:var(--gc-border);">
                    <summary class="cursor-pointer text-sm font-semibold" style="color:var(--gc-text);">Voir la sortie de la dernière exécution</summary>
                    <pre class="mt-3 max-h-96 overflow-auto rounded-lg p-3 text-xs" style="background:#111827;color:#f9fafb;">{{ $latestTestRun->output }}</pre>
                </details>
            @endif
        </section>
    </div>

    <script>
        (() => {
            const loadButton = document.getElementById('coffrac_logs_load_btn');
            const lineSelect = document.getElementById('coffrac_log_lines');
            const statusBox = document.getElementById('coffrac_logs_status');
            const outputBox = document.getElementById('coffrac_logs_output');

            if (!loadButton || !lineSelect || !statusBox || !outputBox) {
                return;
            }

            const setStatus = (message, tone = 'neutral') => {
                const colors = {
                    neutral: ['var(--gc-border)', 'var(--gc-text-soft)', '#ffffff'],
                    loading: ['#bfdbfe', '#1d4ed8', '#eff6ff'],
                    ok: ['#bbf7d0', '#15803d', '#f0fdf4'],
                    error: ['#fecdd3', '#be123c', '#fff1f2'],
                };
                const [border, text, background] = colors[tone] || colors.neutral;

                statusBox.style.display = 'block';
                statusBox.style.borderColor = border;
                statusBox.style.color = text;
                statusBox.style.background = background;
                statusBox.textContent = message;
            };

            loadButton.addEventListener('click', async () => {
                const url = new URL(loadButton.dataset.url, window.location.origin);
                url.searchParams.set('lines', lineSelect.value || '250');

                loadButton.disabled = true;
                outputBox.style.display = 'none';
                outputBox.textContent = '';
                setStatus('Chargement des logs Coffrac...', 'loading');

                try {
                    const response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                        },
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.result === false) {
                        throw new Error(payload.message || 'Impossible de récupérer les logs Coffrac.');
                    }

                    const data = payload.data || {};
                    const updatedAt = data.updated_at
                        ? new Date(data.updated_at).toLocaleString('fr-FR')
                        : 'date inconnue';
                    const size = Number(data.size_bytes || 0).toLocaleString('fr-FR');

                    setStatus(`${data.line_count || 0} ligne(s) chargée(s) depuis ${data.file || 'laravel.log'} · ${size} octets · mis à jour ${updatedAt}`, 'ok');
                    outputBox.textContent = data.text || 'Log Coffrac vide.';
                    outputBox.style.display = 'block';
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : 'Erreur inconnue pendant le chargement des logs Coffrac.', 'error');
                } finally {
                    loadButton.disabled = false;
                }
            });
        })();
    </script>

    @vite('resources/js/chart.js')
    <script>
        (() => {
            const initializeAdminHealthCharts = () => {
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
            };

            if (window.Chart) {
                initializeAdminHealthCharts();
                return;
            }

            window.addEventListener('techcalendar:charts-ready', initializeAdminHealthCharts, { once: true });
        })();
    </script>
</x-layouts.app>
