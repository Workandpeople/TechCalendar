<x-layouts.app>
    @php
        $coffracApiStatus = $coffracApiStatus ?? [
            'state' => 'unavailable',
            'label' => 'API Coffrac indisponible',
            'detail' => 'Statut API Coffrac inconnu.',
            'count' => $crmAppointments->count(),
        ];
        $externalAppointmentSources = $externalAppointmentSources ?? [
            [
                'key' => 'coffrac',
                'label' => 'Coffrac',
                'refresh_label' => 'Actualiser Coffrac',
                'enabled' => true,
                'status' => $coffracApiStatus,
            ],
            [
                'key' => 'external_app_2',
                'label' => 'Connecteur 2',
                'refresh_label' => 'Actualiser connecteur 2',
                'enabled' => false,
                'status' => [
                    'state' => 'not_configured',
                    'label' => 'Connecteur 2 à connecter',
                    'detail' => 'Emplacement préparé pour une future application externe.',
                    'count' => 0,
                ],
            ],
            [
                'key' => 'external_app_3',
                'label' => 'Connecteur 3',
                'refresh_label' => 'Actualiser connecteur 3',
                'enabled' => false,
                'status' => [
                    'state' => 'not_configured',
                    'label' => 'Connecteur 3 à connecter',
                    'detail' => 'Emplacement préparé pour une future application externe.',
                    'count' => 0,
                ],
            ],
        ];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Planning</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Prise de rdv</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Sélectionne une demande externe ou saisis un RDV manuel pour identifier les techniciens éligibles.</p>
            </div>
            <button id="manual-booking-toggle" type="button" class="gc-btn-primary self-start md:self-auto">
                RDV manuel
            </button>
        </div>

        <section id="booking-placement-confirmation" class="gc-card hidden overflow-hidden p-0">
            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div class="p-6">
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" style="background:#dcfce7;color:#15803d;">
                        Placement confirme
                    </span>
                    <h2 class="mt-4 text-2xl font-semibold" style="color:var(--gc-text);">Le rendez-vous a bien été placé</h2>
                    <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                        La prise de RDV est verrouillée sur cette page pour éviter un double placement accidentel.
                    </p>

                    <dl class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Reference</dt>
                            <dd id="booking_confirmation_référence" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Date</dt>
                            <dd id="booking_confirmation_date" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Client</dt>
                            <dd id="booking_confirmation_customer" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Technicien</dt>
                            <dd id="booking_confirmation_technician" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                        <div class="rounded-xl border p-4 md:col-span-2" style="border-color:var(--gc-border);background:#ffffff;">
                            <dt class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Adresse</dt>
                            <dd id="booking_confirmation_address" class="mt-1 font-semibold" style="color:var(--gc-text);"></dd>
                        </div>
                    </dl>
                </div>

                <aside class="flex flex-col justify-between gap-6 p-6" style="background:linear-gradient(145deg,#31424c 0%,#1f2d35 100%);">
                    <div>
                        <p class="text-sm font-semibold" style="color:#f5df9a;">Prochaine action</p>
                        <p class="mt-2 text-sm leading-6 text-white/80">
                            Tu peux maintenant vérifier le rendez-vous dans le suivi ou repartir sur une nouvelle prise de RDV propre.
                        </p>
                    </div>
                    <div class="flex flex-col gap-3">
                        <a id="booking-confirmation-track-link" href="{{ route('planner.tracking') }}" class="gc-btn-primary justify-center text-center">
                            Suivre le RDV
                        </a>
                        <button id="booking-confirmation-new" type="button" class="gc-btn-soft justify-center">
                            Placer un autre RDV
                        </button>
                    </div>
                </aside>
            </div>
        </section>

        <section id="crm-booking-section" class="gc-card p-5">
            <div class="mb-4 space-y-3">
                <div id="booking-external-controls" class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($externalAppointmentSources as $source)
                            @php
                                $sourceStatus = $source['status'] ?? ['state' => 'unavailable', 'label' => 'Indisponible', 'detail' => ''];
                                $sourceButtonStyle = match ($sourceStatus['state'] ?? 'unavailable') {
                                    'available' => 'background:#dcfce7;color:#15803d;border-color:#86efac;',
                                    'syncing' => 'background:#fef3c7;color:#b45309;border-color:#fcd34d;',
                                    default => 'background:#fee2e2;color:#be123c;border-color:#fecdd3;',
                                };
                            @endphp
                            <button
                                @if ($source['key'] === 'coffrac') id="booking-crm-refresh" @endif
                                type="button"
                                class="gc-btn-soft relative overflow-hidden px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                style="{{ $sourceButtonStyle }}"
                                data-external-refresh-source="{{ $source['key'] }}"
                                data-refresh-label="{{ $source['refresh_label'] }}"
                                data-api-state="{{ $sourceStatus['state'] ?? 'unavailable' }}"
                                data-api-label="{{ $sourceStatus['label'] ?? '' }}"
                                data-api-detail="{{ $sourceStatus['detail'] ?? '' }}"
                                data-api-progress="{{ $sourceStatus['progress'] ?? (($sourceStatus['state'] ?? '') === 'syncing' ? 5 : 100) }}"
                                data-api-stage="{{ $sourceStatus['stage'] ?? '' }}"
                                title="{{ $sourceStatus['detail'] ?? $source['refresh_label'] }}"
                                @disabled(! $source['enabled'])
                            >
                                <span class="relative z-10 inline-flex items-center gap-2">
                                    <span data-external-refresh-label>{{ $source['refresh_label'] }}</span>
                                    <span data-external-refresh-progress-label class="{{ ($sourceStatus['state'] ?? '') === 'syncing' ? '' : 'hidden' }} rounded-full px-2 py-0.5 text-xs font-semibold" style="background:rgba(255,255,255,.72);">
                                        {{ $sourceStatus['progress'] ?? 5 }}%
                                    </span>
                                </span>
                                <span data-external-refresh-progress-bar class="{{ ($sourceStatus['state'] ?? '') === 'syncing' ? '' : 'hidden' }} absolute bottom-0 left-0 h-1 rounded-r-full transition-all duration-300" style="width:{{ $sourceStatus['progress'] ?? 5 }}%;background:rgba(180,83,9,.38);"></span>
                            </button>
                        @endforeach
                    </div>
                    <span id="booking-crm-refresh-status" class="hidden text-sm" style="color:var(--gc-text-soft);"></span>
                </div>

                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV externes à placer</h2>
                        <label class="inline-flex cursor-pointer items-center gap-3">
                            <button id="booking-source-switch" type="button" class="relative h-4 w-8 rounded-full transition" style="background:var(--gc-primary);" role="switch" aria-checked="false" aria-label="Basculer vers les lots">
                                <span id="booking-source-switch-knob" class="absolute left-1 top-1 h-2 w-2 rounded-full bg-white transition"></span>
                            </button>
                            <span class="text-lg font-semibold" style="color:var(--gc-text);">depuis des lots</span>
                        </label>
                    </div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Le service est optionnel: s'il est absent, seuls les départements couverts filtrent les techniciens.</p>
                </div>
            </div>

            <div id="booking-crm-search-wrap" class="mb-4">
                <label class="gc-label" for="booking_crm_search">Recherche client</label>
                <input id="booking_crm_search" type="search" class="gc-input" placeholder="Nom ou prénom du client" autocomplete="off" />
            </div>

            <div id="booking-crm-empty" class="hidden rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                Aucun RDV externe ne correspond à cette recherche.
            </div>

            <div id="booking-crm-source">
                <div id="booking-crm-grid" class="space-y-2">
                    @foreach ($crmAppointments as $appointment)
                        <div
                            role="button"
                            tabindex="0"
                            class="crm-appointment-card grid w-full grid-cols-1 items-center gap-2 rounded-xl border px-3 py-2 text-left transition hover:shadow-sm md:grid-cols-[minmax(160px,1fr)_140px_minmax(220px,1.4fr)_auto]"
                            style="border-color:var(--gc-border);background:#ffffff;"
                            data-crm-id="{{ $appointment['id'] }}"
                            data-client="{{ str($appointment['last_name'].' '.$appointment['first_name'])->lower() }}"
                            data-has-coordinates="{{ is_numeric($appointment['latitude'] ?? null) && is_numeric($appointment['longitude'] ?? null) ? '1' : '0' }}"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold" style="color:var(--gc-text);">{{ $appointment['last_name'] }} {{ $appointment['first_name'] }}</p>
                                <p class="truncate text-xs" style="color:var(--gc-text-soft);">{{ $appointment['source'] }}</p>
                            </div>
                            <p class="truncate text-sm" style="color:var(--gc-text-soft);">{{ $appointment['phone'] }}</p>
                            <p class="truncate text-sm" style="color:var(--gc-text);">{{ $appointment['address'] }}</p>
                            <div class="flex flex-wrap items-center gap-2 md:justify-end">
                                <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">Dép. {{ $appointment['department_code'] }}</span>
                                @if ($appointment['service'])
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:#dcfce7;color:#15803d;">{{ $appointment['service']['type'] }}</span>
                                @else
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:#fee2e2;color:#be123c;">Service non renseigné</span>
                                @endif
                                @if (! is_numeric($appointment['latitude'] ?? null) || ! is_numeric($appointment['longitude'] ?? null))
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:#fff7ed;color:#c2410c;">GPS à recalculer</span>
                                @endif
                                <button type="button" class="crm-appointment-detail-button inline-flex h-8 w-8 items-center justify-center rounded-lg border transition hover:shadow-sm" style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);" data-crm-detail-id="{{ $appointment['id'] }}" title="Voir le détail du RDV" aria-label="Voir le détail du RDV">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M2.75 12s3.25-6.25 9.25-6.25S21.25 12 21.25 12 18 18.25 12 18.25 2.75 12 2.75 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M12 14.75A2.75 2.75 0 1 0 12 9.25a2.75 2.75 0 0 0 0 5.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div id="booking-crm-pagination" class="mt-4 flex flex-wrap items-center justify-center gap-2"></div>
            </div>

            <div id="booking-lot-source" class="hidden space-y-3">
                @forelse ($lotRequests as $lot)
                    <details class="booking-lot-details overflow-hidden rounded-2xl border bg-white shadow-sm" style="border-color:var(--gc-border);">
                        <summary class="flex cursor-pointer list-none flex-col gap-4 p-4 transition hover:bg-[color:var(--gc-accent-soft)] lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-semibold" style="color:var(--gc-text);">{{ $lot['title'] }}</h3>
                                    @if ($lot['type_label'])
                                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:#e0f2fe;color:#1d4ed8;">
                                            {{ $lot['type_label'] }}
                                        </span>
                                    @endif
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:{{ $lot['status_background'] }};color:{{ $lot['status_color'] }};">
                                        {{ $lot['status_label'] }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                                    {{ $lot['appointments_count'] }} RDV · {{ $lot['placeable_count'] }} à placer · {{ $lot['placed_count'] }} places
                                    @if ($lot['imported_at'])
                                        · Importe {{ $lot['imported_at']->format('d/m/Y H:i') }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex w-full shrink-0 items-center gap-3 lg:w-auto">
                                <div class="min-w-[220px] flex-1 lg:w-64 lg:flex-none">
                                    <div class="mb-1 flex items-center justify-between gap-3">
                                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Auto-completion</span>
                                        <span class="text-sm font-semibold" style="color:var(--gc-text);">{{ $lot['auto_completion']['percentage'] }}%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full transition-all" style="width:{{ $lot['auto_completion']['percentage'] }}%;background:var(--gc-primary);"></div>
                                    </div>
                                    <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">{{ $lot['auto_completion']['detail'] }}</p>
                                </div>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="booking-lot-chevron h-5 w-5 transition-transform" style="color:var(--gc-text-soft);">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </div>
                        </summary>

                        <div class="border-t" style="border-color:var(--gc-border);">
                            <div class="grid grid-cols-1">
                            @foreach ($lot['appointments'] as $appointment)
                                @php
                                    $isPlaced = (bool) $appointment['is_placed'];
                                    $appointmentPostalCity = trim(implode(' ', array_filter([
                                        $appointment['postal_code'] ?? null,
                                        $appointment['city'] ?? null,
                                    ])));
                                    $appointmentAddress = trim((string) ($appointment['address'] ?? ''));
                                    $appointmentFullAddress = $appointmentAddress !== ''
                                        ? $appointmentAddress
                                        : 'Adresse à qualifier';

                                    if ($appointmentPostalCity !== '' && ! str_contains(mb_strtolower($appointmentFullAddress), mb_strtolower($appointmentPostalCity))) {
                                        $appointmentFullAddress .= ', '.$appointmentPostalCity;
                                    }
                                @endphp
                                <article class="grid grid-cols-1 gap-4 border-b p-4 last:border-b-0 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1.7fr)_minmax(280px,auto)] xl:items-center" style="border-color:{{ $isPlaced ? '#bbf7d0' : 'var(--gc-border)' }};background:{{ $isPlaced ? '#f0fdf4' : '#ffffff' }};">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">Dept. {{ $appointment['department_code'] ?: '--' }}</span>
                                            @if ($isPlaced)
                                                <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#dcfce7;color:#15803d;">RDV placé</span>
                                            @endif
                                            @if ($appointment['status'] === \App\Models\LotAppointment::STATUS_NEEDS_REVIEW)
                                                <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#fef3c7;color:#b45309;">A vérifier</span>
                                            @endif
                                        </div>
                                        <h4 class="mt-2 font-semibold" style="color:var(--gc-text);">{{ $appointment['customer_name'] }}</h4>
                                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $appointment['customer_phone'] ?: 'Téléphone non renseigné' }}</p>
                                    </div>

                                    <div class="min-w-0">
                                        <p class="text-sm font-medium" style="color:var(--gc-text);">{{ $appointmentFullAddress }}</p>
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">
                                            @if ($appointment['external_reference'])
                                                Réf. {{ $appointment['external_reference'] }}
                                            @elseif ($appointment['row_number'])
                                                Ligne fichier {{ $appointment['row_number'] }}
                                            @else
                                                RDV lot #{{ $appointment['id'] }}
                                            @endif
                                        </p>
                                        @if ($isPlaced)
                                            <p class="mt-2 text-xs" style="color:#15803d;">
                                                {{ $appointment['placed_at']?->format('d/m/Y H:i') ?? 'Date non renseignée' }}
                                                @if ($appointment['placed_technician_name'])
                                                    · {{ $appointment['placed_technician_name'] }}
                                                @endif
                                                @if ($appointment['placed_service_label'])
                                                    · {{ $appointment['placed_service_label'] }}
                                                @endif
                                            </p>
                                        @endif
                                    </div>

                                    @if ($isPlaced)
                                        <div class="flex justify-start xl:justify-end">
                                            @if ($appointment['tracking_url'])
                                                <a href="{{ $appointment['tracking_url'] }}" class="gc-btn-soft whitespace-nowrap">
                                                    Voir le RDV
                                                </a>
                                            @else
                                                <span class="rounded-lg border px-3 py-2 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                                                    RDV indisponible
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                                            <div>
                                                <label class="gc-label" for="lot_appointment_service_{{ $appointment['id'] }}">Prestation</label>
                                                <select
                                                    id="lot_appointment_service_{{ $appointment['id'] }}"
                                                    class="gc-input lot-appointment-service-select"
                                                    data-lot-appointment-id="{{ $appointment['id'] }}"
                                                    data-can-search="{{ $appointment['can_search'] ? '1' : '0' }}"
                                                    @disabled(! $appointment['can_search'])
                                                >
                                                    <option value="">Sélectionner</option>
                                                    @foreach ($services as $service)
                                                        <option value="{{ $service->id }}" @selected((int) ($appointment['service_id'] ?? 0) === $service->id)>
                                                            {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <button
                                                type="button"
                                                class="lot-appointment-book-button gc-btn-primary justify-center whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-50"
                                                data-lot-appointment-id="{{ $appointment['id'] }}"
                                                data-can-search="{{ $appointment['can_search'] ? '1' : '0' }}"
                                                disabled
                                            >
                                                Placer le RDV
                                            </button>
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                            </div>
                        </div>
                    </details>
                @empty
                    <div class="rounded-2xl border border-dashed p-8 text-center" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                        Aucun RDV de lot à placer pour le moment.
                    </div>
                @endforelse
            </div>
        </section>

        <section id="manual-booking-section" class="gc-card hidden p-5">
            <div class="mb-4">
                <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV manuel</h2>
                <p class="text-sm" style="color:var(--gc-text-soft);">Saisie rapide d'un client hors application externe. L'adresse doit être sélectionnée via Mapbox pour récupérer le département et les coordonnées.</p>
            </div>

            <form id="manual-booking-form" class="grid grid-cols-1 gap-4 xl:grid-cols-12" data-validate-form>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_last_name">Nom client</label>
                    <input id="manual_last_name" type="text" class="gc-input" maxlength="120" required />
                </div>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_first_name">Prénom client</label>
                    <input id="manual_first_name" type="text" class="gc-input" maxlength="120" required />
                </div>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_phone">Téléphone</label>
                    <input id="manual_phone" type="tel" class="gc-input" maxlength="30" required />
                </div>
                <div class="xl:col-span-3">
                    <label class="gc-label" for="manual_service_id">Prestation</label>
                    <select id="manual_service_id" class="gc-input" required>
                        <option value="">Sélectionner</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}">
                                {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="relative xl:col-span-12">
                    <label class="gc-label" for="manual_address">Adresse du RDV</label>
                    <input id="manual_address" type="text" class="gc-input" maxlength="255" autocomplete="off" required />
                    <input id="manual_department_code" type="hidden" />
                    <input id="manual_latitude" type="hidden" />
                    <input id="manual_longitude" type="hidden" />
                </div>

                <div class="xl:col-span-12">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <p id="manual-booking-status" class="hidden text-sm"></p>
                        <button id="manual-booking-submit" type="submit" class="gc-btn-primary self-start md:self-auto">
                            Trouver un technicien
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <section id="booking-analysis-section" class="hidden space-y-6">
            <div id="booking-feedback" class="hidden rounded-xl border px-4 py-3 text-sm"></div>

            <div id="booking-analysis-loader" class="hidden rounded-2xl border bg-white p-4 shadow-sm" style="border-color:var(--gc-border);">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p id="booking-analysis-loader-title" class="text-sm font-semibold" style="color:var(--gc-text);">Analyse en cours</p>
                        <p id="booking-analysis-loader-detail" class="mt-1 text-xs" style="color:var(--gc-text-soft);">Préparation de la recherche...</p>
                    </div>
                    <span id="booking-analysis-loader-label" class="shrink-0 text-sm font-semibold" style="color:var(--gc-text);">0%</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div id="booking-analysis-loader-bar" class="h-full rounded-full transition-all duration-200" style="width:0%;background:var(--gc-primary);"></div>
                </div>
            </div>

            <div id="booking-results-anchor" class="grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
                <section class="gc-card flex h-[560px] flex-col p-5 xl:h-[640px]">
                    <div class="mb-4 shrink-0 space-y-4">
                        <p class="text-sm" style="color:var(--gc-text-soft);">Techniciens éligibles</p>
                        <h2 id="analysis-title" class="text-lg font-semibold" style="color:var(--gc-text);"></h2>
                        <p id="analysis-subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>

                        <div class="rounded-xl border p-3" style="border-color:var(--gc-border);background:#ffffff;">
                            <label class="gc-label" for="eligible-technician-search">Ajouter un technicien manuellement</label>
                            <input id="eligible-technician-search" type="search" class="gc-input" placeholder="Nom, prénom, téléphone, département..." autocomplete="off" />
                            <p id="eligible-technician-search-status" class="mt-2 hidden text-xs"></p>
                            <div id="eligible-technician-search-results" class="mt-3 hidden space-y-2"></div>
                        </div>

                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span id="eligible-technician-selection-count" style="color:var(--gc-text-soft);"></span>
                            <button id="eligible-technician-select-all" type="button" class="gc-link">Tout cocher</button>
                        </div>
                    </div>
                    <div id="eligible-technicians-list" class="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1"></div>
                </section>

                <section class="gc-card flex h-[560px] flex-col overflow-hidden p-5 xl:h-[640px]">
                    <div class="mb-4 flex shrink-0 items-center justify-between gap-3">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">Carte</p>
                            <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Trajets domicile vers RDV</h2>
                        </div>
                    </div>
                    <div id="booking-map" class="min-h-0 flex-1 overflow-hidden rounded-2xl border" style="border-color:var(--gc-border);"></div>
                </section>
            </div>

            <section class="gc-card p-5">
                <div class="mb-4">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Disponibilites visuelles</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">RDV existants des techniciens retournés</h2>
                </div>
                <div id="booking-calendar-loader" class="mb-4 hidden rounded-xl border px-4 py-3 text-sm" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text);">
                    <div class="flex items-center justify-between gap-3">
                        <span id="booking-calendar-loader-detail">Calcul des propositions pour la semaine affichée...</span>
                        <span id="booking-calendar-loader-label" class="font-semibold">0%</span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/70">
                        <div id="booking-calendar-loader-bar" class="h-full rounded-full transition-all duration-200" style="width:0%;background:var(--gc-primary);"></div>
                    </div>
                </div>
                <div id="booking-calendar"></div>
            </section>
        </section>
    </div>

    <div id="booking-crm-detail-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl max-h-[calc(100vh-2rem)] overflow-y-auto">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Demande externe</p>
                    <h2 id="booking_crm_detail_title" class="text-xl font-semibold" style="color:var(--gc-text);"></h2>
                    <p id="booking_crm_detail_subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>
                </div>
                <button type="button" id="booking-crm-detail-close" class="gc-link">Fermer</button>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.15fr)_460px]">
                <section>
                    <div id="booking-crm-detail-map" class="h-[420px] overflow-hidden rounded-2xl border" style="border-color:var(--gc-border);"></div>
                    <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">Carte libre: tu peux zoomer, dézoomer et te déplacer pour vérifier le point.</p>
                </section>

                <section class="space-y-4">
                    <form id="booking-crm-detail-form" class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <div class="grid grid-cols-1 gap-3">
                            <div>
                                <label class="gc-label" for="booking_crm_detail_service_id">Prestation</label>
                                <select id="booking_crm_detail_service_id" class="gc-input">
                                    <option value="">Prestation non renseignée</option>
                                    @foreach ($services as $service)
                                        <option value="{{ $service->id }}">{{ $service->type }} - {{ $service->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="gc-label" for="booking_crm_detail_address_input">Adresse</label>
                                <input id="booking_crm_detail_address_input" type="text" class="gc-input" autocomplete="off" placeholder="Adresse complète du RDV" />
                                <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">La sauvegarde relance le géocodage Mapbox et met à jour le point GPS.</p>
                            </div>
                            <div>
                                <label class="gc-label" for="booking_crm_detail_comment">Commentaires</label>
                                <textarea id="booking_crm_detail_comment" class="gc-input min-h-[110px]" placeholder="Commentaires du dossier ou notes internes"></textarea>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <p id="booking_crm_detail_status" class="hidden text-sm"></p>
                            <button id="booking-crm-detail-save" type="submit" class="gc-btn">Enregistrer et regéocoder</button>
                        </div>
                    </form>

                    <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <dl id="booking_crm_detail_infos" class="grid grid-cols-1 gap-3 text-sm"></dl>
                    </div>

                    <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold" style="color:var(--gc-text);">Documents</h3>
                            <span id="booking_crm_detail_documents_count" class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);"></span>
                        </div>
                        <div id="booking_crm_detail_documents" class="mt-3 space-y-2"></div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div id="booking-appointment-modal" class="gc-modal hidden">
        <div class="gc-modal-panel gc-modal-panel-xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p id="booking_modal_kind" class="text-sm" style="color:var(--gc-text-soft);">Rendez-vous</p>
                    <h2 id="booking_modal_title" class="text-xl font-semibold" style="color:var(--gc-text);"></h2>
                    <p id="booking_modal_subtitle" class="mt-1 text-sm" style="color:var(--gc-text-soft);"></p>
                </div>
                <button type="button" id="booking-modal-close" class="gc-link">Fermer</button>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.15fr)_420px]">
                <section>
                    <div id="booking-detail-map" class="h-[420px] overflow-hidden rounded-2xl border" style="border-color:var(--gc-border);"></div>
                    <div id="booking_route_summary" class="mt-3 rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text);"></div>
                    <div id="booking_day_route_summary" class="mt-3 rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);"></div>
                </section>

                <section class="space-y-4">
                    <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <dl class="grid grid-cols-1 gap-3 text-sm">
                            <div>
                                <dt style="color:var(--gc-text-soft);">Technicien</dt>
                                <dd id="booking_detail_technician" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Client</dt>
                                <dd id="booking_detail_customer" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Téléphone</dt>
                                <dd id="booking_detail_phone" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Prestation</dt>
                                <dd id="booking_detail_service" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div id="booking_detail_service_select_wrap" class="hidden">
                                <dt style="color:var(--gc-text-soft);">Prestation à définir</dt>
                                <dd class="mt-2">
                                    <select id="booking_detail_service_select" class="gc-input">
                                        <option value="">Sélectionner une prestation</option>
                                        @foreach ($services as $service)
                                            <option value="{{ $service->id }}" data-duration="{{ $service->average_duration_minutes }}">
                                                {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                                            </option>
                                        @endforeach
                                    </select>
                                </dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Adresse</dt>
                                <dd id="booking_detail_address" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                            <div>
                                <dt style="color:var(--gc-text-soft);">Origine trajet</dt>
                                <dd id="booking_detail_origin" class="font-medium" style="color:var(--gc-text);"></dd>
                            </div>
                        </dl>
                    </div>

                    <form id="booking-detail-form" class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                        <input id="booking_detail_appointment_id" type="hidden" />
                        <input id="booking_detail_crm_id" type="hidden" />
                        <input id="booking_detail_technician_id" type="hidden" />

                        <div id="booking_detail_technician_select_wrap" class="mb-4 hidden">
                            <label class="gc-label" for="booking_detail_technician_select">Technicien</label>
                            <select id="booking_detail_technician_select" class="gc-input"></select>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="gc-label" for="booking_detail_starts_at">Date et heure</label>
                                <input id="booking_detail_starts_at" type="datetime-local" class="gc-input" />
                            </div>
                            <div>
                                <label class="gc-label" for="booking_detail_duration">Durée prestation</label>
                                <input id="booking_detail_duration" type="number" min="30" max="480" step="5" class="gc-input" />
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="gc-label" for="booking_detail_comment">Commentaire</label>
                            <textarea id="booking_detail_comment" rows="5" class="gc-input" style="min-height:120px;"></textarea>
                        </div>

                        <div id="booking_detail_status" class="mt-3 hidden text-sm"></div>

                        <div class="mt-4 flex flex-wrap justify-end gap-2">
                            <button id="booking-problem-appointment-btn" type="button" class="gc-btn-soft" style="background:#fef3c7;color:#92400e;">Problème RDV</button>
                            <button id="booking-save-comment-btn" type="button" class="gc-btn-soft">Enregistrer commentaire</button>
                            <button id="booking-confirm-suggestion-btn" type="button" class="gc-btn-primary">Valider la prise du RDV</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales-all.global.min.js"></script>
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <style>
        .booking-lot-details > summary::-webkit-details-marker {
            display: none;
        }

        .booking-lot-details[open] .booking-lot-chevron {
            transform: rotate(180deg);
        }
    </style>

    <script>
        const bookingAnalyzeUrl = @json(route('planner.book.analyze'));
        const bookingCrmIndexUrl = @json(route('planner.book.crm-appointments.index'));
        const bookingCrmRefreshUrl = @json(route('planner.book.crm-appointments.refresh'));
        const bookingCrmUpdateUrlTemplate = @json(route('planner.book.crm-appointments.update', ['crmAppointmentId' => '__CRM_APPOINTMENT__']));
        const bookingTechnicianSearchUrl = @json(route('planner.book.technicians.search'));
        const bookingCalendarWindowUrl = @json(route('planner.book.calendar-window'));
        const bookingStoreUrl = @json(route('planner.book.appointments.store'));
        const bookingCommentUrlTemplate = @json(route('planner.tracking.appointments.comment', ['appointment' => '__APPOINTMENT__']));
        const bookingProblemUrlTemplate = @json(route('planner.tracking.appointments.problem', ['appointment' => '__APPOINTMENT__']));
        const bookingTrackingUrl = @json(route('planner.tracking'));
        const bookingCsrfToken = @json(csrf_token());
        const bookingMapboxToken = @json($mapboxToken);
        const bookingInitialCrmAppointmentId = @json($initialCrmAppointmentId);
        const bookingServices = @json($bookingServices);
        let bookingCrmAppointments = @json($crmAppointments->values());
        const routeColors = ['#1d4ed8', '#0f766e', '#b45309', '#7e22ce', '#be123c', '#475569', '#a16207', '#0369a1'];
        let bookingMap = null;
        let bookingCalendar = null;
        let bookingMapMarkers = [];
        let crmDetailMap = null;
        let crmDetailMapMarkers = [];
        let currentCrmDetailAppointmentId = null;
        let bookingSuggestionTooltip = null;
        let currentTechnicianColors = {};
        let currentCrmAppointmentId = null;
        let currentAnalysisPayload = null;
        let currentAppointmentRequest = null;
        let currentTechnicians = [];
        let selectedTechnicianIds = new Set();
        let currentFilters = null;
        let currentCalendarEvents = [];
        let currentCalendarSuggestions = [];
        let technicianSearchAbortController = null;
        let technicianSearchTimer = null;
        let calendarWindowAbortController = null;
        let bookingInitialDetailComment = '';
        let calendarWindowRequestId = 0;
        let lastCalendarDateInfo = null;
        let mapRenderRequestId = 0;
        let shouldFetchCalendarWindow = false;
        let detailMap = null;
        let detailMapMarkers = [];
        let detailMapRenderRequestId = 0;
        let selectedCalendarEvent = null;

        const mapboxTokenPreview = () => {
            if (!bookingMapboxToken) return null;

            return `${String(bookingMapboxToken).slice(0, 10)}...${String(bookingMapboxToken).slice(-6)}`;
        };

        const mapboxStatus = () => ({
            token_found: Boolean(bookingMapboxToken),
            token_preview: mapboxTokenPreview(),
            mapboxgl_found: Boolean(window.mapboxgl),
            mapboxgl_version: window.mapboxgl?.version || null,
            script_tag_found: Boolean(document.querySelector('script[src*="mapbox-gl.js"]')),
            css_tag_found: Boolean(document.querySelector('link[href*="mapbox-gl.css"]')),
        });

        const sanitizeMapboxUrl = (url) => {
            const clone = new URL(url.toString());
            if (clone.searchParams.has('access_token')) {
                clone.searchParams.set('access_token', '[masked]');
            }

            return clone.toString();
        };

        const mapboxDebug = () => {};

        const showMapboxUnavailable = (containerId, reason) => {
            const container = document.getElementById(containerId);
            if (!container) return;

            container.innerHTML = `
                <div class="flex h-full min-h-[260px] items-center justify-center rounded-2xl border border-dashed px-5 text-center" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text);">
                    <div>
                        <p class="font-semibold">Mapbox indisponible</p>
                        <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">${escapeHtml(reason)}</p>
                        <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">token: ${bookingMapboxToken ? 'present' : 'absent'} · mapboxgl: ${window.mapboxgl ? 'present' : 'absent'}</p>
                    </div>
                </div>
            `;
        };

        const analysisSection = document.getElementById('booking-analysis-section');
        const crmBookingSection = document.getElementById('crm-booking-section');
        const placementConfirmationSection = document.getElementById('booking-placement-confirmation');
        const bookingFeedback = document.getElementById('booking-feedback');
        const bookingResultsAnchor = document.getElementById('booking-results-anchor');
        const bookingCrmSearch = document.getElementById('booking_crm_search');
        const bookingCrmGrid = document.getElementById('booking-crm-grid');
        let bookingCrmCards = Array.from(document.querySelectorAll('.crm-appointment-card'));
        const bookingCrmEmpty = document.getElementById('booking-crm-empty');
        const bookingCrmSource = document.getElementById('booking-crm-source');
        const bookingLotSource = document.getElementById('booking-lot-source');
        const bookingCrmSearchWrap = document.getElementById('booking-crm-search-wrap');
        const bookingCrmPagination = document.getElementById('booking-crm-pagination');
        const bookingCrmRefreshButton = document.getElementById('booking-crm-refresh');
        const bookingExternalRefreshButtons = Array.from(document.querySelectorAll('[data-external-refresh-source]'));
        const bookingCrmRefreshStatus = document.getElementById('booking-crm-refresh-status');
        const bookingSourceSwitch = document.getElementById('booking-source-switch');
        const bookingSourceSwitchKnob = document.getElementById('booking-source-switch-knob');
        const techniciansList = document.getElementById('eligible-technicians-list');
        const technicianSearchInput = document.getElementById('eligible-technician-search');
        const technicianSearchResults = document.getElementById('eligible-technician-search-results');
        const technicianSearchStatus = document.getElementById('eligible-technician-search-status');
        const technicianSelectionCount = document.getElementById('eligible-technician-selection-count');
        const technicianSelectAllButton = document.getElementById('eligible-technician-select-all');
        const bookingAppointmentModal = document.getElementById('booking-appointment-modal');
        const bookingCrmDetailModal = document.getElementById('booking-crm-detail-modal');
        const bookingCrmDetailForm = document.getElementById('booking-crm-detail-form');
        const bookingCrmDetailService = document.getElementById('booking_crm_detail_service_id');
        const bookingCrmDetailAddress = document.getElementById('booking_crm_detail_address_input');
        const bookingCrmDetailComment = document.getElementById('booking_crm_detail_comment');
        const bookingCrmDetailStatus = document.getElementById('booking_crm_detail_status');
        const bookingCrmDetailSave = document.getElementById('booking-crm-detail-save');
        const bookingDetailStatus = document.getElementById('booking_detail_status');
        const manualBookingSection = document.getElementById('manual-booking-section');
        const manualBookingStatus = document.getElementById('manual-booking-status');
        const manualBookingToggle = document.getElementById('manual-booking-toggle');
        const bookingAnalysisLoader = document.getElementById('booking-analysis-loader');
        const bookingAnalysisLoaderTitle = document.getElementById('booking-analysis-loader-title');
        const bookingAnalysisLoaderDetail = document.getElementById('booking-analysis-loader-detail');
        const bookingAnalysisLoaderLabel = document.getElementById('booking-analysis-loader-label');
        const bookingAnalysisLoaderBar = document.getElementById('booking-analysis-loader-bar');
        const bookingCalendarLoader = document.getElementById('booking-calendar-loader');
        const bookingCalendarLoaderDetail = document.getElementById('booking-calendar-loader-detail');
        const bookingCalendarLoaderLabel = document.getElementById('booking-calendar-loader-label');
        const bookingCalendarLoaderBar = document.getElementById('booking-calendar-loader-bar');
        const confirmationTrackLink = document.getElementById('booking-confirmation-track-link');
        const bookingCrmPageSize = 10;
        let bookingCrmPage = 1;
        let bookingSourceMode = 'crm';
        let bookingAnalysisProgress = 0;
        let bookingAnalysisProgressTimer = null;
        let bookingCalendarProgress = 0;
        let bookingCalendarProgressTimer = null;
        let externalSyncSubscription = null;
        let externalSyncLocalRefreshTimer = null;
        let externalSyncPollingTimer = null;
        let externalSyncPollStartedAt = null;

        const externalRefreshStatusStyles = {
            available: {
                background: '#dcfce7',
                color: '#15803d',
                borderColor: '#86efac',
            },
            syncing: {
                background: '#fef3c7',
                color: '#b45309',
                borderColor: '#fcd34d',
            },
            unavailable: {
                background: '#fee2e2',
                color: '#be123c',
                borderColor: '#fecdd3',
            },
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const formatShortDate = (value) => {
            if (!value) return '-';

            return new Date(value).toLocaleDateString('fr-FR');
        };

        const showFeedback = (message, type = 'info') => {
            bookingFeedback.textContent = message;
            bookingFeedback.classList.remove('hidden');
            bookingFeedback.style.borderColor = type === 'error' ? '#fecdd3' : 'var(--gc-border)';
            bookingFeedback.style.background = type === 'error' ? '#fff1f2' : 'var(--gc-accent-soft)';
            bookingFeedback.style.color = type === 'error' ? '#be123c' : 'var(--gc-text)';
        };

        const clearFeedback = () => {
            bookingFeedback.textContent = '';
            bookingFeedback.classList.add('hidden');
        };

        const setBookingCrmRefreshStatus = (message = '', type = 'info') => {
            if (!bookingCrmRefreshStatus) return;

            bookingCrmRefreshStatus.textContent = message;
            bookingCrmRefreshStatus.classList.toggle('hidden', message === '');
            bookingCrmRefreshStatus.style.color = type === 'error' ? '#be123c' : 'var(--gc-text-soft)';
        };

        const renderBookingCrmCard = (appointment) => {
            const customerName = `${appointment.last_name || ''} ${appointment.first_name || ''}`.trim();
            const hasCoordinates = Number.isFinite(Number(appointment.latitude)) && Number.isFinite(Number(appointment.longitude));
            const serviceBadge = appointment.service
                ? `<span class="rounded-lg px-2 py-1 text-xs" style="background:#dcfce7;color:#15803d;">${escapeHtml(appointment.service.type)}</span>`
                : '<span class="rounded-lg px-2 py-1 text-xs" style="background:#fee2e2;color:#be123c;">Service non renseigné</span>';
            const gpsBadge = hasCoordinates
                ? ''
                : '<span class="rounded-lg px-2 py-1 text-xs" style="background:#fff7ed;color:#c2410c;">GPS à recalculer</span>';

            return `
                <div
                    role="button"
                    tabindex="0"
                    class="crm-appointment-card grid w-full grid-cols-1 items-center gap-2 rounded-xl border px-3 py-2 text-left transition hover:shadow-sm md:grid-cols-[minmax(160px,1fr)_140px_minmax(220px,1.4fr)_auto]"
                    style="border-color:var(--gc-border);background:#ffffff;"
                    data-crm-id="${escapeHtml(appointment.id)}"
                    data-client="${escapeHtml(customerName.toLowerCase())}"
                    data-has-coordinates="${hasCoordinates ? '1' : '0'}"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold" style="color:var(--gc-text);">${escapeHtml(customerName)}</p>
                        <p class="truncate text-xs" style="color:var(--gc-text-soft);">${escapeHtml(appointment.source)}</p>
                    </div>
                    <p class="truncate text-sm" style="color:var(--gc-text-soft);">${escapeHtml(appointment.phone)}</p>
                    <p class="truncate text-sm" style="color:var(--gc-text);">${escapeHtml(appointment.address)}</p>
                    <div class="flex flex-wrap items-center gap-2 md:justify-end">
                        <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">Dép. ${escapeHtml(appointment.department_code)}</span>
                        ${serviceBadge}
                        ${gpsBadge}
                        <button type="button" class="crm-appointment-detail-button inline-flex h-8 w-8 items-center justify-center rounded-lg border transition hover:shadow-sm" style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);" data-crm-detail-id="${escapeHtml(appointment.id)}" title="Voir le détail du RDV" aria-label="Voir le détail du RDV">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2.75 12s3.25-6.25 9.25-6.25S21.25 12 21.25 12 18 18.25 12 18.25 2.75 12 2.75 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M12 14.75A2.75 2.75 0 1 0 12 9.25a2.75 2.75 0 0 0 0 5.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        };

        const setBookingExternalSourceStatus = (sourceKey, apiStatus) => {
            if (!apiStatus) return;

            const state = apiStatus.state || 'unavailable';
            const style = externalRefreshStatusStyles[state] || externalRefreshStatusStyles.unavailable;
            const button = document.querySelector(`[data-external-refresh-source="${sourceKey}"]`);

            if (!button) return;

            button.dataset.apiState = state;
            button.dataset.apiLabel = apiStatus.label || '';
            button.dataset.apiDetail = apiStatus.detail || '';
            button.dataset.apiProgress = String(apiStatus.progress ?? button.dataset.apiProgress ?? (state === 'syncing' ? 5 : 100));
            button.dataset.apiStage = apiStatus.stage || apiStatus.message || button.dataset.apiDetail || '';
            button.title = button.dataset.apiDetail || button.textContent.trim();
            button.style.setProperty('background', style.background);
            button.style.setProperty('color', style.color);
            button.style.setProperty('border-color', style.borderColor);
            renderExternalRefreshButton(button);
        };

        const renderExternalRefreshButton = (button) => {
            const state = button.dataset.apiState || 'unavailable';
            const progress = clampProgress(button.dataset.apiProgress ?? (state === 'syncing' ? 5 : 100));
            const label = button.dataset.refreshLabel || button.textContent.trim();
            const labelElement = button.querySelector('[data-external-refresh-label]');
            const progressLabel = button.querySelector('[data-external-refresh-progress-label]');
            const progressBar = button.querySelector('[data-external-refresh-progress-bar]');
            const isSyncing = state === 'syncing';

            if (labelElement) {
                labelElement.textContent = isSyncing ? 'Synchronisation Coffrac' : label;
            }

            if (progressLabel) {
                progressLabel.textContent = `${progress}%`;
                progressLabel.classList.toggle('hidden', !isSyncing);
            }

            if (progressBar) {
                progressBar.style.width = `${progress}%`;
                progressBar.classList.toggle('hidden', !isSyncing);
            }
        };

        const bookingExternalStatusMessage = (apiStatus) => {
            if (!apiStatus) return '';

            const progress = clampProgress(apiStatus.progress ?? (apiStatus.state === 'syncing' ? 5 : 100));
            const pieces = [];

            if (apiStatus.state === 'syncing') {
                pieces.push(`${progress}%`);
            }

            pieces.push(apiStatus.stage || apiStatus.detail || apiStatus.label || 'Statut Coffrac inconnu.');

            if (Number.isFinite(Number(apiStatus.count))) {
                const totalCount = Number(apiStatus.count);
                const displayedCount = Number.isFinite(Number(apiStatus.displayed_count))
                    ? Number(apiStatus.displayed_count)
                    : totalCount;

                pieces.push(displayedCount < totalCount
                    ? `${displayedCount}/${totalCount} RDV à placer affichés`
                    : `${totalCount} RDV à placer en local`);
            }

            if (Number(apiStatus.missing_coordinates_count || 0) > 0) {
                pieces.push(`${Number(apiStatus.missing_coordinates_count)} à regéocoder`);
            }

            return pieces.join(' · ');
        };

        const renderBookingCrmAppointments = (appointments, apiStatus = null, options = {}) => {
            if (!bookingCrmGrid) return;

            const preserveUi = Boolean(options.preserveUi);
            const previousSearch = bookingCrmSearch.value;
            const previousPage = bookingCrmPage;

            bookingCrmAppointments = Array.isArray(appointments) ? appointments : [];
            bookingCrmGrid.innerHTML = bookingCrmAppointments.map(renderBookingCrmCard).join('');
            bookingCrmCards = Array.from(bookingCrmGrid.querySelectorAll('.crm-appointment-card'));
            setBookingExternalSourceStatus('coffrac', apiStatus);
            bookingCrmSearch.value = preserveUi ? previousSearch : '';
            bookingCrmPage = preserveUi ? previousPage : 1;
            bindBookingCrmCards();
            filterBookingCrmCards();
        };

        const refreshBookingCrmAppointments = async () => {
            if (!bookingCrmRefreshButton || bookingCrmRefreshButton.disabled) return;

            bookingCrmRefreshButton.disabled = true;
            setBookingExternalSourceStatus('coffrac', {
                state: 'syncing',
                label: 'Synchronisation Coffrac en cours',
                detail: 'Synchronisation Coffrac lancée...',
                progress: 3,
                stage: 'Synchronisation Coffrac lancée...',
            });
            setBookingCrmRefreshStatus('Lancement de la synchronisation Coffrac...');
            startExternalSyncPolling();

            try {
                const response = await fetch(bookingCrmRefreshUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Actualisation Coffrac impossible.');
                }

                renderBookingCrmAppointments(payload.appointments || [], payload.coffrac_api_status || null, { preserveUi: true });
                setBookingCrmRefreshStatus(bookingExternalStatusMessage(payload.coffrac_api_status) || payload.message || `${(payload.appointments || []).length} RDV Coffrac disponibles en local.`);
            } catch (error) {
                setBookingCrmRefreshStatus(error.message || 'Actualisation Coffrac impossible.', 'error');
            } finally {
                bookingCrmRefreshButton.disabled = false;
            }
        };

        const loadBookingCrmAppointmentsFromLocal = async (options = {}) => {
            const response = await fetch(bookingCrmIndexUrl, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || 'Rechargement des RDV Coffrac impossible.');
            }

            renderBookingCrmAppointments(payload.appointments || [], payload.coffrac_api_status || null, {
                preserveUi: Boolean(options.preserveUi),
            });

            if (!options.quiet) {
                setBookingCrmRefreshStatus(bookingExternalStatusMessage(payload.coffrac_api_status) || 'RDV Coffrac rechargés depuis la base locale.');
            }

            return payload;
        };

        const stopExternalSyncPolling = () => {
            window.clearInterval(externalSyncPollingTimer);
            externalSyncPollingTimer = null;
            externalSyncPollStartedAt = null;
        };

        const startExternalSyncPolling = () => {
            stopExternalSyncPolling();
            externalSyncPollStartedAt = Date.now();

            externalSyncPollingTimer = window.setInterval(async () => {
                try {
                    const payload = await loadBookingCrmAppointmentsFromLocal({ preserveUi: true, quiet: true });
                    const status = payload.coffrac_api_status || {};

                    setBookingCrmRefreshStatus(bookingExternalStatusMessage(status));

                    if (status.state !== 'syncing') {
                        stopExternalSyncPolling();
                        return;
                    }

                    if (clampProgress(status.progress || 0) <= 3 && externalSyncPollStartedAt && Date.now() - externalSyncPollStartedAt > 30000) {
                        setBookingCrmRefreshStatus('La synchronisation est toujours en attente de démarrage. Vérifie que le worker de queue tourne bien.', 'error');
                    }
                } catch (error) {
                    setBookingCrmRefreshStatus(error.message || 'Impossible de relire le statut Coffrac.', 'error');
                }
            }, 2500);
        };

        const scheduleBookingCrmLocalRefresh = () => {
            window.clearTimeout(externalSyncLocalRefreshTimer);
            externalSyncLocalRefreshTimer = window.setTimeout(async () => {
                try {
                    await loadBookingCrmAppointmentsFromLocal();
                } catch (error) {
                    setBookingCrmRefreshStatus(error.message || 'Rechargement des RDV Coffrac impossible.', 'error');
                }
            }, 500);
        };

        const handleExternalApiSyncProgress = (payload) => {
            if (!payload || payload.source !== 'coffrac') return;

            const state = payload.state === 'available'
                ? 'available'
                : (payload.state === 'syncing' ? 'syncing' : 'unavailable');
            const label = state === 'available'
                ? 'API Coffrac disponible'
                : (state === 'syncing' ? 'Synchronisation Coffrac en cours' : 'API Coffrac indisponible');

            setBookingExternalSourceStatus('coffrac', {
                state,
                label,
                detail: payload.message || payload.stage || label,
                progress: payload.progress ?? (state === 'syncing' ? 5 : 100),
                stage: payload.stage || payload.message || label,
            });
            setBookingCrmRefreshStatus(payload.stage || payload.message || label, state === 'unavailable' ? 'error' : 'info');

            if (state !== 'syncing') {
                stopExternalSyncPolling();
                scheduleBookingCrmLocalRefresh();
            } else if (!externalSyncPollingTimer) {
                startExternalSyncPolling();
            }
        };

        const subscribeToExternalApiSync = () => {
            bookingExternalRefreshButtons.forEach((button) => renderExternalRefreshButton(button));

            if (!window.TechCalendarReverb?.subscribePrivate) {
                return;
            }

            externalSyncSubscription?.unsubscribe?.();
            externalSyncSubscription = window.TechCalendarReverb.subscribePrivate(
                'external-api-sync.coffrac',
                'external-api-sync.progressed',
                handleExternalApiSyncProgress,
                {
                    onError: () => {
                        if (bookingCrmRefreshButton?.dataset.apiState === 'syncing') {
                            setBookingCrmRefreshStatus('Reverb indisponible, le statut se mettra à jour au prochain rechargement.', 'error');
                        }
                    },
                },
            );
        };

        const renderBookingCrmPagination = (visibleCount) => {
            if (!bookingCrmPagination) return;

            const totalPages = Math.max(1, Math.ceil(visibleCount / bookingCrmPageSize));

            if (bookingSourceMode !== 'crm' || totalPages <= 1) {
                bookingCrmPagination.innerHTML = '';
                bookingCrmPagination.classList.add('hidden');
                return;
            }

            bookingCrmPagination.classList.remove('hidden');
            bookingCrmPagination.innerHTML = `
                <button type="button" class="gc-btn-soft px-3 py-2 text-xs" data-crm-page-action="prev" ${bookingCrmPage <= 1 ? 'disabled' : ''}>Précédent</button>
                <span class="rounded-full px-3 py-2 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">Page ${bookingCrmPage}/${totalPages}</span>
                <button type="button" class="gc-btn-soft px-3 py-2 text-xs" data-crm-page-action="next" ${bookingCrmPage >= totalPages ? 'disabled' : ''}>Suivant</button>
            `;

            bookingCrmPagination.querySelectorAll('[data-crm-page-action]').forEach((button) => {
                button.addEventListener('click', () => {
                    const direction = button.dataset.crmPageAction === 'next' ? 1 : -1;
                    bookingCrmPage = Math.min(totalPages, Math.max(1, bookingCrmPage + direction));
                    filterBookingCrmCards();
                });
            });
        };

        const filterBookingCrmCards = () => {
            const query = bookingCrmSearch.value.trim().toLowerCase();
            const matchingCards = bookingCrmCards.filter((card) => query === '' || card.dataset.client.includes(query));
            const totalPages = Math.max(1, Math.ceil(matchingCards.length / bookingCrmPageSize));

            bookingCrmPage = Math.min(bookingCrmPage, totalPages);
            const pageStart = (bookingCrmPage - 1) * bookingCrmPageSize;
            const pageCards = new Set(matchingCards.slice(pageStart, pageStart + bookingCrmPageSize));

            bookingCrmCards.forEach((card) => {
                const isVisible = bookingSourceMode === 'crm' && pageCards.has(card);
                card.classList.toggle('hidden', !isVisible);
            });

            bookingCrmEmpty.classList.toggle('hidden', bookingSourceMode !== 'crm' || matchingCards.length > 0);
            renderBookingCrmPagination(matchingCards.length);
        };

        const setBookingSourceMode = (mode) => {
            bookingSourceMode = mode === 'lot' ? 'lot' : 'crm';
            const isLotMode = bookingSourceMode === 'lot';

            bookingCrmSource?.classList.toggle('hidden', isLotMode);
            bookingLotSource?.classList.toggle('hidden', !isLotMode);
            bookingCrmSearchWrap?.classList.toggle('hidden', isLotMode);
            bookingExternalRefreshButtons.forEach((button) => button.classList.toggle('hidden', isLotMode));
            bookingCrmRefreshStatus?.classList.toggle('hidden', isLotMode || bookingCrmRefreshStatus.textContent === '');
            bookingCrmEmpty?.classList.add('hidden');
            bookingSourceSwitch?.setAttribute('aria-checked', String(isLotMode));
            bookingSourceSwitch?.style.setProperty('background', isLotMode ? '#d8c27a' : 'var(--gc-primary)');
            bookingSourceSwitchKnob?.style.setProperty('transform', isLotMode ? 'translateX(1rem)' : 'translateX(0)');

            if (isLotMode) {
                bookingCrmPagination?.classList.add('hidden');
                bookingCrmCards.forEach((card) => card.classList.add('hidden'));
                return;
            }

            bookingCrmPage = 1;
            filterBookingCrmCards();
        };

        const clampProgress = (value) => Math.max(0, Math.min(100, Math.round(Number(value || 0))));

        const setProgressBar = (bar, label, progress) => {
            const safeProgress = clampProgress(progress);

            if (bar) bar.style.width = `${safeProgress}%`;
            if (label) label.textContent = `${safeProgress}%`;

            return safeProgress;
        };

        const nextSoftProgress = (progress, cap = 92) => {
            if (progress >= cap) return progress;

            const step = progress < 45 ? 4 : (progress < 75 ? 2 : 0.7);

            return Math.min(cap, progress + step);
        };

        const finishProgress = (setProgress, stopTimer, hide, message = null) => new Promise((resolve) => {
            stopTimer();
            let current = setProgress(null, message);
            const timer = window.setInterval(() => {
                current = setProgress(Math.min(100, current + (current < 85 ? 8 : 16)), message);

                if (current >= 100) {
                    window.clearInterval(timer);
                    window.setTimeout(() => {
                        hide();
                        resolve();
                    }, 160);
                }
            }, 70);
        });

        const stopBookingAnalysisLoader = () => {
            if (bookingAnalysisProgressTimer) {
                window.clearInterval(bookingAnalysisProgressTimer);
                bookingAnalysisProgressTimer = null;
            }
        };

        const setBookingAnalysisProgress = (progress = null, detail = null) => {
            if (progress !== null) {
                bookingAnalysisProgress = setProgressBar(bookingAnalysisLoaderBar, bookingAnalysisLoaderLabel, progress);
            }

            if (detail) {
                bookingAnalysisLoaderDetail.textContent = detail;
            }

            return bookingAnalysisProgress;
        };

        const startBookingAnalysisLoader = (sourceLabel) => {
            stopBookingAnalysisLoader();
            bookingAnalysisProgress = 8;
            bookingAnalysisLoaderTitle.textContent = `Analyse ${sourceLabel.toLowerCase()}`;
            bookingAnalysisLoaderDetail.textContent = 'Lecture des données du rendez-vous...';
            setProgressBar(bookingAnalysisLoaderBar, bookingAnalysisLoaderLabel, bookingAnalysisProgress);
            bookingAnalysisLoader.classList.remove('hidden');

            const stages = [
                'Vérification du département et de la prestation...',
                'Recherche des techniciens éligibles...',
                'Calcul des trajets et contraintes horaires...',
                'Préparation de la carte et du calendrier...',
            ];

            bookingAnalysisProgressTimer = window.setInterval(() => {
                bookingAnalysisProgress = nextSoftProgress(bookingAnalysisProgress, 91);
                setProgressBar(bookingAnalysisLoaderBar, bookingAnalysisLoaderLabel, bookingAnalysisProgress);
                bookingAnalysisLoaderDetail.textContent = stages[Math.min(stages.length - 1, Math.floor(bookingAnalysisProgress / 25))];
            }, 360);
        };

        const finishBookingAnalysisLoader = (message = 'Analyse terminée.') => finishProgress(
            setBookingAnalysisProgress,
            stopBookingAnalysisLoader,
            () => bookingAnalysisLoader.classList.add('hidden'),
            message,
        );

        const scrollToBookingResults = () => {
            if (!bookingResultsAnchor) return;

            bookingResultsAnchor.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });

            window.setTimeout(() => {
                bookingMap?.resize?.();
                bookingCalendar?.updateSize?.();
            }, 360);
        };

        const stopBookingCalendarLoader = () => {
            if (bookingCalendarProgressTimer) {
                window.clearInterval(bookingCalendarProgressTimer);
                bookingCalendarProgressTimer = null;
            }
        };

        const setBookingCalendarProgress = (progress = null, detail = null) => {
            if (progress !== null) {
                bookingCalendarProgress = setProgressBar(bookingCalendarLoaderBar, bookingCalendarLoaderLabel, progress);
            }

            if (detail) {
                bookingCalendarLoaderDetail.textContent = detail;
            }

            return bookingCalendarProgress;
        };

        const showCalendarLoader = () => {
            stopBookingCalendarLoader();
            bookingCalendarProgress = 12;
            bookingCalendarLoader?.classList.remove('hidden');
            setBookingCalendarProgress(bookingCalendarProgress, 'Calcul des propositions pour la semaine affichée...');
            bookingCalendarProgressTimer = window.setInterval(() => {
                bookingCalendarProgress = nextSoftProgress(bookingCalendarProgress, 90);
                setBookingCalendarProgress(bookingCalendarProgress, bookingCalendarProgress < 70
                    ? 'Vérification des disponibilités des techniciens...'
                    : 'Assemblage des propositions dans le calendrier...');
            }, 300);
        };

        const hideCalendarLoader = (immediate = false) => {
            if (immediate) {
                stopBookingCalendarLoader();
                bookingCalendarLoader?.classList.add('hidden');
                return;
            }

            void finishProgress(
                setBookingCalendarProgress,
                stopBookingCalendarLoader,
                () => bookingCalendarLoader?.classList.add('hidden'),
                'Calendrier prêt.',
            );
        };

        const setManualStatus = (message, type = 'info') => {
            manualBookingStatus.textContent = message;
            manualBookingStatus.style.color = type === 'error' ? '#be123c' : '#0f766e';
            manualBookingStatus.classList.remove('hidden');
        };

        const clearManualStatus = () => {
            manualBookingStatus.textContent = '';
            manualBookingStatus.classList.add('hidden');
        };

        const extractDepartmentCode = (feature) => {
            const contexts = Array.isArray(feature?.context) ? feature.context : [];
            const postcodeContext = contexts.find((context) => String(context.id || '').startsWith('postcode'));
            const postcode = postcodeContext?.text || feature?.properties?.postcode || feature?.place_name?.match(/\b\d{5}\b/)?.[0] || '';

            if (!postcode) return '';

            return String(postcode).slice(0, 3).startsWith('97')
                ? String(postcode).slice(0, 3)
                : String(postcode).slice(0, 2).toUpperCase();
        };

        const initManualAddressAutocomplete = () => {
            const addressInput = document.getElementById('manual_address');
            const departmentCodeInput = document.getElementById('manual_department_code');
            const latInput = document.getElementById('manual_latitude');
            const lngInput = document.getElementById('manual_longitude');

            mapboxDebug('init manual address autocomplete', {
                address_input_found: Boolean(addressInput),
                department_input_found: Boolean(departmentCodeInput),
                lat_input_found: Boolean(latInput),
                lng_input_found: Boolean(lngInput),
            });

            if (!addressInput || !departmentCodeInput || !latInput || !lngInput) return;

            let list = addressInput.parentElement.querySelector('.gc-mapbox-suggestions');
            if (!list) {
                list = document.createElement('div');
                list.className = 'gc-mapbox-suggestions hidden';
                addressInput.parentElement.appendChild(list);
            }

            if (!bookingMapboxToken) {
                mapboxDebug('manual autocomplete blocked: token missing');
                addressInput.addEventListener('focus', () => setManualStatus('Token Mapbox absent: impossible de récupérer les coordonnées automatiquement.', 'error'));
                return;
            }

            if (addressInput.dataset.mapboxBound === '1') {
                mapboxDebug('manual autocomplete already bound');
                return;
            }
            addressInput.dataset.mapboxBound = '1';
            mapboxDebug('manual autocomplete bound');

            let debounceTimer;
            addressInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                const query = addressInput.value.trim();
                departmentCodeInput.value = '';
                latInput.value = '';
                lngInput.value = '';

                if (query.length < 3) {
                    list.innerHTML = '';
                    list.classList.add('hidden');
                    return;
                }

                debounceTimer = setTimeout(async () => {
                    const url = new URL(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json`);
                    url.searchParams.set('access_token', bookingMapboxToken);
                    url.searchParams.set('autocomplete', 'true');
                    url.searchParams.set('language', 'fr');
                    url.searchParams.set('country', 'fr');
                    url.searchParams.set('limit', '5');

                    try {
                        mapboxDebug('geocoding request', {
                            query,
                            url: sanitizeMapboxUrl(url),
                        });
                        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                        const data = await response.json();
                        const features = Array.isArray(data.features) ? data.features : [];

                        mapboxDebug('geocoding response', {
                            ok: response.ok,
                            status: response.status,
                            status_text: response.statusText,
                            features_count: features.length,
                            mapbox_code: data.code || null,
                            mapbox_message: data.message || null,
                        });

                        if (features.length === 0) {
                            list.innerHTML = '';
                            list.classList.add('hidden');
                            return;
                        }

                        list.innerHTML = features.map((feature) => {
                            const address = escapeHtml(feature.place_name || '');
                            const departmentCode = escapeHtml(extractDepartmentCode(feature));
                            const lng = escapeHtml(feature.center?.[0] ?? '');
                            const lat = escapeHtml(feature.center?.[1] ?? '');

                            return `<button type="button" class="gc-mapbox-item" data-address="${address}" data-department-code="${departmentCode}" data-lng="${lng}" data-lat="${lat}">${address}</button>`;
                        }).join('');
                        list.classList.remove('hidden');

                        list.querySelectorAll('.gc-mapbox-item').forEach((item) => {
                            item.addEventListener('click', () => {
                                addressInput.value = item.dataset.address || '';
                                departmentCodeInput.value = item.dataset.departmentCode || '';
                                lngInput.value = item.dataset.lng || '';
                                latInput.value = item.dataset.lat || '';
                                list.innerHTML = '';
                                list.classList.add('hidden');
                                clearManualStatus();
                            });
                        });
                    } catch (error) {
                        setManualStatus('Erreur Mapbox pendant la recherche adresse.', 'error');
                    }
                }, 280);
            });
        };

        const initBookingMap = () => {
            mapboxDebug('init booking map', {
                container_found: Boolean(document.getElementById('booking-map')),
                existing_map: Boolean(bookingMap),
            });

            if (!bookingMapboxToken || !window.mapboxgl) {
                mapboxDebug('booking map blocked: missing token or mapboxgl');
                showMapboxUnavailable('booking-map', !bookingMapboxToken
                    ? 'Token Mapbox absent côté Laravel.'
                    : 'La librairie Mapbox GL JS n’est pas chargée. Vérifie la CSP script-src/script-src-elem et le chargement du CDN.');
                return null;
            }

            window.mapboxgl.accessToken = bookingMapboxToken;

            if (bookingMap) {
                bookingMap.resize();
                mapboxDebug('booking map reused and resized');
                return bookingMap;
            }

            bookingMap = new window.mapboxgl.Map({
                container: 'booking-map',
                style: 'mapbox://styles/mapbox/light-v11',
                center: [2.4, 46.7],
                zoom: 5,
            });
            bookingMap.on('load', () => mapboxDebug('booking map load event'));
            mapboxDebug('booking map instance created');

            return bookingMap;
        };

        const markerElement = (color, label = '') => {
            const element = document.createElement('div');
            element.className = 'flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-[10px] font-bold shadow-lg';
            element.style.background = color;
            element.style.color = '#fff';
            element.textContent = label;
            return element;
        };

        const crmAppointmentById = (crmId) => bookingCrmAppointments
            .find((appointment) => String(appointment.id) === String(crmId)) || null;

        const safeExternalDocumentUrl = (url) => {
            const normalizedUrl = String(url || '').trim();

            return /^https?:\/\//i.test(normalizedUrl) ? normalizedUrl : '';
        };

        const crmDetailInfoRow = (label, value) => `
            <div>
                <dt style="color:var(--gc-text-soft);">${escapeHtml(label)}</dt>
                <dd class="font-medium" style="color:var(--gc-text);">${escapeHtml(value || '-')}</dd>
            </div>
        `;

        const setCrmDetailStatus = (message = '', type = 'info') => {
            if (!bookingCrmDetailStatus) return;

            bookingCrmDetailStatus.textContent = message;
            bookingCrmDetailStatus.classList.toggle('hidden', message === '');
            bookingCrmDetailStatus.style.color = type === 'error' ? '#be123c' : '#0f766e';
        };

        const setCrmDetailSaving = (isSaving) => {
            if (!bookingCrmDetailSave) return;

            bookingCrmDetailSave.disabled = isSaving;
            bookingCrmDetailSave.textContent = isSaving ? 'Géocodage...' : 'Enregistrer et regéocoder';
        };

        const fillCrmDetailForm = (appointment) => {
            if (bookingCrmDetailService) {
                bookingCrmDetailService.value = appointment?.service?.id ? String(appointment.service.id) : '';
            }

            if (bookingCrmDetailAddress) {
                bookingCrmDetailAddress.value = appointment?.address || '';
            }

            if (bookingCrmDetailComment) {
                bookingCrmDetailComment.value = appointment?.comment || appointment?.external_payload?.comment || '';
            }

            setCrmDetailStatus();
        };

        const renderCrmDetailDocuments = (documents) => {
            const list = document.getElementById('booking_crm_detail_documents');
            const count = document.getElementById('booking_crm_detail_documents_count');
            const safeDocuments = Array.isArray(documents) ? documents : [];

            count.textContent = `${safeDocuments.length} document(s)`;

            if (safeDocuments.length === 0) {
                list.innerHTML = '<p class="text-sm" style="color:var(--gc-text-soft);">Aucun document associé à ce RDV.</p>';
                return;
            }

            list.innerHTML = safeDocuments.map((document, index) => {
                const name = document.name || document.title || document.filename || document.original_name || `Document ${index + 1}`;
                const scope = document.scope || document.type || '';
                const url = safeExternalDocumentUrl(document.url || document.download_url || document.href);

                return `
                    <div class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2" style="border-color:var(--gc-border);background:#ffffff;">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium" style="color:var(--gc-text);">${escapeHtml(name)}</p>
                            ${scope ? `<p class="mt-0.5 truncate text-xs" style="color:var(--gc-text-soft);">${escapeHtml(scope)}</p>` : ''}
                        </div>
                        ${url
                            ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="gc-link shrink-0">Ouvrir</a>`
                            : '<span class="shrink-0 text-xs" style="color:var(--gc-text-soft);">Lien absent</span>'}
                    </div>
                `;
            }).join('');
        };

        const initCrmDetailMap = () => {
            if (!bookingMapboxToken || !window.mapboxgl) {
                showMapboxUnavailable('booking-crm-detail-map', !bookingMapboxToken
                    ? 'Token Mapbox absent côté Laravel.'
                    : 'La librairie Mapbox GL JS n’est pas chargée.');
                return null;
            }

            window.mapboxgl.accessToken = bookingMapboxToken;

            if (crmDetailMap) {
                crmDetailMap.resize();
                return crmDetailMap;
            }

            crmDetailMap = new window.mapboxgl.Map({
                container: 'booking-crm-detail-map',
                style: 'mapbox://styles/mapbox/light-v11',
                center: [2.4, 46.7],
                zoom: 5,
            });
            crmDetailMap.addControl(new window.mapboxgl.NavigationControl({ showCompass: false }), 'top-right');

            return crmDetailMap;
        };

        const clearCrmDetailMap = () => {
            crmDetailMapMarkers.forEach((marker) => marker.remove());
            crmDetailMapMarkers = [];
        };

        const renderCrmDetailMap = (appointment) => {
            const latitude = Number(appointment?.latitude);
            const longitude = Number(appointment?.longitude);
            const container = document.getElementById('booking-crm-detail-map');

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                if (container) {
                    container.innerHTML = `
                        <div class="flex h-full items-center justify-center rounded-2xl border border-dashed px-5 text-center" style="border-color:var(--gc-border);background:var(--gc-accent-soft);color:var(--gc-text-soft);">
                            Coordonnées GPS indisponibles pour ce RDV.
                        </div>
                    `;
                }
                return;
            }

            const map = initCrmDetailMap();
            if (!map) return;

            const drawPoint = () => {
                clearCrmDetailMap();

                crmDetailMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement('#31424c', 'R') })
                    .setLngLat([longitude, latitude])
                    .setPopup(new window.mapboxgl.Popup().setHTML(`<strong>RDV</strong><br>${escapeHtml(appointment.address || '')}`))
                    .addTo(map));

                map.flyTo({
                    center: [longitude, latitude],
                    zoom: 13,
                    essential: true,
                });
            };

            if (map.loaded()) {
                drawPoint();
            } else {
                map.once('load', drawPoint);
            }
        };

        const openCrmAppointmentDetail = (crmId) => {
            const appointment = crmAppointmentById(crmId);

            if (!appointment || !bookingCrmDetailModal) {
                showFeedback('Impossible de retrouver ce RDV dans les données locales.', 'error');
                return;
            }

            currentCrmDetailAppointmentId = appointment.id;
            const customerName = `${appointment.last_name || ''} ${appointment.first_name || ''}`.trim() || 'Client';
            const serviceLabel = appointment.service
                ? `${appointment.service.type} - ${appointment.service.name}`
                : 'Prestation non renseignée';
            const addressParts = [
                appointment.address,
                appointment.postal_code || null,
                appointment.city || null,
            ].filter(Boolean);

            document.getElementById('booking_crm_detail_title').textContent = customerName;
            document.getElementById('booking_crm_detail_subtitle').textContent = `${appointment.source || 'Source externe'} · Réf. ${appointment.external_reference || appointment.id || '-'}`;
            document.getElementById('booking_crm_detail_infos').innerHTML = [
                crmDetailInfoRow('Source', appointment.source || '-'),
                crmDetailInfoRow('Référence', appointment.external_reference || appointment.id || '-'),
                crmDetailInfoRow('Client', customerName),
                crmDetailInfoRow('Téléphone', appointment.phone || '-'),
                crmDetailInfoRow('Prestation', serviceLabel),
                crmDetailInfoRow('Département', appointment.department_code || '-'),
                crmDetailInfoRow('Adresse actuelle', addressParts.join(' · ') || '-'),
                crmDetailInfoRow('GPS', Number.isFinite(Number(appointment.latitude)) && Number.isFinite(Number(appointment.longitude))
                    ? `${Number(appointment.latitude).toFixed(6)}, ${Number(appointment.longitude).toFixed(6)}`
                    : '-'),
            ].join('');
            fillCrmDetailForm(appointment);
            renderCrmDetailDocuments(appointment.documents || []);

            bookingCrmDetailModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';

            requestAnimationFrame(() => {
                renderCrmDetailMap(appointment);
                window.setTimeout(() => crmDetailMap?.resize?.(), 160);
            });
        };

        const closeCrmAppointmentDetail = () => {
            bookingCrmDetailModal?.classList.add('hidden');
            currentCrmDetailAppointmentId = null;
            setCrmDetailStatus();
            clearCrmDetailMap();

            if (bookingAppointmentModal?.classList.contains('hidden')) {
                document.body.style.overflow = '';
            }
        };

        const saveCrmAppointmentDetail = async () => {
            if (!currentCrmDetailAppointmentId) return;

            const address = bookingCrmDetailAddress?.value.trim() || '';

            if (address === '') {
                setCrmDetailStatus('Adresse obligatoire pour regéocoder le RDV.', 'error');
                return;
            }

            setCrmDetailSaving(true);
            setCrmDetailStatus('Géocodage Mapbox et sauvegarde en cours...');

            try {
                const response = await fetch(
                    bookingCrmUpdateUrlTemplate.replace('__CRM_APPOINTMENT__', encodeURIComponent(currentCrmDetailAppointmentId)),
                    {
                        method: 'PATCH',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': bookingCsrfToken,
                        },
                        body: JSON.stringify({
                            service_id: bookingCrmDetailService?.value || null,
                            address,
                            comment: bookingCrmDetailComment?.value || '',
                        }),
                    },
                );
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
                    throw new Error(firstError || payload.message || 'Impossible de mettre à jour ce RDV.');
                }

                renderBookingCrmAppointments(payload.appointments || bookingCrmAppointments, payload.coffrac_api_status || null);
                openCrmAppointmentDetail(payload.appointment?.id || currentCrmDetailAppointmentId);
                setCrmDetailStatus(payload.message || 'RDV externe mis à jour.');
            } catch (error) {
                setCrmDetailStatus(error.message || 'Impossible de mettre à jour ce RDV.', 'error');
            } finally {
                setCrmDetailSaving(false);
            }
        };

        const clearMap = () => {
            bookingMapMarkers.forEach((marker) => marker.remove());
            bookingMapMarkers = [];

            if (!bookingMap) return;

            [...bookingMap.getStyle().layers]
                .filter((layer) => layer.id.startsWith('tech-route-'))
                .forEach((layer) => bookingMap.removeLayer(layer.id));

            Object.keys(bookingMap.getStyle().sources)
                .filter((source) => source.startsWith('tech-route-'))
                .forEach((source) => bookingMap.removeSource(source));
        };

        const straightRoute = (technician, crmAppointment) => ({
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: [
                    [Number(technician.longitude), Number(technician.latitude)],
                    [Number(crmAppointment.longitude), Number(crmAppointment.latitude)],
                ],
            },
        });

        const fetchRoute = async (technician, crmAppointment) => {
            if (!bookingMapboxToken) {
                mapboxDebug('route request skipped: token missing, using straight route', {
                    technician_id: technician.id,
                });
                return straightRoute(technician, crmAppointment);
            }

            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${technician.longitude},${technician.latitude};${crmAppointment.longitude},${crmAppointment.latitude}`);
            url.searchParams.set('access_token', bookingMapboxToken);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');

            try {
                mapboxDebug('directions request for technician route', {
                    technician_id: technician.id,
                    technician_name: technician.name,
                    url: sanitizeMapboxUrl(url),
                });
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                const route = data.routes?.[0]?.geometry;

                mapboxDebug('directions response for technician route', {
                    technician_id: technician.id,
                    ok: response.ok,
                    status: response.status,
                    status_text: response.statusText,
                    routes_count: Array.isArray(data.routes) ? data.routes.length : 0,
                    has_geometry: Boolean(route),
                    mapbox_code: data.code || null,
                    mapbox_message: data.message || null,
                });

                return route ? { type: 'Feature', geometry: route } : straightRoute(technician, crmAppointment);
            } catch (error) {
                return straightRoute(technician, crmAppointment);
            }
        };

        const fetchRouteBetween = async (origin, destination) => {
            const fallback = () => {
                const distanceKm = haversineDistanceKm(origin.lat, origin.lng, destination.lat, destination.lng);

                return {
                    feature: {
                        type: 'Feature',
                        geometry: {
                            type: 'LineString',
                            coordinates: [[origin.lng, origin.lat], [destination.lng, destination.lat]],
                        },
                    },
                    distance_km: distanceKm,
                    duration_minutes: Math.max(1, Math.round((distanceKm / 65) * 60)),
                    source: 'estimation interne',
                };
            };

            if (!bookingMapboxToken) {
                mapboxDebug('route between skipped: token missing, using fallback', { origin, destination });
                return fallback();
            }

            const url = new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${origin.lng},${origin.lat};${destination.lng},${destination.lat}`);
            url.searchParams.set('access_token', bookingMapboxToken);
            url.searchParams.set('geometries', 'geojson');
            url.searchParams.set('overview', 'full');

            try {
                mapboxDebug('directions request between points', {
                    origin,
                    destination,
                    url: sanitizeMapboxUrl(url),
                });
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                const route = data.routes?.[0];

                mapboxDebug('directions response between points', {
                    ok: response.ok,
                    status: response.status,
                    status_text: response.statusText,
                    routes_count: Array.isArray(data.routes) ? data.routes.length : 0,
                    has_geometry: Boolean(route?.geometry),
                    distance_m: route?.distance || null,
                    duration_s: route?.duration || null,
                    mapbox_code: data.code || null,
                    mapbox_message: data.message || null,
                });

                return route?.geometry ? {
                    feature: { type: 'Feature', geometry: route.geometry },
                    distance_km: Number(route.distance || 0) / 1000,
                    duration_minutes: Math.max(1, Math.round(Number(route.duration || 0) / 60)),
                    source: 'Mapbox voiture',
                } : fallback();
            } catch (error) {
                return fallback();
            }
        };

        const haversineDistanceKm = (fromLat, fromLng, toLat, toLng) => {
            const earthRadiusKm = 6371;
            const toRadians = (value) => value * Math.PI / 180;
            const latDelta = toRadians(toLat - fromLat);
            const lngDelta = toRadians(toLng - fromLng);
            const a = Math.sin(latDelta / 2) ** 2
                + Math.cos(toRadians(fromLat)) * Math.cos(toRadians(toLat)) * Math.sin(lngDelta / 2) ** 2;

            return earthRadiusKm * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
        };

        const formatRouteDuration = (minutes) => {
            const safeMinutes = Math.max(0, Math.round(Number(minutes || 0)));
            const hours = Math.floor(safeMinutes / 60);
            const remainingMinutes = safeMinutes % 60;

            if (hours === 0) {
                return `${remainingMinutes} min`;
            }

            return remainingMinutes > 0 ? `${hours}h${String(remainingMinutes).padStart(2, '0')}` : `${hours}h`;
        };

        const formatRouteDistance = (distanceKm) => `${Number(distanceKm || 0).toFixed(1)} km`;

        const ensureSuggestionTooltip = () => {
            if (bookingSuggestionTooltip) {
                return bookingSuggestionTooltip;
            }

            bookingSuggestionTooltip = document.createElement('div');
            bookingSuggestionTooltip.style.cssText = [
                'position:fixed',
                'z-index:90',
                'display:none',
                'pointer-events:none',
                'max-width:360px',
                'border-radius:16px',
                'padding:12px 14px',
                'background:#31424c',
                'color:#ffffff',
                'box-shadow:0 18px 45px rgba(15,23,42,.28)',
                'font-size:12px',
                'line-height:1.45',
                'transform:translate(-50%, calc(-100% - 14px))',
            ].join(';');
            document.body.appendChild(bookingSuggestionTooltip);

            return bookingSuggestionTooltip;
        };

        const moveSuggestionTooltip = (event) => {
            const tooltip = ensureSuggestionTooltip();
            const safeLeft = Math.min(Math.max(event.clientX, 170), window.innerWidth - 170);
            const safeTop = Math.max(event.clientY, 120);

            tooltip.style.left = `${safeLeft}px`;
            tooltip.style.top = `${safeTop}px`;
        };

        const showSuggestionTooltip = (event, props) => {
            if (!props?.is_suggestion) return;

            const tooltip = ensureSuggestionTooltip();
            const originName = props.origin_name ? String(props.origin_name) : '';
            const hasPreviousAppointment = Boolean(props.has_previous_appointment)
                || String(props.origin_label || '').toLowerCase().includes('rdv');
            const hasNextAppointment = Boolean(props.has_next_appointment || props.next_appointment_id);
            const tooltipRows = [{
                title: 'Domicile → proposition',
                detail: props.technician_address || 'Adresse du technicien',
                distance: props.home_to_distance_km ?? props.travel_to_distance_km,
                duration: props.home_to_minutes ?? props.travel_to_minutes,
            }];

            if (hasPreviousAppointment) {
                tooltipRows.push({
                    title: 'RDV précédent → proposition',
                    detail: originName || 'Dernier RDV de la journée',
                    distance: props.travel_to_distance_km,
                    duration: props.travel_to_minutes,
                });
            }

            if (hasNextAppointment) {
                tooltipRows.push({
                    title: 'Proposition → prochain RDV',
                    detail: 'Trajet nécessaire pour conserver la suite du planning',
                    distance: props.travel_after_distance_km,
                    duration: props.travel_after_minutes,
                });
            } else {
                tooltipRows.push({
                    title: 'Proposition → retour domicile',
                    detail: props.technician_address || 'Adresse du technicien',
                    distance: props.return_home_distance_km ?? props.travel_after_distance_km,
                    duration: props.return_home_minutes ?? props.travel_after_minutes,
                });
            }

            const rowsHtml = tooltipRows.map((row, index) => `
                <div style="${index === 0 ? 'margin-top:10px;' : 'margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:10px;'}">
                    <p style="font-weight:700;">${escapeHtml(row.title)}</p>
                    ${row.detail ? `<p style="margin-top:3px;color:rgba(255,255,255,.72);">${escapeHtml(row.detail)}</p>` : ''}
                    <p style="margin-top:5px;color:rgba(255,255,255,.92);">${escapeHtml(formatRouteDistance(row.distance))} · ${escapeHtml(formatRouteDuration(row.duration))}</p>
                </div>
            `).join('');

            tooltip.innerHTML = `
                <div>
                    <p style="font-weight:800;letter-spacing:.02em;">Distances de la proposition</p>
                    ${rowsHtml}
                </div>
            `;
            tooltip.style.display = 'block';
            moveSuggestionTooltip(event);
        };

        const hideSuggestionTooltip = () => {
            if (bookingSuggestionTooltip) {
                bookingSuggestionTooltip.style.display = 'none';
            }
        };

        const addMinutes = (date, minutes) => new Date(date.getTime() + (Number(minutes || 0) * 60000));

        const requestDurationMinutes = () => Math.max(30, Number(currentAppointmentRequest?.service?.average_duration_minutes || 60));

        const serviceById = (serviceId) => bookingServices.find((service) => String(service.id) === String(serviceId));

        const serviceLabel = (service) => service
            ? `${service.type} - ${service.name}`
            : 'Prestation non renseignée';

        const technicianById = (technicianId) => currentTechnicians.find((technician) => String(technician.id) === String(technicianId));

        const selectedTechnicians = () => currentTechnicians
            .filter((technician) => selectedTechnicianIds.has(String(technician.id)));

        const technicianAbsences = (technician) => Array.isArray(technician?.absences) ? technician.absences : [];

        const technicianIsAbsentAt = (technician, date) => {
            if (!date) return false;

            const checkedAt = new Date(date).getTime();

            return technicianAbsences(technician).some((absence) => {
                const startsAt = absence.starts_at ? new Date(absence.starts_at).getTime() : NaN;
                const endsAt = absence.ends_at ? new Date(absence.ends_at).getTime() : NaN;

                return Number.isFinite(startsAt) && Number.isFinite(endsAt) && startsAt <= checkedAt && endsAt >= checkedAt;
            });
        };

        const selectedAvailableTechnicians = (date) => selectedTechnicians()
            .filter((technician) => !technicianIsAbsentAt(technician, date));

        const absenceBadgesForTechnician = (technician) => technicianAbsences(technician)
            .map((absence) => {
                const label = absence.label || `Abs du ${formatShortDate(absence.starts_at)} au ${formatShortDate(absence.ends_at)}`;
                const reason = absence.reason ? ` · ${absence.reason}` : '';

                return `<span class="rounded-lg px-2 py-1 text-xs font-semibold" style="background:#fee2e2;color:#be123c;">${escapeHtml(label)}${escapeHtml(reason)}</span>`;
            })
            .join('');

        const mergeTechnicianUpdates = (technicians) => {
            (technicians || []).forEach((technician) => {
                const technicianId = String(technician.id);
                const existingIndex = currentTechnicians.findIndex((currentTechnician) => String(currentTechnician.id) === technicianId);

                if (existingIndex >= 0) {
                    currentTechnicians[existingIndex] = {
                        ...currentTechnicians[existingIndex],
                        ...technician,
                    };
                }
            });
        };

        const selectedTechnicianIdsArray = () => selectedTechnicians()
            .map((technician) => Number(technician.id))
            .filter((id) => Number.isFinite(id));

        const visibleCalendarItems = (items) => (items || [])
            .filter((item) => selectedTechnicianIds.has(String(item.extendedProps?.technician_id || '')));

        const refreshTechnicianColors = () => {
            const existingColors = currentTechnicianColors;

            currentTechnicianColors = Object.fromEntries(
                currentTechnicians.map((technician, index) => {
                    const technicianId = String(technician.id);

                    return [technicianId, existingColors[technicianId] || routeColors[index % routeColors.length]];
                })
            );
        };

        const technicianColor = (technicianId) => {
            const normalizedTechnicianId = String(technicianId);
            const technicianIndex = currentTechnicians.findIndex((technician) => String(technician.id) === normalizedTechnicianId);

            return currentTechnicianColors[normalizedTechnicianId]
                || routeColors[Math.max(0, technicianIndex) % routeColors.length]
                || '#31424c';
        };

        const technicianOrdinal = (technicianId) => {
            const technicianIndex = currentTechnicians.findIndex((technician) => String(technician.id) === String(technicianId));

            return technicianIndex >= 0 ? technicianIndex + 1 : null;
        };

        const updateTechnicianSelectionCount = () => {
            if (!technicianSelectionCount) return;

            technicianSelectionCount.textContent = `${selectedTechnicianIds.size}/${currentTechnicians.length} technicien(s) affiche(s)`;
            technicianSelectAllButton?.classList.toggle('hidden', currentTechnicians.length === selectedTechnicianIds.size);
        };

        const upsertTechnician = (technician) => {
            const technicianId = String(technician.id);
            const existingIndex = currentTechnicians.findIndex((currentTechnician) => String(currentTechnician.id) === technicianId);

            if (existingIndex >= 0) {
                currentTechnicians[existingIndex] = {
                    ...currentTechnicians[existingIndex],
                    ...technician,
                };
            } else {
                currentTechnicians.push(technician);
            }

            selectedTechnicianIds.add(technicianId);
        };

        const sameLocalDay = (leftDate, rightDate) => leftDate?.toDateString?.() === rightDate?.toDateString?.();

        const previousAppointmentForTechnician = (technicianId, startsAt) => {
            if (!bookingCalendar) return null;

            return bookingCalendar.getEvents()
                .filter((event) => {
                    const props = event.extendedProps || {};

                    return String(props.technician_id || '') === String(technicianId)
                        && !props.is_suggestion
                        && !props.deleted_at
                        && event.end
                        && event.end <= startsAt
                        && sameLocalDay(event.start, startsAt);
                })
                .sort((left, right) => right.end - left.end)
                .at(0) || null;
        };

        const routeOriginForTechnician = (technician, startsAt) => {
            const previousAppointment = previousAppointmentForTechnician(technician.id, startsAt);

            if (previousAppointment) {
                const props = previousAppointment.extendedProps || {};

                return {
                    label: 'rdv précédent',
                    name: props.customer_name || previousAppointment.title || 'RDV précédent',
                    lat: Number(props.latitude),
                    lng: Number(props.longitude),
                };
            }

            return {
                label: 'domicile',
                name: 'Domicile',
                lat: Number(technician.latitude),
                lng: Number(technician.longitude),
            };
        };

        const serviceLabelForRequest = () => currentAppointmentRequest?.service
            ? `${currentAppointmentRequest.service.type} - ${currentAppointmentRequest.service.name}`
            : 'Prestation non renseignée';

        const draftPropsForTechnician = (technician, startsAt) => {
            const origin = routeOriginForTechnician(technician, startsAt);

            return {
                technician_id: technician.id,
                technician_name: technician.name,
                technician_address: technician.address,
                technician_latitude: routeNumber(technician.latitude),
                technician_longitude: routeNumber(technician.longitude),
                is_suggestion: true,
                is_calendar_click: true,
                allow_technician_change: true,
                origin_label: origin.label,
                origin_latitude: origin.lat,
                origin_longitude: origin.lng,
                origin_name: origin.name,
                latitude: Number(currentAppointmentRequest.latitude),
                longitude: Number(currentAppointmentRequest.longitude),
                address: currentAppointmentRequest.address,
                customer_name: `${currentAppointmentRequest.first_name || ''} ${currentAppointmentRequest.last_name || ''}`.trim(),
                customer_phone: currentAppointmentRequest.phone,
                service_label: serviceLabelForRequest(),
                crm_appointment_id: currentAppointmentRequest.id,
                crm_service_id: null,
                lot_appointment_id: currentAppointmentRequest.lot_appointment_id || null,
                can_validate: Boolean(currentAppointmentRequest.service),
                duration_minutes: requestDurationMinutes(),
                comment: '',
            };
        };

        const applyTechnicianToDraftEvent = (event, technicianId) => {
            const technician = technicianById(technicianId);

            if (!technician) return;

            event.extendedProps = {
                ...(event.extendedProps || {}),
                ...draftPropsForTechnician(technician, event.start),
                duration_minutes: Number(document.getElementById('booking_detail_duration')?.value || event.extendedProps?.duration_minutes || requestDurationMinutes()),
                comment: document.getElementById('booking_detail_comment')?.value || event.extendedProps?.comment || '',
            };

            document.getElementById('booking_detail_technician').textContent = technician.name || '-';
            document.getElementById('booking_detail_origin').textContent = `${event.extendedProps.origin_label || '-'}${event.extendedProps.origin_name ? ` (${event.extendedProps.origin_name})` : ''}`;
            document.getElementById('booking_detail_technician_id').value = technician.id;
            renderRouteSummary(event.extendedProps, null, true);
            if (requiresCrmServiceSelection(event.extendedProps)) {
                syncCrmServiceSelection();
            }
            requestAnimationFrame(() => renderDetailMap(event));
        };

        const syncDraftEventFromInputs = () => {
            if (!selectedCalendarEvent?.extendedProps?.is_calendar_click) return;

            const startValue = document.getElementById('booking_detail_starts_at').value;
            const durationMinutes = Number(document.getElementById('booking_detail_duration').value || requestDurationMinutes());
            const technicianId = document.getElementById('booking_detail_technician_select').value;

            if (startValue) {
                selectedCalendarEvent.start = new Date(startValue);
                selectedCalendarEvent.end = addMinutes(selectedCalendarEvent.start, durationMinutes);
            }

            selectedCalendarEvent.extendedProps.duration_minutes = durationMinutes;
            applyTechnicianToDraftEvent(selectedCalendarEvent, technicianId);
        };

        const openCalendarSlotModal = (info) => {
            if (!currentAppointmentRequest) {
                showFeedback('Lance d’abord une recherche Coffrac ou manuelle avant de placer un RDV depuis le calendrier.', 'error');
                return;
            }

            const startsAt = new Date(info.date);

            if (info.allDay) {
                startsAt.setHours(8, 0, 0, 0);
            }

            const activeTechnicians = selectedAvailableTechnicians(startsAt);

            if (activeTechnicians.length === 0) {
                showFeedback('Aucun technicien sélectionné n’est disponible sur ce créneau: ils sont absents ou non sélectionnés.', 'error');
                return;
            }

            const durationMinutes = requestDurationMinutes();
            const technician = activeTechnicians[0];
            const draftEvent = {
                id: `calendar-draft-${Date.now()}`,
                title: `Placement | ${technician.name}`,
                start: startsAt,
                end: addMinutes(startsAt, durationMinutes),
                extendedProps: draftPropsForTechnician(technician, startsAt),
            };

            openBookingAppointmentModal(draftEvent);
        };

        const renderRouteSummary = (props, route = null, isLoading = false) => {
            const summary = document.getElementById('booking_route_summary');
            const originLabel = props.origin_label || 'origine';
            const originName = props.origin_name ? ` (${props.origin_name})` : '';

            if (isLoading) {
                summary.innerHTML = `
                    <div class="flex items-center justify-between gap-3">
                        <span style="color:var(--gc-text-soft);">Calcul du trajet depuis ${escapeHtml(originLabel)}${escapeHtml(originName)}...</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:#ffffff;color:var(--gc-text);">En cours</span>
                    </div>
                `;
                return;
            }

            if (!route) {
                summary.innerHTML = '<span style="color:var(--gc-text-soft);">Trajet indisponible pour ce rendez-vous.</span>';
                return;
            }

            summary.innerHTML = `
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Origine</p>
                        <p class="mt-1 font-medium">${escapeHtml(originLabel)}${escapeHtml(originName)}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Distance</p>
                        <p class="mt-1 font-medium">${Number(route.distance_km || 0).toFixed(1)} km</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Temps estime</p>
                        <p class="mt-1 font-medium">${escapeHtml(formatRouteDuration(route.duration_minutes))}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Calcul</p>
                        <p class="mt-1 font-medium">${escapeHtml(route.source)}</p>
                    </div>
                </div>
            `;
        };

        const setBookingDayRouteSummary = (content) => {
            const summary = document.getElementById('booking_day_route_summary');
            if (summary) summary.innerHTML = content;
        };

        const routeNumber = (value) => value === null || value === undefined || value === '' ? NaN : Number(value);

        const validRoutePoint = (point) => Number.isFinite(point?.lat) && Number.isFinite(point?.lng);

        const sameEventIdentity = (left, right) => {
            if (!left || !right) return false;
            if (left === right) return true;

            return String(left.id || '') !== '' && String(left.id) === String(right.id || '');
        };

        const technicianHomePoint = (props) => {
            const technician = technicianById(props.technician_id);
            const lat = routeNumber(props.technician_latitude ?? technician?.latitude);
            const lng = routeNumber(props.technician_longitude ?? technician?.longitude);

            return {
                kind: 'home',
                lat,
                lng,
                name: props.technician_address || technician?.address || 'Domicile',
                label: 'Domicile',
            };
        };

        const appointmentPointFromEvent = (event, currentEvent) => {
            const props = event.extendedProps || {};

            return {
                kind: 'appointment',
                lat: routeNumber(props.latitude),
                lng: routeNumber(props.longitude),
                name: props.customer_name || props.service_label || event.title || 'RDV',
                label: props.is_suggestion ? 'Proposition' : 'RDV',
                event,
                isCurrent: sameEventIdentity(event, currentEvent),
            };
        };

        const sameDayAppointmentsForEvent = (currentEvent) => {
            const props = currentEvent.extendedProps || {};
            const calendarEvents = bookingCalendar ? bookingCalendar.getEvents() : [];
            const events = calendarEvents.filter((event) => {
                const eventProps = event.extendedProps || {};

                return String(eventProps.technician_id || '') === String(props.technician_id || '')
                    && sameLocalDay(event.start, currentEvent.start)
                    && !eventProps.is_suggestion
                    && (!eventProps.deleted_at || sameEventIdentity(event, currentEvent))
                    && Number.isFinite(routeNumber(eventProps.latitude))
                    && Number.isFinite(routeNumber(eventProps.longitude));
            });

            if (!events.some((event) => sameEventIdentity(event, currentEvent))) {
                events.push(currentEvent);
            }

            return Array.from(new Map(events.map((event) => [String(event.id), event])).values())
                .filter((event) => event.start)
                .sort((left, right) => left.start - right.start);
        };

        const buildBookingDayRouteSegments = (event) => {
            const props = event.extendedProps || {};
            const home = technicianHomePoint(props);

            if (!validRoutePoint(home)) return [];

            const appointmentPoints = sameDayAppointmentsForEvent(event)
                .map((appointmentEvent) => appointmentPointFromEvent(appointmentEvent, event))
                .filter(validRoutePoint);

            if (appointmentPoints.length === 0) return [];

            const points = [
                home,
                ...appointmentPoints,
                { ...home, label: 'Retour domicile', isReturnHome: true },
            ];

            return points.slice(0, -1).map((from, index) => {
                const to = points[index + 1];
                const isCurrent = Boolean(to.isCurrent);

                return {
                    from,
                    to,
                    isCurrent,
                    badge: isCurrent
                        ? 'Trajet vers ce RDV'
                        : (from.isCurrent ? 'Suite de journée' : (to.kind === 'home' ? 'Retour domicile' : 'Autre trajet')),
                };
            });
        };

        const fallbackCurrentSegment = (event) => {
            const props = event.extendedProps || {};
            const origin = {
                kind: 'origin',
                lat: routeNumber(props.origin_latitude),
                lng: routeNumber(props.origin_longitude),
                name: props.origin_name || props.origin_label || 'Origine',
                label: props.origin_label || 'Origine',
            };
            const destination = {
                kind: 'appointment',
                lat: routeNumber(props.latitude),
                lng: routeNumber(props.longitude),
                name: props.customer_name || event.title || 'RDV',
                label: 'RDV',
                isCurrent: true,
            };

            return validRoutePoint(origin) && validRoutePoint(destination)
                ? [{ from: origin, to: destination, isCurrent: true, badge: 'Trajet vers ce RDV' }]
                : [];
        };

        const enrichBookingDaySegments = async (segments, renderRequestId) => {
            const enrichedSegments = [];

            for (const segment of segments) {
                const route = await fetchRouteBetween(segment.from, segment.to);
                if (renderRequestId !== detailMapRenderRequestId) return null;
                enrichedSegments.push({ ...segment, route });
            }

            return enrichedSegments;
        };

        const renderBookingDayRouteSummary = (segments, isLoading = false, activeIndex = null, onSelect = null) => {
            const summary = document.getElementById('booking_day_route_summary');

            if (!summary) return;

            if (isLoading) {
                summary.innerHTML = `
                    <div class="flex items-center justify-between gap-3">
                        <span style="color:var(--gc-text-soft);">Calcul de la journée du technicien...</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">En cours</span>
                    </div>
                `;
                return;
            }

            if (!segments || segments.length === 0) {
                summary.innerHTML = '<span style="color:var(--gc-text-soft);">Journée du technicien indisponible pour ce RDV.</span>';
                return;
            }

            const defaultActiveIndex = segments.findIndex((segment) => segment.isCurrent);
            const safeActiveIndex = Number.isInteger(activeIndex) && activeIndex >= 0 && activeIndex < segments.length
                ? activeIndex
                : Math.max(0, defaultActiveIndex);

            summary.innerHTML = `
                <div class="mb-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Journée du technicien</p>
                    <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Clique une ligne pour mettre son trajet en couleur. Les autres restent en pointillé.</p>
                </div>
                <div class="space-y-2">
                    ${segments.map((segment, index) => {
                        const isActive = index === safeActiveIndex;

                        return `
                        <button type="button" data-booking-day-segment-index="${index}" class="flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition hover:shadow-sm" style="border-color:${isActive ? 'var(--gc-accent)' : 'transparent'};background:${isActive ? 'var(--gc-accent-soft)' : '#f8fafc'};">
                            <div class="min-w-0">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" style="background:${isActive ? '#ffffff' : 'var(--gc-accent-soft)'};color:var(--gc-text);">${escapeHtml(segment.badge)}</span>
                                <p class="mt-1 truncate font-medium">${escapeHtml(segment.from.name)} → ${escapeHtml(segment.to.name)}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="font-semibold">${Number(segment.route?.distance_km || 0).toFixed(1)} km</p>
                                <p class="text-xs" style="color:var(--gc-text-soft);">${escapeHtml(formatRouteDuration(segment.route?.duration_minutes))}</p>
                            </div>
                        </button>
                    `;
                    }).join('')}
                </div>
            `;

            summary.querySelectorAll('[data-booking-day-segment-index]').forEach((button) => {
                button.addEventListener('click', () => {
                    onSelect?.(Number(button.dataset.bookingDaySegmentIndex));
                });
            });
        };

        const initDetailMap = () => {
            mapboxDebug('init detail map', {
                container_found: Boolean(document.getElementById('booking-detail-map')),
                existing_map: Boolean(detailMap),
            });

            if (!bookingMapboxToken || !window.mapboxgl) {
                mapboxDebug('detail map blocked: missing token or mapboxgl');
                showMapboxUnavailable('booking-detail-map', !bookingMapboxToken
                    ? 'Token Mapbox absent côté Laravel.'
                    : 'La librairie Mapbox GL JS n’est pas chargée. Vérifie la CSP script-src/script-src-elem et le chargement du CDN.');
                return null;
            }

            window.mapboxgl.accessToken = bookingMapboxToken;

            if (detailMap) {
                detailMap.resize();
                mapboxDebug('detail map reused and resized');
                return detailMap;
            }

            detailMap = new window.mapboxgl.Map({
                container: 'booking-detail-map',
                style: 'mapbox://styles/mapbox/light-v11',
                center: [2.4, 46.7],
                zoom: 5,
                dragPan: false,
                scrollZoom: false,
                boxZoom: false,
                dragRotate: false,
                keyboard: false,
                doubleClickZoom: false,
                touchZoomRotate: false,
            });
            detailMap.on('load', () => mapboxDebug('detail map load event'));
            mapboxDebug('detail map instance created');

            return detailMap;
        };

        const clearDetailMap = () => {
            detailMapMarkers.forEach((marker) => marker.remove());
            detailMapMarkers = [];

            if (!detailMap || !detailMap.loaded()) return;

            [...detailMap.getStyle().layers]
                .filter((layer) => layer.id.startsWith('detail-route'))
                .forEach((layer) => detailMap.removeLayer(layer.id));

            Object.keys(detailMap.getStyle().sources)
                .filter((source) => source.startsWith('detail-route'))
                .forEach((source) => detailMap.removeSource(source));
        };

        const renderDetailMap = async (event) => {
            const renderRequestId = ++detailMapRenderRequestId;
            const props = event.extendedProps;
            let segments = buildBookingDayRouteSegments(event);

            mapboxDebug('render detail map called', {
                event_id: event.id,
                technician_id: props.technician_id,
                segments_count: segments.length,
            });

            if (segments.length === 0) {
                segments = fallbackCurrentSegment(event);
            }

            if (segments.length === 0) {
                mapboxDebug('render detail map blocked: invalid coordinates');
                renderRouteSummary(props);
                renderBookingDayRouteSummary([]);
                return;
            }

            renderRouteSummary(props, null, true);
            renderBookingDayRouteSummary([], true);

            const enrichedSegments = await enrichBookingDaySegments(segments, renderRequestId);
            if (!enrichedSegments || renderRequestId !== detailMapRenderRequestId) return;

            const map = initDetailMap();
            if (!map) {
                mapboxDebug('render detail map aborted: map unavailable');
                return;
            }

            const defaultActiveIndex = Math.max(0, enrichedSegments.findIndex((segment) => segment.isCurrent));

            const renderSelectedSegment = async (activeSegmentIndex = defaultActiveIndex) => {
                if (renderRequestId !== detailMapRenderRequestId) return;
                const safeActiveIndex = Number.isInteger(activeSegmentIndex) && activeSegmentIndex >= 0 && activeSegmentIndex < enrichedSegments.length
                    ? activeSegmentIndex
                    : defaultActiveIndex;
                const activeSegment = enrichedSegments[safeActiveIndex] || enrichedSegments[0];

                renderRouteSummary(props, activeSegment.route);
                renderBookingDayRouteSummary(enrichedSegments, false, safeActiveIndex, (selectedIndex) => {
                    void renderSelectedSegment(selectedIndex);
                });

                mapboxDebug('render detail map drawing route', { active_segment_index: safeActiveIndex });
                clearDetailMap();

                const color = currentTechnicianColors[String(props.technician_id)] || '#31424c';
                const bounds = new window.mapboxgl.LngLatBounds();
                const appointmentMarkerKeys = new Set();
                const segmentsForDisplay = enrichedSegments.map((segment, index) => ({
                    ...segment,
                    isHighlighted: index === safeActiveIndex,
                }));
                const orderedSegments = [
                    ...segmentsForDisplay.filter((segment) => !segment.isHighlighted),
                    ...segmentsForDisplay.filter((segment) => segment.isHighlighted),
                ];

                detailMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement(color, 'D') })
                    .setLngLat([enrichedSegments[0].from.lng, enrichedSegments[0].from.lat])
                    .addTo(map));
                bounds.extend([enrichedSegments[0].from.lng, enrichedSegments[0].from.lat]);

                enrichedSegments.forEach((segment) => {
                    [segment.from, segment.to].forEach((point) => bounds.extend([point.lng, point.lat]));

                    const routeCoordinates = segment.route?.feature?.geometry?.coordinates || [];
                    routeCoordinates.forEach((coordinate) => bounds.extend(coordinate));

                    if (segment.to.kind === 'appointment') {
                        const key = segment.to.event?.id ? String(segment.to.event.id) : `${segment.to.lng},${segment.to.lat}`;

                        if (!appointmentMarkerKeys.has(key)) {
                            appointmentMarkerKeys.add(key);
                            detailMapMarkers.push(new window.mapboxgl.Marker({
                                element: markerElement(segment.to.isCurrent ? '#31424c' : color, segment.to.isCurrent ? 'R' : String(appointmentMarkerKeys.size)),
                            })
                                .setLngLat([segment.to.lng, segment.to.lat])
                                .addTo(map));
                        }
                    }
                });

                orderedSegments.forEach((segment, index) => {
                    const sourceId = `detail-route-${index}`;
                    const layerId = `detail-route-${index}`;

                    if (!segment.route?.feature) return;

                    map.addSource(sourceId, { type: 'geojson', data: segment.route.feature });
                    map.addLayer({
                        id: layerId,
                        type: 'line',
                        source: sourceId,
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': segment.isHighlighted ? color : '#64748b',
                            'line-width': segment.isHighlighted ? 5 : 3,
                            'line-opacity': segment.isHighlighted ? 0.9 : 0.5,
                            ...(segment.isHighlighted ? {} : { 'line-dasharray': [1.5, 2.2] }),
                        },
                    });
                });

                map.fitBounds(bounds, { padding: 70, maxZoom: 12 });
            };

            if (map.loaded()) {
                await renderSelectedSegment();
            } else {
                mapboxDebug('detail map waiting for load event');
                map.once('load', () => renderSelectedSegment());
            }
        };

        const formatDateTimeForInput = (value) => {
            if (!value) return '';
            const date = new Date(value);
            const offsetDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
            return offsetDate.toISOString().slice(0, 16);
        };

        const formatDateTimeForConfirmation = (value) => {
            if (!value) return '-';

            return new Intl.DateTimeFormat('fr-FR', {
                dateStyle: 'full',
                timeStyle: 'short',
            }).format(new Date(value));
        };

        const showPlacementConfirmation = (data, payload, event) => {
            const props = event?.extendedProps || {};
            const technician = technicianById(payload.technician_id);
            const startsAt = payload.starts_at || event?.start || null;

            calendarWindowAbortController?.abort();
            technicianSearchAbortController?.abort();
            shouldFetchCalendarWindow = false;
            selectedCalendarEvent = null;

            crmBookingSection?.classList.add('hidden');
            manualBookingSection?.classList.add('hidden');
            analysisSection?.classList.add('hidden');
            manualBookingToggle?.classList.add('hidden');
            placementConfirmationSection?.classList.remove('hidden');

            document.getElementById('booking_confirmation_référence').textContent = data.appointment_id
                ? `RDV #${data.appointment_id}`
                : 'RDV créé';
            document.getElementById('booking_confirmation_date').textContent = formatDateTimeForConfirmation(startsAt);
            document.getElementById('booking_confirmation_customer').textContent = props.customer_name || '-';
            document.getElementById('booking_confirmation_technician').textContent = technician?.name || props.technician_name || '-';
            document.getElementById('booking_confirmation_address').textContent = props.address || '-';

            const trackingUrl = new URL(bookingTrackingUrl, window.location.origin);
            trackingUrl.searchParams.set('technician_id', payload.technician_id);

            if (data.appointment_id) {
                trackingUrl.searchParams.set('appointment_id', data.appointment_id);
            }

            if (startsAt) {
                trackingUrl.searchParams.set('date', String(startsAt).slice(0, 10));
            }

            confirmationTrackLink.href = trackingUrl.toString();

            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        const closeBookingAppointmentModal = () => {
            detailMapRenderRequestId++;
            bookingAppointmentModal.classList.add('hidden');
            selectedCalendarEvent = null;
            if (bookingCrmDetailModal?.classList.contains('hidden')) {
                document.body.style.overflow = '';
            }
        };

        const showDetailStatus = (message, type = 'info') => {
            bookingDetailStatus.textContent = message;
            bookingDetailStatus.style.color = type === 'error' ? '#be123c' : '#0f766e';
            bookingDetailStatus.classList.remove('hidden');
        };

        const hideDetailStatus = () => {
            bookingDetailStatus.textContent = '';
            bookingDetailStatus.classList.add('hidden');
        };

        const requiresCrmServiceSelection = (props) => Boolean(
            props?.is_suggestion
            && props.crm_appointment_id
            && !props.lot_appointment_id
            && !currentAppointmentRequest?.service
        );

        const syncCrmServiceSelection = ({ updateDuration = false } = {}) => {
            if (!selectedCalendarEvent) return;

            const serviceSelect = document.getElementById('booking_detail_service_select');
            const selectedService = serviceById(serviceSelect?.value);
            const confirmButton = document.getElementById('booking-confirm-suggestion-btn');
            const durationInput = document.getElementById('booking_detail_duration');
            const props = selectedCalendarEvent.extendedProps || {};

            selectedCalendarEvent.extendedProps = {
                ...props,
                crm_service_id: selectedService?.id || null,
                service_label: serviceLabel(selectedService),
                can_validate: Boolean(selectedService),
            };

            document.getElementById('booking_detail_service').textContent = serviceLabel(selectedService);
            document.getElementById('booking_modal_title').textContent = serviceLabel(selectedService);

            if (selectedService && updateDuration) {
                durationInput.value = selectedService.average_duration_minutes;
                selectedCalendarEvent.extendedProps.duration_minutes = Number(selectedService.average_duration_minutes);
                selectedCalendarEvent.end = addMinutes(selectedCalendarEvent.start, selectedService.average_duration_minutes);
            }

            confirmButton.disabled = !selectedService;

            if (selectedService) {
                hideDetailStatus();
                return;
            }

            showDetailStatus('Choisis une prestation avant de valider ce RDV Coffrac.', 'error');
        };

        const openBookingAppointmentModal = async (event) => {
            selectedCalendarEvent = event;
            const props = event.extendedProps;
            const isSuggestion = Boolean(props.is_suggestion);
            const allowTechnicianChange = Boolean(props.allow_technician_change);
            const technicianSelectWrap = document.getElementById('booking_detail_technician_select_wrap');
            const technicianSelect = document.getElementById('booking_detail_technician_select');
            const serviceSelectWrap = document.getElementById('booking_detail_service_select_wrap');
            const serviceSelect = document.getElementById('booking_detail_service_select');
            const startsAtInput = document.getElementById('booking_detail_starts_at');
            const durationInput = document.getElementById('booking_detail_duration');
            const shouldSelectCrmService = requiresCrmServiceSelection(props);

            hideDetailStatus();
            document.getElementById('booking_modal_kind').textContent = props.is_calendar_click
                ? 'Placement depuis le calendrier'
                : (isSuggestion ? 'Proposition de rendez-vous' : 'Rendez-vous place');
            document.getElementById('booking_modal_title').textContent = props.service_label || event.title;
            document.getElementById('booking_modal_subtitle').textContent = props.is_calendar_click
                ? 'Le RDV courant est repris; tu peux changer le technicien, l’heure et la durée avant validation.'
                : (isSuggestion
                ? 'Tu peux ajuster l’heure et la durée avant validation.'
                : 'Detail du rendez-vous déjà place.');
            document.getElementById('booking_detail_technician').textContent = props.technician_name || '-';
            document.getElementById('booking_detail_customer').textContent = props.customer_name || '-';
            document.getElementById('booking_detail_phone').textContent = props.customer_phone || '-';
            document.getElementById('booking_detail_service').textContent = props.service_label || '-';
            document.getElementById('booking_detail_address').textContent = props.address || '-';
            document.getElementById('booking_detail_origin').textContent = `${props.origin_label || '-'}${props.origin_name ? ` (${props.origin_name})` : ''}`;
            document.getElementById('booking_detail_appointment_id').value = isSuggestion ? '' : event.id;
            document.getElementById('booking_detail_crm_id').value = props.crm_appointment_id || '';
            document.getElementById('booking_detail_technician_id').value = props.technician_id || '';
            startsAtInput.value = formatDateTimeForInput(event.start);
            durationInput.value = props.duration_minutes || Math.max(30, Math.round(((event.end || addMinutes(event.start, requestDurationMinutes())) - event.start) / 60000));
            document.getElementById('booking_detail_comment').value = props.comment || '';
            bookingInitialDetailComment = props.comment || '';
            renderRouteSummary(props, null, true);

            serviceSelectWrap.classList.toggle('hidden', !shouldSelectCrmService);
            serviceSelect.value = props.crm_service_id || '';
            serviceSelect.onchange = shouldSelectCrmService
                ? () => syncCrmServiceSelection({ updateDuration: true })
                : null;

            technicianSelectWrap.classList.toggle('hidden', !allowTechnicianChange);
            technicianSelect.innerHTML = selectedTechnicians()
                .filter((technician) => !technicianIsAbsentAt(technician, event.start) || String(technician.id) === String(props.technician_id))
                .map((technician) => `
                    <option value="${escapeHtml(technician.id)}">${escapeHtml(technician.name)} · ${escapeHtml(technician.driving_duration_minutes)} min</option>
                `).join('');
            technicianSelect.value = props.technician_id || '';
            technicianSelect.onchange = allowTechnicianChange ? syncDraftEventFromInputs : null;
            startsAtInput.onchange = allowTechnicianChange ? syncDraftEventFromInputs : null;
            durationInput.oninput = allowTechnicianChange ? syncDraftEventFromInputs : null;

            startsAtInput.disabled = !isSuggestion;
            durationInput.disabled = !isSuggestion;
            document.getElementById('booking-problem-appointment-btn').classList.toggle('hidden', isSuggestion || Boolean(props.deleted_at));
            document.getElementById('booking-save-comment-btn').classList.toggle('hidden', isSuggestion);
            document.getElementById('booking-confirm-suggestion-btn').classList.toggle('hidden', !isSuggestion);
            document.getElementById('booking-confirm-suggestion-btn').disabled = isSuggestion && !props.can_validate;

            if (shouldSelectCrmService) {
                syncCrmServiceSelection();
            } else if (isSuggestion && !props.can_validate) {
                showDetailStatus('Validation impossible: aucune prestation n’est renseignée.', 'error');
            }

            bookingAppointmentModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => renderDetailMap(event));
        };

        const renderMap = async (crmAppointment, technicians) => {
            const renderRequestId = ++mapRenderRequestId;

            mapboxDebug('render booking map called', {
                crm_appointment_id: crmAppointment?.id,
                technicians_count: technicians.length,
                appointment_coordinates: {
                    lat: crmAppointment?.latitude,
                    lng: crmAppointment?.longitude,
                },
            });
            const map = initBookingMap();
            if (!map) {
                mapboxDebug('render booking map aborted: map unavailable');
                return;
            }

            const render = async () => {
                if (renderRequestId !== mapRenderRequestId) return;

                mapboxDebug('render booking map drawing', {
                    technicians_count: technicians.length,
                    map_loaded: map.loaded(),
                });
                clearMap();

                const bounds = new window.mapboxgl.LngLatBounds();
                const appointmentCoordinates = [Number(crmAppointment.longitude), Number(crmAppointment.latitude)];
                bounds.extend(appointmentCoordinates);

                bookingMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement('#31424c', 'R') })
                    .setLngLat(appointmentCoordinates)
                    .setPopup(new window.mapboxgl.Popup().setHTML(`<strong>RDV</strong><br>${escapeHtml(crmAppointment.address)}`))
                    .addTo(map));

                for (const [index, technician] of technicians.entries()) {
                    const color = technicianColor(technician.id);
                    const markerLabel = String(technicianOrdinal(technician.id) || index + 1);
                    const techCoordinates = [Number(technician.longitude), Number(technician.latitude)];
                    bounds.extend(techCoordinates);

                    bookingMapMarkers.push(new window.mapboxgl.Marker({ element: markerElement(color, markerLabel) })
                        .setLngLat(techCoordinates)
                        .setPopup(new window.mapboxgl.Popup().setHTML(`<strong>${escapeHtml(technician.name)}</strong>`))
                        .addTo(map));

                    const route = await fetchRoute(technician, crmAppointment);
                    if (renderRequestId !== mapRenderRequestId) return;

                    const sourceId = `tech-route-${technician.id}`;
                    map.addSource(sourceId, { type: 'geojson', data: route });
                    map.addLayer({
                        id: sourceId,
                        type: 'line',
                        source: sourceId,
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': color,
                            'line-width': 4,
                            'line-opacity': 0.72,
                        },
                    });

                }

                if (technicians.length > 0) {
                    map.fitBounds(bounds, { padding: 70, maxZoom: 10 });
                } else {
                    map.flyTo({ center: appointmentCoordinates, zoom: 9 });
                }
            };

            if (map.loaded()) {
                await render();
            } else {
                mapboxDebug('booking map waiting for load event');
                map.once('load', render);
            }
        };

        const renderTechnicians = (technicians, crmAppointment, filters) => {
            refreshTechnicianColors();
            updateTechnicianSelectionCount();

            const activeTechnicians = selectedTechnicians();
            document.getElementById('analysis-title').textContent = `${activeTechnicians.length}/${technicians.length} technicien(s) affiche(s)`;
            const serviceLabel = crmAppointment.service
                ? `${crmAppointment.service.type} - ${crmAppointment.service.name}`
                : null;
            const availabilityLabel = filters.preferred_starts_at
                ? ` Creneau client: ${new Date(filters.preferred_starts_at).toLocaleString('fr-FR')}.`
                : '';
            document.getElementById('analysis-subtitle').textContent = filters.service_required
                ? `Département ${filters.department_code} + prestation ${serviceLabel}.${availabilityLabel}`
                : `Département ${filters.department_code}; aucun service renseigné, pas de filtre prestation.${availabilityLabel}`;

            if (technicians.length === 0) {
                techniciansList.innerHTML = '<div class="rounded-xl border p-4 text-sm" style="border-color:#fecdd3;background:#fff1f2;color:#be123c;">Aucun technicien ne couvre ce département avec ces critères.</div>';
                return;
            }

            techniciansList.innerHTML = technicians.map((technician, index) => {
                const coverageBadge = technician.covers_requested_department
                    ? '<span class="rounded-lg px-2 py-1 text-xs" style="background:#dcfce7;color:#15803d;">Département couvert</span>'
                    : '<span class="rounded-lg px-2 py-1 text-xs" style="background:#fee2e2;color:#be123c;">Fallback proximite</span>';
                const technicianId = String(technician.id);
                const isSelected = selectedTechnicianIds.has(technicianId);
                const color = technicianColor(technicianId);
                const rankLabel = technicianOrdinal(technicianId) || index + 1;
                const absenceBadges = absenceBadgesForTechnician(technician);

                return `
                    <label class="block cursor-pointer rounded-xl border p-4 transition hover:shadow-sm" style="border-color:${isSelected ? 'var(--gc-border)' : '#e2e8f0'};opacity:${isSelected ? '1' : '0.58'};">
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                class="eligible-technician-checkbox mt-1 h-4 w-4 rounded"
                                style="accent-color:${color};"
                                data-technician-id="${escapeHtml(technician.id)}"
                                ${isSelected ? 'checked' : ''}
                            />
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style="background:${color};">${rankLabel}</span>
                            <div class="min-w-0">
                                <h3 class="font-semibold" style="color:var(--gc-text);">${escapeHtml(technician.name)}</h3>
                                <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">${escapeHtml(technician.phone || 'Téléphone non renseigné')}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">${escapeHtml(technician.driving_distance_km)} km voiture</span>
                                    <span class="rounded-lg px-2 py-1 text-xs" style="background:var(--gc-accent-soft);color:var(--gc-text);">${escapeHtml(technician.driving_duration_minutes)} min</span>
                                    ${coverageBadge}
                                    ${absenceBadges}
                                </div>
                            </div>
                        </div>
                    </label>
                `;
            }).join('');

            techniciansList.querySelectorAll('.eligible-technician-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', async () => {
                    const technicianId = String(checkbox.dataset.technicianId || '');

                    if (checkbox.checked) {
                        selectedTechnicianIds.add(technicianId);
                    } else {
                        selectedTechnicianIds.delete(technicianId);
                    }

                    await refreshBookingOutputs({ fetchCalendar: checkbox.checked });
                });
            });
        };

        const colorizedEvents = (events, suggestions = []) => [...visibleCalendarItems(events), ...visibleCalendarItems(suggestions)].map((event) => {
            const technicianId = String(event.extendedProps?.technician_id || '');
            const color = currentTechnicianColors[technicianId] || '#31424c';
            const isDeleted = Boolean(event.extendedProps?.deleted_at);
            const isSuggestion = Boolean(event.extendedProps?.is_suggestion);

            return {
                ...event,
                backgroundColor: isSuggestion || isDeleted ? `${color}26` : color,
                borderColor: isDeleted ? '#be123c' : color,
                textColor: isSuggestion ? color : '#ffffff',
                classNames: [
                    ...(isDeleted ? ['appointment-soft-deleted'] : []),
                    ...(isSuggestion ? ['appointment-suggestion'] : []),
                ],
            };
        });

        const refreshCalendarWindow = async (dateInfo, options = {}) => {
            lastCalendarDateInfo = dateInfo;

            const force = Boolean(options.force);

            if ((!force && !shouldFetchCalendarWindow) || !currentAnalysisPayload || !bookingCalendar) return;

            if (selectedTechnicianIds.size === 0) {
                bookingCalendar.removeAllEvents();
                hideCalendarLoader(true);
                return;
            }

            calendarWindowAbortController?.abort();
            calendarWindowAbortController = new AbortController();
            const requestId = ++calendarWindowRequestId;

            showCalendarLoader();

            try {
                const response = await fetch(bookingCalendarWindowUrl, {
                    method: 'POST',
                    signal: calendarWindowAbortController.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({
                        ...currentAnalysisPayload,
                        start: dateInfo.startStr,
                        end: dateInfo.endStr,
                        technician_ids: selectedTechnicianIdsArray(),
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Calcul des propositions impossible.');
                }

                if (requestId !== calendarWindowRequestId) return;

                currentCalendarEvents = payload.events || [];
                currentCalendarSuggestions = payload.suggestions || [];
                if (Array.isArray(payload.technicians)) {
                    mergeTechnicianUpdates(payload.technicians);
                    renderTechnicians(currentTechnicians, currentAppointmentRequest, currentFilters);
                }
                bookingCalendar.removeAllEvents();
                bookingCalendar.addEventSource(colorizedEvents(currentCalendarEvents, currentCalendarSuggestions));
            } catch (error) {
                if (error.name !== 'AbortError') {
                    showFeedback(error.message || 'Erreur pendant le calcul des propositions.', 'error');
                }
            } finally {
                if (requestId === calendarWindowRequestId) {
                    hideCalendarLoader();
                }
            }
        };

        const renderCalendar = (events, suggestions = [], focusDate = null, shouldAutoFocus = true) => {
            const calendarElement = document.getElementById('booking-calendar');
            const preparedEvents = colorizedEvents(events, suggestions);
            const firstSuggestionStart = shouldAutoFocus
                ? visibleCalendarItems(suggestions).find((suggestion) => suggestion?.start)?.start || null
                : null;
            const calendarFocusDate = focusDate || firstSuggestionStart;

            if (bookingCalendar) {
                hideSuggestionTooltip();
                bookingCalendar.removeAllEvents();
                bookingCalendar.addEventSource(preparedEvents);
                if (calendarFocusDate) {
                    bookingCalendar.gotoDate(calendarFocusDate);
                }
                return;
            }

            bookingCalendar = new FullCalendar.Calendar(calendarElement, {
                locale: 'fr',
                initialView: 'timeGridWeek',
                firstDay: 1,
                hiddenDays: [0],
                allDaySlot: false,
                height: 'auto',
                nowIndicator: true,
                slotMinTime: '07:00:00',
                slotMaxTime: '21:00:00',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridWeek,dayGridMonth',
                },
                buttonText: {
                    today: 'Aujourd hui',
                    month: 'Mois',
                    week: 'Semaine',
                },
                eventClick: (info) => {
                    hideSuggestionTooltip();
                    openBookingAppointmentModal(info.event);
                },
                eventMouseEnter: (info) => {
                    if (!info.event.extendedProps?.is_suggestion) return;

                    const moveHandler = (event) => moveSuggestionTooltip(event);
                    info.el._bookingSuggestionTooltipMoveHandler = moveHandler;
                    info.el.addEventListener('mousemove', moveHandler);
                    showSuggestionTooltip(info.jsEvent, info.event.extendedProps);
                },
                eventMouseLeave: (info) => {
                    const moveHandler = info.el._bookingSuggestionTooltipMoveHandler;

                    if (moveHandler) {
                        info.el.removeEventListener('mousemove', moveHandler);
                        delete info.el._bookingSuggestionTooltipMoveHandler;
                    }

                    hideSuggestionTooltip();
                },
                dateClick: openCalendarSlotModal,
                datesSet: refreshCalendarWindow,
                events: preparedEvents,
            });

            bookingCalendar.render();

            if (calendarFocusDate) {
                bookingCalendar.gotoDate(calendarFocusDate);
            }
        };

        const refreshBookingOutputs = async ({ fetchCalendar = false } = {}) => {
            if (!currentAppointmentRequest || !currentFilters) return;

            refreshTechnicianColors();
            renderTechnicians(currentTechnicians, currentAppointmentRequest, currentFilters);
            await renderMap(currentAppointmentRequest, selectedTechnicians());
            renderCalendar(currentCalendarEvents, currentCalendarSuggestions, null, false);

            if (fetchCalendar && lastCalendarDateInfo) {
                await refreshCalendarWindow(lastCalendarDateInfo, { force: true });
            }
        };

        const analyzeAppointment = async (payload, sourceLabel = 'RDV', { scrollToResults = true } = {}) => {
            currentAnalysisPayload = payload;
            currentCrmAppointmentId = payload.crm_appointment_id || null;
            analysisSection.classList.remove('hidden');
            startBookingAnalysisLoader(sourceLabel);
            if (scrollToResults) {
                window.requestAnimationFrame(scrollToBookingResults);
            }
            clearFeedback();
            techniciansList.innerHTML = '<div class="rounded-xl border p-4 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Analyse en cours...</div>';

            try {
                const response = await fetch(bookingAnalyzeUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Analyse impossible.');
                }

                const suggestions = data.suggestions || [];
                currentAppointmentRequest = data.crm_appointment;
                currentTechnicians = data.technicians || [];
                currentTechnicianColors = {};
                selectedTechnicianIds = new Set(currentTechnicians.map((technician) => String(technician.id)));
                currentFilters = data.filters || null;
                currentCalendarEvents = data.events || [];
                currentCalendarSuggestions = suggestions;
                technicianSearchInput.value = '';
                clearTechnicianSearch();
                shouldFetchCalendarWindow = false;
                calendarWindowAbortController?.abort();
                hideCalendarLoader();

                renderTechnicians(currentTechnicians, currentAppointmentRequest, currentFilters);
                await renderMap(currentAppointmentRequest, selectedTechnicians());
                renderCalendar(currentCalendarEvents, currentCalendarSuggestions, currentFilters?.preferred_starts_at || null);
                await finishBookingAnalysisLoader('Analyse terminée, affichage des résultats.');
                window.setTimeout(() => {
                    shouldFetchCalendarWindow = true;
                }, 250);

                if (data.technicians.length === 0) {
                    showFeedback(`Aucun technicien éligible pour ce ${sourceLabel}.`, 'error');
                } else if (suggestions.length === 0) {
                    showFeedback('Aucune proposition de placement calculee avec les contraintes actuelles.', 'error');
                } else if (sourceLabel === 'RDV manuel' || sourceLabel === 'RDV lot') {
                    showFeedback(`${suggestions.length} proposition(s) de placement calculee(s) pour ce ${sourceLabel.toLowerCase()}.`);
                }

                return true;
            } catch (error) {
                await finishBookingAnalysisLoader('Analyse interrompue.');
                showFeedback(error.message || 'Erreur pendant l’analyse du RDV.', 'error');
                return false;
            }
        };

        const analyzeCrmAppointment = async (crmId) => analyzeAppointment({ crm_appointment_id: crmId }, 'RDV Coffrac');
        const lotServiceSelectFor = (lotAppointmentId) => document.querySelector(`.lot-appointment-service-select[data-lot-appointment-id="${lotAppointmentId}"]`);
        const lotBookButtonFor = (lotAppointmentId) => document.querySelector(`.lot-appointment-book-button[data-lot-appointment-id="${lotAppointmentId}"]`);

        const updateLotBookButtonState = (lotAppointmentId) => {
            const select = lotServiceSelectFor(lotAppointmentId);
            const button = lotBookButtonFor(lotAppointmentId);

            if (!button) return;

            const canSearch = button.dataset.canSearch === '1' && select?.dataset.canSearch === '1';
            button.disabled = !canSearch || !select?.value;
        };

        const analyzeLotAppointment = async (lotAppointmentId) => {
            const select = lotServiceSelectFor(lotAppointmentId);
            const serviceId = select?.value;

            if (!serviceId) {
                select?.focus();
                return false;
            }

            return analyzeAppointment({
                lot_appointment_id: Number(lotAppointmentId),
                lot_service_id: Number(serviceId),
            }, 'RDV lot');
        };

        const manualAppointmentPayload = () => ({
            first_name: document.getElementById('manual_first_name').value.trim(),
            last_name: document.getElementById('manual_last_name').value.trim(),
            phone: document.getElementById('manual_phone').value.trim(),
            service_id: document.getElementById('manual_service_id').value,
            address: document.getElementById('manual_address').value.trim(),
            department_code: document.getElementById('manual_department_code').value.trim(),
            latitude: document.getElementById('manual_latitude').value,
            longitude: document.getElementById('manual_longitude').value,
        });

        const setTechnicianSearchStatus = (message, type = 'info') => {
            technicianSearchStatus.textContent = message;
            technicianSearchStatus.style.color = type === 'error' ? '#be123c' : 'var(--gc-text-soft)';
            technicianSearchStatus.classList.remove('hidden');
        };

        const clearTechnicianSearch = () => {
            technicianSearchStatus.textContent = '';
            technicianSearchStatus.classList.add('hidden');
            technicianSearchResults.innerHTML = '';
            technicianSearchResults.classList.add('hidden');
        };

        const renderTechnicianSearchResults = (technicians) => {
            if (technicians.length === 0) {
                technicianSearchResults.innerHTML = '<div class="rounded-xl border p-3 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">Aucun technicien trouvé.</div>';
                technicianSearchResults.classList.remove('hidden');
                return;
            }

            technicianSearchResults.innerHTML = technicians.map((technician) => {
                const technicianId = String(technician.id);
                const isKnown = currentTechnicians.some((currentTechnician) => String(currentTechnician.id) === technicianId);
                const isSelected = selectedTechnicianIds.has(technicianId);
                const actionLabel = isSelected ? 'Déjà affiche' : (isKnown ? 'Recocher' : 'Ajouter');
                const coverageLabel = technician.covers_requested_department ? 'Dept. couvert' : 'Hors dept.';
                const absenceLabel = technician.absence_label ? ` · ${technician.absence_label}` : '';

                return `
                    <article class="flex items-center justify-between gap-3 rounded-xl border p-3" style="border-color:var(--gc-border);">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold" style="color:var(--gc-text);">${escapeHtml(technician.name)}</p>
                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">${escapeHtml(technician.driving_distance_km)} km · ${escapeHtml(technician.driving_duration_minutes)} min · ${coverageLabel}${escapeHtml(absenceLabel)}</p>
                        </div>
                        <button
                            type="button"
                            class="${isSelected ? 'gc-btn-soft opacity-60' : 'gc-btn-primary'} shrink-0 px-3 py-2 text-xs"
                            data-search-technician-id="${escapeHtml(technician.id)}"
                            ${isSelected ? 'disabled' : ''}
                        >
                            ${actionLabel}
                        </button>
                    </article>
                `;
            }).join('');
            technicianSearchResults.classList.remove('hidden');

            technicianSearchResults.querySelectorAll('[data-search-technician-id]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const technician = technicians.find((candidate) => String(candidate.id) === String(button.dataset.searchTechnicianId));

                    if (!technician) return;

                    button.disabled = true;
                    button.textContent = 'Ajout...';
                    upsertTechnician(technician);
                    await refreshBookingOutputs({ fetchCalendar: true });
                    technicianSearchInput.value = '';
                    clearTechnicianSearch();
                    setTechnicianSearchStatus(`${technician.name} ajouté à la Sélection.`);
                });
            });
        };

        const searchTechnicians = async (query) => {
            const normalizedQuery = query.trim();

            if (normalizedQuery.length < 2) {
                clearTechnicianSearch();
                return;
            }

            if (!currentAnalysisPayload) {
                setTechnicianSearchStatus('Lance d’abord une analyse Coffrac ou manuelle.', 'error');
                return;
            }

            technicianSearchAbortController?.abort();
            technicianSearchAbortController = new AbortController();
            setTechnicianSearchStatus('Recherche en cours...');

            try {
                const response = await fetch(bookingTechnicianSearchUrl, {
                    method: 'POST',
                    signal: technicianSearchAbortController.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({
                        ...currentAnalysisPayload,
                        query: normalizedQuery,
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Recherche technicien impossible.');
                }

                technicianSearchStatus.classList.add('hidden');
                renderTechnicianSearchResults(payload.technicians || []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setTechnicianSearchStatus(error.message || 'Recherche technicien impossible.', 'error');
                }
            }
        };

        const bindBookingCrmCards = () => {
            bookingCrmCards.forEach((card) => {
                if (card.dataset.bound === '1') return;

                card.dataset.bound = '1';
                card.addEventListener('click', (event) => {
                    if (event.target.closest('.crm-appointment-detail-button')) return;

                    if (card.dataset.hasCoordinates !== '1') {
                        openCrmAppointmentDetail(card.dataset.crmId);
                        setCrmDetailStatus('Coordonnées GPS absentes: corrige l’adresse puis enregistre pour relancer Mapbox.', 'error');
                        return;
                    }

                    analyzeCrmAppointment(card.dataset.crmId);
                });
                card.addEventListener('keydown', (event) => {
                    if (!['Enter', ' '].includes(event.key)) return;

                    event.preventDefault();

                    if (card.dataset.hasCoordinates !== '1') {
                        openCrmAppointmentDetail(card.dataset.crmId);
                        setCrmDetailStatus('Coordonnées GPS absentes: corrige l’adresse puis enregistre pour relancer Mapbox.', 'error');
                        return;
                    }

                    analyzeCrmAppointment(card.dataset.crmId);
                });
            });

            bookingCrmGrid?.querySelectorAll('.crm-appointment-detail-button').forEach((button) => {
                if (button.dataset.bound === '1') return;

                button.dataset.bound = '1';
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    openCrmAppointmentDetail(button.dataset.crmDetailId);
                });
            });
        };

        bindBookingCrmCards();

        document.querySelectorAll('.lot-appointment-book-button').forEach((button) => {
            button.addEventListener('click', () => analyzeLotAppointment(button.dataset.lotAppointmentId));
        });

        document.querySelectorAll('.lot-appointment-service-select').forEach((select) => {
            updateLotBookButtonState(select.dataset.lotAppointmentId);
            select.addEventListener('change', () => updateLotBookButtonState(select.dataset.lotAppointmentId));
        });

        bookingCrmSearch.addEventListener('input', () => {
            bookingCrmPage = 1;
            filterBookingCrmCards();
        });

        bookingSourceSwitch?.addEventListener('click', () => {
            setBookingSourceMode(bookingSourceMode === 'crm' ? 'lot' : 'crm');
        });

        bookingCrmRefreshButton?.addEventListener('click', refreshBookingCrmAppointments);
        subscribeToExternalApiSync();
        if (bookingCrmRefreshButton?.dataset.apiState === 'syncing') {
            startExternalSyncPolling();
        }

        setBookingSourceMode('crm');

        if (bookingInitialCrmAppointmentId) {
            const initialCard = Array.from(document.querySelectorAll('.crm-appointment-card'))
                .find((card) => card.dataset.crmId === bookingInitialCrmAppointmentId);

            if (initialCard) {
                initialCard.style.boxShadow = '0 0 0 3px rgba(216,194,122,0.42), 0 18px 40px rgba(49,66,76,0.14)';
                initialCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            window.setTimeout(() => analyzeCrmAppointment(bookingInitialCrmAppointmentId), 120);
        }

        technicianSearchInput.addEventListener('input', () => {
            window.clearTimeout(technicianSearchTimer);
            technicianSearchTimer = window.setTimeout(() => searchTechnicians(technicianSearchInput.value), 320);
        });

        technicianSelectAllButton.addEventListener('click', async () => {
            currentTechnicians.forEach((technician) => selectedTechnicianIds.add(String(technician.id)));
            await refreshBookingOutputs({ fetchCalendar: true });
        });

        document.getElementById('booking-confirmation-new').addEventListener('click', () => {
            window.location.reload();
        });

        document.getElementById('manual-booking-toggle').addEventListener('click', () => {
            manualBookingSection.classList.toggle('hidden');
            if (!manualBookingSection.classList.contains('hidden')) {
                initManualAddressAutocomplete();
                document.getElementById('manual_last_name').focus();
            }
        });

        document.getElementById('manual-booking-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            clearManualStatus();

            const submitButton = document.getElementById('manual-booking-submit');
            const manualPayload = manualAppointmentPayload();

            if (!manualPayload.department_code || !manualPayload.latitude || !manualPayload.longitude) {
                setManualStatus('Sélectionne une adresse Mapbox pour récupérer le département et les coordonnées.', 'error');
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Recherche...';

            try {
                const analyzed = await analyzeAppointment({ manual_appointment: manualPayload }, 'RDV manuel');
                if (analyzed) {
                    setManualStatus('Recherche lancée.');
                }
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Trouver un technicien';
            }
        });

        document.getElementById('booking-modal-close').addEventListener('click', closeBookingAppointmentModal);
        bookingAppointmentModal.addEventListener('click', (event) => {
            if (event.target === bookingAppointmentModal) closeBookingAppointmentModal();
        });
        document.getElementById('booking-crm-detail-close')?.addEventListener('click', closeCrmAppointmentDetail);
        bookingCrmDetailModal?.addEventListener('click', (event) => {
            if (event.target === bookingCrmDetailModal) closeCrmAppointmentDetail();
        });
        bookingCrmDetailForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            await saveCrmAppointmentDetail();
        });

        document.getElementById('booking-save-comment-btn').addEventListener('click', async () => {
            const appointmentId = document.getElementById('booking_detail_appointment_id').value;
            const comment = document.getElementById('booking_detail_comment').value;
            const button = document.getElementById('booking-save-comment-btn');

            if (!appointmentId) return;

            button.disabled = true;
            button.textContent = 'Enregistrement...';

            try {
                const response = await fetch(bookingCommentUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({ comment }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : 'Enregistrement impossible.';
                    throw new Error(firstError);
                }

                selectedCalendarEvent?.setExtendedProp('comment', payload.comment || comment);
                bookingInitialDetailComment = payload.comment || comment;
                showDetailStatus('Commentaire enregistre.');
            } catch (error) {
                showDetailStatus(error.message || 'Enregistrement impossible.', 'error');
            } finally {
                button.disabled = false;
                button.textContent = 'Enregistrer commentaire';
            }
        });

        document.getElementById('booking-problem-appointment-btn').addEventListener('click', async () => {
            const appointmentId = document.getElementById('booking_detail_appointment_id').value;
            const comment = document.getElementById('booking_detail_comment').value.trim();
            const button = document.getElementById('booking-problem-appointment-btn');

            if (!appointmentId) return;

            if (!comment) {
                showDetailStatus('Un commentaire est obligatoire avant de déclarer un problème RDV.', 'error');
                return;
            }

            if (comment === bookingInitialDetailComment.trim()) {
                showDetailStatus('Le commentaire doit être modifié avant de déclarer un problème RDV.', 'error');
                return;
            }

            button.disabled = true;
            button.textContent = 'Signalement...';

            try {
                const response = await fetch(bookingProblemUrlTemplate.replace('__APPOINTMENT__', appointmentId), {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify({ comment }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const firstError = payload?.errors ? Object.values(payload.errors)[0][0] : payload.message || 'Signalement impossible.';
                    throw new Error(firstError);
                }

                if (selectedCalendarEvent) {
                    selectedCalendarEvent.setExtendedProp('comment', payload.comment || comment);
                    selectedCalendarEvent.setExtendedProp('status', payload.status || 'problem');
                    selectedCalendarEvent.setExtendedProp('problem_reported_at', payload.problem_reported_at || new Date().toISOString());
                    selectedCalendarEvent.setProp('backgroundColor', '#fef3c7');
                    selectedCalendarEvent.setProp('borderColor', '#d97706');
                    selectedCalendarEvent.setProp('textColor', '#713f12');
                }

                bookingInitialDetailComment = payload.comment || comment;
                showDetailStatus('Problème RDV déclaré.');
            } catch (error) {
                showDetailStatus(error.message || 'Signalement impossible.', 'error');
            } finally {
                button.disabled = false;
                button.textContent = 'Problème RDV';
            }
        });

        document.getElementById('booking-confirm-suggestion-btn').addEventListener('click', async () => {
            const button = document.getElementById('booking-confirm-suggestion-btn');
            const serviceSelectWrap = document.getElementById('booking_detail_service_select_wrap');
            const serviceSelect = document.getElementById('booking_detail_service_select');
            const payload = {
                ...(currentAnalysisPayload || { crm_appointment_id: document.getElementById('booking_detail_crm_id').value }),
                technician_id: document.getElementById('booking_detail_technician_id').value,
                starts_at: document.getElementById('booking_detail_starts_at').value,
                duration_minutes: document.getElementById('booking_detail_duration').value,
                comment: document.getElementById('booking_detail_comment').value,
            };

            if (!serviceSelectWrap.classList.contains('hidden')) {
                if (!serviceSelect.value) {
                    showDetailStatus('Choisis une prestation avant de valider ce RDV Coffrac.', 'error');
                    serviceSelect.focus();
                    return;
                }

                payload.crm_service_id = Number(serviceSelect.value);
            }

            button.disabled = true;
            button.textContent = 'Validation...';

            try {
                const response = await fetch(bookingStoreUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': bookingCsrfToken,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();

                if (!response.ok) {
                    const firstError = data?.errors ? Object.values(data.errors)[0][0] : data.message || 'Création impossible.';
                    throw new Error(firstError);
                }

                const confirmedEvent = selectedCalendarEvent;
                closeBookingAppointmentModal();
                showPlacementConfirmation(data, payload, confirmedEvent);
            } catch (error) {
                showDetailStatus(error.message || 'Création impossible.', 'error');
            } finally {
                button.disabled = false;
                button.textContent = 'Valider la prise du RDV';
            }
        });

        window.addEventListener('techcalendar:layout-resized', () => {
            bookingMap?.resize();
            detailMap?.resize();
            bookingCalendar?.updateSize();
        });
    </script>
</x-layouts.app>
