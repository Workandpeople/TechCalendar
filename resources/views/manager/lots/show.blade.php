<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <a href="{{ route('manager.lots') }}" class="gc-link">Retour à la gestion des lots</a>
                <p class="mt-3 text-sm" style="color:var(--gc-text-soft);">Gérant · Lot</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['title'] }}</h1>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:{{ $lot['status_background'] }};color:{{ $lot['status_color'] }};">
                        {{ $lot['status_label'] }}
                    </span>
                    @if ($lot['global_plus'])
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:#fef3c7;color:#b45309;">Global +</span>
                    @endif
                </div>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                    {{ $lot['type_label'] }}
                    @if ($lot['delegataire'])
                        · Délégataire : {{ $lot['delegataire'] }}
                    @endif
                    @if ($lot['imported_at'])
                        · Importé le {{ $lot['imported_at']->format('d/m/Y H:i') }}
                    @endif
                </p>
            </div>

            @if ($lot['can_download_original_file'])
                <a href="{{ $lot['download_url'] }}" class="gc-btn-soft justify-center">
                    Télécharger le fichier source
                </a>
            @endif
        </div>

        <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Dossiers</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['appointments_count'] }}</p>
                @if (($lot['stats_excluded_count'] ?? 0) > 0)
                    <p class="mt-1 text-xs" style="color:#991b1b;">{{ $lot['stats_excluded_count'] }} hors statistiques</p>
                @endif
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">À traiter</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['placeable_count'] }}</p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">RDV physiques</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['placed_count'] }}</p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Contacts traités</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['contact_processed_count'] }}</p>
            </article>
        </section>

        @php
            $chartItems = collect([
                $lot['auto_completion']['physical'] ?? null,
                $lot['auto_completion']['contact'] ?? null,
            ])->filter()->values();

            if ($chartItems->isEmpty()) {
                $chartItems = collect([$lot['auto_completion']]);
            }
        @endphp

        <section class="grid grid-cols-1 gap-4 {{ $chartItems->count() > 1 ? 'xl:grid-cols-2' : '' }}">
            @foreach ($chartItems as $chart)
                @php
                    $percentage = (int) ($chart['percentage'] ?? 0);
                @endphp
                <article class="gc-card p-5">
                    <div class="flex flex-col gap-5 md:flex-row md:items-center">
                        <div class="lot-chart-ring shrink-0" style="--value:{{ $percentage }};">
                            <span>{{ $percentage }}%</span>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">{{ $chart['label'] ?? 'Lot' }}</p>
                            <h2 class="mt-1 text-lg font-semibold" style="color:var(--gc-text);">{{ $chart['detail'] ?? 'Suivi du lot' }}</h2>
                            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                                {{ $chart['completed_count'] ?? 0 }} dossier(s) terminé(s)
                                @if (! empty($chart['is_sampling']))
                                    sur un objectif de {{ $chart['target_count'] ?? 0 }}.
                                @else
                                    sur {{ $chart['total_count'] ?? $lot['appointments_count'] }}.
                                @endif
                            </p>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="gc-card overflow-hidden">
            <div class="border-b p-5" style="border-color:var(--gc-border);">
                <p class="text-sm" style="color:var(--gc-text-soft);">Suivi du lot</p>
                <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Dossiers du fichier</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y text-sm" style="border-color:var(--gc-border);">
                    <thead style="background:#fbfaf6;color:var(--gc-text-soft);">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Ligne</th>
                            <th class="px-4 py-3 text-left font-semibold">Client / site</th>
                            <th class="px-4 py-3 text-left font-semibold">Contact</th>
                            <th class="px-4 py-3 text-left font-semibold">Adresse</th>
                            <th class="px-4 py-3 text-left font-semibold">Traitement</th>
                            <th class="px-4 py-3 text-left font-semibold">Résultat</th>
                            <th class="px-4 py-3 text-left font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y" style="border-color:var(--gc-border);">
                        @if ($lot['appointments']->isEmpty())
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center" style="color:var(--gc-text-soft);">
                                    Aucun dossier dans ce lot.
                                </td>
                            </tr>
                        @else
                            @foreach ($lot['appointments'] as $appointment)
                            @php
                                $clientLabel = $appointment['company_name'] ?: $appointment['customer_name'];
                                $siteLabel = $appointment['site_name'] ?: null;
                                $postalCity = trim(implode(' ', array_filter([$appointment['postal_code'] ?? null, $appointment['city'] ?? null])));
                                $fullAddress = trim((string) ($appointment['address'] ?? ''));

                                if ($postalCity !== '' && ! str_contains(mb_strtolower($fullAddress), mb_strtolower($postalCity))) {
                                    $fullAddress = trim($fullAddress.', '.$postalCity, ' ,');
                                }

                                $resultLabel = match (true) {
                                    $appointment['excluded_from_lot_stats'] => 'Sorti des statistiques',
                                    $appointment['contact_satisfaction'] === true => 'Contact satisfaisant',
                                    $appointment['contact_satisfaction'] === false => 'Contact non satisfaisant',
                                    $appointment['physical_satisfaction'] === true => 'Physique satisfaisant',
                                    $appointment['physical_satisfaction'] === false => 'Physique non satisfaisant',
                                    $appointment['is_placed'] => 'RDV physique placé',
                                    $appointment['is_contact_processed'] => 'Contact traité',
                                    default => 'En attente',
                                };
                            @endphp
                            <tr @class(['opacity-60' => $appointment['excluded_from_lot_stats']])>
                                <td class="whitespace-nowrap px-4 py-3" style="color:var(--gc-text-soft);">{{ $appointment['row_number'] ?: '-' }}</td>
                                <td class="min-w-[220px] px-4 py-3">
                                    <p class="font-semibold" style="color:var(--gc-text);">{{ $clientLabel ?: 'Client à qualifier' }}</p>
                                    @if ($siteLabel)
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Site : {{ $siteLabel }}</p>
                                    @endif
                                    @if ($appointment['external_reference'])
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Réf. {{ $appointment['external_reference'] }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3" style="color:var(--gc-text-soft);">{{ $appointment['customer_phone'] ?: '-' }}</td>
                                <td class="min-w-[280px] px-4 py-3" style="color:var(--gc-text);">{{ $fullAddress !== '' ? $fullAddress : 'Adresse à qualifier' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                                        {{ $appointment['status_label'] }}
                                    </span>
                                    @if ($appointment['excluded_from_lot_stats'])
                                        <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold" style="background:#fee2e2;color:#991b1b;">
                                            Hors stats
                                        </span>
                                    @endif
                                    @if ($appointment['placed_technician_name'])
                                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $appointment['placed_technician_name'] }}</p>
                                    @endif
                                </td>
                                <td class="min-w-[180px] px-4 py-3" style="color:var(--gc-text);">
                                    {{ $resultLabel }}
                                    @if ($appointment['is_placed'])
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);" data-lot-appointment-portes="{{ $appointment['id'] }}">
                                            Portes : {{ $appointment['unsuccessful_visits_count'] ?? 0 }}
                                        </p>
                                    @endif
                                    @if ($appointment['contact_processed_at'])
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">
                                            {{ $appointment['contact_processed_at']->format('d/m/Y H:i') }}
                                            @if ($appointment['contact_processed_by_name'])
                                                · {{ $appointment['contact_processed_by_name'] }}
                                            @endif
                                        </p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <button
                                        type="button"
                                        class="lot-appointment-detail-trigger inline-flex h-9 w-9 items-center justify-center rounded-xl border transition hover:shadow-sm"
                                        style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);"
                                        data-lot-appointment-id="{{ $appointment['id'] }}"
                                        title="Voir le détail"
                                        aria-label="Voir le détail du dossier"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M2.75 12s3.25-6.25 9.25-6.25S21.25 12 21.25 12 18 18.25 12 18.25 2.75 12 2.75 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M12 14.75A2.75 2.75 0 1 0 12 9.25a2.75 2.75 0 0 0 0 5.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        @foreach ($lot['appointments'] as $appointment)
            <script type="application/json" data-lot-appointment-json="{{ $appointment['id'] }}">
                @json($appointment)
            </script>
        @endforeach
    </div>

    <div id="lot-physical-detail-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
        <div class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Détail physique</p>
                    <h2 id="lot-physical-detail-title" class="text-xl font-semibold" style="color:var(--gc-text);"></h2>
                    <p id="lot-physical-detail-subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>
                </div>
                <button id="lot-physical-detail-close" type="button" class="gc-link">Fermer</button>
            </div>

            <div class="grid min-h-0 grid-cols-1 gap-5 overflow-y-auto p-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);">
                    <h3 class="font-semibold" style="color:var(--gc-text);">Informations du RDV</h3>
                    <dl id="lot-physical-detail-infos" class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2"></dl>
                </section>

                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                    <form id="lot-physical-visits-form" class="space-y-3">
                        <div>
                            <label class="gc-label" for="lot_physical_unsuccessful_visits_count">Nombre de portes</label>
                            <input id="lot_physical_unsuccessful_visits_count" type="number" min="0" max="65535" step="1" class="gc-input" />
                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Nombre de déplacements effectués sans aboutir.</p>
                        </div>
                        <p id="lot-physical-visits-status" class="hidden text-sm"></p>
                        <button id="lot-physical-visits-submit" type="submit" class="gc-btn-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-50">
                            Enregistrer les portes
                        </button>
                    </form>

                    <div id="lot-physical-tracking-link-wrap" class="mt-4 hidden">
                        <a id="lot-physical-tracking-link" href="#" class="gc-btn-soft w-full justify-center">Voir dans la gestion des RDV</a>
                    </div>

                    <div class="mt-4 border-t pt-4" style="border-color:var(--gc-border);">
                        <h3 class="font-semibold" style="color:var(--gc-text);">Statistiques du lot</h3>
                        <p id="lot-physical-stats-exclusion-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                        <button id="lot-physical-stats-exclusion-toggle" type="button" class="gc-btn-soft mt-3 w-full justify-center">
                            Sortir des stats du lot
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div id="lot-contact-detail-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Détail contact</p>
                    <h2 id="lot-contact-detail-title" class="text-xl font-semibold" style="color:var(--gc-text);"></h2>
                    <p id="lot-contact-detail-subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>
                </div>
                <button id="lot-contact-detail-close" type="button" class="gc-link">Fermer</button>
            </div>

            <div class="space-y-4 overflow-y-auto p-5">
                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);">
                    <h3 class="font-semibold" style="color:var(--gc-text);">Informations du contact</h3>
                    <dl id="lot-contact-detail-infos" class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2"></dl>
                </section>

                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#f0f9ff;">
                    <h3 class="font-semibold" style="color:var(--gc-text);">Commentaire</h3>
                    <p id="lot-contact-detail-comment" class="mt-2 whitespace-pre-line text-sm" style="color:var(--gc-text);"></p>
                </section>

                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                    <h3 class="font-semibold" style="color:var(--gc-text);">Statistiques du lot</h3>
                    <p id="lot-contact-stats-exclusion-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                    <button id="lot-contact-stats-exclusion-toggle" type="button" class="gc-btn-soft mt-3 justify-center">
                        Sortir des stats du lot
                    </button>
                </section>
            </div>
        </div>
    </div>

    <script>
        const lotDetailCsrfToken = @json(csrf_token());
        const lotAppointmentDetails = new Map();
        let currentPhysicalLotAppointment = null;
        let currentContactLotAppointment = null;

        document.querySelectorAll('[data-lot-appointment-json]').forEach((script) => {
            try {
                const appointment = JSON.parse(script.textContent || '{}');

                if (appointment.id) {
                    lotAppointmentDetails.set(String(appointment.id), appointment);
                }
            } catch (error) {
                // Une ligne mal sérialisée ne doit pas bloquer le tableau complet.
            }
        });

        const physicalModal = document.getElementById('lot-physical-detail-modal');
        const physicalClose = document.getElementById('lot-physical-detail-close');
        const physicalTitle = document.getElementById('lot-physical-detail-title');
        const physicalSubtitle = document.getElementById('lot-physical-detail-subtitle');
        const physicalInfos = document.getElementById('lot-physical-detail-infos');
        const physicalVisitsForm = document.getElementById('lot-physical-visits-form');
        const physicalVisitsInput = document.getElementById('lot_physical_unsuccessful_visits_count');
        const physicalVisitsSubmit = document.getElementById('lot-physical-visits-submit');
        const physicalVisitsStatus = document.getElementById('lot-physical-visits-status');
        const physicalTrackingWrap = document.getElementById('lot-physical-tracking-link-wrap');
        const physicalTrackingLink = document.getElementById('lot-physical-tracking-link');
        const physicalStatsExclusionStatus = document.getElementById('lot-physical-stats-exclusion-status');
        const physicalStatsExclusionToggle = document.getElementById('lot-physical-stats-exclusion-toggle');

        const contactModal = document.getElementById('lot-contact-detail-modal');
        const contactClose = document.getElementById('lot-contact-detail-close');
        const contactTitle = document.getElementById('lot-contact-detail-title');
        const contactSubtitle = document.getElementById('lot-contact-detail-subtitle');
        const contactInfos = document.getElementById('lot-contact-detail-infos');
        const contactComment = document.getElementById('lot-contact-detail-comment');
        const contactStatsExclusionStatus = document.getElementById('lot-contact-stats-exclusion-status');
        const contactStatsExclusionToggle = document.getElementById('lot-contact-stats-exclusion-toggle');

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function displayValue(value, fallback = '-') {
            return value === null || value === undefined || value === '' ? fallback : value;
        }

        function formatDateTime(value) {
            if (!value) {
                return '-';
            }

            const date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return String(value);
            }

            return new Intl.DateTimeFormat('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }).format(date);
        }

        function fullAddress(appointment) {
            const postalCity = [appointment.postal_code, appointment.city].filter(Boolean).join(' ');
            const address = String(appointment.address || '').trim();

            if (!postalCity) {
                return address || 'Adresse à qualifier';
            }

            return address.toLowerCase().includes(postalCity.toLowerCase())
                ? address
                : `${address}${address ? ', ' : ''}${postalCity}`;
        }

        function customerLabel(appointment) {
            return appointment.company_name || appointment.customer_name || 'Client à qualifier';
        }

        function infoGrid(items) {
            return items.map(([label, value]) => `
                <div>
                    <dt style="color:var(--gc-text-soft);">${escapeHtml(label)}</dt>
                    <dd class="mt-1 font-medium" style="color:var(--gc-text);">${escapeHtml(displayValue(value))}</dd>
                </div>
            `).join('');
        }

        function openModal(modal) {
            modal?.classList.remove('hidden');
            modal?.classList.add('flex');
        }

        function closeModal(modal) {
            modal?.classList.add('hidden');
            modal?.classList.remove('flex');
        }

        function setPhysicalVisitsStatus(message, color = 'var(--gc-text-soft)') {
            if (!physicalVisitsStatus) return;

            physicalVisitsStatus.textContent = message;
            physicalVisitsStatus.style.color = color;
            physicalVisitsStatus.classList.remove('hidden');
        }

        function statsExclusionStatusText(appointment) {
            if (!appointment.excluded_from_lot_stats) {
                return 'Ce dossier compte actuellement dans les statistiques et objectifs du lot.';
            }

            const excludedAt = formatDateTime(appointment.excluded_from_lot_stats_at);
            const excludedBy = appointment.excluded_from_lot_stats_by_name
                ? ` par ${appointment.excluded_from_lot_stats_by_name}`
                : '';

            return `Ce dossier ne compte plus dans les statistiques du lot depuis le ${excludedAt}${excludedBy}.`;
        }

        function configureStatsExclusionControls(appointment, statusElement, toggleElement) {
            if (!statusElement || !toggleElement) {
                return;
            }

            statusElement.textContent = statsExclusionStatusText(appointment);
            statusElement.style.color = appointment.excluded_from_lot_stats ? '#991b1b' : 'var(--gc-text-soft)';
            toggleElement.textContent = appointment.excluded_from_lot_stats
                ? 'Réintégrer dans les stats du lot'
                : 'Sortir des stats du lot';
            toggleElement.disabled = !appointment.stats_exclusion_update_url;
        }

        async function toggleStatsExclusion(appointment, statusElement, toggleElement) {
            if (!appointment?.stats_exclusion_update_url || !toggleElement) {
                return;
            }

            toggleElement.disabled = true;
            toggleElement.textContent = 'Mise à jour...';

            try {
                const response = await fetch(appointment.stats_exclusion_update_url, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': lotDetailCsrfToken,
                    },
                    body: JSON.stringify({
                        excluded_from_lot_stats: !appointment.excluded_from_lot_stats,
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Mise à jour impossible.');
                }

                statusElement.textContent = `${payload.message || 'Statut mis à jour.'} Rechargement des statistiques...`;
                statusElement.style.color = '#15803d';
                window.setTimeout(() => window.location.reload(), 650);
            } catch (error) {
                statusElement.textContent = error.message || 'Mise à jour impossible.';
                statusElement.style.color = '#be123c';
                toggleElement.disabled = false;
                toggleElement.textContent = appointment.excluded_from_lot_stats
                    ? 'Réintégrer dans les stats du lot'
                    : 'Sortir des stats du lot';
            }
        }

        function openPhysicalDetail(appointment) {
            currentPhysicalLotAppointment = appointment;
            physicalTitle.textContent = customerLabel(appointment);
            physicalSubtitle.textContent = appointment.is_placed
                ? 'RDV physique placé'
                : 'Dossier non encore placé physiquement';
            physicalInfos.innerHTML = infoGrid([
                ['Statut', appointment.status_label],
                ['Technicien', appointment.placed_technician_name],
                ['Date du RDV', formatDateTime(appointment.placed_at)],
                ['Prestation', appointment.placed_service_label],
                ['Téléphone', appointment.customer_phone],
                ['Adresse', fullAddress(appointment)],
                ['Référence', appointment.external_reference],
                ['Satisfaction physique', appointment.physical_satisfaction === null || appointment.physical_satisfaction === undefined ? '-' : (appointment.physical_satisfaction ? 'Satisfaisant' : 'Non satisfaisant')],
            ]);

            physicalVisitsInput.value = appointment.unsuccessful_visits_count ?? 0;
            physicalVisitsInput.disabled = !appointment.is_placed;
            physicalVisitsSubmit.disabled = !appointment.is_placed;
            physicalVisitsStatus.classList.add('hidden');

            if (appointment.tracking_url) {
                physicalTrackingLink.href = appointment.tracking_url;
                physicalTrackingWrap.classList.remove('hidden');
            } else {
                physicalTrackingWrap.classList.add('hidden');
            }

            configureStatsExclusionControls(appointment, physicalStatsExclusionStatus, physicalStatsExclusionToggle);
            openModal(physicalModal);
        }

        function openContactDetail(appointment) {
            currentContactLotAppointment = appointment;
            contactTitle.textContent = customerLabel(appointment);
            contactSubtitle.textContent = appointment.contact_satisfaction === null || appointment.contact_satisfaction === undefined
                ? 'Contact non qualifié'
                : (appointment.contact_satisfaction ? 'Contact satisfaisant' : 'Contact non satisfaisant');
            contactInfos.innerHTML = infoGrid([
                ['Statut', appointment.status_label],
                ['Traité le', formatDateTime(appointment.contact_processed_at)],
                ['Traité par', appointment.contact_processed_by_name],
                ['Téléphone', appointment.customer_phone],
                ['Adresse', fullAddress(appointment)],
                ['Référence', appointment.external_reference],
            ]);
            contactComment.textContent = appointment.contact_comment || appointment.comment || 'Aucun commentaire renseigné.';

            configureStatsExclusionControls(appointment, contactStatsExclusionStatus, contactStatsExclusionToggle);
            openModal(contactModal);
        }

        document.querySelectorAll('.lot-appointment-detail-trigger').forEach((button) => {
            button.addEventListener('click', () => {
                const appointment = lotAppointmentDetails.get(String(button.dataset.lotAppointmentId));

                if (!appointment) {
                    return;
                }

                if (appointment.is_contact_processed || appointment.processing_mode === 'contact') {
                    openContactDetail(appointment);
                    return;
                }

                openPhysicalDetail(appointment);
            });
        });

        physicalClose?.addEventListener('click', () => closeModal(physicalModal));
        contactClose?.addEventListener('click', () => closeModal(contactModal));
        physicalStatsExclusionToggle?.addEventListener('click', () => {
            toggleStatsExclusion(currentPhysicalLotAppointment, physicalStatsExclusionStatus, physicalStatsExclusionToggle);
        });
        contactStatsExclusionToggle?.addEventListener('click', () => {
            toggleStatsExclusion(currentContactLotAppointment, contactStatsExclusionStatus, contactStatsExclusionToggle);
        });

        physicalVisitsForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!currentPhysicalLotAppointment?.visits_update_url || physicalVisitsSubmit.disabled) {
                return;
            }

            physicalVisitsSubmit.disabled = true;
            physicalVisitsSubmit.textContent = 'Enregistrement...';
            setPhysicalVisitsStatus('Mise à jour du nombre de portes...');

            try {
                const response = await fetch(currentPhysicalLotAppointment.visits_update_url, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': lotDetailCsrfToken,
                    },
                    body: JSON.stringify({
                        unsuccessful_visits_count: Number(physicalVisitsInput.value || 0),
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Mise à jour impossible.');
                }

                const updatedAppointment = payload.appointment;
                lotAppointmentDetails.set(String(updatedAppointment.id), updatedAppointment);
                currentPhysicalLotAppointment = updatedAppointment;

                document.querySelectorAll(`[data-lot-appointment-portes="${updatedAppointment.id}"]`).forEach((element) => {
                    element.textContent = `Portes : ${updatedAppointment.unsuccessful_visits_count ?? 0}`;
                });

                setPhysicalVisitsStatus(payload.message || 'Nombre de portes mis à jour.', '#15803d');
            } catch (error) {
                setPhysicalVisitsStatus(error.message || 'Mise à jour impossible.', '#be123c');
            } finally {
                physicalVisitsSubmit.disabled = !currentPhysicalLotAppointment?.is_placed;
                physicalVisitsSubmit.textContent = 'Enregistrer les portes';
            }
        });
    </script>

    <style>
        .lot-chart-ring {
            align-items: center;
            background:
                radial-gradient(circle at center, #ffffff 0 58%, transparent 59%),
                conic-gradient(var(--gc-primary) calc(var(--value) * 1%), #e2e8f0 0);
            border-radius: 9999px;
            display: flex;
            height: 104px;
            justify-content: center;
            width: 104px;
        }

        .lot-chart-ring span {
            color: var(--gc-text);
            font-size: 1rem;
            font-weight: 800;
        }
    </style>
</x-layouts.app>
