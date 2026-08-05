<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm" style="color: var(--gc-text-soft);">Gérant</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Gestion des lots</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                    Un lot regroupe des dossiers à traiter depuis un fichier d’import, avec suivi physique, contact ou hybride.
                </p>
            </div>
            <button id="lot-import-form-open" type="button" class="gc-btn-primary justify-center">
                Importer un lot
            </button>
        </div>

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#fecaca;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="grid grid-cols-1 gap-3 md:grid-cols-3">
            @foreach ($stats['status_widgets'] as $widget)
                <article class="flex items-center justify-between gap-4 rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">{{ $widget['label'] }}</p>
                        <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $widget['count'] }}</p>
                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">{{ $widget['percentage'] }}% du total</p>
                    </div>
                    <div class="lot-chart-ring lot-widget-ring shrink-0" style="--value:{{ $widget['percentage'] }};--ring-color:{{ $widget['color'] }};">
                        <span>{{ $widget['percentage'] }}%</span>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="gc-card p-4">
            <form id="manager-lot-filters-form" method="GET" action="{{ route('manager.lots') }}" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="search" value="{{ $filters['q'] }}" class="gc-input" placeholder="Client, délégataire, adresse, téléphone, référence" autocomplete="off" />
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

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($lots as $lot)
                @php
                    $lotActionPayload = [
                        'id' => $lot['id'],
                        'title' => $lot['title'],
                        'type' => $lot['type'],
                        'service_id' => $lot['service_id'],
                        'service_label' => $lot['service_label'],
                        'status' => $lot['status'],
                        'sampling_percentage' => $lot['sampling_percentage'],
                        'physical_sampling_percentage' => $lot['physical_sampling_percentage'],
                        'contact_sampling_percentage' => $lot['contact_sampling_percentage'],
                        'received_at' => $lot['received_at_input'],
                        'comment' => $lot['comment'],
                        'delegataire' => $lot['delegataire'],
                        'appointments_count' => $lot['appointments_count'],
                        'placed_count' => $lot['placed_count'],
                        'contact_processed_count' => $lot['contact_processed_count'],
                        'appointment_targets' => $lot['appointment_targets'],
                        'update_url' => $lot['update_url'],
                        'delete_url' => $lot['delete_url'],
                    ];
                    $physicalCompletion = is_array($lot['auto_completion']['physical'] ?? null)
                        ? $lot['auto_completion']['physical']
                        : null;
                    $contactCompletion = is_array($lot['auto_completion']['contact'] ?? null)
                        ? $lot['auto_completion']['contact']
                        : null;
                    $lotMetricItems = [[
                        'label' => 'Total',
                        'value' => (string) $lot['appointments_count'],
                        'hint' => 'dossiers',
                    ]];

                    if ($lot['supports_physical']) {
                        $physicalTarget = $lot['appointment_targets']['physical'] ?? [];
                        $lotMetricItems[] = [
                            'label' => 'Physiques',
                            'value' => sprintf(
                                '%d / %d',
                                (int) ($physicalTarget['completed_count'] ?? $physicalCompletion['completed_count'] ?? $lot['placed_count']),
                                (int) ($physicalTarget['target_count'] ?? $physicalCompletion['target_count'] ?? $lot['appointments_count']),
                            ),
                            'hint' => 'pris / cible',
                        ];
                    }

                    if ($lot['supports_contact']) {
                        $contactTarget = $lot['appointment_targets']['contact'] ?? [];
                        $lotMetricItems[] = [
                            'label' => 'Contacts',
                            'value' => sprintf(
                                '%d / %d',
                                (int) ($contactTarget['completed_count'] ?? $contactCompletion['completed_count'] ?? $lot['contact_processed_count']),
                                (int) ($contactTarget['target_count'] ?? $contactCompletion['target_count'] ?? $lot['appointments_count']),
                            ),
                            'hint' => 'traités / cible',
                        ];
                    }

                    $satisfactionCharts = collect($lot['satisfaction_charts'] ?? [])->values();
                    $dissatisfactionCharts = collect($lot['dissatisfaction_charts'] ?? [])->values();
                    $lotCardCharts = $satisfactionCharts->concat($dissatisfactionCharts)->values();

                    $lotCardChartGridClass = match (true) {
                        $lotCardCharts->count() >= 4 => 'grid-cols-2 xl:grid-cols-4',
                        $lotCardCharts->count() >= 3 => 'grid-cols-3',
                        $lotCardCharts->count() === 2 => 'grid-cols-2',
                        default => 'grid-cols-1',
                    };
                @endphp
                <article
                    class="lot-card group relative cursor-pointer overflow-hidden rounded-3xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-[var(--gc-primary)] focus:ring-offset-2"
                    style="border-color:var(--gc-border);"
                    data-lot-card-href="{{ $lot['show_url'] }}"
                    role="link"
                    tabindex="0"
                    aria-label="Voir le détail du lot {{ $lot['title'] }}"
                >
                    <div class="relative z-10 flex h-full flex-col gap-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="mb-2 flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background:{{ $lot['status_background'] }};color:{{ $lot['status_color'] }};">
                                        {{ $lot['status_label'] }}
                                    </span>
                                </div>
                                <h2 class="truncate text-xl font-semibold" style="color:var(--gc-text);">{{ $lot['title'] }}</h2>
                                <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">{{ $lot['type_label'] }}</p>
                                @if ($lot['service_label'])
                                    <p class="mt-1 truncate text-xs font-semibold" style="color:var(--gc-text);">Prestation : {{ $lot['service_label'] }}</p>
                                @endif
                                @if ($lot['delegataire'])
                                    <p class="mt-1 truncate text-xs" style="color:var(--gc-text-soft);">Délégataire : {{ $lot['delegataire'] }}</p>
                                @endif
                            </div>
                            <button
                                type="button"
                                class="gc-btn-soft relative z-20 whitespace-nowrap lot-action-trigger"
                                data-lot-id="{{ $lot['id'] }}"
                            >
                                Modifier
                            </button>
                        </div>

                        <div class="grid gap-3 text-center" style="grid-template-columns:repeat({{ count($lotMetricItems) }},minmax(0,1fr));">
                            @foreach ($lotMetricItems as $metric)
                                <div class="rounded-2xl border px-3 py-2" style="border-color:var(--gc-border);background:#fbfaf6;">
                                    <p class="text-xs" style="color:var(--gc-text-soft);">{{ $metric['label'] }}</p>
                                    <p class="text-lg font-semibold" style="color:var(--gc-text);">{{ $metric['value'] }}</p>
                                    <p class="mt-0.5 text-[0.68rem]" style="color:var(--gc-text-soft);">{{ $metric['hint'] }}</p>
                                </div>
                            @endforeach
                        </div>

                        @if ($lot['is_hybrid'])
                            <div class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach ($satisfactionCharts as $chart)
                                        <div class="rounded-2xl border p-3 text-center" style="border-color:var(--gc-border);background:linear-gradient(180deg,#ffffff,#fbfaf6);">
                                            <div class="lot-chart-ring mx-auto" style="--value:{{ $chart['percentage'] }};--ring-color:{{ $chart['color'] }};">
                                                <span>{{ $chart['display'] }}</span>
                                            </div>
                                            <p class="mt-3 text-xs font-semibold" style="color:var(--gc-text);">{{ $chart['label'] }}</p>
                                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">
                                                {{ $chart['satisfied_count'] }} / {{ $chart['target_count'] }} satisfaisant(s)
                                            </p>
                                            <p class="mt-1 text-[0.68rem] font-semibold" style="color:#166534;">
                                                Cible satisfaction : {{ $chart['target_percentage_display'] }}
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach ($dissatisfactionCharts as $chart)
                                        <div class="rounded-2xl border p-3 text-center" style="border-color:var(--gc-border);background:linear-gradient(180deg,#ffffff,#fbfaf6);">
                                            <div class="lot-chart-ring mx-auto" style="--value:{{ $chart['percentage'] }};--ring-color:{{ $chart['color'] }};">
                                                <span>{{ $chart['display'] }}</span>
                                            </div>
                                            <p class="mt-3 text-xs font-semibold" style="color:var(--gc-text);">{{ $chart['label'] }}</p>
                                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">
                                                {{ $chart['dissatisfied_count'] }} / {{ $chart['processed_count'] }} non satisfaisant(s)
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="grid gap-3 {{ $lotCardChartGridClass }}">
                                @foreach ($lotCardCharts as $chart)
                                    @php
                                        $isDissatisfactionChart = str_contains((string) ($chart['key'] ?? ''), 'dissatisfaction');
                                    @endphp
                                    <div class="rounded-2xl border p-3 text-center" style="border-color:var(--gc-border);background:linear-gradient(180deg,#ffffff,#fbfaf6);">
                                        <div class="lot-chart-ring mx-auto" style="--value:{{ $chart['percentage'] }};--ring-color:{{ $chart['color'] }};">
                                            <span>{{ $chart['display'] }}</span>
                                        </div>
                                        <p class="mt-3 text-xs font-semibold" style="color:var(--gc-text);">{{ $chart['label'] }}</p>
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">
                                            @if ($isDissatisfactionChart)
                                                {{ $chart['dissatisfied_count'] }} / {{ $chart['processed_count'] }} non satisfaisant(s)
                                            @else
                                                {{ $chart['satisfied_count'] }} / {{ $chart['target_count'] }} satisfaisant(s)
                                            @endif
                                        </p>
                                        @unless ($isDissatisfactionChart)
                                            <p class="mt-1 text-[0.68rem] font-semibold" style="color:#166534;">
                                                Cible satisfaction : {{ $chart['target_percentage_display'] }}
                                            </p>
                                        @endunless
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-auto border-t pt-4" style="border-color:var(--gc-border);">
                            <div class="min-w-0 text-xs" style="color:var(--gc-text-soft);">
                                @if ($lot['received_at_formatted'])
                                    <span>Reçu le {{ $lot['received_at_formatted'] }}</span>
                                    <span> · </span>
                                @endif
                                @if ($lot['original_filename'])
                                    <span class="truncate">{{ $lot['original_filename'] }}</span>
                                @else
                                    <span>Fichier original non renseigné</span>
                                @endif
                                @if ($lot['imported_at'])
                                    <span> · {{ $lot['imported_at']->format('d/m/Y H:i') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <script type="application/json" data-lot-json="{{ $lot['id'] }}">
                        @json($lotActionPayload)
                    </script>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed p-8 text-center md:col-span-2 xl:col-span-3" style="border-color:var(--gc-border);color:var(--gc-text-soft);">
                    Aucun lot ne correspond aux filtres.
                </div>
            @endforelse
        </section>

        @if ($lots->hasPages())
            <div class="rounded-2xl border bg-white px-4 py-3" style="border-color:var(--gc-border);">
                {{ $lots->links() }}
            </div>
        @endif

        <div id="lot-action-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Lot</p>
                        <h2 id="lot-action-title" class="text-xl font-semibold" style="color:var(--gc-text);">Modifier le lot</h2>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Modifie les informations du lot. Les admins peuvent aussi supprimer localement un lot déjà démarré.</p>
                    </div>
                    <button id="lot-action-close" type="button" class="gc-link">Fermer</button>
                </div>

                <div class="space-y-5 overflow-y-auto p-5">
                    <form id="lot-action-edit-form" method="POST" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="gc-label" for="lot_action_name">Nom du lot</label>
                                <input id="lot_action_name" name="name" type="text" class="gc-input" maxlength="190" required />
                            </div>
                            <div class="md:col-span-2">
                                <label class="gc-label" for="lot_action_delegataire">Délégataire</label>
                                <select id="lot_action_delegataire" name="delegataire" class="gc-input">
                                    <option value="">Sélectionner</option>
                                    @foreach ($delegataires as $delegataire)
                                        <option value="{{ $delegataire->name }}">
                                            {{ $delegataire->name }}
                                            @if ($delegataire->company_name)
                                                · {{ $delegataire->company_name }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="gc-label" for="lot_action_comment">Commentaires du lot</label>
                                <textarea id="lot_action_comment" name="comment" class="gc-input min-h-28" maxlength="5000" placeholder="Note interne, consignes de traitement, contexte client..."></textarea>
                            </div>
                            <div>
                                <label class="gc-label" for="lot_action_received_at">Date de réception du lot</label>
                                <input id="lot_action_received_at" name="received_at" type="date" class="gc-input" />
                            </div>
                            <div>
                                <label class="gc-label" for="lot_action_type">Type de lot</label>
                                <select id="lot_action_type" name="type" class="gc-input" required>
                                    @foreach ($lotTypes as $typeValue => $typeLabel)
                                        <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="gc-label" for="lot_action_service_id">Prestation</label>
                                <select id="lot_action_service_id" name="service_id" class="gc-input" required>
                                    <option value="">Sélectionner</option>
                                    @foreach ($services as $service)
                                        <option value="{{ $service->id }}">
                                            {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="gc-label" for="lot_action_status">Statut du lot</label>
                                <select id="lot_action_status" name="status" class="gc-input" required>
                                    @foreach ($lotStatuses as $statusValue => $statusLabel)
                                        <option value="{{ $statusValue }}">{{ $statusLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div id="lot-action-single-sampling-wrap" class="hidden md:col-span-2">
                                <label class="gc-label" for="lot_action_sampling_percentage">% d'échantillonnage</label>
                                <input id="lot_action_sampling_percentage" name="sampling_percentage" type="number" min="0.01" max="100" step="0.01" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Uniquement pour les lots échantillonnés" />
                            </div>
                            <div id="lot-action-physical-sampling-wrap" class="hidden">
                                <label class="gc-label" for="lot_action_physical_sampling_percentage">% physique</label>
                                <input id="lot_action_physical_sampling_percentage" name="physical_sampling_percentage" type="number" min="0.01" max="100" step="0.01" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Hybride" disabled />
                            </div>
                            <div id="lot-action-contact-sampling-wrap" class="hidden">
                                <label class="gc-label" for="lot_action_contact_sampling_percentage">% contact</label>
                                <input id="lot_action_contact_sampling_percentage" name="contact_sampling_percentage" type="number" min="0.01" max="100" step="0.01" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Hybride" disabled />
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 border-t pt-4" style="border-color:var(--gc-border);">
                            <button id="lot-action-cancel" type="button" class="gc-btn-soft">Annuler</button>
                            <button type="submit" class="gc-btn-primary">Enregistrer</button>
                        </div>
                    </form>

                    <div class="rounded-2xl border p-4" style="border-color:#fecaca;background:#fff7f8;">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="font-semibold" style="color:#9f1239;">Suppression du lot</h3>
                                <p id="lot-action-delete-note" class="mt-1 text-sm" style="color:#9f1239;"></p>
                            </div>
                            <form id="lot-action-delete-form" method="POST">
                                @csrf
                                @method('DELETE')
                                <button id="lot-action-delete-submit" type="submit" class="rounded-xl px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50" style="background:#dc2626;">
                                    Supprimer le lot
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                        <input id="lot_appointment_customer_name" data-lot-appointment-field="customer_name" type="hidden" />
                        <div>
                            <label class="gc-label" for="lot_appointment_company_name">Raison sociale</label>
                            <input id="lot_appointment_company_name" class="gc-input" data-lot-appointment-field="company_name" type="text" maxlength="190" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_site_name">Nom du site</label>
                            <input id="lot_appointment_site_name" class="gc-input" data-lot-appointment-field="site_name" type="text" maxlength="190" />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_appointment_customer_phone">Téléphone</label>
                            <input id="lot_appointment_customer_phone" class="gc-input" data-lot-appointment-field="customer_phone" type="text" maxlength="255" />
                        </div>
                        <div class="relative md:col-span-2 xl:col-span-3">
                            <label class="gc-label" for="lot_appointment_full_address">Adresse</label>
                            <input id="lot_appointment_full_address" class="gc-input" type="text" maxlength="255" autocomplete="off" placeholder="Saisir une adresse..." />
                            <div id="lot-appointment-address-suggestions" class="absolute left-0 right-0 top-full z-20 mt-2 hidden overflow-hidden rounded-xl border bg-white shadow-xl" style="border-color:var(--gc-border);"></div>
                            <p id="lot-appointment-address-meta" class="mt-1 text-xs" style="color:var(--gc-text-soft);"></p>
                            <input id="lot_appointment_address" data-lot-appointment-field="address" type="hidden" />
                            <input id="lot_appointment_postal_code" data-lot-appointment-field="postal_code" type="hidden" />
                            <input id="lot_appointment_city" data-lot-appointment-field="city" type="hidden" />
                            <input id="lot_appointment_department_code" data-lot-appointment-field="department_code" type="hidden" />
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

        <div id="lot-import-form-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Nouveau lot</p>
                        <h2 class="text-xl font-semibold" style="color:var(--gc-text);">Importer un lot</h2>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Formats supportés : .xlsx, .csv et .txt. Le nombre de lignes sera recalculé pendant la normalisation IA.</p>
                    </div>
                    <button id="lot-import-form-close" type="button" class="gc-link">Fermer</button>
                </div>

                <form id="lot-import-form" method="POST" action="{{ route('manager.lots.imports.store') }}" enctype="multipart/form-data" class="space-y-5 overflow-y-auto p-5">
                    @csrf
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div id="lot-file-field" class="xl:col-span-2">
                            <label class="gc-label" for="lot_file">Fichier du lot</label>
                            <input id="lot_file" name="file" type="file" class="gc-input" accept=".xlsx,.csv,.txt" required />
                            <p id="lot-file-selected" class="mt-1 hidden text-xs font-medium" style="color:#047857;"></p>
                        </div>
                        <div>
                            <label class="gc-label" for="lot_name">Nom du lot</label>
                            <input id="lot_name" name="name" type="text" value="{{ old('name') }}" class="gc-input" placeholder="Optionnel" />
                        </div>
                        <div class="md:col-span-2 xl:col-span-3">
                            <label class="gc-label" for="lot_comment">Commentaires du lot</label>
                            <textarea id="lot_comment" name="comment" class="gc-input min-h-28" maxlength="5000" placeholder="Optionnel : consignes de traitement, contexte, points d’attention...">{{ old('comment') }}</textarea>
                        </div>
                        <div>
                            <label class="gc-label" for="lot_received_at">Date de réception du lot</label>
                            <input id="lot_received_at" name="received_at" type="date" value="{{ old('received_at') }}" class="gc-input" required />
                        </div>
                        <div>
                            <label class="gc-label" for="lot_delegataire">Délégataire</label>
                            <select id="lot_delegataire" name="delegataire_id" class="gc-input" required>
                                <option value="">Sélectionner</option>
                                @foreach ($delegataires as $delegataire)
                                    <option value="{{ $delegataire->id }}" @selected((string) old('delegataire_id') === (string) $delegataire->id)>
                                        {{ $delegataire->name }}{{ $delegataire->email ? ' · '.$delegataire->email : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($delegataires->isEmpty())
                                <p class="mt-1 text-xs" style="color:#9f1239;">Aucun délégataire actif synchronisé. Va dans Gestion des délégataires puis récupère les données Coffrac.</p>
                            @endif
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
                            <label class="gc-label" for="lot_service_id">Prestation</label>
                            <select id="lot_service_id" name="service_id" class="gc-input" required>
                                <option value="">Sélectionner</option>
                                @foreach ($services as $service)
                                    <option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>
                                        {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div id="lot-single-sampling-wrap">
                            <label class="gc-label" for="lot_sampling_percentage">% d'échantillonnage</label>
                            <input id="lot_sampling_percentage" name="sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ old('sampling_percentage') }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Ex: 10" disabled />
                        </div>
                        <div id="lot-physical-sampling-wrap" class="hidden">
                            <label class="gc-label" for="lot_physical_sampling_percentage">% échantillonnage physique</label>
                            <input id="lot_physical_sampling_percentage" name="physical_sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ old('physical_sampling_percentage') }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Ex: 10" disabled />
                        </div>
                        <div id="lot-contact-sampling-wrap" class="hidden">
                            <label class="gc-label" for="lot_contact_sampling_percentage">% échantillonnage contact</label>
                            <input id="lot_contact_sampling_percentage" name="contact_sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ old('contact_sampling_percentage') }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Ex: 20" disabled />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t pt-4" style="border-color:var(--gc-border);">
                        <button id="lot-import-form-cancel" type="button" class="gc-btn-soft">Annuler</button>
                        <button id="lot-import-submit" type="submit" class="gc-btn-primary justify-center disabled:cursor-not-allowed disabled:opacity-50" disabled>Importer</button>
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
                            Relancer l'import
                        </button>
                    </div>

                    <div id="lot-import-preview" class="hidden space-y-3">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="font-semibold" style="color:var(--gc-text);">Données nettoyées</h3>
                                <p id="lot-import-preview-summary" class="text-sm" style="color:var(--gc-text-soft);"></p>
                                <p id="lot-import-warnings-summary" class="mt-1 text-xs font-semibold" style="color:var(--gc-text-soft);"></p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold" style="border-color:var(--gc-border);background:#fbfaf6;color:var(--gc-text);">
                                    <input id="lot-import-warnings-only" type="checkbox" class="gc-check">
                                    <span>Voir uniquement les warnings</span>
                                </label>
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
        .lot-chart-ring {
            align-items: center;
            background:
                radial-gradient(circle at center, #ffffff 0 58%, transparent 59%),
                conic-gradient(var(--ring-color, var(--gc-primary)) calc(var(--value) * 1%), #e2e8f0 0);
            border-radius: 9999px;
            display: flex;
            height: 88px;
            justify-content: center;
            width: 88px;
        }

        .lot-widget-ring {
            height: 74px;
            width: 74px;
        }

        .lot-chart-ring span {
            color: var(--gc-text);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .lot-widget-ring span {
            font-size: 0.9rem;
        }
    </style>

    <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>

    <script>
        const lotFiltersForm = document.getElementById('manager-lot-filters-form');
        const lotSearchInput = document.getElementById('q');
        let lotSearchTimer = null;
        const sampleLotTypes = @json(\App\Models\Lot::samplingTypes());
        const hybridLotType = @json(\App\Models\Lot::TYPE_HYBRID_LOCATION_CONTACT);
        const canForceDeleteStartedLots = @json($canForceDeleteStartedLots);
        const resumedLotImport = @json($activeImportPreview);
        const lockedLotImportStatuses = ['pending', 'processing'];
        const lotData = new Map();
        const lotActionModal = document.getElementById('lot-action-modal');
        const lotActionTitle = document.getElementById('lot-action-title');
        const lotActionClose = document.getElementById('lot-action-close');
        const lotActionCancel = document.getElementById('lot-action-cancel');
        const lotActionEditForm = document.getElementById('lot-action-edit-form');
        const lotActionDeleteForm = document.getElementById('lot-action-delete-form');
        const lotActionDeleteSubmit = document.getElementById('lot-action-delete-submit');
        const lotActionDeleteNote = document.getElementById('lot-action-delete-note');
        const lotActionName = document.getElementById('lot_action_name');
        const lotActionType = document.getElementById('lot_action_type');
        const lotActionServiceId = document.getElementById('lot_action_service_id');
        const lotActionStatus = document.getElementById('lot_action_status');
        const lotActionSingleSamplingWrap = document.getElementById('lot-action-single-sampling-wrap');
        const lotActionPhysicalSamplingWrap = document.getElementById('lot-action-physical-sampling-wrap');
        const lotActionContactSamplingWrap = document.getElementById('lot-action-contact-sampling-wrap');
        const lotActionSamplingPercentage = document.getElementById('lot_action_sampling_percentage');
        const lotActionPhysicalSamplingPercentage = document.getElementById('lot_action_physical_sampling_percentage');
        const lotActionContactSamplingPercentage = document.getElementById('lot_action_contact_sampling_percentage');
        const lotActionDelegataire = document.getElementById('lot_action_delegataire');
        const lotActionReceivedAt = document.getElementById('lot_action_received_at');
        const lotActionComment = document.getElementById('lot_action_comment');
        let currentLotAction = null;

        const lotImportFormOpen = document.getElementById('lot-import-form-open');
        const lotImportFormModal = document.getElementById('lot-import-form-modal');
        const lotImportFormClose = document.getElementById('lot-import-form-close');
        const lotImportFormCancel = document.getElementById('lot-import-form-cancel');
        const lotImportForm = document.getElementById('lot-import-form');
        const lotImportFile = document.getElementById('lot_file');
        const lotFileSelected = document.getElementById('lot-file-selected');
        const lotImportReceivedAt = document.getElementById('lot_received_at');
        const lotImportDelegataire = document.getElementById('lot_delegataire');
        const lotImportType = document.getElementById('lot_type');
        const lotImportServiceId = document.getElementById('lot_service_id');
        const lotSamplingPercentage = document.getElementById('lot_sampling_percentage');
        const lotSingleSamplingWrap = document.getElementById('lot-single-sampling-wrap');
        const lotPhysicalSamplingWrap = document.getElementById('lot-physical-sampling-wrap');
        const lotContactSamplingWrap = document.getElementById('lot-contact-sampling-wrap');
        const lotPhysicalSamplingPercentage = document.getElementById('lot_physical_sampling_percentage');
        const lotContactSamplingPercentage = document.getElementById('lot_contact_sampling_percentage');
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
        const lotImportWarningsSummary = document.getElementById('lot-import-warnings-summary');
        const lotImportWarningsOnly = document.getElementById('lot-import-warnings-only');
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
        const lotAppointmentFullAddress = document.getElementById('lot_appointment_full_address');
        const lotAppointmentAddressSuggestions = document.getElementById('lot-appointment-address-suggestions');
        const lotAppointmentAddressMeta = document.getElementById('lot-appointment-address-meta');
        const lotMapboxToken = @json($mapboxToken ?? null);
        const lotAppointmentData = new Map();
        let currentLotAppointment = null;
        let lotAppointmentMap = null;
        let lotAppointmentMarker = null;
        let lotAppointmentAddressTimer = null;
        let lotAppointmentAddressAbortController = null;

        document.querySelectorAll('[data-lot-json]').forEach((script) => {
            try {
                const lot = JSON.parse(script.textContent || '{}');

                if (lot.id) {
                    lotData.set(String(lot.id), lot);
                }
            } catch (error) {
                // Données invalides ignorées : le lot reste consultable, seul le modal d’action ne s’ouvrira pas.
            }
        });

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

        document.querySelectorAll('[data-lot-card-href]').forEach((card) => {
            const goToLotDetail = () => {
                const target = card.dataset.lotCardHref;

                if (target) {
                    window.location.href = target;
                }
            };

            card.addEventListener('click', (event) => {
                if (event.target.closest('button, a, input, select, textarea, label')) {
                    return;
                }

                goToLotDetail();
            });

            card.addEventListener('keydown', (event) => {
                if (!['Enter', ' '].includes(event.key)) {
                    return;
                }

                event.preventDefault();
                goToLotDetail();
            });
        });

        document.querySelectorAll('.lot-action-trigger').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                openLotActionModal(button.dataset.lotId);
            });
        });

        lotActionClose?.addEventListener('click', closeLotActionModal);
        lotActionCancel?.addEventListener('click', closeLotActionModal);
        lotActionType?.addEventListener('change', updateLotActionSamplingState);
        lotActionDeleteForm?.addEventListener('submit', (event) => {
            if (lotActionDeleteSubmit?.disabled) {
                event.preventDefault();
                return;
            }

            if (!window.confirm(`Supprimer définitivement le lot "${currentLotAction?.title || ''}" ?`)) {
                event.preventDefault();
            }
        });

        function csrfToken() {
            return lotImportForm?.querySelector('input[name="_token"]')?.value || '';
        }

        function isSamplingType(type) {
            return sampleLotTypes.includes(type);
        }

        function isHybridType(type) {
            return type === hybridLotType;
        }

        function updateLotActionSamplingState() {
            if (!lotActionSamplingPercentage) return;

            const currentType = lotActionType?.value;
            const needsSampling = isSamplingType(currentType);
            const needsHybridSampling = isHybridType(currentType);
            const needsSingleSampling = needsSampling && !needsHybridSampling;

            lotActionSingleSamplingWrap?.classList.toggle('hidden', !needsSingleSampling);
            lotActionPhysicalSamplingWrap?.classList.toggle('hidden', !needsHybridSampling);
            lotActionContactSamplingWrap?.classList.toggle('hidden', !needsHybridSampling);

            lotActionSamplingPercentage.disabled = !needsSampling;
            lotActionSamplingPercentage.required = needsSingleSampling;
            if (lotActionPhysicalSamplingPercentage) {
                lotActionPhysicalSamplingPercentage.disabled = !needsHybridSampling;
                lotActionPhysicalSamplingPercentage.required = needsHybridSampling;
            }
            if (lotActionContactSamplingPercentage) {
                lotActionContactSamplingPercentage.disabled = !needsHybridSampling;
                lotActionContactSamplingPercentage.required = needsHybridSampling;
            }

            if (!needsSingleSampling) {
                lotActionSamplingPercentage.value = '';
            }
            if (!needsHybridSampling) {
                if (lotActionPhysicalSamplingPercentage) lotActionPhysicalSamplingPercentage.value = '';
                if (lotActionContactSamplingPercentage) lotActionContactSamplingPercentage.value = '';
            }
        }

        function ensureSelectOption(select, value, label = value) {
            if (!select || !value) return;

            const exists = Array.from(select.options).some((option) => option.value === value);

            if (!exists) {
                select.add(new Option(label, value));
            }
        }

        function openLotActionModal(lotId) {
            const lot = lotData.get(String(lotId));

            if (!lot?.update_url || !lot?.delete_url) {
                return;
            }

            currentLotAction = lot;
            lotActionTitle.textContent = `Modifier le lot ${lot.title || `#${lot.id}`}`;
            lotActionEditForm.action = lot.update_url;
            lotActionDeleteForm.action = lot.delete_url;
            lotActionName.value = lot.title || '';
            lotActionType.value = lot.type || '';
            if (lotActionServiceId) lotActionServiceId.value = lot.service_id || '';
            lotActionStatus.value = lot.status || '';
            lotActionSamplingPercentage.value = lot.sampling_percentage ?? '';
            if (lotActionPhysicalSamplingPercentage) lotActionPhysicalSamplingPercentage.value = lot.physical_sampling_percentage ?? '';
            if (lotActionContactSamplingPercentage) lotActionContactSamplingPercentage.value = lot.contact_sampling_percentage ?? '';
            if (lotActionReceivedAt) lotActionReceivedAt.value = lot.received_at || '';
            if (lotActionComment) lotActionComment.value = lot.comment || '';
            ensureSelectOption(lotActionDelegataire, lot.delegataire || '');
            lotActionDelegataire.value = lot.delegataire || '';
            updateLotActionSamplingState();

            const placedCount = Number(lot.placed_count || 0);
            const contactProcessedCount = Number(lot.contact_processed_count || 0);
            const tracedCount = placedCount + contactProcessedCount;
            const appointmentsCount = Number(lot.appointments_count || 0);
            lotActionDeleteSubmit.disabled = tracedCount > 0 && !canForceDeleteStartedLots;
            lotActionDeleteNote.textContent = tracedCount > 0
                ? (canForceDeleteStartedLots
                    ? `${placedCount} RDV placé(s) et ${contactProcessedCount} contact(s) traité(s) sur ${appointmentsCount}. Suppression locale autorisée pour admin, sans modification Coffrac.`
                    : `${placedCount} RDV placé(s) et ${contactProcessedCount} contact(s) traité(s) sur ${appointmentsCount}. Suppression bloquée pour conserver la traçabilité.`)
                : `${appointmentsCount} RDV non placé(s) seront supprimés avec ce lot.`;

            lotActionModal?.classList.remove('hidden');
            lotActionModal?.classList.add('flex');
        }

        function closeLotActionModal() {
            lotActionModal?.classList.add('hidden');
            lotActionModal?.classList.remove('flex');
            currentLotAction = null;
        }

        function updateLotImportState() {
            const currentType = lotImportType?.value;
            const needsSampling = isSamplingType(currentType);
            const needsHybridSampling = isHybridType(currentType);
            const needsSingleSampling = needsSampling && !needsHybridSampling;

            lotSingleSamplingWrap?.classList.toggle('hidden', !needsSingleSampling);
            lotPhysicalSamplingWrap?.classList.toggle('hidden', !needsHybridSampling);
            lotContactSamplingWrap?.classList.toggle('hidden', !needsHybridSampling);

            if (lotSamplingPercentage) {
                lotSamplingPercentage.disabled = !needsSingleSampling;
                lotSamplingPercentage.required = needsSingleSampling;

                if (!needsSingleSampling) {
                    lotSamplingPercentage.value = '';
                }
            }

            if (lotPhysicalSamplingPercentage) {
                lotPhysicalSamplingPercentage.disabled = !needsHybridSampling;
                lotPhysicalSamplingPercentage.required = needsHybridSampling;

                if (!needsHybridSampling) {
                    lotPhysicalSamplingPercentage.value = '';
                }
            }

            if (lotContactSamplingPercentage) {
                lotContactSamplingPercentage.disabled = !needsHybridSampling;
                lotContactSamplingPercentage.required = needsHybridSampling;

                if (!needsHybridSampling) {
                    lotContactSamplingPercentage.value = '';
                }
            }

            const hasFile = lotImportFile?.files?.length > 0;
            const selectedFile = hasFile ? lotImportFile.files[0] : null;
            const hasDelegataire = Boolean(lotImportDelegataire?.value?.trim());
            const hasReceivedAt = Boolean(lotImportReceivedAt?.value);
            const hasType = Boolean(lotImportType?.value);
            const hasService = Boolean(lotImportServiceId?.value);
            const hasSampling = needsHybridSampling
                ? Number(lotPhysicalSamplingPercentage?.value || 0) > 0 && Number(lotContactSamplingPercentage?.value || 0) > 0
                : (!needsSampling || Number(lotSamplingPercentage?.value || 0) > 0);

            if (lotImportFile && lotFileSelected) {
                lotImportFile.style.borderColor = hasFile ? '#86efac' : '';
                lotFileSelected.classList.toggle('hidden', !hasFile);
                lotFileSelected.textContent = selectedFile
                    ? `Fichier sélectionné: ${selectedFile.name} (${formatFileSize(selectedFile.size)})`
                    : '';
            }

            if (lotImportSubmit) {
                lotImportSubmit.disabled = !(hasFile && hasDelegataire && hasReceivedAt && hasType && hasService && hasSampling);
            }
        }

        function openLotImportFormModal() {
            lotImportFormModal?.classList.remove('hidden');
            lotImportFormModal?.classList.add('flex');
            updateLotImportState();
        }

        function closeLotImportFormModal() {
            lotImportFormModal?.classList.add('hidden');
            lotImportFormModal?.classList.remove('flex');
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
                lotImportRetry.textContent = 'Relancer l\'import';
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

        function lotImportWarnings(appointment) {
            return Array.isArray(appointment?.warnings)
                ? appointment.warnings.filter(Boolean)
                : [];
        }

        function lotImportCheckboxes() {
            return Array.from(lotImportPreviewRows.querySelectorAll('.lot-import-row-checkbox'));
        }

        function lotImportVisibleCheckboxes() {
            const checkboxes = lotImportCheckboxes();

            if (!lotImportWarningsOnly?.checked) {
                return checkboxes;
            }

            return checkboxes.filter((checkbox) => !checkbox.closest('[data-preview-row]')?.classList.contains('hidden'));
        }

        function lotImportSelectedWarningCheckboxes() {
            return lotImportCheckboxes()
                .filter((checkbox) => checkbox.checked && checkbox.dataset.hasWarnings === '1');
        }

        function applyLotImportWarningFilter() {
            const warningsOnly = Boolean(lotImportWarningsOnly?.checked);

            lotImportPreviewRows.querySelectorAll('[data-preview-row]').forEach((row) => {
                const hiddenByFilter = warningsOnly && row.dataset.hasWarnings !== '1';
                row.classList.toggle('hidden', hiddenByFilter);

                lotImportPreviewRows.querySelectorAll('.lot-import-edit-row').forEach((editRow) => {
                    if (editRow.dataset.editRow === row.dataset.previewRow && hiddenByFilter) {
                        editRow.classList.add('hidden');
                    }
                });
            });
        }

        function updateLotImportSelectionCount() {
            selectedLotImportRows = new Set(lotImportCheckboxes().filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value));

            const selectedWarningRows = lotImportSelectedWarningCheckboxes();
            const warningSuffix = selectedWarningRows.length > 0
                ? ` · ${selectedWarningRows.length} warning(s) à corriger ou décocher`
                : '';

            lotImportSelectionCount.textContent = `${selectedLotImportRows.size} ligne(s) sélectionnée(s)${warningSuffix}`;
            lotImportConfirm.disabled = selectedLotImportRows.size === 0
                || selectedWarningRows.length > 0
                || !currentLotImport?.confirm_url;
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
            lotImportPreviewSummary.textContent = `${data.normalized_rows || 0} ligne(s) nettoyée(s), ${data.rejected_rows || 0} rejet(s). ${data.summary || ''}`;

            const appointments = normalizedPreviewRows(data.appointments);
            const warningRowsCount = appointments.filter((appointment) => lotImportWarnings(appointment).length > 0).length;

            if (lotImportWarningsOnly?.checked && warningRowsCount === 0) {
                lotImportWarningsOnly.checked = false;
            }

            if (lotImportWarningsSummary) {
                lotImportWarningsSummary.textContent = warningRowsCount > 0
                    ? `${warningRowsCount} ligne(s) avec warning : corrige-les ou décoche-les avant validation.`
                    : 'Aucun warning détecté sur les lignes nettoyées.';
                lotImportWarningsSummary.style.color = warningRowsCount > 0 ? '#b45309' : '#15803d';
            }

            appointments.forEach((appointment) => {
                const rowNumber = appointment.row_number || '';
                const rowChecked = isLotImportRowSelected(rowNumber) ? 'checked' : '';
                const warnings = lotImportWarnings(appointment);
                const hasWarnings = warnings.length > 0;
                const gps = appointment.latitude && appointment.longitude
                    ? `${Number(appointment.latitude).toFixed(5)}, ${Number(appointment.longitude).toFixed(5)}`
                    : '--';
                const displayName = lotImportDisplayName(appointment);
                const businessLabel = lotAppointmentBusinessLabel(appointment);
                const row = document.createElement('tr');
                row.dataset.previewRow = String(rowNumber);
                row.dataset.hasWarnings = hasWarnings ? '1' : '0';
                row.innerHTML = `
                    <td class="px-3 py-3 align-top">
                        <input class="gc-check lot-import-row-checkbox" type="checkbox" value="${escapeHtml(rowNumber)}" data-has-warnings="${hasWarnings ? '1' : '0'}" ${rowChecked}>
                    </td>
                    <td class="px-3 py-3 align-top">
                        <div class="font-semibold" style="color:var(--gc-text);">${escapeHtml(displayName)}</div>
                        ${businessLabel ? `<div class="text-xs" style="color:var(--gc-text-soft);">${escapeHtml(businessLabel)}</div>` : ''}
                        <div class="text-xs" style="color:var(--gc-text-soft);">Ligne ${escapeHtml(appointment.row_number || '--')}</div>
                    </td>
                    <td class="px-3 py-3 align-top">${escapeHtml(appointment.customer_phone || '--')}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml(appointment.address || '--')}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml([appointment.postal_code, appointment.city].filter(Boolean).join(' ') || appointment.department_code || '--')}</td>
                    <td class="px-3 py-3 align-top">${escapeHtml(gps)}</td>
                    <td class="px-3 py-3 align-top">
                        ${hasWarnings
                            ? `<span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold" style="background:#fef3c7;color:#92400e;">${escapeHtml(warnings.join(' · '))}</span>`
                            : '<span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold" style="background:#dcfce7;color:#166534;">OK</span>'}
                    </td>
                    <td class="px-3 py-3 align-top">
                        ${appointment.update_url ? `<button type="button" class="gc-link lot-import-edit-button" data-row-number="${escapeHtml(rowNumber)}">Modifier</button>` : '--'}
                    </td>
                `;
                lotImportPreviewRows.appendChild(row);

                if (appointment.update_url) {
                    const editRow = document.createElement('tr');
                    editRow.className = 'lot-import-edit-row hidden';
                    editRow.dataset.editRow = String(rowNumber);
                    editRow.dataset.hasWarnings = hasWarnings ? '1' : '0';
                    editRow.dataset.updateUrl = appointment.update_url;
                    editRow.innerHTML = `
                        <td colspan="8" class="bg-slate-50 px-4 py-4">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <input type="hidden" data-field="customer_name" value="${escapeHtml(appointment.customer_name || '')}">
                                <div>
                                    <label class="gc-label">Raison sociale</label>
                                    <input class="gc-input" data-field="company_name" value="${escapeHtml(appointment.company_name || '')}">
                                </div>
                                <div>
                                    <label class="gc-label">Nom du site</label>
                                    <input class="gc-input" data-field="site_name" value="${escapeHtml(appointment.site_name || '')}">
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
            applyLotImportWarningFilter();
            updateLotImportSelectionCount();

            if (Number(data.normalized_rows || 0) > 0 && appointments.length === 0) {
                showLotImportError('La preview indique des lignes nettoyées, mais le payload complet est absent. Relance l’import.');
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

        function lotImportDisplayName(appointment) {
            return (appointment?.company_name || appointment?.customer_name || [
                appointment?.customer_first_name,
                appointment?.customer_last_name,
            ].filter(Boolean).join(' ') || appointment?.site_name || 'Client à qualifier').trim();
        }

        function lotAppointmentBusinessLabel(appointment) {
            return [
                appointment?.company_name ? `Raison sociale : ${appointment.company_name}` : null,
                appointment?.site_name ? `Site : ${appointment.site_name}` : null,
            ].filter(Boolean).join(' · ');
        }

        function lotAppointmentLocation(appointment) {
            return [appointment?.postal_code, appointment?.city]
                .filter(Boolean)
                .join(' ')
                .trim();
        }

        function lotAppointmentFullAddressLabel(appointment) {
            return [
                appointment?.address,
                lotAppointmentLocation(appointment),
            ]
                .filter(Boolean)
                .join(', ')
                .trim();
        }

        function lotAppointmentHiddenField(name) {
            return lotAppointmentEditForm?.querySelector(`[data-lot-appointment-field="${name}"]`);
        }

        function setLotAppointmentHiddenValue(name, value) {
            const field = lotAppointmentHiddenField(name);

            if (field) {
                field.value = value || '';
            }
        }

        function lotAppointmentDepartmentFromPostalCode(postalCode) {
            const match = String(postalCode || '').match(/^(\d{2})/);

            return match ? match[1] : '';
        }

        function setLotAppointmentAddressMeta(appointment) {
            if (!lotAppointmentAddressMeta) return;

            const location = lotAppointmentLocation(appointment);
            lotAppointmentAddressMeta.textContent = location
                ? `Adresse qualifiée : ${location}${appointment?.department_code ? ` · dept. ${appointment.department_code}` : ''}`
                : 'Sélectionne une suggestion Mapbox pour récupérer le code postal, la ville et les coordonnées.';
        }

        function hideLotAppointmentAddressSuggestions() {
            if (!lotAppointmentAddressSuggestions) return;

            lotAppointmentAddressSuggestions.classList.add('hidden');
            lotAppointmentAddressSuggestions.innerHTML = '';
        }

        function lotAppointmentFeatureText(feature, type) {
            if (Array.isArray(feature?.place_type) && feature.place_type.includes(type)) {
                return feature.text || '';
            }

            return (feature?.context || [])
                .find((item) => String(item?.id || '').startsWith(`${type}.`))
                ?.text || '';
        }

        function lotAppointmentSuggestionPayload(feature) {
            const coordinates = feature?.center || feature?.geometry?.coordinates || [];
            const postalCode = lotAppointmentFeatureText(feature, 'postcode');
            const city = lotAppointmentFeatureText(feature, 'place')
                || lotAppointmentFeatureText(feature, 'locality')
                || lotAppointmentFeatureText(feature, 'region');
            const streetNumber = feature?.address || feature?.properties?.address || '';
            const streetName = feature?.text || '';
            const address = [streetNumber, streetName].filter(Boolean).join(' ').trim()
                || String(feature?.place_name || '').split(',')[0]?.trim()
                || '';
            const departmentCode = lotAppointmentDepartmentFromPostalCode(postalCode);

            return {
                address,
                postal_code: postalCode,
                city,
                department_code: departmentCode,
                latitude: Number(coordinates[1]),
                longitude: Number(coordinates[0]),
                label: [
                    address,
                    [postalCode, city].filter(Boolean).join(' '),
                ].filter(Boolean).join(', '),
                place_name: feature?.place_name || '',
            };
        }

        function applyLotAppointmentAddressSuggestion(payload) {
            setLotAppointmentHiddenValue('address', payload.address);
            setLotAppointmentHiddenValue('postal_code', payload.postal_code);
            setLotAppointmentHiddenValue('city', payload.city);
            setLotAppointmentHiddenValue('department_code', payload.department_code);

            if (lotAppointmentFullAddress) {
                lotAppointmentFullAddress.value = payload.label || payload.place_name || payload.address || '';
            }

            currentLotAppointment = {
                ...(currentLotAppointment || {}),
                address: payload.address,
                postal_code: payload.postal_code,
                city: payload.city,
                department_code: payload.department_code,
                latitude: Number.isFinite(payload.latitude) ? payload.latitude : currentLotAppointment?.latitude,
                longitude: Number.isFinite(payload.longitude) ? payload.longitude : currentLotAppointment?.longitude,
            };

            setLotAppointmentAddressMeta(currentLotAppointment);

            if (lotAppointmentEditGps) {
                lotAppointmentEditGps.textContent = lotAppointmentGpsLabel(currentLotAppointment);
            }

            renderLotAppointmentMap(currentLotAppointment);
            hideLotAppointmentAddressSuggestions();
        }

        function renderLotAppointmentAddressSuggestions(features) {
            if (!lotAppointmentAddressSuggestions) return;

            const suggestions = (features || [])
                .map(lotAppointmentSuggestionPayload)
                .filter((suggestion) => suggestion.address || suggestion.place_name)
                .slice(0, 6);

            if (suggestions.length === 0) {
                lotAppointmentAddressSuggestions.innerHTML = '<div class="px-4 py-3 text-sm" style="color:var(--gc-text-soft);">Aucune adresse trouvée.</div>';
                lotAppointmentAddressSuggestions.classList.remove('hidden');
                return;
            }

            lotAppointmentAddressSuggestions.innerHTML = suggestions.map((suggestion, index) => `
                <button
                    type="button"
                    class="block w-full px-4 py-3 text-left text-sm transition hover:bg-slate-50"
                    data-lot-appointment-address-suggestion="${index}"
                >
                    <span class="block font-semibold" style="color:var(--gc-text);">${escapeHtml(suggestion.label || suggestion.place_name)}</span>
                    ${suggestion.place_name && suggestion.place_name !== suggestion.label ? `<span class="block text-xs" style="color:var(--gc-text-soft);">${escapeHtml(suggestion.place_name)}</span>` : ''}
                </button>
            `).join('');

            lotAppointmentAddressSuggestions.querySelectorAll('[data-lot-appointment-address-suggestion]').forEach((button) => {
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => applyLotAppointmentAddressSuggestion(suggestions[Number(button.dataset.lotAppointmentAddressSuggestion)]));
            });

            lotAppointmentAddressSuggestions.classList.remove('hidden');
        }

        async function searchLotAppointmentAddressSuggestions(query) {
            if (!lotMapboxToken || !lotAppointmentAddressSuggestions) {
                setLotAppointmentAddressMeta({
                    address: query,
                    postal_code: null,
                    city: null,
                    department_code: null,
                });
                return;
            }

            lotAppointmentAddressAbortController?.abort();
            lotAppointmentAddressAbortController = new AbortController();

            const url = new URL(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json`);
            url.searchParams.set('access_token', lotMapboxToken);
            url.searchParams.set('country', 'fr');
            url.searchParams.set('language', 'fr');
            url.searchParams.set('types', 'address,postcode,place,locality');
            url.searchParams.set('limit', '6');

            try {
                const response = await fetch(url.toString(), {
                    signal: lotAppointmentAddressAbortController.signal,
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    hideLotAppointmentAddressSuggestions();
                    return;
                }

                const data = await response.json();
                renderLotAppointmentAddressSuggestions(data.features || []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    hideLotAppointmentAddressSuggestions();
                }
            }
        }

        function handleLotAppointmentAddressInput() {
            const value = lotAppointmentFullAddress?.value?.trim() || '';

            setLotAppointmentHiddenValue('address', value);
            setLotAppointmentHiddenValue('postal_code', '');
            setLotAppointmentHiddenValue('city', '');
            setLotAppointmentHiddenValue('department_code', '');
            setLotAppointmentAddressMeta({
                address: value,
                postal_code: null,
                city: null,
                department_code: null,
            });

            window.clearTimeout(lotAppointmentAddressTimer);

            if (value.length < 3) {
                hideLotAppointmentAddressSuggestions();
                return;
            }

            lotAppointmentAddressTimer = window.setTimeout(() => {
                void searchLotAppointmentAddressSuggestions(value);
            }, 280);
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
                    attributionControl: false,
                });

                lotAppointmentMap.addControl(new window.mapboxgl.NavigationControl({
                    showCompass: false,
                    visualizePitch: false,
                }), 'top-right');
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

            if (lotAppointmentFullAddress) {
                lotAppointmentFullAddress.value = lotAppointmentFullAddressLabel(appointment);
            }

            setLotAppointmentAddressMeta(appointment);
            hideLotAppointmentAddressSuggestions();

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
            lotAppointmentAddressAbortController?.abort();
            hideLotAppointmentAddressSuggestions();
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
            const businessLabel = lotAppointmentBusinessLabel(appointment);

            row.querySelector('[data-lot-appointment-department]').textContent = `Dept. ${appointment.department_code || '--'}`;
            row.querySelector('[data-lot-appointment-customer]').textContent = lotImportDisplayName(appointment);
            row.querySelector('[data-lot-appointment-phone]').textContent = appointment.customer_phone || 'Téléphone non renseigné';
            row.querySelector('[data-lot-appointment-address]').textContent = appointment.address || 'Adresse à qualifier';
            row.querySelector('[data-lot-appointment-reference]').textContent = lotAppointmentReference(appointment);

            const businessElement = row.querySelector('[data-lot-appointment-business]');
            businessElement.textContent = businessLabel;
            businessElement.classList.toggle('hidden', businessLabel === '');

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
                if (!forceGeocode) {
                    window.setTimeout(closeLotAppointmentEditModal, 450);
                }
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
        lotAppointmentFullAddress?.addEventListener('input', handleLotAppointmentAddressInput);
        lotAppointmentFullAddress?.addEventListener('blur', () => {
            window.setTimeout(hideLotAppointmentAddressSuggestions, 160);
        });
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
        lotImportReceivedAt?.addEventListener('change', updateLotImportState);
        lotImportDelegataire?.addEventListener('change', updateLotImportState);
        lotImportType?.addEventListener('change', updateLotImportState);
        lotImportServiceId?.addEventListener('change', updateLotImportState);
        lotSamplingPercentage?.addEventListener('input', updateLotImportState);
        lotPhysicalSamplingPercentage?.addEventListener('input', updateLotImportState);
        lotContactSamplingPercentage?.addEventListener('input', updateLotImportState);
        lotImportFormOpen?.addEventListener('click', openLotImportFormModal);
        lotImportFormClose?.addEventListener('click', closeLotImportFormModal);
        lotImportFormCancel?.addEventListener('click', closeLotImportFormModal);
        updateLotImportState();

        lotImportModalClose?.addEventListener('click', closeLotImportModal);

        lotImportSelectAll?.addEventListener('click', () => {
            lotImportVisibleCheckboxes().forEach((checkbox) => checkbox.checked = true);
            updateLotImportSelectionCount();
        });

        lotImportUnselectAll?.addEventListener('click', () => {
            lotImportVisibleCheckboxes().forEach((checkbox) => checkbox.checked = false);
            updateLotImportSelectionCount();
        });

        lotImportWarningsOnly?.addEventListener('change', () => {
            applyLotImportWarningFilter();
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
            if (lotImportWarningsOnly) {
                lotImportWarningsOnly.checked = false;
            }
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
            if (lotImportWarningsOnly) {
                lotImportWarningsOnly.checked = false;
            }
            closeLotImportFormModal();
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
            const selectedWarningRows = lotImportSelectedWarningCheckboxes()
                .map((checkbox) => checkbox.value)
                .filter(Boolean);

            if (!selectedRows.length || !currentLotImport?.confirm_url) {
                return;
            }

            if (selectedWarningRows.length > 0) {
                showLotImportError(`Corrige ou décoche les lignes avec warning avant validation : ${selectedWarningRows.join(', ')}.`, false);
                updateLotImportSelectionCount();
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
