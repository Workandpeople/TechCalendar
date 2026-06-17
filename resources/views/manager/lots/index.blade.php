<x-layouts.app>
    <div class="space-y-6">
        <div>
            <p class="text-sm" style="color: var(--gc-text-soft);">Gérant</p>
            <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Gestion des lots</h1>
            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                Un lot regroupe des RDV à placer depuis un fichier d’import. Les colonnes sont normalisées par OpenAI pour absorber les variations de format.
            </p>
        </div>

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#fecaca;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="gc-card p-5">
            <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Import</p>
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Ajouter un lot par fichier</h2>
                    <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Formats supportés: .xlsx, .csv et .txt. Le fichier original est conservé en stockage privé après import.</p>
                </div>
            </div>

            <form id="lot-import-form" method="POST" action="{{ route('manager.lots.imports.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_220px_300px_180px_auto] lg:items-end">
                @csrf
                <div id="lot-file-field">
                    <label class="gc-label" for="lot_file">Fichier du lot</label>
                    <input id="lot_file" name="file" type="file" class="gc-input" accept=".xlsx,.csv,.txt" required />
                    <p id="lot-file-selected" class="mt-1 hidden text-xs font-medium" style="color:#047857;"></p>
                </div>
                <div>
                    <label class="gc-label" for="lot_name">Nom du lot</label>
                    <input id="lot_name" name="name" type="text" value="{{ old('name') }}" class="gc-input" placeholder="Optionnel" />
                </div>
                <div>
                    <label class="gc-label" for="lot_type">Type de lot</label>
                    <select id="lot_type" name="type" class="gc-input" required>
                        <option value="">Sélectionner</option>
                        @foreach ($lotTypes as $typeValue => $typeLabel)
                            <option value="{{ $typeValue }}" @selected(old('type') === $typeValue)>{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="gc-label" for="lot_sampling_percentage">% d'échantillonnage</label>
                    <input id="lot_sampling_percentage" name="sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ old('sampling_percentage') }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Ex: 10" disabled />
                </div>
                <button id="lot-import-submit" type="submit" class="gc-btn-primary justify-center disabled:cursor-not-allowed disabled:opacity-50" disabled>Importer</button>
            </form>
        </section>

        <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Lots</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $stats['lots_count'] }}</p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">RDV à placer</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $stats['placeable_count'] }}</p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">RDV placés</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $stats['placed_count'] }}</p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">RDV total</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $stats['appointments_count'] }}</p>
            </article>
        </section>

        <section class="gc-card p-4">
            <form id="manager-lot-filters-form" method="GET" action="{{ route('manager.lots') }}" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="search" value="{{ $filters['q'] }}" class="gc-input" placeholder="Client, adresse, téléphone, référence" autocomplete="off" />
                </div>

                <div>
                    <label class="gc-label" for="type">Type de lot</label>
                    <select id="type" name="type" class="gc-input">
                        <option value="">Toutes</option>
                        @foreach ($lotTypes as $typeValue => $typeLabel)
                            <option value="{{ $typeValue }}" @selected($filters['type'] === $typeValue)>{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="gc-label" for="status">Statut du lot</label>
                    <select id="status" name="status" class="gc-input">
                        <option value="">Tous</option>
                        @foreach ($lotStatuses as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected($filters['status'] === $statusValue)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-3 flex items-center justify-between">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Les filtres se mettent à jour automatiquement.</p>
                    <a href="{{ route('manager.lots') }}" class="gc-link">Réinitialiser les filtres</a>
                </div>
            </form>
        </section>

        <section class="space-y-3">
            @forelse ($lots as $lot)
                <details class="lot-details overflow-hidden rounded-2xl border bg-white shadow-sm" style="border-color:var(--gc-border);">
                    <summary class="flex cursor-pointer list-none flex-col gap-4 p-4 transition hover:bg-[color:var(--gc-accent-soft)] lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold" style="color:var(--gc-text);">{{ $lot['title'] }}</h2>
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
                                {{ $lot['appointments_count'] }} RDV · {{ $lot['placeable_count'] }} à placer · {{ $lot['placed_count'] }} placés
                                @if ($lot['imported_at'])
                                    · Importé {{ $lot['imported_at']->format('d/m/Y H:i') }}
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="lot-chevron h-5 w-5 transition-transform" style="color:var(--gc-text-soft);">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </div>
                    </summary>

                    <div class="border-t" style="border-color:var(--gc-border);">
                        @if ($lot['type_label'] || $lot['original_filename'])
                            <div class="flex flex-col gap-3 border-b p-4 md:flex-row md:items-center md:justify-between" style="border-color:var(--gc-border);background:#fbfaf6;">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($lot['type_label'])
                                            <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:#e0f2fe;color:#1d4ed8;">Type: {{ $lot['type_label'] }}</span>
                                        @endif
                                        @if ($lot['original_filename'])
                                            <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                                                Fichier: {{ $lot['original_filename'] }}
                                                @if ($lot['original_file_size'])
                                                    · {{ number_format($lot['original_file_size'] / 1024, 1, ',', ' ') }} Ko
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                @if ($lot['can_download_original_file'])
                                    <a href="{{ $lot['download_url'] }}" class="gc-btn-soft inline-flex justify-center">
                                        Télécharger le fichier original
                                    </a>
                                @endif
                            </div>
                        @endif

                        <div class="grid grid-cols-1">
                            @foreach ($lot['appointments'] as $appointment)
                                @php
                                    $isPlaced = (bool) $appointment['is_placed'];
                                    $appointmentLocation = trim(implode(' ', array_filter([
                                        $appointment['postal_code'] ?? null,
                                        $appointment['city'] ?? null,
                                    ])));
                                @endphp
                                <article
                                    class="grid grid-cols-1 gap-4 border-b p-4 last:border-b-0 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1.7fr)_auto] xl:items-center"
                                    data-lot-appointment-row="{{ $appointment['id'] }}"
                                    style="border-color:{{ $isPlaced ? '#bbf7d0' : 'var(--gc-border)' }};background:{{ $isPlaced ? '#f0fdf4' : '#ffffff' }};"
                                >
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span data-lot-appointment-department class="rounded-full px-2 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">Dept. {{ $appointment['department_code'] ?: '--' }}</span>
                                            @if ($isPlaced)
                                                <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#dcfce7;color:#15803d;">RDV placé</span>
                                            @endif
                                            @if ($appointment['status'] === \App\Models\LotAppointment::STATUS_NEEDS_REVIEW)
                                                <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:#fef3c7;color:#b45309;">À vérifier</span>
                                            @endif
                                        </div>
                                        <h3 data-lot-appointment-customer class="mt-2 font-semibold" style="color:var(--gc-text);">{{ $appointment['customer_name'] }}</h3>
                                        <p data-lot-appointment-phone class="mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $appointment['customer_phone'] ?: 'Téléphone non renseigné' }}</p>
                                    </div>

                                    <div class="min-w-0">
                                        <p data-lot-appointment-address class="text-sm font-medium" style="color:var(--gc-text);">{{ $appointment['address'] ?: 'Adresse à qualifier' }}</p>
                                        <p data-lot-appointment-location class="{{ $appointmentLocation === '' ? 'hidden ' : '' }}mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $appointmentLocation }}</p>
                                        <p data-lot-appointment-reference class="mt-1 text-xs" style="color:var(--gc-text-soft);">
                                            @if ($appointment['external_reference'])
                                                Réf. {{ $appointment['external_reference'] }}
                                            @elseif ($appointment['row_number'])
                                                Ligne fichier {{ $appointment['row_number'] }}
                                            @else
                                                RDV lot #{{ $appointment['id'] }}
                                            @endif
                                        </p>
                                        <p data-lot-appointment-warnings class="{{ empty($appointment['ai_warnings']) ? 'hidden ' : '' }}mt-1 text-xs" style="color:#b45309;">{{ implode(' · ', $appointment['ai_warnings']) }}</p>
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

                                    <div class="flex flex-wrap justify-start gap-2 xl:justify-end">
                                        <button
                                            type="button"
                                            class="gc-btn-soft whitespace-nowrap lot-appointment-edit-trigger"
                                            data-lot-appointment-id="{{ $appointment['id'] }}"
                                        >
                                            Modifier
                                        </button>
                                        @if ($isPlaced && $appointment['tracking_url'])
                                            <a href="{{ $appointment['tracking_url'] }}" class="gc-btn-soft whitespace-nowrap">
                                                Voir le RDV
                                            </a>
                                        @elseif ($isPlaced)
                                            <span class="rounded-lg border px-3 py-2 text-sm" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                                                RDV indisponible
                                            </span>
                                        @endif
                                    </div>
                                    <script type="application/json" data-lot-appointment-json="{{ $appointment['id'] }}">
                                        @json($appointment)
                                    </script>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </details>
            @empty
                <div class="rounded-2xl border border-dashed p-8 text-center" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                    Aucun lot ne correspond aux filtres.
                </div>
            @endforelse
        </section>

        <div id="lot-appointment-edit-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">RDV du lot</p>
                        <h2 class="text-xl font-semibold" style="color:var(--gc-text);">Modifier les informations</h2>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">L’adresse est nettoyée puis géocodée à l’enregistrement.</p>
                    </div>
                    <button id="lot-appointment-edit-close" type="button" class="gc-link">Fermer</button>
                </div>

                <form id="lot-appointment-edit-form" class="space-y-4 overflow-y-auto p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="gc-label" for="lot_appointment_external_reference">Référence</label>
                            <input id="lot_appointment_external_reference" class="gc-input" data-lot-appointment-field="external_reference" type="text" maxlength="120" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_customer_first_name">Prénom</label>
                            <input id="lot_appointment_customer_first_name" class="gc-input" data-lot-appointment-field="customer_first_name" type="text" maxlength="120" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_customer_last_name">Nom</label>
                            <input id="lot_appointment_customer_last_name" class="gc-input" data-lot-appointment-field="customer_last_name" type="text" maxlength="120" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="gc-label" for="lot_appointment_customer_name">Nom complet client</label>
                            <input id="lot_appointment_customer_name" class="gc-input" data-lot-appointment-field="customer_name" type="text" maxlength="190" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_customer_phone">Téléphone</label>
                            <input id="lot_appointment_customer_phone" class="gc-input" data-lot-appointment-field="customer_phone" type="text" maxlength="30" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="gc-label" for="lot_appointment_address">Adresse</label>
                            <input id="lot_appointment_address" class="gc-input" data-lot-appointment-field="address" type="text" maxlength="255" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_postal_code">Code postal</label>
                            <input id="lot_appointment_postal_code" class="gc-input" data-lot-appointment-field="postal_code" type="text" maxlength="20" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_city">Ville</label>
                            <input id="lot_appointment_city" class="gc-input" data-lot-appointment-field="city" type="text" maxlength="120" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_department_code">Département</label>
                            <input id="lot_appointment_department_code" class="gc-input" data-lot-appointment-field="department_code" type="text" maxlength="3" />
                        </div>
                        <div class="md:col-span-2 xl:col-span-3">
                            <label class="gc-label" for="lot_appointment_comment">Commentaire</label>
                            <textarea id="lot_appointment_comment" class="gc-input min-h-28" data-lot-appointment-field="comment" maxlength="2000"></textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_260px]">
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <label class="gc-label mb-0">Position Mapbox</label>
                                <button id="lot-appointment-recalculate" type="button" class="gc-btn-soft text-sm disabled:cursor-not-allowed disabled:opacity-50">
                                    Recalculer
                                </button>
                            </div>
                            <div id="lot-appointment-edit-map" class="h-[280px] overflow-hidden rounded-xl border" style="border-color:var(--gc-border);background:#eef2f7;"></div>
                        </div>
                        <div class="rounded-xl border px-4 py-3 text-sm" style="border-color:var(--gc-border);background:#fbfaf6;">
                            <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Coordonnées</p>
                            <p id="lot-appointment-edit-gps" class="mt-2" style="color:var(--gc-text);"></p>
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">Utilise “Recalculer” si Mapbox a mal positionné le point pendant l’import.</p>
                        </div>
                    </div>
                    <p id="lot-appointment-edit-status" class="hidden text-sm"></p>

                    <div class="flex justify-end gap-2 border-t pt-4" style="border-color:var(--gc-border);">
                        <button id="lot-appointment-edit-cancel" type="button" class="gc-btn-soft">Annuler</button>
                        <button id="lot-appointment-edit-submit" type="submit" class="gc-btn-primary disabled:cursor-not-allowed disabled:opacity-50">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="lot-import-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Import du lot</p>
                        <h2 class="text-xl font-semibold" style="color:var(--gc-text);">Nettoyage IA et géocodage Mapbox</h2>
                        <p id="lot-import-modal-status" class="mt-1 text-sm" style="color:var(--gc-text-soft);">Préparation de l'import...</p>
                    </div>
                    <button id="lot-import-modal-close" type="button" class="gc-link disabled:cursor-not-allowed disabled:opacity-50">Fermer</button>
                </div>

                <div class="space-y-4 overflow-y-auto p-5">
                    <div>
                        <div class="mb-2 flex items-center justify-between text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">
                            <span>Progression</span>
                            <span id="lot-import-progress-label">0%</span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                            <div id="lot-import-progress-bar" class="h-full rounded-full transition-all" style="width:0%;background:var(--gc-primary);"></div>
                        </div>
                        <div class="mt-3 rounded-xl border p-3 text-sm" style="border-color:var(--gc-border);background:#fbfaf6;">
                            <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Étape en cours</p>
                            <p id="lot-import-stage" class="mt-1 font-medium" style="color:var(--gc-text);">En attente du lancement.</p>
                            <p id="lot-import-realtime-state" class="mt-1 text-xs" style="color:var(--gc-text-soft);">Suivi temps réel en attente.</p>
                        </div>
                    </div>

                    <div id="lot-import-error" class="hidden rounded-xl border p-4 text-sm" style="border-color:#fecaca;background:#fff1f2;color:#9f1239;">
                        <p id="lot-import-error-message"></p>
                        <button id="lot-import-retry" type="button" class="mt-3 hidden rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50" style="border-color:#fecaca;background:#fff7f8;color:#9f1239;">
                            Relancér l'import
                        </button>
                    </div>

                    <div id="lot-import-preview" class="hidden space-y-3">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="font-semibold" style="color:var(--gc-text);">Données nettoyees</h3>
                                <p id="lot-import-preview-summary" class="text-sm" style="color:var(--gc-text-soft);"></p>
                            </div>
                            <div class="flex items-center gap-3">
                                <button id="lot-import-select-all" type="button" class="gc-link">Tout cocher</button>
                                <button id="lot-import-unselect-all" type="button" class="gc-link">Tout décocher</button>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-xl border" style="border-color:var(--gc-border);">
                            <table class="min-w-full divide-y text-sm" style="border-color:var(--gc-border);">
                                <thead style="background:#fbfaf6;color:var(--gc-text-soft);">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Inclure</th>
                                        <th class="px-3 py-2 text-left">Client</th>
                                        <th class="px-3 py-2 text-left">Téléphone</th>
                                        <th class="px-3 py-2 text-left">Adresse</th>
                                        <th class="px-3 py-2 text-left">CP / ville</th>
                                        <th class="px-3 py-2 text-left">GPS</th>
                                        <th class="px-3 py-2 text-left">Warnings</th>
                                        <th class="px-3 py-2 text-left">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="lot-import-preview-rows" class="divide-y" style="border-color:var(--gc-border);"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t p-5" style="border-color:var(--gc-border);">
                    <p id="lot-import-selection-count" class="text-sm" style="color:var(--gc-text-soft);">0 ligne sélectionnée</p>
                    <button id="lot-import-confirm" type="button" class="gc-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled>Valider et créer le lot</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .lot-details > summary::-webkit-details-marker {
            display: none;
        }

        .lot-details[open] .lot-chevron {
            transform: rotate(180deg);
        }
    </style>

    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const lotFiltersForm = document.getElementById('manager-lot-filters-form');
        const lotSearchInput = document.getElementById('q');
        let lotSearchTimer = null;
        const sampleLotTypes = @json(\App\Models\Lot::samplingTypes());
        const resumedLotImport = @json($activeImportPreview);
        const lockedLotImportStatuses = ['pending', 'processing'];

        const lotImportForm = document.getElementById('lot-import-form');
        const lotImportFile = document.getElementById('lot_file');
        const lotFileSelected = document.getElementById('lot-file-selected');
        const lotImportType = document.getElementById('lot_type');
        const lotSamplingPercentage = document.getElementById('lot_sampling_percentage');
        const lotImportSubmit = document.getElementById('lot-import-submit');
        const lotImportModal = document.getElementById('lot-import-modal');
        const lotImportModalClose = document.getElementById('lot-import-modal-close');
        const lotImportStatus = document.getElementById('lot-import-modal-status');
        const lotImportProgressBar = document.getElementById('lot-import-progress-bar');
        const lotImportProgressLabel = document.getElementById('lot-import-progress-label');
        const lotImportStage = document.getElementById('lot-import-stage');
        const lotImportRealtimeState = document.getElementById('lot-import-realtime-state');
        const lotImportError = document.getElementById('lot-import-error');
        const lotImportPreview = document.getElementById('lot-import-preview');
        const lotImportPreviewRows = document.getElementById('lot-import-preview-rows');
        const lotImportPreviewSummary = document.getElementById('lot-import-preview-summary');
        const lotImportConfirm = document.getElementById('lot-import-confirm');
        const lotImportSelectionCount = document.getElementById('lot-import-selection-count');
        const lotImportSelectAll = document.getElementById('lot-import-select-all');
        const lotImportUnselectAll = document.getElementById('lot-import-unselect-all');
        const lotImportErrorMessage = document.getElementById('lot-import-error-message');
        const lotImportRetry = document.getElementById('lot-import-retry');
        let currentLotImport = null;
        let currentLotImportPoll = null;
        let currentLotImportSubscription = null;
        let currentLotImportCompleted = false;
        let selectedLotImportRows = null;
        let lotImportActualProgress = 0;
        let lotImportVisualProgress = 0;
        let lotImportProgressTimer = null;
        const lotAppointmentEditModal = document.getElementById('lot-appointment-edit-modal');
        const lotAppointmentEditForm = document.getElementById('lot-appointment-edit-form');
        const lotAppointmentEditClose = document.getElementById('lot-appointment-edit-close');
        const lotAppointmentEditCancel = document.getElementById('lot-appointment-edit-cancel');
        const lotAppointmentEditSubmit = document.getElementById('lot-appointment-edit-submit');
        const lotAppointmentRecalculate = document.getElementById('lot-appointment-recalculate');
        const lotAppointmentEditStatus = document.getElementById('lot-appointment-edit-status');
        const lotAppointmentEditGps = document.getElementById('lot-appointment-edit-gps');
        const lotMapboxToken = @json($mapboxToken ?? null);
        const lotAppointmentData = new Map();
        let currentLotAppointment = null;
        let lotAppointmentMap = null;
        let lotAppointmentMarker = null;

        document.querySelectorAll('[data-lot-appointment-json]').forEach((script) => {
            try {
                const appointment = JSON.parse(script.textContent || '{}');

                if (appointment.id) {
                    lotAppointmentData.set(String(appointment.id), appointment);
                }
            } catch (error) {
                // Données de secours ignorées : la ligne restera visible, seul le modal ne s’ouvrira pas.
            }
        });

        if (lotFiltersForm) {
            lotFiltersForm.querySelectorAll('select').forEach((select) => {
                select.addEventListener('change', () => lotFiltersForm.submit());
            });

            lotSearchInput?.addEventListener('input', () => {
                window.clearTimeout(lotSearchTimer);
                lotSearchTimer = window.setTimeout(() => lotFiltersForm.submit(), 350);
            });
        }

        function csrfToken() {
            return lotImportForm?.querySelector('input[name="_token"]')?.value || '';
        }

        function isSamplingType(type) {
            return sampleLotTypes.includes(type);
        }

        function updateLotImportState() {
            const needsSampling = isSamplingType(lotImportType?.value);

            if (lotSamplingPercentage) {
                lotSamplingPercentage.disabled = !needsSampling;

                if (!needsSampling) {
                    lotSamplingPercentage.value = '';
                }
            }

            const hasFile = lotImportFile?.files?.length > 0;
            const selectedFile = hasFile ? lotImportFile.files[0] : null;
            const hasType = Boolean(lotImportType?.value);
            const hasSampling = !needsSampling || Number(lotSamplingPercentage?.value || 0) > 0;

            if (lotImportFile && lotFileSelected) {
                lotImportFile.style.borderColor = hasFile ? '#86efac' : '';
                lotFileSelected.classList.toggle('hidden', !hasFile);
                lotFileSelected.textContent = selectedFile
                    ? `Fichier sélectionné: ${selectedFile.name} (${formatFileSize(selectedFile.size)})`
                    : '';
            }

            if (lotImportSubmit) {
                lotImportSubmit.disabled = !(hasFile && hasType && hasSampling);
            }
        }

        function formatFileSize(bytes) {
            if (!Number.isFinite(bytes) || bytes <= 0) {
                return '0 Ko';
            }

            if (bytes < 1024 * 1024) {
                return `${(bytes / 1024).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Ko`;
            }

            return `${(bytes / (1024 * 1024)).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} Mo`;
        }

        function openLotImportModal() {
            lotImportModal?.classList.remove('hidden');
            lotImportModal?.classList.add('flex');
            updateLotImportModalCloseState();
        }

        function closeLotImportModal() {
            if (isLotImportLocked()) {
                return;
            }

            lotImportModal?.classList.add('hidden');
            lotImportModal?.classList.remove('flex');
        }

        function isLotImportLocked(data = currentLotImport) {
            return lockedLotImportStatuses.includes(data?.status);
        }

        function updateLotImportModalCloseState() {
            if (!lotImportModalClose) return;

            const isLocked = isLotImportLocked();

            lotImportModalClose.disabled = isLocked;
            lotImportModalClose.textContent = isLocked ? 'Import en cours' : 'Fermer';
            lotImportModalClose.setAttribute('aria-disabled', String(isLocked));
        }

        function setLotImportRealtimeState(message, type = 'muted') {
            if (!lotImportRealtimeState) return;

            lotImportRealtimeState.textContent = message;
            lotImportRealtimeState.style.color = type === 'error'
                ? '#be123c'
                : (type === 'succèss' ? '#15803d' : 'var(--gc-text-soft)');
        }

        function setLotImportProgressVisual(progress) {
            const safeProgress = Math.max(0, Math.min(100, Math.round(Number(progress || 0))));
            lotImportVisualProgress = safeProgress;
            lotImportProgressBar.style.width = `${safeProgress}%`;
            lotImportProgressLabel.textContent = `${safeProgress}%`;
        }

        function stopLotImportProgressAnimation() {
            if (lotImportProgressTimer) {
                window.clearInterval(lotImportProgressTimer);
                lotImportProgressTimer = null;
            }
        }

        function resetLotImportProgressAnimation(progress = 0) {
            stopLotImportProgressAnimation();
            lotImportActualProgress = Math.max(0, Math.min(100, Number(progress || 0)));
            setLotImportProgressVisual(lotImportActualProgress);
        }

        function startLotImportProgressAnimation() {
            if (lotImportProgressTimer) return;

            lotImportProgressTimer = window.setInterval(() => {
                const isTerminal = lotImportActualProgress >= 100 || ['completed', 'failed', 'confirmed'].includes(currentLotImport?.status);
                const cap = isTerminal ? 100 : 94;
                const softTarget = isTerminal
                    ? 100
                    : Math.min(cap, lotImportVisualProgress + (lotImportVisualProgress < 55 ? 2.8 : (lotImportVisualProgress < 82 ? 1.2 : 0.35)));
                const target = Math.max(lotImportActualProgress, softTarget);
                const step = isTerminal
                    ? (lotImportVisualProgress < 86 ? 8 : 14)
                    : (target - lotImportVisualProgress > 8 ? 3.5 : 1);

                setLotImportProgressVisual(Math.min(target, lotImportVisualProgress + step));

                if (isTerminal && lotImportVisualProgress >= 100) {
                    stopLotImportProgressAnimation();
                }
            }, 220);
        }

        function updateLotImportProgress(progress, statusText, stageText = null) {
            const safeProgress = Math.max(0, Math.min(100, Number(progress || 0)));
            lotImportActualProgress = Math.max(lotImportActualProgress, safeProgress);

            if (safeProgress >= 100 || ['completed', 'failed', 'confirmed'].includes(currentLotImport?.status)) {
                lotImportActualProgress = 100;
            }

            if (lotImportVisualProgress === 0 || safeProgress <= 5) {
                setLotImportProgressVisual(safeProgress);
            }

            startLotImportProgressAnimation();
            lotImportStatus.textContent = statusText;

            if (stageText !== null && lotImportStage) {
                lotImportStage.textContent = stageText || 'Etape non renseignée.';
            }
        }

        function stopLotImportWatchers() {
            if (currentLotImportPoll) {
                window.clearInterval(currentLotImportPoll);
                currentLotImportPoll = null;
            }

            if (currentLotImportSubscription) {
                currentLotImportSubscription.unsubscribe();
                currentLotImportSubscription = null;
            }
        }

        function hideLotImportError() {
            lotImportError.classList.add('hidden');
            lotImportErrorMessage.textContent = '';
            lotImportRetry?.classList.add('hidden');
            if (lotImportRetry) {
                lotImportRetry.disabled = false;
                lotImportRetry.textContent = 'Relancér l\'import';
            }
        }

        function showLotImportError(message, canRetry = Boolean(currentLotImport?.retry_url)) {
            lotImportErrorMessage.textContent = message;
            lotImportError.classList.remove('hidden');
            lotImportRetry?.classList.toggle('hidden', !canRetry);
            lotImportConfirm.disabled = true;
        }

        function normalizedPreviewRows(rows) {
            if (Array.isArray(rows)) {
                return rows;
            }

            if (rows && typeof rows === 'object') {
                return Object.values(rows);
            }

            return [];
        }

        function hasCompleteLotImportPreview(data) {
            const expectedRows = Number(data?.normalized_rows || 0);
            const rows = normalizedPreviewRows(data?.appointments);

            return expectedRows === 0 || rows.length >= expectedRows;
        }

        function updateLotImportSelectionCount() {
            selectedLotImportRows = new Set(Array.from(lotImportPreviewRows.querySelectorAll('input[type="checkbox"]:checked'))
                .map((checkbox) => checkbox.value));

            lotImportSelectionCount.textContent = `${selectedLotImportRows.size} ligne(s) sélectionnée(s)`;
            lotImportConfirm.disabled = selectedLotImportRows.size === 0 || !currentLotImport?.confirm_url;
        }

        function isLotImportRowSelected(rowNumber) {
            if (selectedLotImportRows === null) {
                return true;
            }

            return selectedLotImportRows.has(String(rowNumber || ''));
        }

        function renderLotImportPreview(data) {
            currentLotImport = {
                ...currentLotImport,
                ...data,
            };
            lotImportPreviewRows.innerHTML = '';
            lotImportPreviewSummary.textContent = `${data.normalized_rows || 0} ligne(s) nettoyee(s), ${data.rejected_rows || 0} rejet(s). ${data.summary || ''}`;

            const appointments = normalizedPreviewRows(data.appointments);

            appointments.forEach((appointment) => {
                const rowNumber = appointment.row_number || '';
                const rowChecked = isLotImportRowSelected(rowNumber) ? 'checked' : '';
                const gps = appointment.latitude && appointment.longitude
                    ? `${Number(appointment.latitude).toFixed(5)}, ${Number(appointment.longitude).toFixed(5)}`
                    : '--';
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td class="px-3 py-3 align-top">
                        <input class="gc-check lot-import-row-checkbox" type="checkbox" value="${escapeHtml(rowNumber)}" ${rowChecked}>
                    </td>
                    <td class="px-3 py-3 align-top">
                        <div class="font-semibold" style="color:var(--gc-text);">${escapeHtml(appointment.customer_name || 'Client à qualifier')}</div>
                        <div class="text-xs" style="color:var(--gc-text-soft);">Ligne ${escapeHtml(appointment.row_number || '--')}</div>
                    </td>
                    <td class="px-3 py-3 align-top">${escapeHtml(appointment.customer_phone || '--')}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml(appointment.address || '--')}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml([appointment.postal_code, appointment.city].filter(Boolean).join(' ') || appointment.department_code || '--')}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml(gps)}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml((appointment.warnings || []).join(' · ') || '--')}</td>
                    <td class="px-3 py-3 align-top">
                        ${appointment.update_url ? `<button type="button" class="gc-link lot-import-edit-button" data-row-number="${escapeHtml(rowNumber)}">Modifier</button>` : '--'}
                    </td>
                `;
                lotImportPreviewRows.appendChild(row);

                if (appointment.update_url) {
                    const editRow = document.createElement('tr');
                    editRow.className = 'lot-import-edit-row hidden';
                    editRow.dataset.editRow = String(rowNumber);
                    editRow.dataset.updateUrl = appointment.update_url;
                    editRow.innerHTML = `
                        <td colspan="8" class="bg-slate-50 px-4 py-4">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <label class="gc-label">Nom complet</label>
                                    <input class="gc-input" data-field="customer_name" value="${escapeHtml(appointment.customer_name || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Prénom</label>
                                    <input class="gc-input" data-field="customer_first_name" value="${escapeHtml(appointment.customer_first_name || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Nom</label>
                                    <input class="gc-input" data-field="customer_last_name" value="${escapeHtml(appointment.customer_last_name || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Téléphone</label>
                                    <input class="gc-input" data-field="customer_phone" value="${escapeHtml(appointment.customer_phone || '')}">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="gc-label">Adresse</label>
                                    <input class="gc-input" data-field="address" value="${escapeHtml(appointment.address || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Code postal</label>
                                    <input class="gc-input" data-field="postal_code" value="${escapeHtml(appointment.postal_code || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Ville</label>
                                    <input class="gc-input" data-field="city" value="${escapeHtml(appointment.city || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Département</label>
                                    <input class="gc-input" data-field="department_code" value="${escapeHtml(appointment.department_code || '')}" maxlength="3">
                                </div>
                                <div class="md:col-span-2 xl:col-span-3">
                                    <label class="gc-label">Commentaire</label>
                                    <textarea class="gc-input min-h-24" data-field="comment">${escapeHtml(appointment.comment || '')}</textarea>
                                </div>
                            </div>
                            <p class="lot-import-row-error mt-3 hidden text-sm" style="color:#be123c;"></p>
                            <div class="mt-4 flex flex-wrap justify-end gap-2">
                                <button type="button" class="gc-btn-soft lot-import-cancel-edit" data-row-number="${escapeHtml(rowNumber)}">Annuler</button>
                                <button type="button" class="gc-btn-primary lot-import-save-row" data-row-number="${escapeHtml(rowNumber)}">Enregistrer et géocoder</button>
                            </div>
                        </td>
                    `;
                    lotImportPreviewRows.appendChild(editRow);
                }
            });

            lotImportPreview.classList.remove('hidden');
            lotImportPreviewRows.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
                checkbox.addEventListener('change', updateLotImportSelectionCount);
            });
            lotImportPreviewRows.querySelectorAll('.lot-import-edit-button').forEach((button) => {
                button.addEventListener('click', () => toggleLotImportEditRow(button.dataset.rowNumber, true));
            });
            lotImportPreviewRows.querySelectorAll('.lot-import-cancel-edit').forEach((button) => {
                button.addEventListener('click', () => toggleLotImportEditRow(button.dataset.rowNumber, false));
            });
            lotImportPreviewRows.querySelectorAll('.lot-import-save-row').forEach((button) => {
                button.addEventListener('click', () => {
                    void saveLotImportPreviewRow(button);
                });
            });
            updateLotImportSelectionCount();

            if (Number(data.normalized_rows || 0) > 0 && appointments.length === 0) {
                showLotImportError('La preview indique des lignes nettoyees, mais le payload complet est absent. Relancé l’import.');
            }
        }

        function lotImportStatusText(data) {
            if (data.status === 'completed') return 'Nettoyage terminé.';
            if (data.status === 'failed') return 'Import en erreur.';
            if (data.status === 'confirmed') return 'Lot créé.';

            return 'Traitement en cours...';
        }

        async function handleLotImportStatus(data) {
            currentLotImport = {
                ...currentLotImport,
                ...data,
                status_url: data.status_url || currentLotImport?.status_url,
                confirm_url: data.confirm_url || currentLotImport?.confirm_url,
                retry_url: data.retry_url || currentLotImport?.retry_url,
            };

            updateLotImportProgress(
                currentLotImport.progress,
                lotImportStatusText(currentLotImport),
                currentLotImport.stage || 'Traitement en cours...',
            );
            updateLotImportModalCloseState();

            if (currentLotImport.status === 'completed') {
                if (!hasCompleteLotImportPreview(currentLotImport)) {
                    setLotImportRealtimeState('Import terminé, recuperation de la preview complete...');
                    const fullPreview = await fetchLotImportStatus(currentLotImport.status_url);

                    if (fullPreview) {
                        currentLotImport = {
                            ...currentLotImport,
                            ...fullPreview,
                            status_url: fullPreview.status_url || currentLotImport?.status_url,
                            confirm_url: fullPreview.confirm_url || currentLotImport?.confirm_url,
                            retry_url: fullPreview.retry_url || currentLotImport?.retry_url,
                        };
                    }

                    if (!hasCompleteLotImportPreview(currentLotImport)) {
                        stopLotImportWatchers();
                        setLotImportRealtimeState('Preview complete indisponible.', 'error');
                        showLotImportError('Le traitement est terminé mais les lignes nettoyees ne sont pas disponibles. Relancé l’import.');
                        return;
                    }
                }

                if (!currentLotImportCompleted) {
                    currentLotImportCompleted = true;
                    stopLotImportWatchers();
                    setLotImportRealtimeState('Import terminé, preview prête.', 'succèss');
                    renderLotImportPreview(currentLotImport);
                }

                return;
            }

            if (currentLotImport.status === 'failed') {
                stopLotImportWatchers();
                updateLotImportModalCloseState();
                setLotImportRealtimeState('Import interrompu.', 'error');
                showLotImportError(currentLotImport.error_message || 'Import impossible.');
            }
        }

        function subscribeToLotImport(data) {
            if (!window.TechCalendarReverb?.subscribePrivate || !data.uuid) {
                setLotImportRealtimeState('Reverb indisponible, polling de secours actif.');
                return;
            }

            currentLotImportSubscription = window.TechCalendarReverb.subscribePrivate(
                `lot-import-preview.${data.uuid}`,
                'lot-import-preview.progressed',
                (payload) => {
                    void handleLotImportStatus(payload);
                },
                {
                    onState: (state) => {
                        if (state === 'closed' && (currentLotImportCompleted || currentLotImport?.status === 'failed')) {
                            return;
                        }

                        const labels = {
                            connecting: 'Connexion Reverb en cours...',
                            connected: 'Connexion Reverb ouverte, authentification du channel...',
                            subscribed: 'Suivi temps réel actif via Reverb.',
                            disconnected: 'Reverb deconnecte, polling de secours actif.',
                            closed: 'Suivi temps réel ferme.',
                        };

                        setLotImportRealtimeState(labels[state] || 'Etat Reverb inconnu.', state === 'subscribed' ? 'succèss' : 'muted');
                    },
                    onError: () => {
                        setLotImportRealtimeState('Reverb indisponible, polling de secours actif.', 'error');
                    },
                },
            );
        }

        function escapeHtml(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function lotAppointmentLocation(appointment) {
            return [appointment?.postal_code, appointment?.city]
                .filter(Boolean)
                .join(' ')
                .trim();
        }

        function lotAppointmentReference(appointment) {
            if (appointment?.external_reference) {
                return `Réf. ${appointment.external_reference}`;
            }

            if (appointment?.row_number) {
                return `Ligne fichier ${appointment.row_number}`;
            }

            return `RDV lot #${appointment?.id || '--'}`;
        }

        function lotAppointmentGpsLabel(appointment) {
            if (appointment?.latitude && appointment?.longitude) {
                return `GPS: ${Number(appointment.latitude).toFixed(5)}, ${Number(appointment.longitude).toFixed(5)}`;
            }

            return 'GPS non renseigné.';
        }

        function lotAppointmentCoordinates(appointment) {
            const latitude = Number(appointment?.latitude);
            const longitude = Number(appointment?.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                return null;
            }

            return { latitude, longitude };
        }

        function lotAppointmentMarkerElement() {
            const element = document.createElement('div');
            element.style.cssText = [
                'width:22px',
                'height:22px',
                'border-radius:9999px',
                'background:#e11d48',
                'border:3px solid #ffffff',
                'box-shadow:0 8px 20px rgba(15,23,42,.32)',
            ].join(';');

            return element;
        }

        function ensureLotAppointmentMap() {
            const container = document.getElementById('lot-appointment-edit-map');

            if (!container || !lotMapboxToken || !window.mapboxgl) {
                if (container) {
                    container.innerHTML = '<div class="flex h-full items-center justify-center px-4 text-center text-sm" style="color:var(--gc-text-soft);">Mapbox indisponible ou token absent.</div>';
                }

                return null;
            }

            window.mapboxgl.accessToken = lotMapboxToken;

            if (!lotAppointmentMap) {
                lotAppointmentMap = new window.mapboxgl.Map({
                    container: 'lot-appointment-edit-map',
                    style: 'mapbox://styles/mapbox/streets-v12',
                    center: [2.2137, 46.2276],
                    zoom: 4.6,
                    interactive: false,
                    attributionControl: false,
                });
            }

            return lotAppointmentMap;
        }

        function renderLotAppointmentMap(appointment) {
            const map = ensureLotAppointmentMap();

            if (!map) return;

            const coordinates = lotAppointmentCoordinates(appointment);

            window.setTimeout(() => {
                map.resize();

                if (lotAppointmentMarker) {
                    lotAppointmentMarker.remove();
                    lotAppointmentMarker = null;
                }

                if (!coordinates) {
                    map.setCenter([2.2137, 46.2276]);
                    map.setZoom(4.6);
                    return;
                }

                lotAppointmentMarker = new window.mapboxgl.Marker({
                    element: lotAppointmentMarkerElement(),
                    anchor: 'center',
                })
                    .setLngLat([coordinates.longitude, coordinates.latitude])
                    .addTo(map);

                map.setCenter([coordinates.longitude, coordinates.latitude]);
                map.setZoom(13);
            }, 80);
        }

        function setLotAppointmentEditStatus(message, color = '#0f766e') {
            if (!lotAppointmentEditStatus) return;

            lotAppointmentEditStatus.textContent = message || '';
            lotAppointmentEditStatus.style.color = color;
            lotAppointmentEditStatus.classList.toggle('hidden', !message);
        }

        function fillLotAppointmentEditForm(appointment) {
            lotAppointmentEditForm?.querySelectorAll('[data-lot-appointment-field]').forEach((field) => {
                field.value = appointment?.[field.dataset.lotAppointmentField] || '';
            });

            if (lotAppointmentEditGps) {
                lotAppointmentEditGps.textContent = lotAppointmentGpsLabel(appointment);
            }

            renderLotAppointmentMap(appointment);
        }

        function openLotAppointmentEditModal(appointmentId) {
            const appointment = lotAppointmentData.get(String(appointmentId));

            if (!appointment?.update_url) {
                return;
            }

            currentLotAppointment = appointment;
            fillLotAppointmentEditForm(appointment);
            setLotAppointmentEditStatus('');
            lotAppointmentEditModal?.classList.remove('hidden');
            lotAppointmentEditModal?.classList.add('flex');
        }

        function closeLotAppointmentEditModal() {
            lotAppointmentEditModal?.classList.add('hidden');
            lotAppointmentEditModal?.classList.remove('flex');
            currentLotAppointment = null;
        }

        function lotAppointmentEditPayload() {
            const payload = {};

            lotAppointmentEditForm?.querySelectorAll('[data-lot-appointment-field]').forEach((field) => {
                payload[field.dataset.lotAppointmentField] = field.value;
            });

            return payload;
        }

        function updateLotAppointmentRow(appointment) {
            const row = document.querySelector(`[data-lot-appointment-row="${appointment.id}"]`);

            if (!row) return;

            const location = lotAppointmentLocation(appointment);
            const warnings = Array.isArray(appointment.ai_warnings) ? appointment.ai_warnings.filter(Boolean) : [];

            row.querySelector('[data-lot-appointment-department]').textContent = `Dept. ${appointment.department_code || '--'}`;
            row.querySelector('[data-lot-appointment-customer]').textContent = appointment.customer_name || 'Client à qualifier';
            row.querySelector('[data-lot-appointment-phone]').textContent = appointment.customer_phone || 'Téléphone non renseigné';
            row.querySelector('[data-lot-appointment-address]').textContent = appointment.address || 'Adresse à qualifier';
            row.querySelector('[data-lot-appointment-reference]').textContent = lotAppointmentReference(appointment);

            const locationElement = row.querySelector('[data-lot-appointment-location]');
            locationElement.textContent = location;
            locationElement.classList.toggle('hidden', location === '');

            const warningElement = row.querySelector('[data-lot-appointment-warnings]');
            warningElement.textContent = warnings.join(' · ');
            warningElement.classList.toggle('hidden', warnings.length === 0);
        }

        async function saveLotAppointmentEdit(forceGeocode = false) {
            if (!currentLotAppointment?.update_url || lotAppointmentEditSubmit?.disabled) {
                return;
            }

            lotAppointmentEditSubmit.disabled = true;
            lotAppointmentRecalculate.disabled = true;
            lotAppointmentEditSubmit.textContent = forceGeocode ? 'Recalcul...' : 'Enregistrement...';
            setLotAppointmentEditStatus(forceGeocode ? 'Recalcul Mapbox des coordonnées...' : 'Nettoyage et géocodage en cours...');

            try {
                const response = await fetch(currentLotAppointment.update_url, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        ...lotAppointmentEditPayload(),
                        force_geocode: forceGeocode,
                    }),
                });
                const data = await response.json();

                if (!response.ok) {
                    setLotAppointmentEditStatus(data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Modification impossible.', '#be123c');
                    return;
                }

                lotAppointmentData.set(String(data.appointment.id), data.appointment);
                currentLotAppointment = data.appointment;
                fillLotAppointmentEditForm(data.appointment);
                updateLotAppointmentRow(data.appointment);
                setLotAppointmentEditStatus(data.message || 'RDV du lot mis à jour.', '#15803d');
                window.setTimeout(closeLotAppointmentEditModal, 450);
            } catch (error) {
                setLotAppointmentEditStatus('Erreur réseau pendant la modification.', '#be123c');
            } finally {
                lotAppointmentEditSubmit.disabled = false;
                lotAppointmentRecalculate.disabled = false;
                lotAppointmentEditSubmit.textContent = 'Enregistrer';
            }
        }

        document.querySelectorAll('.lot-appointment-edit-trigger').forEach((button) => {
            button.addEventListener('click', () => openLotAppointmentEditModal(button.dataset.lotAppointmentId));
        });

        lotAppointmentEditClose?.addEventListener('click', closeLotAppointmentEditModal);
        lotAppointmentEditCancel?.addEventListener('click', closeLotAppointmentEditModal);
        lotAppointmentEditForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            void saveLotAppointmentEdit();
        });
        lotAppointmentRecalculate?.addEventListener('click', () => {
            void saveLotAppointmentEdit(true);
        });

        function lotImportEditRow(rowNumber) {
            return lotImportPreviewRows.querySelector(`[data-edit-row="${String(rowNumber || '')}"]`);
        }

        function toggleLotImportEditRow(rowNumber, shouldOpen) {
            lotImportEditRow(rowNumber)?.classList.toggle('hidden', !shouldOpen);
        }

        async function saveLotImportPreviewRow(button) {
            const rowNumber = button.dataset.rowNumber;
            const editRow = lotImportEditRow(rowNumber);

            if (!editRow?.dataset.updateUrl) {
                return;
            }

            const error = editRow.querySelector('.lot-import-row-error');
            const payload = {};

            editRow.querySelectorAll('[data-field]').forEach((field) => {
                payload[field.dataset.field] = field.value;
            });

            button.disabled = true;
            button.textContent = 'Géocodage...';
            error?.classList.add('hidden');

            try {
                const response = await fetch(editRow.dataset.updateUrl, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();

                if (!response.ok) {
                    if (error) {
                        error.textContent = data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Modification impossible.';
                        error.classList.remove('hidden');
                    }

                    return;
                }

                currentLotImportCompleted = false;
                setLotImportRealtimeState(`Ligne ${rowNumber} mise à jour et géocodée.`, 'succèss');
                await handleLotImportStatus(data);
            } catch (exception) {
                if (error) {
                    error.textContent = 'Erreur réseau pendant la modification.';
                    error.classList.remove('hidden');
                }
            } finally {
                button.disabled = false;
                button.textContent = 'Enregistrer et géocoder';
            }
        }

        async function fetchLotImportStatus(statusUrl) {
            if (!statusUrl) return null;

            try {
                const response = await fetch(statusUrl, {
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                return await response.json();
            } catch (error) {
                setLotImportRealtimeState('Polling de secours momentanement indisponible.', 'error');

                return null;
            }
        }

        async function pollLotImport(statusUrl) {
            const data = await fetchLotImportStatus(statusUrl);

            if (!data) return;

            await handleLotImportStatus(data);
        }

        lotImportFile?.addEventListener('change', updateLotImportState);
        lotImportType?.addEventListener('change', updateLotImportState);
        lotSamplingPercentage?.addEventListener('input', updateLotImportState);
        updateLotImportState();

        lotImportModalClose?.addEventListener('click', closeLotImportModal);

        lotImportSelectAll?.addEventListener('click', () => {
            lotImportPreviewRows.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => checkbox.checked = true);
            updateLotImportSelectionCount();
        });

        lotImportUnselectAll?.addEventListener('click', () => {
            lotImportPreviewRows.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => checkbox.checked = false);
            updateLotImportSelectionCount();
        });

        async function watchLotImport(data) {
            currentLotImport = data;
            updateLotImportProgress(data.progress || 10, 'Import lancé, nettoyage IA en cours...', data.stage || 'Import ajouté à la file de traitement.');
            updateLotImportModalCloseState();
            subscribeToLotImport(data);
            currentLotImportPoll = window.setInterval(() => pollLotImport(data.status_url), 5000);
            await pollLotImport(data.status_url);
        }

        async function retryLotImport() {
            if (!currentLotImport?.retry_url || lotImportRetry?.disabled) {
                return;
            }

            stopLotImportWatchers();
            currentLotImportCompleted = false;
            selectedLotImportRows = null;
            if (lotImportRetry) {
                lotImportRetry.disabled = true;
                lotImportRetry.textContent = 'Relance en cours...';
            }

            currentLotImport = {
                ...currentLotImport,
                status: 'processing',
                progress: 0,
                stage: 'Relance de l’import.',
            };
            resetLotImportProgressAnimation(0);
            updateLotImportModalCloseState();
            hideLotImportError();
            lotImportPreview.classList.add('hidden');
            lotImportPreviewRows.innerHTML = '';
            lotImportConfirm.disabled = true;
            setLotImportRealtimeState('Relance demandée, attente de la queue...');
            updateLotImportProgress(0, 'Relance de l’import...', 'Relance de l’import.');

            try {
                const response = await fetch(currentLotImport.retry_url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                });
                const data = await response.json();

                if (!response.ok) {
                    currentLotImport = {
                        ...currentLotImport,
                        ...data,
                        status: 'failed',
                        progress: 100,
                    };
                    updateLotImportModalCloseState();
                    updateLotImportProgress(100, 'Relance refusée.', currentLotImport.stage || 'Relance refusée.');
                    showLotImportError(data.message || 'Relance impossible.', Boolean(currentLotImport.retry_url));
                    return;
                }

                await watchLotImport(data);
            } catch (error) {
                currentLotImport = {
                    ...currentLotImport,
                    status: 'failed',
                    progress: 100,
                    stage: 'Erreur réseau pendant la relance.',
                };
                updateLotImportModalCloseState();
                updateLotImportProgress(100, 'Relance non lancée.', currentLotImport.stage);
                showLotImportError('Erreur réseau pendant la relance de l’import.', Boolean(currentLotImport.retry_url));
            }
        }

        lotImportRetry?.addEventListener('click', () => {
            void retryLotImport();
        });

        lotImportForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (lotImportSubmit.disabled) {
                return;
            }

            stopLotImportWatchers();
            currentLotImport = {
                status: 'processing',
                progress: 5,
                stage: 'Envoi du fichier au serveur.',
            };
            resetLotImportProgressAnimation(5);
            currentLotImportCompleted = false;
            selectedLotImportRows = null;
            openLotImportModal();
            updateLotImportModalCloseState();
            hideLotImportError();
            lotImportPreview.classList.add('hidden');
            lotImportPreviewRows.innerHTML = '';
            lotImportConfirm.disabled = true;
            setLotImportRealtimeState('Suivi temps réel en attente.');
            updateLotImportProgress(5, 'Upload du fichier...', 'Envoi du fichier au serveur.');

            try {
                const response = await fetch(lotImportForm.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: new FormData(lotImportForm),
                });
                const data = await response.json();

                if (!response.ok) {
                    currentLotImport = {
                        status: 'failed',
                        progress: 100,
                        stage: 'Import refusé avant lancement du job.',
                    };
                    updateLotImportModalCloseState();
                    updateLotImportProgress(100, 'Import refusé.', currentLotImport.stage);
                    showLotImportError(data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Import refusé.');
                    return;
                }

                await watchLotImport(data);
            } catch (error) {
                currentLotImport = {
                    status: 'failed',
                    progress: 100,
                    stage: 'Erreur réseau pendant le lancement.',
                };
                updateLotImportModalCloseState();
                updateLotImportProgress(100, 'Import non lancé.', currentLotImport.stage);
                showLotImportError('Erreur réseau pendant le lancement de l’import.');
            }
        });

        lotImportConfirm?.addEventListener('click', async () => {
            const selectedRows = Array.from(lotImportPreviewRows.querySelectorAll('input[type="checkbox"]:checked'))
                .map((checkbox) => Number(checkbox.value))
                .filter(Boolean);

            if (!selectedRows.length || !currentLotImport?.confirm_url) {
                return;
            }

            lotImportConfirm.disabled = true;
            lotImportConfirm.textContent = 'Création du lot...';

            try {
                const response = await fetch(currentLotImport.confirm_url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ selected_rows: selectedRows }),
                });
                const data = await response.json();

                if (!response.ok) {
                    showLotImportError(data.message || 'Création du lot impossible.');
                    lotImportConfirm.textContent = 'Valider et créer le lot';
                    updateLotImportSelectionCount();
                    return;
                }

                stopLotImportWatchers();
                currentLotImport = {
                    ...currentLotImport,
                    status: 'confirmed',
                };
                updateLotImportModalCloseState();
                lotImportStatus.textContent = data.message || 'Lot créé.';
                window.setTimeout(() => {
                    window.location.href = data.redirect_url || '{{ route('manager.lots') }}';
                }, 800);
            } catch (error) {
                showLotImportError('Erreur réseau pendant la création du lot.');
                lotImportConfirm.textContent = 'Valider et créer le lot';
                updateLotImportSelectionCount();
            }
        });

        async function resumeLotImportIfNeeded() {
            if (!resumedLotImport || !isLotImportLocked(resumedLotImport)) {
                return;
            }

            currentLotImport = resumedLotImport;
            currentLotImportCompleted = false;
            selectedLotImportRows = null;
            resetLotImportProgressAnimation(resumedLotImport.progress || 0);
            hideLotImportError();
            lotImportPreview.classList.add('hidden');
            lotImportPreviewRows.innerHTML = '';
            lotImportConfirm.disabled = true;

            openLotImportModal();
            updateLotImportProgress(
                resumedLotImport.progress || 0,
                lotImportStatusText(resumedLotImport),
                resumedLotImport.stage || 'Import en cours...',
            );
            updateLotImportModalCloseState();
            setLotImportRealtimeState('Import en cours détecté, reprise du suivi temps réel...');
            subscribeToLotImport(resumedLotImport);
            currentLotImportPoll = window.setInterval(() => pollLotImport(resumedLotImport.status_url), 5000);
            await pollLotImport(resumedLotImport.status_url);
        }

        void resumeLotImportIfNeeded();
    </script>
</x-layouts.app>
