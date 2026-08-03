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
                @php($percentage = (int) ($chart['percentage'] ?? 0))
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
                        @forelse ($lot['appointments'] as $appointment)
                            @php
                                $clientLabel = $appointment['company_name'] ?: $appointment['customer_name'];
                                $siteLabel = $appointment['site_name'] ?: null;
                                $postalCity = trim(implode(' ', array_filter([$appointment['postal_code'] ?? null, $appointment['city'] ?? null])));
                                $fullAddress = trim((string) ($appointment['address'] ?? ''));

                                if ($postalCity !== '' && ! str_contains(mb_strtolower($fullAddress), mb_strtolower($postalCity))) {
                                    $fullAddress = trim($fullAddress.', '.$postalCity, ' ,');
                                }

                                $resultLabel = match (true) {
                                    $appointment['contact_satisfaction'] === true => 'Contact satisfaisant',
                                    $appointment['contact_satisfaction'] === false => 'Contact non satisfaisant',
                                    $appointment['physical_satisfaction'] === true => 'Physique satisfaisant',
                                    $appointment['physical_satisfaction'] === false => 'Physique non satisfaisant',
                                    $appointment['is_placed'] => 'RDV physique placé',
                                    $appointment['is_contact_processed'] => 'Contact traité',
                                    default => 'En attente',
                                };
                            @endphp
                            <tr>
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
                                    @if ($appointment['placed_technician_name'])
                                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $appointment['placed_technician_name'] }}</p>
                                    @endif
                                </td>
                                <td class="min-w-[180px] px-4 py-3" style="color:var(--gc-text);">
                                    {{ $resultLabel }}
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
                                    @if ($appointment['tracking_url'])
                                        <a href="{{ $appointment['tracking_url'] }}" class="gc-btn-soft px-3 py-2 text-xs">Voir le RDV</a>
                                    @else
                                        <span class="text-xs" style="color:var(--gc-text-soft);">Non placé</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center" style="color:var(--gc-text-soft);">
                                    Aucun dossier dans ce lot.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

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
