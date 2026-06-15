<x-layouts.app>
    <div class="space-y-6" data-manager-dashboard data-data-url="{{ $dataUrl }}" data-refresh-url="{{ $refreshUrl }}">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Gérant</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Dashboard gérant</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                    Efficacité planning, chargé terrain et kilomètres calculés en tâche de fond puis cachés en base.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span id="manager-dashboard-generated-at" class="text-sm" style="color:var(--gc-text-soft);"></span>
                <button type="button" id="manager-dashboard-refresh" class="gc-btn-secondary">
                    Recalculer
                </button>
            </div>
        </div>

        <section id="manager-dashboard-loader" class="gc-card p-5">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-sm font-semibold" style="color:var(--gc-text);">Calcul des widgets</p>
                    <p id="manager-dashboard-loader-message" class="mt-1 text-sm" style="color:var(--gc-text-soft);">
                        Préparation du job de calcul...
                    </p>
                </div>
                <span id="manager-dashboard-progress-label" class="rounded-full px-3 py-1 text-sm font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                    0%
                </span>
            </div>

            <div class="mt-5 h-3 overflow-hidden rounded-full" style="background:var(--gc-accent-soft);">
                <div id="manager-dashboard-progress-bar" class="h-full rounded-full transition-all duration-500" style="width:0%;background:linear-gradient(90deg,#bfdbfe,#bbf7d0,#fde68a);"></div>
            </div>
        </section>

        <section id="manager-dashboard-error" class="hidden rounded-2xl border p-5" style="border-color:#fecaca;background:#fff1f2;color:#991b1b;">
            <p class="font-semibold">Le calcul du dashboard a échoué.</p>
            <p id="manager-dashboard-error-message" class="mt-1 text-sm"></p>
        </section>

        <section id="manager-dashboard-stats" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5"></section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Assistantes planning</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Efficacité des placements</h2>
                </div>

                <div class="h-72">
                    <canvas id="planner-placements-chart"></canvas>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead style="color:var(--gc-text-soft);">
                            <tr>
                                <th class="py-2">Assistante</th>
                                <th class="py-2 text-right">RDV placés</th>
                                <th class="py-2 text-right">Heures planifiées</th>
                            </tr>
                        </thead>
                        <tbody id="planner-efficiency-table"></tbody>
                    </table>
                </div>
            </article>

            <article class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Techniciens</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Kilomètres terrain par jour</h2>
                </div>

                <div class="h-72">
                    <canvas id="daily-kilometers-chart"></canvas>
                </div>

                <p class="mt-4 rounded-xl border px-4 py-3 text-sm" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text-soft);">
                    Calcul caché: domicile -> premier RDV -> autres RDV de la journée -> retour domicile.
                </p>
            </article>
        </section>

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Performance terrain</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Techniciens les plus chargés en kilomètres</h2>
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
                                <th class="py-2 text-right">Supp.</th>
                            </tr>
                        </thead>
                        <tbody id="technician-efficiency-table"></tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        (() => {
            const root = document.querySelector('[data-manager-dashboard]');
            if (!root) {
                return;
            }

            const dataUrl = root.dataset.dataUrl;
            const refreshUrl = root.dataset.refreshUrl;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const pastelPalette = ['#bfdbfe', '#bbf7d0', '#fde68a', '#fecdd3', '#ddd6fe', '#bae6fd', '#fed7aa', '#c7d2fe'];
            const pastelBorders = ['#60a5fa', '#4ade80', '#f59e0b', '#fb7185', '#a78bfa', '#38bdf8', '#fb923c', '#818cf8'];
            const toneMap = {
                blue: ['#dbeafe', '#1d4ed8'],
                green: ['#dcfce7', '#15803d'],
                gold: ['#fef3c7', '#b45309'],
                pink: ['#ffe4e6', '#be123c'],
                orange: ['#ffedd5', '#c2410c'],
            };
            const charts = {};

            const loader = document.getElementById('manager-dashboard-loader');
            const loaderMessage = document.getElementById('manager-dashboard-loader-message');
            const progressBar = document.getElementById('manager-dashboard-progress-bar');
            const progressLabel = document.getElementById('manager-dashboard-progress-label');
            const errorBox = document.getElementById('manager-dashboard-error');
            const errorMessage = document.getElementById('manager-dashboard-error-message');
            const refreshButton = document.getElementById('manager-dashboard-refresh');
            const generatedAt = document.getElementById('manager-dashboard-generated-at');

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[char]));

            const setProgress = (run) => {
                const progress = Math.max(0, Math.min(100, Number(run.progress || 0)));
                progressBar.style.width = `${progress}%`;
                progressLabel.textContent = `${progress}%`;

                if (run.total_steps > 0) {
                    loaderMessage.textContent = `${run.processed_steps} / ${run.total_steps} jour(s) technicien calculés...`;
                    return;
                }

                loaderMessage.textContent = run.status === 'pending'
                    ? 'Job en file d attente...'
                    : 'Initialisation du calcul...';
            };

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

            const destroyChart = (key) => {
                if (charts[key]) {
                    charts[key].destroy();
                    delete charts[key];
                }
            };

            const renderStats = (stats) => {
                document.getElementById('manager-dashboard-stats').innerHTML = stats.map((stat) => {
                    const [toneBg, toneText] = toneMap[stat.tone] || ['var(--gc-accent-soft)', 'var(--gc-text)'];

                    return `
                        <article class="gc-card p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm" style="color:var(--gc-text-soft);">${escapeHtml(stat.label)}</p>
                                    <p class="mt-3 text-3xl font-semibold" style="color:var(--gc-text);">${escapeHtml(stat.value)}</p>
                                    <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">${escapeHtml(stat.detail)}</p>
                                </div>
                                <span class="rounded-2xl px-3 py-2 text-xs font-semibold" style="background:${toneBg};color:${toneText};">Semaine</span>
                            </div>
                        </article>
                    `;
                }).join('');
            };

            const renderTables = (payload) => {
                const plannerRows = payload.plannerEfficiency || [];
                document.getElementById('planner-efficiency-table').innerHTML = plannerRows.length
                    ? plannerRows.map((planner) => `
                        <tr class="border-t" style="border-color:var(--gc-border);">
                            <td class="py-3 font-medium" style="color:var(--gc-text);">${escapeHtml(planner.name)}</td>
                            <td class="py-3 text-right">${escapeHtml(planner.appointments_count)}</td>
                            <td class="py-3 text-right">${escapeHtml(planner.planned_hours)}h</td>
                        </tr>
                    `).join('')
                    : '<tr><td colspan="3" class="py-4 text-sm" style="color:var(--gc-text-soft);">Aucun placement cette semaine.</td></tr>';

                const technicianRows = (payload.technicianEfficiency || []).slice(0, 10);
                document.getElementById('technician-efficiency-table').innerHTML = technicianRows.length
                    ? technicianRows.map((technician) => `
                        <tr class="border-t" style="border-color:var(--gc-border);">
                            <td class="py-3 font-medium" style="color:var(--gc-text);">
                                ${escapeHtml(technician.name)} <span style="color:var(--gc-text-soft);">(${escapeHtml(technician.department_code)})</span>
                            </td>
                            <td class="py-3 text-right">${escapeHtml(technician.appointment_count)}</td>
                            <td class="py-3 text-right">${escapeHtml(technician.drive_distance_km)} km</td>
                            <td class="py-3 text-right">${escapeHtml(technician.km_per_appointment)}</td>
                            <td class="py-3 text-right">${escapeHtml(technician.drive_duration_hours)}h</td>
                            <td class="py-3 text-right">${escapeHtml(technician.overtime_hours)}h</td>
                        </tr>
                    `).join('')
                    : '<tr><td colspan="6" class="py-4 text-sm" style="color:var(--gc-text-soft);">Aucune metrique terrain disponible cette semaine.</td></tr>';
            };

            const renderCharts = (payload) => {
                const chartData = payload.charts || {};
                const plannerPlacements = chartValues(chartData.plannerPlacements || []);
                const dailyKilometers = chartValues(chartData.dailyKilometers || []);
                const technicianKilometers = chartValues(chartData.technicianKilometers || []);

                destroyChart('planner');
                charts.planner = new Chart(document.getElementById('planner-placements-chart'), {
                    type: 'bar',
                    data: {
                        labels: plannerPlacements.labels.length ? plannerPlacements.labels : ['Aucune donnee'],
                        datasets: [{
                            label: 'RDV placés',
                            data: plannerPlacements.values.length ? plannerPlacements.values : [0],
                            backgroundColor: pastelPalette,
                            borderColor: pastelBorders,
                            borderWidth: 2,
                            borderRadius: 12,
                        }],
                    },
                    options: axisOptions,
                });

                destroyChart('daily');
                charts.daily = new Chart(document.getElementById('daily-kilometers-chart'), {
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

                destroyChart('technician');
                charts.technician = new Chart(document.getElementById('technician-kilometers-chart'), {
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
            };

            const renderDashboard = (payload, run) => {
                renderStats(payload.stats || []);
                renderTables(payload);
                renderCharts(payload);

                loader.classList.add('hidden');
                errorBox.classList.add('hidden');
                refreshButton.disabled = false;
                generatedAt.textContent = run.generated_at
                    ? `Mis à jour le ${new Date(run.generated_at).toLocaleString('fr-FR')}`
                    : '';
            };

            const fetchRun = async (url, options = {}) => {
                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        ...(options.headers || {}),
                    },
                    ...options,
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json();
            };

            const poll = async () => {
                const run = await fetchRun(dataUrl);

                if (run.status === 'completed' && run.result) {
                    renderDashboard(run.result, run);
                    return;
                }

                if (run.status === 'failed') {
                    loader.classList.add('hidden');
                    errorBox.classList.remove('hidden');
                    errorMessage.textContent = run.error_message || 'Erreur inconnue.';
                    refreshButton.disabled = false;
                    return;
                }

                loader.classList.remove('hidden');
                errorBox.classList.add('hidden');
                refreshButton.disabled = true;
                setProgress(run);
                window.setTimeout(poll, 1500);
            };

            refreshButton?.addEventListener('click', async () => {
                refreshButton.disabled = true;
                loader.classList.remove('hidden');
                errorBox.classList.add('hidden');
                setProgress({ status: 'pending', progress: 0, processed_steps: 0, total_steps: 0 });

                try {
                    await fetchRun(refreshUrl, { method: 'POST' });
                    await poll();
                } catch (error) {
                    loader.classList.add('hidden');
                    errorBox.classList.remove('hidden');
                    errorMessage.textContent = error.message;
                    refreshButton.disabled = false;
                }
            });

            poll().catch((error) => {
                loader.classList.add('hidden');
                errorBox.classList.remove('hidden');
                errorMessage.textContent = error.message;
            });
        })();
    </script>
</x-layouts.app>
