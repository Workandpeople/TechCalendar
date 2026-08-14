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
                @if ($lot['comment'])
                    <p class="mt-3 max-w-4xl whitespace-pre-line rounded-2xl border px-4 py-3 text-sm" style="border-color:var(--gc-border);background:#fbfaf6;color:var(--gc-text);">
                        {{ $lot['comment'] }}
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($lot['can_download_original_file'])
                    <a href="{{ $lot['download_url'] }}" class="gc-btn-soft inline-flex h-[42px] items-center justify-center px-4">
                        Télécharger le fichier source
                    </a>
                @endif
                <button id="lot-detail-documents-open" type="button" class="gc-btn-soft inline-flex h-[42px] items-center justify-center px-4">
                    Gérer les documents
                </button>
                <button id="lot-detail-edit-open" type="button" class="gc-btn-primary inline-flex h-[42px] items-center justify-center px-4">
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

                        <div class="md:col-span-2">
                            <label class="gc-label" for="lot_detail_edit_comment">Commentaires du lot</label>
                            <textarea id="lot_detail_edit_comment" name="comment" class="gc-input min-h-28" maxlength="5000" placeholder="Note interne, consignes de traitement, contexte client...">{{ $lot['comment'] }}</textarea>
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
                            <label class="gc-label" for="lot_detail_edit_coffrac_service_alias_id">Alias Coffrac du lot</label>
                            <select id="lot_detail_edit_coffrac_service_alias_id" name="coffrac_service_alias_id" class="gc-input disabled:cursor-not-allowed disabled:opacity-50" disabled>
                                <option value="">Sélectionner une prestation</option>
                            </select>
                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Alias envoyé à Coffrac pour les RDV physiques issus de ce lot.</p>
                        </div>

                        <div>
                            <span class="gc-label">Statut du lot</span>
                            <div class="rounded-xl border px-4 py-3 text-sm font-semibold" style="border-color:var(--gc-border);background:#fbfaf6;color:var(--gc-text);">
                                {{ $lot['status_label'] }}
                            </div>
                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Statut recalculé automatiquement selon les objectifs de satisfaction.</p>
                        </div>

                        @if ($lot['can_archive'])
                            <div class="rounded-xl border p-4 md:col-span-2" style="border-color:#bbf7d0;background:#f0fdf4;">
                                <label class="flex items-start gap-3 text-sm font-semibold" style="color:#166534;">
                                    <input name="archive_lot" type="checkbox" value="1" class="gc-check mt-1">
                                    <span>
                                        Passer le lot en « Complet archivé »
                                        <span class="mt-1 block text-xs font-normal" style="color:#15803d;">Disponible uniquement lorsque le lot est à facturer.</span>
                                    </span>
                                </label>
                            </div>
                        @endif

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

        <div id="lot-documents-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
            <div class="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex flex-col gap-4 border-b p-5 lg:flex-row lg:items-start lg:justify-between" style="border-color:var(--gc-border);">
                    <div>
                        <p class="text-sm" style="color:var(--gc-text-soft);">Documents du lot</p>
                        <h2 class="text-xl font-semibold" style="color:var(--gc-text);">Gérer les documents dossier par dossier</h2>
                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Ajoute les pièces sur les dossiers du lot. Elles seront envoyées à Coffrac automatiquement quand le RDV physique sera créé.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span id="lot-documents-counter" class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">0 / 0</span>
                        <button id="lot-documents-close" type="button" class="gc-link">Fermer</button>
                    </div>
                </div>

                <div class="grid min-h-0 flex-1 grid-cols-1 gap-5 overflow-y-auto p-5 lg:grid-cols-[280px_minmax(0,1fr)]">
                    <aside class="space-y-4 rounded-2xl border p-4" style="border-color:var(--gc-border);background:#fbfaf6;">
                        <div>
                            <label class="gc-label" for="lot_documents_search">Rechercher un dossier</label>
                            <input id="lot_documents_search" type="search" class="gc-input" placeholder="Client, site, adresse, référence...">
                        </div>
                        <p id="lot-documents-status" class="text-sm" style="color:var(--gc-text-soft);">Ouvre la modale pour charger les dossiers.</p>
                        <div class="flex items-center gap-2">
                            <button id="lot-documents-prev" type="button" class="gc-btn-soft flex-1 justify-center" disabled>Précédent</button>
                            <button id="lot-documents-next" type="button" class="gc-btn-primary flex-1 justify-center" disabled>Suivant</button>
                        </div>
                    </aside>

                    <section class="min-h-[520px] rounded-3xl border p-5 shadow-sm" style="border-color:var(--gc-border);background:linear-gradient(145deg,#ffffff,#fbfaf6);">
                        <div id="lot-documents-empty" class="flex h-full min-h-[420px] items-center justify-center text-center">
                            <div>
                                <p class="text-lg font-semibold" style="color:var(--gc-text);">Aucun dossier chargé</p>
                                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Utilise la recherche ou recharge la modale.</p>
                            </div>
                        </div>

                        <div id="lot-documents-card" class="hidden space-y-5">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <p id="lot-documents-card-reference" class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);"></p>
                                    <h3 id="lot-documents-card-title" class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);"></h3>
                                    <p id="lot-documents-card-subtitle" class="mt-2 text-sm" style="color:var(--gc-text-soft);"></p>
                                </div>
                                <span id="lot-documents-card-status" class="w-fit rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);"></span>
                            </div>

                            <dl id="lot-documents-card-infos" class="grid grid-cols-1 gap-3 rounded-2xl border p-4 text-sm md:grid-cols-2" style="border-color:var(--gc-border);background:#ffffff;"></dl>

                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
                                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                                    <div class="flex items-center justify-between gap-3">
                                        <h4 class="font-semibold" style="color:var(--gc-text);">Documents du dossier</h4>
                                        <span id="lot-documents-card-doc-count" class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">0</span>
                                    </div>
                                    <div id="lot-documents-card-list" class="mt-3 space-y-2"></div>
                                </section>

                                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                                    <h4 class="font-semibold" style="color:var(--gc-text);">Ajouter des documents</h4>
                                    <div id="lot-documents-dropzone" class="mt-3 rounded-2xl border border-dashed p-5 text-center transition" style="border-color:var(--gc-border);background:#fbfaf6;">
                                        <p class="font-semibold" style="color:var(--gc-text);">Dépose les fichiers ici</p>
                                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">ou clique pour sélectionner des fichiers</p>
                                        <input id="lot_documents_file_input" type="file" class="hidden" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                                    </div>
                                    <form id="lot-documents-upload-form" class="mt-4 hidden space-y-3">
                                        <div id="lot-documents-upload-list" class="space-y-2"></div>
                                        <button id="lot-documents-upload-submit" type="submit" class="gc-btn-primary w-full justify-center">Ajouter au dossier</button>
                                    </form>
                                    <p id="lot-documents-upload-status" class="mt-3 hidden text-sm"></p>
                                </section>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        @php
            $formatRate = fn ($value): string => number_format((float) $value, 2, ',', ' ');
            $satisfactionCharts = collect($lot['satisfaction_charts'] ?? [])->values();
            $dissatisfactionCharts = collect($lot['dissatisfaction_charts'] ?? [])->values();
            $summaryCharts = $satisfactionCharts->concat($dissatisfactionCharts)->values();
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

        <section class="grid grid-cols-1 gap-3 md:grid-cols-2 {{ $lot['is_hybrid'] ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }}">
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
        @if ($lot['is_hybrid'])
        </section>

        <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        @endif
            @foreach ($summaryCharts as $chart)
                @php
                    $isDissatisfactionChart = str_contains((string) ($chart['key'] ?? ''), 'dissatisfaction');
                @endphp
                <article
                    class="rounded-2xl border p-4"
                    style="{{ $isDissatisfactionChart ? 'border-color:#fecaca;background:#fff1f2;' : 'border-color:#bbf7d0;background:#f0fdf4;' }}"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:{{ $isDissatisfactionChart ? '#991b1b' : '#166534' }};">{{ $chart['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold" style="color:{{ $isDissatisfactionChart ? '#991b1b' : '#166534' }};">{{ $chart['display'] }}</p>
                    @if ($isDissatisfactionChart)
                        <p class="mt-1 text-xs" style="color:#7f1d1d;">
                            {{ $chart['dissatisfied_count'] }} / {{ $chart['processed_count'] }} dossier(s) traité(s)
                        </p>
                    @else
                        <p class="mt-1 text-xs" style="color:#14532d;">
                            {{ $chart['satisfied_count'] }} / {{ $chart['target_count'] }} satisfaisant(s)
                        </p>
                        <p class="mt-1 text-xs font-semibold" style="color:#166534;">
                            Cible satisfaction : {{ $chart['target_percentage_display'] }}
                        </p>
                        @if (($chart['unsatisfied_count'] ?? 0) > 0)
                            <p class="mt-1 text-xs" style="color:#991b1b;">{{ $chart['unsatisfied_count'] }} non satisfaisant(s)</p>
                        @endif
                    @endif
                </article>
            @endforeach
        </section>

        @php
            $chartItems = $satisfactionCharts;
        @endphp

        <section class="grid grid-cols-1 gap-4 {{ $chartItems->count() > 1 ? 'xl:grid-cols-2' : '' }}">
            @foreach ($chartItems as $chart)
                @php
                    $targetCount = max(0, (int) ($chart['target_count'] ?? 0));
                    $satisfiedCount = max(0, (int) ($chart['satisfied_count'] ?? 0));
                    $unsatisfiedCount = max(0, (int) ($chart['unsatisfied_count'] ?? 0));
                    $satisfactionAnsweredCount = max(0, (int) ($chart['answered_count'] ?? min($targetCount, $satisfiedCount + $unsatisfiedCount)));
                    $usesManualTarget = (bool) ($chart['is_manual_target'] ?? false);
                    $satisfiedShare = (float) (
                        $usesManualTarget
                            ? ($chart['target_satisfied_share'] ?? $chart['satisfied_share'] ?? 0)
                            : ($chart['satisfied_share'] ?? ($targetCount > 0 ? min(100, round(($satisfiedCount / $targetCount) * 100, 2)) : 0))
                    );
                    $answeredShare = (float) (
                        $usesManualTarget
                            ? ($chart['target_answered_share'] ?? $chart['answered_share'] ?? 0)
                            : ($chart['answered_share'] ?? ($targetCount > 0 ? min(100, round(($satisfactionAnsweredCount / $targetCount) * 100, 2)) : 0))
                    );
                @endphp
                <article class="gc-card p-5">
                    <div class="flex flex-col gap-5 md:flex-row md:items-center">
                        <div
                            class="lot-chart-ring shrink-0"
                            style="--satisfied:{{ $satisfiedShare }};--answered:{{ $answeredShare }};"
                            aria-label="{{ $satisfiedCount }} satisfaisant(s), {{ $unsatisfiedCount }} non satisfaisant(s)"
                        >
                            <span>{{ $chart['display'] }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">{{ $chart['label'] ?? 'Lot' }}</p>
                            <h2 class="mt-1 text-lg font-semibold" style="color:var(--gc-text);">{{ $chart['detail'] ?? 'Suivi du lot' }}</h2>
                            <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                                {{ $satisfactionAnsweredCount }} réponse(s) de satisfaction sur {{ $chart['total_count'] ?? $lot['appointments_count'] }} dossier(s) du lot.
                            </p>
                            <p class="mt-1 text-sm font-semibold" style="color:#166534;">
                                Cible satisfaction : {{ $chart['target_percentage_display'] }} · objectif {{ $targetCount }} dossier(s)
                            </p>
                            @if ($chart['is_manual_target'] ?? false)
                                <p class="mt-1 text-xs font-semibold" style="color:#15803d;">objectif RDV manuel</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1" style="background:#dcfce7;color:#166534;">
                                    <span class="h-2 w-2 rounded-full" style="background:#16a34a;"></span>
                                    {{ $satisfiedCount }} satisfaisant(s)
                                </span>
                                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1" style="background:#fee2e2;color:#991b1b;">
                                    <span class="h-2 w-2 rounded-full" style="background:#dc2626;"></span>
                                    {{ $unsatisfiedCount }} non satisfaisant(s)
                                </span>
                                @if (($chart['remaining_count'] ?? 0) > 0)
                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1" style="background:#f1f5f9;color:#475569;">
                                        <span class="h-2 w-2 rounded-full" style="background:#cbd5e1;"></span>
                                        {{ $chart['remaining_count'] }} en attente
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

            <form id="manager-lot-appointment-filters-form" method="GET" action="{{ route('manager.lots.show', $lot['id']) }}" class="border-b p-4 md:p-5" style="border-color:var(--gc-border);background:#fbfaf6;">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-6 xl:grid-cols-12 xl:items-end">
                    <label class="block md:col-span-2 lg:col-span-3 xl:col-span-3">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Recherche</span>
                        <input
                            id="appointment_q"
                            name="appointment_q"
                            type="search"
                            value="{{ $appointmentFilters['appointment_q'] }}"
                            class="mt-2 w-full rounded-2xl border px-4 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                            placeholder="Client, site, téléphone, adresse, référence..."
                        >
                    </label>

                    <label class="block lg:col-span-1 xl:col-span-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Statut</span>
                        <select
                            name="appointment_status"
                            class="mt-2 w-full rounded-2xl border px-4 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous les statuts</option>
                            @foreach ($lotAppointmentStatuses as $status => $label)
                                <option value="{{ $status }}" @selected($appointmentFilters['appointment_status'] === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block lg:col-span-1 xl:col-span-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Traitement</span>
                        <select
                            name="appointment_processing"
                            class="mt-2 w-full rounded-2xl border px-4 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous les traitements</option>
                            @foreach ($lotAppointmentProcessingFilters as $processing => $label)
                                <option value="{{ $processing }}" @selected($appointmentFilters['appointment_processing'] === $processing)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block lg:col-span-1 xl:col-span-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Résultat</span>
                        <select
                            name="appointment_satisfaction"
                            class="mt-2 w-full rounded-2xl border px-4 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous les résultats</option>
                            @foreach ($lotAppointmentSatisfactionFilters as $satisfaction => $label)
                                <option value="{{ $satisfaction }}" @selected($appointmentFilters['appointment_satisfaction'] === $satisfaction)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block lg:col-span-1 xl:col-span-1">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Global +</span>
                        <select
                            name="appointment_global_plus"
                            class="mt-2 w-full rounded-2xl border px-4 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            <option value="">Tous</option>
                            @foreach ($lotAppointmentGlobalPlusFilters as $globalPlus => $label)
                                <option value="{{ $globalPlus }}" @selected($appointmentFilters['appointment_global_plus'] === $globalPlus)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block lg:col-span-1 xl:col-span-1">
                        <span class="text-xs font-semibold uppercase tracking-[0.08em]" style="color:var(--gc-text-soft);">Lignes</span>
                        <select
                            id="lot_appointment_per_page"
                            name="per_page"
                            class="mt-2 w-full rounded-2xl border px-4 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-900/10"
                            style="border-color:var(--gc-border);color:var(--gc-text);"
                        >
                            @foreach ($lotAppointmentPerPageOptions as $perPage => $label)
                                <option value="{{ $perPage }}" @selected((int) $appointmentFilters['per_page'] === (int) $perPage)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <a href="{{ route('manager.lots.show', $lot['id']) }}" class="gc-btn-soft min-h-[42px] justify-center self-end md:col-span-2 lg:col-span-2 xl:col-span-1">
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
                            <th class="px-4 py-3 text-left font-semibold">Installateur</th>
                            <th class="px-4 py-3 text-left font-semibold">Contact</th>
                            <th class="px-4 py-3 text-left font-semibold">Adresse</th>
                            <th class="px-4 py-3 text-left font-semibold">Traitement</th>
                            <th class="px-4 py-3 text-left font-semibold">Résultat</th>
                            <th class="px-4 py-3 text-center font-semibold">Global +</th>
                            <th class="px-4 py-3 text-left font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y" style="border-color:var(--gc-border);">
                        @if ($lot['appointments']->isEmpty())
                            <tr>
                                <td colspan="9" class="px-4 py-10 text-center" style="color:var(--gc-text-soft);">
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
                                $rowTone = match (true) {
                                    $appointment['contact_satisfaction'] === false || $appointment['physical_satisfaction'] === false => 'unsatisfied',
                                    $appointment['contact_satisfaction'] === true || $appointment['physical_satisfaction'] === true => 'satisfied',
                                    default => null,
                                };
                            @endphp
                            <tr
                                @class([
                                    'lot-appointment-row transition hover:bg-[color:var(--gc-accent-soft)]' => true,
                                    'bg-emerald-50/70' => $rowTone === 'satisfied',
                                    'bg-rose-50/80' => $rowTone === 'unsatisfied',
                                    'opacity-60' => $appointment['excluded_from_lot_stats'],
                                ])
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
                                <td class="min-w-[180px] px-4 py-3" style="color:var(--gc-text-soft);">{{ $appointment['installer_name'] ?: '-' }}</td>
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
                                <td class="whitespace-nowrap px-4 py-3 text-center">
                                    <input
                                        type="checkbox"
                                        class="gc-check lot-appointment-global-plus-checkbox"
                                        data-lot-appointment-id="{{ $appointment['id'] }}"
                                        @checked($appointment['added_to_global_plus'])
                                        @disabled(! $appointment['global_plus_update_url'])
                                        aria-label="Ajouter le dossier {{ $clientLabel ?: 'client' }} au Global +"
                                    />
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
                <div class="space-y-4">
                    <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);">
                        <h3 class="font-semibold" style="color:var(--gc-text);">Informations du RDV</h3>
                        <dl id="lot-physical-detail-infos" class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2"></dl>
                    </section>

                    <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold" style="color:var(--gc-text);">Documents</h3>
                            <span id="lot-physical-documents-count" class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">0</span>
                        </div>
                        <div id="lot-physical-documents-list" class="mt-3 space-y-2"></div>
                        <div id="lot-physical-documents-dropzone" class="mt-4 rounded-2xl border border-dashed p-4 text-center transition" style="border-color:var(--gc-border);background:#fbfaf6;">
                            <p class="text-sm font-semibold" style="color:var(--gc-text);">Déposer ou choisir des fichiers</p>
                            <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Ils partiront vers Coffrac dès que possible.</p>
                            <input id="lot_physical_documents_file_input" type="file" class="hidden" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                        </div>
                        <form id="lot-physical-documents-upload-form" class="mt-3 hidden space-y-3">
                            <div id="lot-physical-documents-upload-list" class="space-y-2"></div>
                            <button id="lot-physical-documents-upload-submit" type="submit" class="gc-btn-primary w-full justify-center">Ajouter les documents</button>
                        </form>
                        <p id="lot-physical-documents-upload-status" class="mt-3 hidden text-sm"></p>
                    </section>
                </div>

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

                    <div class="mt-4 border-t pt-4" style="border-color:var(--gc-border);">
                        <h3 class="font-semibold" style="color:var(--gc-text);">Remise à traiter</h3>
                        <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">
                            Remet le dossier en statut « ne pas placer » et supprime son état de traitement.
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

                <section class="rounded-2xl border p-4" style="border-color:var(--gc-border);background:#ffffff;">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold" style="color:var(--gc-text);">Documents</h3>
                        <span id="lot-contact-documents-count" class="rounded-full px-3 py-1 text-xs font-semibold" style="background:var(--gc-accent-soft);color:var(--gc-text);">0</span>
                    </div>
                    <div id="lot-contact-documents-list" class="mt-3 space-y-2"></div>
                    <div id="lot-contact-documents-dropzone" class="mt-4 rounded-2xl border border-dashed p-4 text-center transition" style="border-color:var(--gc-border);background:#fbfaf6;">
                        <p class="text-sm font-semibold" style="color:var(--gc-text);">Déposer ou choisir des fichiers</p>
                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">Les documents seront conservés sur ce dossier.</p>
                        <input id="lot_contact_documents_file_input" type="file" class="hidden" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                    </div>
                    <form id="lot-contact-documents-upload-form" class="mt-3 hidden space-y-3">
                        <div id="lot-contact-documents-upload-list" class="space-y-2"></div>
                        <button id="lot-contact-documents-upload-submit" type="submit" class="gc-btn-primary w-full justify-center">Ajouter les documents</button>
                    </form>
                    <p id="lot-contact-documents-upload-status" class="mt-3 hidden text-sm"></p>
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
                        Remet le dossier en statut « ne pas placer » et supprime son état de satisfaction.
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
        const lotAppointmentPerPageSelect = document.getElementById('lot_appointment_per_page');
        const lotAppointmentPerPageStorageKey = 'manager_lot_detail_per_page';
        const lotAppointmentPerPageAllowedValues = @json(array_map('strval', array_keys($lotAppointmentPerPageOptions)));
        let lotAppointmentSearchTimer = null;
        const lotDetailEditOpen = document.getElementById('lot-detail-edit-open');
        const lotDetailEditModal = document.getElementById('lot-detail-edit-modal');
        const lotDetailEditClose = document.getElementById('lot-detail-edit-close');
        const lotDetailEditCancel = document.getElementById('lot-detail-edit-cancel');
        const lotDocumentsUrl = @json($lot['documents_url']);
        const lotDocumentsOpen = document.getElementById('lot-detail-documents-open');
        const lotDocumentsModal = document.getElementById('lot-documents-modal');
        const lotDocumentsClose = document.getElementById('lot-documents-close');
        const lotDocumentsSearch = document.getElementById('lot_documents_search');
        const lotDocumentsStatus = document.getElementById('lot-documents-status');
        const lotDocumentsCounter = document.getElementById('lot-documents-counter');
        const lotDocumentsPrev = document.getElementById('lot-documents-prev');
        const lotDocumentsNext = document.getElementById('lot-documents-next');
        const lotDocumentsEmpty = document.getElementById('lot-documents-empty');
        const lotDocumentsCard = document.getElementById('lot-documents-card');
        const lotDocumentsCardReference = document.getElementById('lot-documents-card-reference');
        const lotDocumentsCardTitle = document.getElementById('lot-documents-card-title');
        const lotDocumentsCardSubtitle = document.getElementById('lot-documents-card-subtitle');
        const lotDocumentsCardStatus = document.getElementById('lot-documents-card-status');
        const lotDocumentsCardInfos = document.getElementById('lot-documents-card-infos');
        const lotDocumentsCardDocCount = document.getElementById('lot-documents-card-doc-count');
        const lotDocumentsCardList = document.getElementById('lot-documents-card-list');
        const lotDocumentsDropzone = document.getElementById('lot-documents-dropzone');
        const lotDocumentsFileInput = document.getElementById('lot_documents_file_input');
        const lotDocumentsUploadForm = document.getElementById('lot-documents-upload-form');
        const lotDocumentsUploadList = document.getElementById('lot-documents-upload-list');
        const lotDocumentsUploadSubmit = document.getElementById('lot-documents-upload-submit');
        const lotDocumentsUploadStatus = document.getElementById('lot-documents-upload-status');
        const lotDetailEditType = document.getElementById('lot_detail_edit_type');
        const lotDetailEditServiceId = document.getElementById('lot_detail_edit_service_id');
        const lotDetailEditCoffracAliasId = document.getElementById('lot_detail_edit_coffrac_service_alias_id');
        const lotDetailEditSamplingPercentage = document.getElementById('lot_detail_edit_sampling_percentage');
        const lotDetailEditPhysicalSamplingPercentage = document.getElementById('lot_detail_edit_physical_sampling_percentage');
        const lotDetailEditContactSamplingPercentage = document.getElementById('lot_detail_edit_contact_sampling_percentage');
        const lotDetailEditSingleSamplingWrap = document.getElementById('lot-detail-edit-single-sampling-wrap');
        const lotDetailEditPhysicalSamplingWrap = document.getElementById('lot-detail-edit-physical-sampling-wrap');
        const lotDetailEditContactSamplingWrap = document.getElementById('lot-detail-edit-contact-sampling-wrap');
        const lotDetailSamplingTypes = @json(\App\Models\Lot::samplingTypes());
        const lotDetailHybridType = @json(\App\Models\Lot::TYPE_HYBRID_LOCATION_CONTACT);
        const lotDetailCurrentLot = @json([
            'service_id' => $lot['service_id'],
            'coffrac_service_alias_id' => $lot['coffrac_service_alias_id'],
        ]);
        const lotDetailServices = @json($serviceAliasOptions);
        const lotAppointmentTargetsModal = document.getElementById('lot-appointment-targets-modal');
        const lotAppointmentTargetsClose = document.getElementById('lot-appointment-targets-close');
        const lotAppointmentTargetsCancel = document.getElementById('lot-appointment-targets-cancel');

        function getStoredLotAppointmentPerPage() {
            try {
                return window.localStorage.getItem(lotAppointmentPerPageStorageKey);
            } catch (error) {
                return null;
            }
        }

        function storeLotAppointmentPerPage(value) {
            if (!lotAppointmentPerPageAllowedValues.includes(String(value))) {
                return;
            }

            try {
                window.localStorage.setItem(lotAppointmentPerPageStorageKey, String(value));
            } catch (error) {
                // Le stockage local est un confort UX, pas une dépendance fonctionnelle.
            }
        }

        function syncLotAppointmentPerPageFromStorage() {
            if (!lotAppointmentFiltersForm || !lotAppointmentPerPageSelect) {
                return;
            }

            const currentUrl = new URL(window.location.href);
            const urlPerPage = currentUrl.searchParams.get('per_page');

            if (lotAppointmentPerPageAllowedValues.includes(String(urlPerPage))) {
                storeLotAppointmentPerPage(urlPerPage);
                return;
            }

            const storedPerPage = getStoredLotAppointmentPerPage();

            if (!lotAppointmentPerPageAllowedValues.includes(String(storedPerPage))) {
                return;
            }

            if (lotAppointmentPerPageSelect.value !== String(storedPerPage)) {
                lotAppointmentPerPageSelect.value = String(storedPerPage);
                lotAppointmentFiltersForm.submit();
            }
        }

        syncLotAppointmentPerPageFromStorage();

        if (lotAppointmentFiltersForm) {
            lotAppointmentFiltersForm.querySelectorAll('select').forEach((select) => {
                select.addEventListener('change', () => {
                    if (select === lotAppointmentPerPageSelect) {
                        storeLotAppointmentPerPage(select.value);
                    }

                    lotAppointmentFiltersForm.submit();
                });
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
            populateLotDetailCoffracAliasSelect(
                lotDetailEditServiceId?.value || lotDetailCurrentLot.service_id || '',
                lotDetailCoffracAliasSelectedValue(),
            );
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
        lotDocumentsOpen?.addEventListener('click', () => {
            openModal(lotDocumentsModal);
            loadLotDocuments();
        });
        lotDocumentsClose?.addEventListener('click', () => closeModal(lotDocumentsModal));
        lotDocumentsModal?.addEventListener('click', (event) => {
            if (event.target === lotDocumentsModal) {
                closeModal(lotDocumentsModal);
            }
        });
        lotDocumentsSearch?.addEventListener('input', () => {
            window.clearTimeout(lotDocumentsSearchTimer);
            lotDocumentsSearchTimer = window.setTimeout(loadLotDocuments, 350);
        });
        lotDocumentsPrev?.addEventListener('click', () => {
            lotDocumentsCurrentIndex = Math.max(0, lotDocumentsCurrentIndex - 1);
            stageDocumentFiles(lotDocumentsUploadContext, []);
            renderLotDocumentsCard();
        });
        lotDocumentsNext?.addEventListener('click', () => {
            lotDocumentsCurrentIndex = Math.min(lotDocumentsAppointments.length - 1, lotDocumentsCurrentIndex + 1);
            stageDocumentFiles(lotDocumentsUploadContext, []);
            renderLotDocumentsCard();
        });
        lotDetailEditType?.addEventListener('change', updateLotDetailEditSamplingState);
        lotDetailEditServiceId?.addEventListener('change', () => {
            populateLotDetailCoffracAliasSelect(lotDetailEditServiceId.value, '', { selectFirst: true });
        });
        populateLotDetailCoffracAliasSelect(
            lotDetailEditServiceId?.value || lotDetailCurrentLot.service_id || '',
            lotDetailCurrentLot.coffrac_service_alias_id || '',
        );
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
        const physicalDocumentsList = document.getElementById('lot-physical-documents-list');
        const physicalDocumentsCount = document.getElementById('lot-physical-documents-count');
        const physicalDocumentsDropzone = document.getElementById('lot-physical-documents-dropzone');
        const physicalDocumentsFileInput = document.getElementById('lot_physical_documents_file_input');
        const physicalDocumentsUploadForm = document.getElementById('lot-physical-documents-upload-form');
        const physicalDocumentsUploadList = document.getElementById('lot-physical-documents-upload-list');
        const physicalDocumentsUploadSubmit = document.getElementById('lot-physical-documents-upload-submit');
        const physicalDocumentsUploadStatus = document.getElementById('lot-physical-documents-upload-status');

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
        const contactDocumentsList = document.getElementById('lot-contact-documents-list');
        const contactDocumentsCount = document.getElementById('lot-contact-documents-count');
        const contactDocumentsDropzone = document.getElementById('lot-contact-documents-dropzone');
        const contactDocumentsFileInput = document.getElementById('lot_contact_documents_file_input');
        const contactDocumentsUploadForm = document.getElementById('lot-contact-documents-upload-form');
        const contactDocumentsUploadList = document.getElementById('lot-contact-documents-upload-list');
        const contactDocumentsUploadSubmit = document.getElementById('lot-contact-documents-upload-submit');
        const contactDocumentsUploadStatus = document.getElementById('lot-contact-documents-upload-status');
        let lotAppointmentTooltip = null;
        let lotDocumentsAppointments = [];
        let lotDocumentsCurrentIndex = 0;
        let lotDocumentsSearchTimer = null;

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

        function currentLotDocumentsAppointment() {
            return lotDocumentsAppointments[lotDocumentsCurrentIndex] || null;
        }

        function documentStatusMeta(status) {
            if (status === 'uploaded') {
                return { background: '#dcfce7', color: '#166534' };
            }

            if (status === 'failed') {
                return { background: '#fee2e2', color: '#991b1b' };
            }

            if (status === 'queued') {
                return { background: '#fef3c7', color: '#92400e' };
            }

            return { background: 'var(--gc-accent-soft)', color: 'var(--gc-text)' };
        }

        function updateLotAppointmentState(appointment) {
            if (!appointment?.id) {
                return;
            }

            lotAppointmentDetails.set(String(appointment.id), appointment);

            const lotDocumentsIndex = lotDocumentsAppointments.findIndex((item) => String(item.id) === String(appointment.id));

            if (lotDocumentsIndex >= 0) {
                lotDocumentsAppointments[lotDocumentsIndex] = appointment;
            }

            if (currentPhysicalLotAppointment?.id === appointment.id) {
                currentPhysicalLotAppointment = appointment;
            }

            if (currentContactLotAppointment?.id === appointment.id) {
                currentContactLotAppointment = appointment;
            }
        }

        function renderLotDocumentsList(appointment, listElement, countElement) {
            if (!listElement || !countElement) {
                return;
            }

            const documents = Array.isArray(appointment?.documents) ? appointment.documents : [];
            countElement.textContent = String(documents.length);

            if (documents.length === 0) {
                listElement.innerHTML = `
                    <div class="rounded-2xl border p-4 text-sm" style="border-color:var(--gc-border);background:#fbfaf6;color:var(--gc-text-soft);">
                        Aucun document ajouté sur ce dossier.
                    </div>
                `;
                return;
            }

            listElement.innerHTML = documents.map((document) => {
                const meta = documentStatusMeta(document.status);
                const remoteLink = document.remote_url
                    ? `<a href="${escapeHtml(document.remote_url)}" target="_blank" rel="noopener" class="gc-link text-xs">Ouvrir côté Coffrac</a>`
                    : '';
                const visibilityBadge = document.is_private
                    ? '<span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold" style="background:#fee2e2;color:#991b1b;">Privé</span>'
                    : '<span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold" style="background:#dcfce7;color:#166534;">Public</span>';
                const actions = `
                    <div class="flex flex-wrap items-center gap-2">
                        ${document.can_update ? '<button type="button" class="gc-btn-soft px-3 py-1.5 text-xs" data-document-action="rename">Renommer</button>' : ''}
                        ${document.can_delete ? '<button type="button" class="gc-btn-danger px-3 py-1.5 text-xs" data-document-action="delete">Supprimer</button>' : ''}
                        ${remoteLink}
                    </div>
                `;

                return `
                    <article class="rounded-2xl border p-3" data-lot-document-id="${document.id}" data-document-update-url="${escapeHtml(document.update_url || '')}" data-document-delete-url="${escapeHtml(document.delete_url || '')}" style="border-color:var(--gc-border);background:#ffffff;">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div class="min-w-0 flex-1">
                                <input type="text" class="gc-input h-10 text-sm" value="${escapeHtml(document.name || document.original_name || 'Document')}" ${document.can_update ? '' : 'disabled'} data-document-name-input>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs" style="color:var(--gc-text-soft);">
                                    <span>${escapeHtml(document.original_name || '')} · ${escapeHtml(document.size_label || '')}</span>
                                    ${visibilityBadge}
                                </div>
                                ${document.error_message ? `<p class="mt-1 text-xs" style="color:#be123c;">${escapeHtml(document.error_message)}</p>` : ''}
                            </div>
                            <div class="space-y-2 md:text-right">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" style="background:${meta.background};color:${meta.color};">${escapeHtml(document.status_label || 'En attente')}</span>
                                ${actions}
                            </div>
                        </div>
                    </article>
                `;
            }).join('');
        }

        function renderStagedDocumentFiles(files, listElement) {
            if (!listElement) {
                return;
            }

            listElement.innerHTML = files.map((file, index) => {
                const defaultName = (file.name || 'Document').replace(/\.[^.]+$/, '');

                return `
                    <div class="rounded-xl border p-3" style="border-color:var(--gc-border);background:#fbfaf6;">
                        <label class="gc-label">Nom du document</label>
                        <input type="text" class="gc-input h-10 text-sm" value="${escapeHtml(defaultName)}" data-staged-document-name="${index}">
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs" style="color:var(--gc-text-soft);">${escapeHtml(file.name)} · ${Math.max(1, Math.round((file.size || 0) / 1024))} Ko</p>
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold" style="border-color:var(--gc-border);color:var(--gc-text);background:#ffffff;">
                                <input type="checkbox" class="rounded border-slate-300" data-staged-document-private="${index}">
                                Privé
                            </label>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function setDocumentUploadStatus(statusElement, message, color = 'var(--gc-text-soft)') {
            if (!statusElement) {
                return;
            }

            statusElement.textContent = message;
            statusElement.style.color = color;
            statusElement.classList.remove('hidden');
        }

        function clearDocumentUploadStatus(statusElement) {
            statusElement?.classList.add('hidden');
            if (statusElement) {
                statusElement.textContent = '';
            }
        }

        function stageDocumentFiles(context, files) {
            context.stagedFiles = Array.from(files || []);
            context.form?.classList.toggle('hidden', context.stagedFiles.length === 0);
            renderStagedDocumentFiles(context.stagedFiles, context.uploadList);
            clearDocumentUploadStatus(context.status);
            if (context.stagedFiles.length === 0 && context.fileInput) {
                context.fileInput.value = '';
            }
        }

        async function uploadStagedDocuments(context) {
            const appointment = context.getAppointment();

            if (!appointment?.documents_upload_url || context.stagedFiles.length === 0 || context.submit?.disabled) {
                return;
            }

            context.submit.disabled = true;
            context.submit.textContent = 'Ajout en cours...';
            setDocumentUploadStatus(context.status, 'Upload des documents en cours...');

            let latestAppointment = appointment;

            try {
                for (let index = 0; index < context.stagedFiles.length; index++) {
                    const file = context.stagedFiles[index];
                    const nameInput = context.uploadList?.querySelector(`[data-staged-document-name="${index}"]`);
                    const privateInput = context.uploadList?.querySelector(`[data-staged-document-private="${index}"]`);
                    const formData = new FormData();

                    formData.append('document', file);
                    formData.append('name', nameInput?.value || file.name);
                    formData.append('is_private', privateInput?.checked ? '1' : '0');

                    const response = await fetch(appointment.documents_upload_url, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': lotDetailCsrfToken,
                        },
                        body: formData,
                    });
                    const payload = await response.json();

                    if (!response.ok) {
                        throw new Error(payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Upload impossible.');
                    }

                    latestAppointment = payload.appointment || latestAppointment;
                    updateLotAppointmentState(latestAppointment);
                    context.onAppointmentUpdated?.(latestAppointment);
                }

                context.stagedFiles = [];
                context.form?.classList.add('hidden');
                context.fileInput.value = '';
                renderStagedDocumentFiles([], context.uploadList);
                setDocumentUploadStatus(context.status, 'Documents ajoutés.', '#15803d');
            } catch (error) {
                setDocumentUploadStatus(context.status, error.message || 'Upload impossible.', '#be123c');
            } finally {
                context.submit.disabled = false;
                context.submit.textContent = context.submitLabel;
            }
        }

        function setupDocumentUploader(context) {
            context.dropzone?.addEventListener('click', () => context.fileInput?.click());
            context.fileInput?.addEventListener('change', () => stageDocumentFiles(context, context.fileInput.files));
            context.dropzone?.addEventListener('dragover', (event) => {
                event.preventDefault();
                context.dropzone.style.borderColor = 'var(--gc-accent)';
                context.dropzone.style.background = '#fff7d6';
            });
            context.dropzone?.addEventListener('dragleave', () => {
                context.dropzone.style.borderColor = 'var(--gc-border)';
                context.dropzone.style.background = '#fbfaf6';
            });
            context.dropzone?.addEventListener('drop', (event) => {
                event.preventDefault();
                context.dropzone.style.borderColor = 'var(--gc-border)';
                context.dropzone.style.background = '#fbfaf6';
                stageDocumentFiles(context, event.dataTransfer?.files || []);
            });
            context.form?.addEventListener('submit', (event) => {
                event.preventDefault();
                uploadStagedDocuments(context);
            });
        }

        async function handleDocumentListAction(event, getAppointment, onAppointmentUpdated) {
            const button = event.target.closest('[data-document-action]');

            if (!button) {
                return;
            }

            const item = button.closest('[data-lot-document-id]');
            const action = button.dataset.documentAction;
            const nameInput = item?.querySelector('[data-document-name-input]');

            if (!item || !action) {
                return;
            }

            button.disabled = true;

            try {
                const response = await fetch(action === 'rename' ? item.dataset.documentUpdateUrl : item.dataset.documentDeleteUrl, {
                    method: action === 'rename' ? 'PATCH' : 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': lotDetailCsrfToken,
                    },
                    body: action === 'rename'
                        ? JSON.stringify({ name: nameInput?.value || 'Document' })
                        : null,
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Action impossible.');
                }

                const appointment = payload.appointment || getAppointment();
                updateLotAppointmentState(appointment);
                onAppointmentUpdated?.(appointment);
            } catch (error) {
                window.alert(error.message || 'Action impossible.');
            } finally {
                button.disabled = false;
            }
        }

        function renderLotDocumentsCard() {
            const appointment = currentLotDocumentsAppointment();
            const total = lotDocumentsAppointments.length;

            lotDocumentsCounter.textContent = total > 0 ? `${lotDocumentsCurrentIndex + 1} / ${total}` : '0 / 0';
            lotDocumentsPrev.disabled = lotDocumentsCurrentIndex <= 0;
            lotDocumentsNext.disabled = lotDocumentsCurrentIndex >= total - 1;

            if (!appointment) {
                lotDocumentsEmpty.classList.remove('hidden');
                lotDocumentsCard.classList.add('hidden');
                return;
            }

            lotDocumentsEmpty.classList.add('hidden');
            lotDocumentsCard.classList.remove('hidden');
            lotDocumentsCardReference.textContent = appointment.row_number
                ? `Ligne ${appointment.row_number}${appointment.external_reference ? ` · Réf. ${appointment.external_reference}` : ''}`
                : (appointment.external_reference ? `Réf. ${appointment.external_reference}` : 'Dossier du lot');
            lotDocumentsCardTitle.textContent = customerLabel(appointment);
            lotDocumentsCardSubtitle.textContent = [appointment.site_name ? `Site : ${appointment.site_name}` : null, appointment.installer_name ? `Installateur : ${appointment.installer_name}` : null].filter(Boolean).join(' · ');
            lotDocumentsCardStatus.textContent = appointment.status_label || 'À traiter';
            lotDocumentsCardInfos.innerHTML = infoGrid([
                ['Téléphone', appointment.customer_phone],
                ['Adresse', fullAddress(appointment)],
                ['Prestation', appointment.service_label],
                ['Commentaire', appointment.comment],
            ]);
            renderLotDocumentsList(appointment, lotDocumentsCardList, lotDocumentsCardDocCount);
        }

        async function loadLotDocuments() {
            const url = new URL(lotDocumentsUrl, window.location.origin);
            const search = lotDocumentsSearch?.value?.trim();

            if (search) {
                url.searchParams.set('q', search);
            }

            lotDocumentsStatus.textContent = 'Chargement des dossiers...';
            lotDocumentsStatus.style.color = 'var(--gc-text-soft)';

            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Chargement impossible.');
                }

                lotDocumentsAppointments = Array.isArray(payload.appointments) ? payload.appointments : [];
                lotDocumentsAppointments.forEach(updateLotAppointmentState);
                lotDocumentsCurrentIndex = 0;
                lotDocumentsStatus.textContent = lotDocumentsAppointments.length
                    ? `${lotDocumentsAppointments.length} dossier(s) trouvé(s).`
                    : 'Aucun dossier ne correspond à la recherche.';
                renderLotDocumentsCard();
            } catch (error) {
                lotDocumentsStatus.textContent = error.message || 'Chargement impossible.';
                lotDocumentsStatus.style.color = '#be123c';
                lotDocumentsAppointments = [];
                lotDocumentsCurrentIndex = 0;
                renderLotDocumentsCard();
            }
        }

        const lotDocumentsUploadContext = {
            dropzone: lotDocumentsDropzone,
            fileInput: lotDocumentsFileInput,
            form: lotDocumentsUploadForm,
            uploadList: lotDocumentsUploadList,
            submit: lotDocumentsUploadSubmit,
            status: lotDocumentsUploadStatus,
            submitLabel: 'Ajouter au dossier',
            stagedFiles: [],
            getAppointment: currentLotDocumentsAppointment,
            onAppointmentUpdated: (appointment) => {
                updateLotAppointmentState(appointment);
                renderLotDocumentsCard();
            },
        };
        const physicalDocumentsUploadContext = {
            dropzone: physicalDocumentsDropzone,
            fileInput: physicalDocumentsFileInput,
            form: physicalDocumentsUploadForm,
            uploadList: physicalDocumentsUploadList,
            submit: physicalDocumentsUploadSubmit,
            status: physicalDocumentsUploadStatus,
            submitLabel: 'Ajouter les documents',
            stagedFiles: [],
            getAppointment: () => currentPhysicalLotAppointment,
            onAppointmentUpdated: (appointment) => {
                updateLotAppointmentState(appointment);
                renderLotDocumentsList(appointment, physicalDocumentsList, physicalDocumentsCount);
            },
        };
        const contactDocumentsUploadContext = {
            dropzone: contactDocumentsDropzone,
            fileInput: contactDocumentsFileInput,
            form: contactDocumentsUploadForm,
            uploadList: contactDocumentsUploadList,
            submit: contactDocumentsUploadSubmit,
            status: contactDocumentsUploadStatus,
            submitLabel: 'Ajouter les documents',
            stagedFiles: [],
            getAppointment: () => currentContactLotAppointment,
            onAppointmentUpdated: (appointment) => {
                updateLotAppointmentState(appointment);
                renderLotDocumentsList(appointment, contactDocumentsList, contactDocumentsCount);
            },
        };

        setupDocumentUploader(lotDocumentsUploadContext);
        setupDocumentUploader(physicalDocumentsUploadContext);
        setupDocumentUploader(contactDocumentsUploadContext);
        lotDocumentsCardList?.addEventListener('click', (event) => handleDocumentListAction(event, currentLotDocumentsAppointment, renderLotDocumentsCard));
        physicalDocumentsList?.addEventListener('click', (event) => handleDocumentListAction(
            event,
            () => currentPhysicalLotAppointment,
            (appointment) => renderLotDocumentsList(appointment, physicalDocumentsList, physicalDocumentsCount),
        ));
        contactDocumentsList?.addEventListener('click', (event) => handleDocumentListAction(
            event,
            () => currentContactLotAppointment,
            (appointment) => renderLotDocumentsList(appointment, contactDocumentsList, contactDocumentsCount),
        ));

        function isLotDetailSamplingType(type) {
            return lotDetailSamplingTypes.includes(type);
        }

        function isLotDetailHybridType(type) {
            return type === lotDetailHybridType;
        }

        function lotDetailCoffracAliasesForService(serviceId) {
            const service = lotDetailServices.find((item) => String(item.id) === String(serviceId));

            return Array.isArray(service?.aliases) ? service.aliases : [];
        }

        function lotDetailCoffracAliasSelectedValue() {
            return lotDetailEditCoffracAliasId?.value || lotDetailCurrentLot.coffrac_service_alias_id || '';
        }

        function populateLotDetailCoffracAliasSelect(serviceId, selectedAliasId = '', options = {}) {
            if (!lotDetailEditCoffracAliasId) {
                return;
            }

            const aliases = lotDetailCoffracAliasesForService(serviceId);

            lotDetailEditCoffracAliasId.innerHTML = '';

            if (!serviceId) {
                lotDetailEditCoffracAliasId.add(new Option('Sélectionner une prestation', ''));
                lotDetailEditCoffracAliasId.disabled = true;
                return;
            }

            if (!aliases.length) {
                lotDetailEditCoffracAliasId.add(new Option('Aucun alias Coffrac pour cette prestation', ''));
                lotDetailEditCoffracAliasId.disabled = true;
                return;
            }

            lotDetailEditCoffracAliasId.add(new Option('Utiliser le fallback automatique', ''));
            aliases.forEach((alias) => {
                lotDetailEditCoffracAliasId.add(new Option(alias.label, alias.id));
            });

            const selectedValue = selectedAliasId
                ? String(selectedAliasId)
                : (options.selectFirst ? String(aliases[0]?.id || '') : '');

            lotDetailEditCoffracAliasId.value = aliases.some((alias) => String(alias.id) === selectedValue)
                ? selectedValue
                : '';
            lotDetailEditCoffracAliasId.disabled = false;
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

        function globalPlusCheckboxesFor(appointmentId) {
            return document.querySelectorAll(`.lot-appointment-global-plus-checkbox[data-lot-appointment-id="${appointmentId}"]`);
        }

        async function updateLotAppointmentGlobalPlus(appointment, checkboxElement) {
            if (!appointment?.global_plus_update_url || !checkboxElement) {
                return;
            }

            const requestedState = checkboxElement.checked;
            checkboxElement.disabled = true;

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

                globalPlusCheckboxesFor(updatedAppointment.id).forEach((checkbox) => {
                    checkbox.checked = Boolean(updatedAppointment.added_to_global_plus);
                    checkbox.disabled = !updatedAppointment.global_plus_update_url;
                });

                const currentGlobalPlusFilter = new URLSearchParams(window.location.search).get('appointment_global_plus');

                if (
                    (currentGlobalPlusFilter === '1' && !updatedAppointment.added_to_global_plus)
                    || (currentGlobalPlusFilter === '0' && updatedAppointment.added_to_global_plus)
                ) {
                    window.location.reload();
                }
            } catch (error) {
                checkboxElement.checked = !requestedState;
                checkboxElement.disabled = false;
                window.alert(error.message || 'Mise à jour Global + impossible.');
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
                ['Raison sociale bénéficiaire', appointment.company_name],
                ['Nom du site', appointment.site_name],
                ['Installateur', appointment.installer_name],
                ['Téléphone', appointment.customer_phone],
                ['Adresse', fullAddress(appointment)],
                ['Référence', appointment.external_reference],
                ['Satisfaction physique', appointment.physical_satisfaction === null || appointment.physical_satisfaction === undefined ? '-' : (appointment.physical_satisfaction ? 'Satisfaisant' : 'Non satisfaisant')],
            ]);

            physicalVisitsInput.value = appointment.unsuccessful_visits_count ?? 0;
            physicalVisitsInput.disabled = !appointment.can_update_visits;
            physicalVisitsSubmit.disabled = !appointment.can_update_visits;
            physicalVisitsStatus.classList.add('hidden');
            stageDocumentFiles(physicalDocumentsUploadContext, []);
            renderLotDocumentsList(appointment, physicalDocumentsList, physicalDocumentsCount);
            clearDocumentUploadStatus(physicalDocumentsUploadStatus);

            if (appointment.tracking_url) {
                physicalTrackingLink.href = appointment.tracking_url;
                physicalTrackingWrap.classList.remove('hidden');
            } else {
                physicalTrackingWrap.classList.add('hidden');
            }

            configureStatsExclusionControls(appointment, physicalStatsExclusionStatus, physicalStatsExclusionToggle);
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
                ['Raison sociale bénéficiaire', appointment.company_name],
                ['Nom du site', appointment.site_name],
                ['Installateur', appointment.installer_name],
                ['Téléphone', appointment.customer_phone],
                ['Adresse', fullAddress(appointment)],
                ['Référence', appointment.external_reference],
            ]);
            contactComment.textContent = appointment.contact_comment || appointment.comment || 'Aucun commentaire renseigné.';
            stageDocumentFiles(contactDocumentsUploadContext, []);
            renderLotDocumentsList(appointment, contactDocumentsList, contactDocumentsCount);
            clearDocumentUploadStatus(contactDocumentsUploadStatus);

            configureStatsExclusionControls(appointment, contactStatsExclusionStatus, contactStatsExclusionToggle);
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

        document.querySelectorAll('.lot-appointment-global-plus-checkbox').forEach((checkbox) => {
            checkbox.addEventListener('click', (event) => event.stopPropagation());
            checkbox.addEventListener('change', () => {
                const appointment = lotAppointmentDetails.get(String(checkbox.dataset.lotAppointmentId));

                updateLotAppointmentGlobalPlus(appointment, checkbox);
            });
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
