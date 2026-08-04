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

            <div class="flex flex-wrap items-center gap-2">
                @if ($lot['can_download_original_file'])
                    <a href="{{ $lot['download_url'] }}" class="gc-btn-soft justify-center">
                        Télécharger le fichier source
                    </a>
                @endif
                <button id="lot-detail-edit-open" type="button" class="gc-btn-primary justify-center">
                    Modifier
                </button>
            </div>
        </div>

        @php
            $lotDelegataireNames = $delegataires->pluck('name');
        @endphp

        <div id="lot-detail-edit-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Lot</p>
                        <h2 class="text-xl font-semibold" style="color:var(--gc-text);">Modifier le lot {{ $lot['title'] }}</h2>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Ces informations pilotent les objectifs et le traitement des dossiers du lot.</p>
                    </div>
                    <button id="lot-detail-edit-close" type="button" class="gc-link">Fermer</button>
                </div>

                <form id="lot-detail-edit-form" method="POST" action="{{ $lot['update_url'] }}" class="space-y-4 overflow-y-auto p-5">
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="gc-label" for="lot_detail_edit_name">Nom du lot</label>
                            <input id="lot_detail_edit_name" name="name" type="text" value="{{ $lot['title'] }}" class="gc-input" maxlength="190" required>
                        </div>

                        <div class="md:col-span-2">
                            <label class="gc-label" for="lot_detail_edit_delegataire">Délégataire</label>
                            <select id="lot_detail_edit_delegataire" name="delegataire" class="gc-input">
                                <option value="">Sélectionner</option>
                                @foreach ($delegataires as $delegataire)
                                    <option value="{{ $delegataire->name }}" @selected($lot['delegataire'] === $delegataire->name)>
                                        {{ $delegataire->name }}
                                        @if ($delegataire->company_name)
                                            · {{ $delegataire->company_name }}
                                        @endif
                                    </option>
                                @endforeach
                                @if ($lot['delegataire'] && ! $lotDelegataireNames->contains($lot['delegataire']))
                                    <option value="{{ $lot['delegataire'] }}" selected>{{ $lot['delegataire'] }}</option>
                                @endif
                            </select>
                        </div>

                        <div>
                            <label class="gc-label" for="lot_detail_edit_received_at">Date de réception du lot</label>
                            <input id="lot_detail_edit_received_at" name="received_at" type="date" value="{{ $lot['received_at_input'] }}" class="gc-input">
                        </div>

                        <div>
                            <label class="gc-label" for="lot_detail_edit_type">Type de lot</label>
                            <select id="lot_detail_edit_type" name="type" class="gc-input" required>
                                @foreach ($lotTypes as $typeValue => $typeLabel)
                                    <option value="{{ $typeValue }}" @selected($lot['type'] === $typeValue)>{{ $typeLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="gc-label" for="lot_detail_edit_service_id">Prestation</label>
                            <select id="lot_detail_edit_service_id" name="service_id" class="gc-input" required>
                                <option value="">Sélectionner</option>
                                @foreach ($services as $service)
                                    <option value="{{ $service->id }}" @selected((int) $lot['service_id'] === (int) $service->id)>
                                        {{ $service->type }} - {{ $service->name }} ({{ $service->average_duration_minutes }} min)
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="gc-label" for="lot_detail_edit_status">Statut du lot</label>
                            <select id="lot_detail_edit_status" name="status" class="gc-input" required>
                                @foreach ($lotStatuses as $statusValue => $statusLabel)
                                    <option value="{{ $statusValue }}" @selected($lot['status'] === $statusValue)>{{ $statusLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div id="lot-detail-edit-single-sampling-wrap" class="hidden md:col-span-2">
                            <label class="gc-label" for="lot_detail_edit_sampling_percentage">% d'échantillonnage</label>
                            <input id="lot_detail_edit_sampling_percentage" name="sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ $lot['sampling_percentage'] }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Uniquement pour les lots échantillonnés">
                        </div>

                        <div id="lot-detail-edit-physical-sampling-wrap" class="hidden">
                            <label class="gc-label" for="lot_detail_edit_physical_sampling_percentage">% physique</label>
                            <input id="lot_detail_edit_physical_sampling_percentage" name="physical_sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ $lot['physical_sampling_percentage'] }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Hybride" disabled>
                        </div>

                        <div id="lot-detail-edit-contact-sampling-wrap" class="hidden">
                            <label class="gc-label" for="lot_detail_edit_contact_sampling_percentage">% contact</label>
                            <input id="lot_detail_edit_contact_sampling_percentage" name="contact_sampling_percentage" type="number" min="0.01" max="100" step="0.01" value="{{ $lot['contact_sampling_percentage'] }}" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" placeholder="Hybride" disabled>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t pt-4" style="border-color:var(--gc-border);">
                        <button id="lot-detail-edit-cancel" type="button" class="gc-btn-soft">Annuler</button>
                        <button type="submit" class="gc-btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="lot-appointment-targets-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b p-5" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Objectifs de RDV à prendre</p>
                        <h2 class="text-xl font-semibold" style="color:var(--gc-text);">Modifier les objectifs opérationnels</h2>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Ces valeurs modifient uniquement l’objectif de RDV à prendre. Elles ne changent pas les cibles de satisfaction du lot.</p>
                    </div>
                    <button id="lot-appointment-targets-close" type="button" class="gc-link">Fermer</button>
                </div>

                <form method="POST" action="{{ $lot['appointment_targets_update_url'] }}" class="space-y-4 overflow-y-auto p-5">
                    @csrf
                    @method('PATCH')

                    @if ($lot['supports_physical'])
                        <div class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                            <label class="gc-label" for="physical_appointment_target_count">Objectif RDV physiques</label>
                            <input
                                id="physical_appointment_target_count"
                                name="physical_appointment_target_count"
                                type="number"
                                min="0"
                                max="100000"
                                step="1"
                                value="{{ ($lot['appointment_targets']['physical']['is_manual'] ?? false) ? $lot['appointment_targets']['physical']['target_count'] : '' }}"
                                class="gc-input"
                                placeholder="{{ $lot['appointment_targets']['physical']['default_target_count'] ?? 0 }}"
                            >
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">
                                Laisser vide pour revenir à l’objectif calculé automatiquement : {{ $lot['appointment_targets']['physical']['default_target_count'] ?? 0 }}.
                            </p>
                        </div>
                    @endif

                    @if ($lot['supports_contact'])
                        <div class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                            <label class="gc-label" for="contact_appointment_target_count">Objectif contacts téléphoniques</label>
                            <input
                                id="contact_appointment_target_count"
                                name="contact_appointment_target_count"
                                type="number"
                                min="0"
                                max="100000"
                                step="1"
                                value="{{ ($lot['appointment_targets']['contact']['is_manual'] ?? false) ? $lot['appointment_targets']['contact']['target_count'] : '' }}"
                                class="gc-input"
                                placeholder="{{ $lot['appointment_targets']['contact']['default_target_count'] ?? 0 }}"
                            >
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">
                                Laisser vide pour revenir à l’objectif calculé automatiquement : {{ $lot['appointment_targets']['contact']['default_target_count'] ?? 0 }}.
                            </p>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 border-t pt-4" style="border-color:var(--gc-border);">
                        <button id="lot-appointment-targets-cancel" type="button" class="gc-btn-soft">Annuler</button>
                        <button type="submit" class="gc-btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>

        @php
            $totalSatisfaction = $lot['auto_completion']['total_satisfaction'] ?? [
                'percentage' => 0,
                'satisfied_count' => 0,
                'total_count' => 0,
            ];
            $dissatisfaction = $lot['auto_completion']['dissatisfaction'] ?? [
                'percentage' => 0,
                'dissatisfied_count' => 0,
                'processed_count' => 0,
            ];
            $dissatisfactionScope = match (true) {
                $lot['is_hybrid'] => 'sur RDV pris + appels',
                $lot['supports_contact'] && ! $lot['supports_physical'] => 'sur appels traités',
                $lot['supports_physical'] && ! $lot['supports_contact'] => 'sur RDV pris',
                default => 'sur dossiers traités',
            };
            $formatRate = fn ($value): string => number_format((float) $value, 2, ',', ' ');
            $physicalAppointmentTarget = $lot['appointment_targets']['physical'] ?? [
                'enabled' => false,
                'completed_count' => 0,
                'target_count' => 0,
                'percentage' => 0,
                'is_manual' => false,
            ];
            $contactAppointmentTarget = $lot['appointment_targets']['contact'] ?? [
                'enabled' => false,
                'completed_count' => 0,
                'target_count' => 0,
                'percentage' => 0,
                'is_manual' => false,
            ];
        @endphp

        <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Dossiers</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['processed_count'] }} / {{ $lot['appointments_count'] }}</p>
                <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">traités / total</p>
                @if (($lot['stats_excluded_count'] ?? 0) > 0)
                    <p class="mt-1 text-xs" style="color:#991b1b;">{{ $lot['stats_excluded_count'] }} hors statistiques</p>
                @endif
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Réception du lot</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $lot['received_at_formatted'] ?: '-' }}</p>
                <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">date de réception</p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">RDV physiques</p>
                    @if ($physicalAppointmentTarget['enabled'])
                        <button type="button" class="lot-appointment-targets-open inline-flex h-7 w-7 items-center justify-center rounded-full border text-sm font-semibold transition hover:shadow-sm" style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);" aria-label="Modifier l’objectif de RDV physiques">+</button>
                    @endif
                </div>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $physicalAppointmentTarget['completed_count'] }} / {{ $physicalAppointmentTarget['target_count'] }}</p>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full" style="width:{{ $physicalAppointmentTarget['percentage'] }}%;background:#15803d;"></div>
                </div>
                @if ($physicalAppointmentTarget['is_manual'])
                    <p class="mt-1 text-xs" style="color:#15803d;">objectif manuel</p>
                @endif
            </article>
            <article class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Contacts traités</p>
                    @if ($contactAppointmentTarget['enabled'])
                        <button type="button" class="lot-appointment-targets-open inline-flex h-7 w-7 items-center justify-center rounded-full border text-sm font-semibold transition hover:shadow-sm" style="border-color:var(--gc-border);background:#ffffff;color:var(--gc-text);" aria-label="Modifier l’objectif de contacts">+</button>
                    @endif
                </div>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $contactAppointmentTarget['completed_count'] }} / {{ $contactAppointmentTarget['target_count'] }}</p>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full" style="width:{{ $contactAppointmentTarget['percentage'] }}%;background:#0369a1;"></div>
                </div>
                @if ($contactAppointmentTarget['is_manual'])
                    <p class="mt-1 text-xs" style="color:#0369a1;">objectif manuel</p>
                @endif
            </article>
            <article class="rounded-2xl border p-4" style="border-color:#bbf7d0;background:#f0fdf4;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:#166534;">Taux de satisfaction total</p>
                <p class="mt-2 text-2xl font-semibold" style="color:#166534;">{{ $formatRate($totalSatisfaction['percentage']) }}%</p>
                <p class="mt-1 text-xs" style="color:#14532d;">
                    {{ $totalSatisfaction['satisfied_count'] }} / {{ $totalSatisfaction['total_count'] }} dossiers du lot
                </p>
            </article>
            <article class="rounded-2xl border p-4" style="border-color:#fecaca;background:#fff7f7;">
                <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:#991b1b;">Taux d’insatisfaction</p>
                <p class="mt-2 text-2xl font-semibold" style="color:#991b1b;">{{ $formatRate($dissatisfaction['percentage']) }}%</p>
                <p class="mt-1 text-xs" style="color:#7f1d1d;">
                    {{ $dissatisfaction['dissatisfied_count'] }} / {{ $dissatisfaction['processed_count'] }} {{ $dissatisfactionScope }}
                </p>
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
                    $targetCount = max(0, (int) ($chart['target_count'] ?? 0));
                    $satisfiedCount = max(0, (int) ($chart['satisfied_count'] ?? 0));
                    $unsatisfiedCount = max(0, (int) ($chart['unsatisfied_count'] ?? 0));
                    $satisfactionAnsweredCount = min($targetCount, $satisfiedCount + $unsatisfiedCount);
                    $satisfactionPercentage = (int) ($chart['satisfaction_percentage'] ?? ($targetCount > 0 ? min(100, round(($satisfactionAnsweredCount / $targetCount) * 100)) : 0));
                    $satisfiedShare = $targetCount > 0 ? min(100, round(($satisfiedCount / $targetCount) * 100, 2)) : 0;
                    $answeredShare = $targetCount > 0 ? min(100, round(($satisfactionAnsweredCount / $targetCount) * 100, 2)) : 0;
                @endphp
                <article class="gc-card p-5">
                    <div class="flex flex-col gap-5 md:flex-row md:items-center">
                        <div
                            class="lot-chart-ring shrink-0"
                            style="--satisfied:{{ $satisfiedShare }};--answered:{{ $answeredShare }};"
                            aria-label="{{ $satisfiedCount }} satisfaisant(s), {{ $unsatisfiedCount }} non satisfaisant(s)"
                        >
                            <span>{{ $satisfactionPercentage }}%</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">{{ $chart['label'] ?? 'Lot' }}</p>
                            <h2 class="mt-1 text-lg font-semibold" style="color:var(--gc-text);">{{ $chart['detail'] ?? 'Suivi du lot' }}</h2>
                            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                                {{ $satisfactionAnsweredCount }} réponse(s) de satisfaction
                                @if (! empty($chart['is_sampling']))
                                    sur un objectif de {{ $targetCount }}.
                                @else
                                    sur {{ $chart['total_count'] ?? $lot['appointments_count'] }}.
                                @endif
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1" style="background:#dcfce7;color:#166534;">
                                    <span class="h-2 w-2 rounded-full" style="background:#16a34a;"></span>
                                    {{ $satisfiedCount }} satisfaisant(s)
                                </span>
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1" style="background:#fee2e2;color:#991b1b;">
                                    <span class="h-2 w-2 rounded-full" style="background:#dc2626;"></span>
                                    {{ $unsatisfiedCount }} non satisfaisant(s)
                                </span>
                                @if (($chart['satisfaction_remaining_count'] ?? 0) > 0)
                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1" style="background:#f1f5f9;color:#475569;">
                                        <span class="h-2 w-2 rounded-full" style="background:#cbd5e1;"></span>
                                        {{ $chart['satisfaction_remaining_count'] }} en attente
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="gc-card overflow-hidden">
            <div class="border-b p-5" style="border-color:var(--gc-border);">
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Suivi du lot</p>
                        <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Dossiers du fichier</h2>
                    </div>
                    <p class="text-sm" style="color:var(--gc-text-soft);">
                        {{ $appointments->total() }} dossier(s) affiché(s)
                        @if ($appointments->total() !== $lot['appointments_count'])
                            sur {{ $lot['appointments_count'] }}
                        @endif
                    </p>
                </div>
            </div>

            <form id="manager-lot-appointment-filters-form" method="GET" action="{{ route('manager.lots.show', $lot['id']) }}" class="border-b p-5" style="border-color:var(--gc-border);background:#fbfaf6;">
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_200px_220px_220px_auto] xl:items-end">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Recherche</span>
                        <input
                            id="appointment_q"
                            name="appointment_q"
                            type="search"
                            value="{{ $appointmentFilters['appointment_q'] }}"
                            class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                            placeholder="Client, site, téléphone, adresse, référence..."
                        >
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Statut</span>
                        <select
                            name="appointment_status"
                            class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous les statuts</option>
                            @foreach ($lotAppointmentStatuses as $status => $label)
                                <option value="{{ $status }}" @selected($appointmentFilters['appointment_status'] === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Traitement</span>
                        <select
                            name="appointment_processing"
                            class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous les traitements</option>
                            @foreach ($lotAppointmentProcessingFilters as $processing => $label)
                                <option value="{{ $processing }}" @selected($appointmentFilters['appointment_processing'] === $processing)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Résultat</span>
                        <select
                            name="appointment_satisfaction"
                            class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous les résultats</option>
                            @foreach ($lotAppointmentSatisfactionFilters as $satisfaction => $label)
                                <option value="{{ $satisfaction }}" @selected($appointmentFilters['appointment_satisfaction'] === $satisfaction)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <a href="{{ route('manager.lots.show', $lot['id']) }}" class="gc-btn-soft justify-center">
                        Réinitialiser
                    </a>
                </div>
                <p class="mt-3 text-xs" style="color:var(--gc-text-soft);">Les filtres se mettent à jour automatiquement.</p>
            </form>

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
                                    Aucun dossier ne correspond aux filtres.
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
                            <tr
                                @class(['lot-appointment-row transition hover:bg-[color:var(--gc-accent-soft)]' => true, 'opacity-60' => $appointment['excluded_from_lot_stats']])
                                data-lot-appointment-row
                                data-lot-appointment-id="{{ $appointment['id'] }}"
                            >
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
                                    <span
                                        @class([
                                            'mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold' => true,
                                            'hidden' => ! $appointment['added_to_global_plus'],
                                        ])
                                        style="background:#fef3c7;color:#b45309;"
                                        data-lot-appointment-global-plus-badge="{{ $appointment['id'] }}"
                                    >
                                        Global +
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
                                    @if ($appointment['can_update_visits'])
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

            @if ($appointments->hasPages())
                <div class="border-t px-5 py-4" style="border-color:var(--gc-border);">
                    {{ $appointments->links() }}
                </div>
            @endif
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
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3" style="border-color:var(--gc-border);background:#ffffff;">
                            <input id="lot-physical-global-plus" type="checkbox" class="gc-check mt-1" />
                            <span>
                                <span class="block text-sm font-semibold" style="color:var(--gc-text);">Ajouter au Global +</span>
                                <span class="block text-xs" style="color:var(--gc-text-soft);">Option portée par ce dossier uniquement.</span>
                            </span>
                        </label>
                        <p id="lot-physical-global-plus-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                    </div>

                    <div class="mt-4 border-t pt-4" style="border-color:var(--gc-border);">
                        <h3 class="font-semibold" style="color:var(--gc-text);">Statistiques du lot</h3>
                        <p id="lot-physical-stats-exclusion-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                        <button id="lot-physical-stats-exclusion-toggle" type="button" class="gc-btn-soft mt-3 w-full justify-center">
                            Sortir des stats du lot
                        </button>
                    </div>

                    <div class="mt-4 border-t pt-4" style="border-color:var(--gc-border);">
                        <h3 class="font-semibold" style="color:var(--gc-text);">Remise à traiter</h3>
                        <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                            Remet le dossier en statut « n'a pas placé » et supprime son état de traitement.
                        </p>
                        <p id="lot-physical-reset-processing-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                        <button id="lot-physical-reset-processing" type="button" class="gc-btn-danger mt-3 w-full justify-center disabled:cursor-not-allowed disabled:opacity-50">
                            Remettre en non placé
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
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3" style="border-color:var(--gc-border);background:#ffffff;">
                        <input id="lot-contact-global-plus" type="checkbox" class="gc-check mt-1" />
                        <span>
                            <span class="block text-sm font-semibold" style="color:var(--gc-text);">Ajouter au Global +</span>
                            <span class="block text-xs" style="color:var(--gc-text-soft);">Option portée par ce dossier uniquement.</span>
                        </span>
                    </label>
                    <p id="lot-contact-global-plus-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                </section>

                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                    <h3 class="font-semibold" style="color:var(--gc-text);">Statistiques du lot</h3>
                    <p id="lot-contact-stats-exclusion-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                    <button id="lot-contact-stats-exclusion-toggle" type="button" class="gc-btn-soft mt-3 justify-center">
                        Sortir des stats du lot
                    </button>
                </section>

                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                    <h3 class="font-semibold" style="color:var(--gc-text);">Remise à traiter</h3>
                    <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                        Remet le dossier en statut « n'a pas placé » et supprime son état de satisfaction.
                    </p>
                    <p id="lot-contact-reset-processing-status" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                    <button id="lot-contact-reset-processing" type="button" class="gc-btn-danger mt-3 justify-center disabled:cursor-not-allowed disabled:opacity-50">
                        Remettre en non placé
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
        const lotAppointmentFiltersForm = document.getElementById('manager-lot-appointment-filters-form');
        const lotAppointmentSearchInput = document.getElementById('appointment_q');
        let lotAppointmentSearchTimer = null;
        const lotDetailEditOpen = document.getElementById('lot-detail-edit-open');
        const lotDetailEditModal = document.getElementById('lot-detail-edit-modal');
        const lotDetailEditClose = document.getElementById('lot-detail-edit-close');
        const lotDetailEditCancel = document.getElementById('lot-detail-edit-cancel');
        const lotDetailEditType = document.getElementById('lot_detail_edit_type');
        const lotDetailEditSamplingPercentage = document.getElementById('lot_detail_edit_sampling_percentage');
        const lotDetailEditPhysicalSamplingPercentage = document.getElementById('lot_detail_edit_physical_sampling_percentage');
        const lotDetailEditContactSamplingPercentage = document.getElementById('lot_detail_edit_contact_sampling_percentage');
        const lotDetailEditSingleSamplingWrap = document.getElementById('lot-detail-edit-single-sampling-wrap');
        const lotDetailEditPhysicalSamplingWrap = document.getElementById('lot-detail-edit-physical-sampling-wrap');
        const lotDetailEditContactSamplingWrap = document.getElementById('lot-detail-edit-contact-sampling-wrap');
        const lotDetailSamplingTypes = @json(\App\Models\Lot::samplingTypes());
        const lotDetailHybridType = @json(\App\Models\Lot::TYPE_HYBRID_LOCATION_CONTACT);
        const lotAppointmentTargetsModal = document.getElementById('lot-appointment-targets-modal');
        const lotAppointmentTargetsClose = document.getElementById('lot-appointment-targets-close');
        const lotAppointmentTargetsCancel = document.getElementById('lot-appointment-targets-cancel');

        if (lotAppointmentFiltersForm) {
            lotAppointmentFiltersForm.querySelectorAll('select').forEach((select) => {
                select.addEventListener('change', () => lotAppointmentFiltersForm.submit());
            });

            lotAppointmentSearchInput?.addEventListener('input', () => {
                window.clearTimeout(lotAppointmentSearchTimer);
                lotAppointmentSearchTimer = window.setTimeout(() => lotAppointmentFiltersForm.submit(), 450);
            });
        }

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

        lotDetailEditOpen?.addEventListener('click', () => {
            updateLotDetailEditSamplingState();
            openModal(lotDetailEditModal);
        });
        lotDetailEditClose?.addEventListener('click', () => closeModal(lotDetailEditModal));
        lotDetailEditCancel?.addEventListener('click', () => closeModal(lotDetailEditModal));
        lotDetailEditModal?.addEventListener('click', (event) => {
            if (event.target === lotDetailEditModal) {
                closeModal(lotDetailEditModal);
            }
        });
        lotDetailEditType?.addEventListener('change', updateLotDetailEditSamplingState);
        updateLotDetailEditSamplingState();
        document.querySelectorAll('.lot-appointment-targets-open').forEach((button) => {
            button.addEventListener('click', () => openModal(lotAppointmentTargetsModal));
        });
        lotAppointmentTargetsClose?.addEventListener('click', () => closeModal(lotAppointmentTargetsModal));
        lotAppointmentTargetsCancel?.addEventListener('click', () => closeModal(lotAppointmentTargetsModal));
        lotAppointmentTargetsModal?.addEventListener('click', (event) => {
            if (event.target === lotAppointmentTargetsModal) {
                closeModal(lotAppointmentTargetsModal);
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
        const physicalResetProcessingStatus = document.getElementById('lot-physical-reset-processing-status');
        const physicalResetProcessingButton = document.getElementById('lot-physical-reset-processing');
        const physicalGlobalPlusCheckbox = document.getElementById('lot-physical-global-plus');
        const physicalGlobalPlusStatus = document.getElementById('lot-physical-global-plus-status');

        const contactModal = document.getElementById('lot-contact-detail-modal');
        const contactClose = document.getElementById('lot-contact-detail-close');
        const contactTitle = document.getElementById('lot-contact-detail-title');
        const contactSubtitle = document.getElementById('lot-contact-detail-subtitle');
        const contactInfos = document.getElementById('lot-contact-detail-infos');
        const contactComment = document.getElementById('lot-contact-detail-comment');
        const contactStatsExclusionStatus = document.getElementById('lot-contact-stats-exclusion-status');
        const contactStatsExclusionToggle = document.getElementById('lot-contact-stats-exclusion-toggle');
        const contactResetProcessingStatus = document.getElementById('lot-contact-reset-processing-status');
        const contactResetProcessingButton = document.getElementById('lot-contact-reset-processing');
        const contactGlobalPlusCheckbox = document.getElementById('lot-contact-global-plus');
        const contactGlobalPlusStatus = document.getElementById('lot-contact-global-plus-status');
        let lotAppointmentTooltip = null;

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

        function limitTooltipText(value, maxLength = 260) {
            const text = String(value || '').replace(/\s+/g, ' ').trim();

            return text.length > maxLength ? `${text.slice(0, maxLength - 1).trim()}…` : text;
        }

        function lotAppointmentCommentItems(appointment) {
            if (!appointment) return [];

            const items = [];
            const mainComment = limitTooltipText(appointment.comment || '');

            if (mainComment) {
                items.push({
                    title: 'Commentaire du dossier',
                    text: mainComment,
                });
            }

            const contactComment = limitTooltipText(appointment.contact_comment || '');

            if (contactComment && contactComment !== mainComment) {
                items.push({
                    title: 'Commentaire contact',
                    text: contactComment,
                });
            }

            return items;
        }

        function ensureLotAppointmentTooltip() {
            if (lotAppointmentTooltip) {
                return lotAppointmentTooltip;
            }

            lotAppointmentTooltip = document.createElement('div');
            lotAppointmentTooltip.style.cssText = [
                'position:fixed',
                'z-index:95',
                'display:none',
                'pointer-events:none',
                'max-width:420px',
                'border-radius:16px',
                'padding:12px 14px',
                'background:#31424c',
                'color:#ffffff',
                'box-shadow:0 18px 45px rgba(15,23,42,.28)',
                'font-size:12px',
                'line-height:1.45',
                'transform:translate(-50%, calc(-100% - 14px))',
            ].join(';');
            document.body.appendChild(lotAppointmentTooltip);

            return lotAppointmentTooltip;
        }

        function moveLotAppointmentTooltip(event) {
            const tooltip = ensureLotAppointmentTooltip();
            const safeLeft = Math.min(Math.max(event.clientX, 210), window.innerWidth - 210);
            const safeTop = Math.max(event.clientY, 130);

            tooltip.style.left = `${safeLeft}px`;
            tooltip.style.top = `${safeTop}px`;
        }

        function showLotAppointmentTooltip(event, appointment) {
            const comments = lotAppointmentCommentItems(appointment);

            if (comments.length === 0) {
                hideLotAppointmentTooltip();
                return;
            }

            const tooltip = ensureLotAppointmentTooltip();
            const commentsHtml = comments.map((comment, index) => `
                <div style="${index === 0 ? 'margin-top:10px;' : 'margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:10px;'}">
                    <p style="font-weight:700;">${escapeHtml(comment.title)}</p>
                    <p style="margin-top:5px;color:rgba(255,255,255,.9);">${escapeHtml(comment.text)}</p>
                </div>
            `).join('');

            tooltip.innerHTML = `
                <div>
                    <p style="font-weight:800;letter-spacing:.02em;">Commentaires du dossier</p>
                    ${commentsHtml}
                </div>
            `;
            tooltip.style.display = 'block';
            moveLotAppointmentTooltip(event);
        }

        function hideLotAppointmentTooltip() {
            if (lotAppointmentTooltip) {
                lotAppointmentTooltip.style.display = 'none';
            }
        }

        function infoGrid(items) {
            return items.map(([label, value]) => `
                <div>
                    <dt style="color:var(--gc-text-soft);">${escapeHtml(label)}</dt>
                    <dd class="mt-1 font-medium" style="color:var(--gc-text);">${escapeHtml(displayValue(value))}</dd>
                </div>
            `).join('');
        }

        function isLotDetailSamplingType(type) {
            return lotDetailSamplingTypes.includes(type);
        }

        function isLotDetailHybridType(type) {
            return type === lotDetailHybridType;
        }

        function updateLotDetailEditSamplingState() {
            if (!lotDetailEditType || !lotDetailEditSamplingPercentage) {
                return;
            }

            const currentType = lotDetailEditType.value;
            const needsSampling = isLotDetailSamplingType(currentType);
            const needsHybridSampling = isLotDetailHybridType(currentType);
            const needsSingleSampling = needsSampling && !needsHybridSampling;

            lotDetailEditSingleSamplingWrap?.classList.toggle('hidden', !needsSingleSampling);
            lotDetailEditPhysicalSamplingWrap?.classList.toggle('hidden', !needsHybridSampling);
            lotDetailEditContactSamplingWrap?.classList.toggle('hidden', !needsHybridSampling);

            lotDetailEditSamplingPercentage.disabled = !needsSingleSampling;
            lotDetailEditSamplingPercentage.required = needsSingleSampling;

            if (lotDetailEditPhysicalSamplingPercentage) {
                lotDetailEditPhysicalSamplingPercentage.disabled = !needsHybridSampling;
                lotDetailEditPhysicalSamplingPercentage.required = needsHybridSampling;
            }

            if (lotDetailEditContactSamplingPercentage) {
                lotDetailEditContactSamplingPercentage.disabled = !needsHybridSampling;
                lotDetailEditContactSamplingPercentage.required = needsHybridSampling;
            }

            if (!needsSingleSampling) {
                lotDetailEditSamplingPercentage.value = '';
            }

            if (!needsHybridSampling) {
                if (lotDetailEditPhysicalSamplingPercentage) lotDetailEditPhysicalSamplingPercentage.value = '';
                if (lotDetailEditContactSamplingPercentage) lotDetailEditContactSamplingPercentage.value = '';
            }
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

        function configureGlobalPlusControls(appointment, checkboxElement, statusElement) {
            if (!checkboxElement || !statusElement) {
                return;
            }

            const addedToGlobalPlus = Boolean(appointment?.added_to_global_plus);

            checkboxElement.checked = addedToGlobalPlus;
            checkboxElement.disabled = !appointment?.global_plus_update_url;
            statusElement.textContent = addedToGlobalPlus
                ? 'Ce dossier est ajouté au Global +.'
                : 'Ce dossier n’est pas ajouté au Global +.';
            statusElement.style.color = addedToGlobalPlus ? '#b45309' : 'var(--gc-text-soft)';
        }

        function updateGlobalPlusBadge(appointment) {
            document.querySelectorAll(`[data-lot-appointment-global-plus-badge="${appointment.id}"]`).forEach((badge) => {
                badge.classList.toggle('hidden', !appointment.added_to_global_plus);
            });
        }

        async function updateLotAppointmentGlobalPlus(appointment, checkboxElement, statusElement) {
            if (!appointment?.global_plus_update_url || !checkboxElement || !statusElement) {
                return;
            }

            const requestedState = checkboxElement.checked;
            checkboxElement.disabled = true;
            statusElement.textContent = 'Mise à jour du Global +...';
            statusElement.style.color = 'var(--gc-text-soft)';

            try {
                const response = await fetch(appointment.global_plus_update_url, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': lotDetailCsrfToken,
                    },
                    body: JSON.stringify({
                        added_to_global_plus: requestedState,
                    }),
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Mise à jour impossible.');
                }

                const updatedAppointment = payload.appointment;
                lotAppointmentDetails.set(String(updatedAppointment.id), updatedAppointment);

                if (currentPhysicalLotAppointment?.id === updatedAppointment.id) {
                    currentPhysicalLotAppointment = updatedAppointment;
                }

                if (currentContactLotAppointment?.id === updatedAppointment.id) {
                    currentContactLotAppointment = updatedAppointment;
                }

                checkboxElement.checked = Boolean(updatedAppointment.added_to_global_plus);
                checkboxElement.disabled = !updatedAppointment.global_plus_update_url;
                statusElement.textContent = payload.message || 'Global + mis à jour.';
                statusElement.style.color = '#15803d';
                updateGlobalPlusBadge(updatedAppointment);
            } catch (error) {
                checkboxElement.checked = !requestedState;
                checkboxElement.disabled = false;
                statusElement.textContent = error.message || 'Mise à jour impossible.';
                statusElement.style.color = '#be123c';
            }
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

        function configureResetProcessingControls(appointment, statusElement, buttonElement) {
            if (!statusElement || !buttonElement) {
                return;
            }

            const canReset = Boolean(appointment?.can_reset_processing && appointment?.reset_processing_url);

            statusElement.textContent = canReset
                ? 'Le dossier peut être remis dans le flux de traitement du lot.'
                : 'Aucun traitement à annuler pour ce dossier.';
            statusElement.style.color = canReset ? 'var(--gc-text-soft)' : '#64748b';
            buttonElement.disabled = !canReset;
            buttonElement.textContent = 'Remettre en non placé';
        }

        async function resetLotAppointmentProcessing(appointment, statusElement, buttonElement) {
            if (!appointment?.reset_processing_url || !buttonElement || buttonElement.disabled) {
                return;
            }

            buttonElement.disabled = true;
            buttonElement.textContent = 'Remise à traiter...';
            statusElement.textContent = 'Suppression de l’état de traitement et recalcul des statistiques...';
            statusElement.style.color = 'var(--gc-text-soft)';

            try {
                const response = await fetch(appointment.reset_processing_url, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': lotDetailCsrfToken,
                    },
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Remise à traiter impossible.');
                }

                statusElement.textContent = `${payload.message || 'Dossier remis à traiter.'} Rechargement des statistiques...`;
                statusElement.style.color = '#15803d';
                window.setTimeout(() => window.location.reload(), 650);
            } catch (error) {
                statusElement.textContent = error.message || 'Remise à traiter impossible.';
                statusElement.style.color = '#be123c';
                buttonElement.disabled = false;
                buttonElement.textContent = 'Remettre en non placé';
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
            physicalVisitsInput.disabled = !appointment.can_update_visits;
            physicalVisitsSubmit.disabled = !appointment.can_update_visits;
            physicalVisitsStatus.classList.add('hidden');

            if (appointment.tracking_url) {
                physicalTrackingLink.href = appointment.tracking_url;
                physicalTrackingWrap.classList.remove('hidden');
            } else {
                physicalTrackingWrap.classList.add('hidden');
            }

            configureStatsExclusionControls(appointment, physicalStatsExclusionStatus, physicalStatsExclusionToggle);
            configureGlobalPlusControls(appointment, physicalGlobalPlusCheckbox, physicalGlobalPlusStatus);
            configureResetProcessingControls(appointment, physicalResetProcessingStatus, physicalResetProcessingButton);
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
            configureGlobalPlusControls(appointment, contactGlobalPlusCheckbox, contactGlobalPlusStatus);
            configureResetProcessingControls(appointment, contactResetProcessingStatus, contactResetProcessingButton);
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

        document.querySelectorAll('[data-lot-appointment-row]').forEach((row) => {
            row.addEventListener('mouseenter', (event) => {
                const appointment = lotAppointmentDetails.get(String(row.dataset.lotAppointmentId));

                showLotAppointmentTooltip(event, appointment);
            });
            row.addEventListener('mousemove', moveLotAppointmentTooltip);
            row.addEventListener('mouseleave', hideLotAppointmentTooltip);
        });

        physicalClose?.addEventListener('click', () => closeModal(physicalModal));
        contactClose?.addEventListener('click', () => closeModal(contactModal));
        physicalStatsExclusionToggle?.addEventListener('click', () => {
            toggleStatsExclusion(currentPhysicalLotAppointment, physicalStatsExclusionStatus, physicalStatsExclusionToggle);
        });
        contactStatsExclusionToggle?.addEventListener('click', () => {
            toggleStatsExclusion(currentContactLotAppointment, contactStatsExclusionStatus, contactStatsExclusionToggle);
        });
        physicalResetProcessingButton?.addEventListener('click', () => {
            resetLotAppointmentProcessing(currentPhysicalLotAppointment, physicalResetProcessingStatus, physicalResetProcessingButton);
        });
        contactResetProcessingButton?.addEventListener('click', () => {
            resetLotAppointmentProcessing(currentContactLotAppointment, contactResetProcessingStatus, contactResetProcessingButton);
        });
        physicalGlobalPlusCheckbox?.addEventListener('change', () => {
            updateLotAppointmentGlobalPlus(currentPhysicalLotAppointment, physicalGlobalPlusCheckbox, physicalGlobalPlusStatus);
        });
        contactGlobalPlusCheckbox?.addEventListener('change', () => {
            updateLotAppointmentGlobalPlus(currentContactLotAppointment, contactGlobalPlusCheckbox, contactGlobalPlusStatus);
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
                physicalVisitsSubmit.disabled = !currentPhysicalLotAppointment?.can_update_visits;
                physicalVisitsSubmit.textContent = 'Enregistrer les portes';
            }
        });
    </script>

    <style>
        .lot-chart-ring {
            align-items: center;
            background:
                radial-gradient(circle at center, #ffffff 0 58%, transparent 59%),
                conic-gradient(
                    #16a34a 0 calc(var(--satisfied) * 1%),
                    #dc2626 calc(var(--satisfied) * 1%) calc(var(--answered) * 1%),
                    #e2e8f0 calc(var(--answered) * 1%) 100%
                );
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
