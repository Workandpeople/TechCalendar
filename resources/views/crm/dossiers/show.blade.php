<x-layouts.app :crm-navigation="true">
    @php
        $planningRoute = (auth()->user()->admin || auth()->user()->role === 0) ? 'manager.appointments' : 'planner.tracking';
    @endphp
    <div class="space-y-6">
        <a href="{{ route('v2.dossiers.index') }}" class="gc-link">← Tous les dossiers</a>
        <x-workspace-heading :title="$dossier->beneficiaryLabel()" :eyebrow="$dossier->reference" :description="'Créé le '.$dossier->created_at?->format('d/m/Y').' · Retrouvez ici le suivi, les rendez-vous et les échanges de ce dossier.'">
            <a class="gc-btn-secondary" href="{{ route('v2.dossiers.edit', $dossier) }}"><x-nav-icon name="edit" /> Modifier les informations</a>
        </x-workspace-heading>
        @include('crm.dossiers.notice')
        @if ($dossier->source === 'coffrac')
            <p class="text-sm text-slate-600">Référence Coffrac : {{ $dossier->legacy_reference ?: 'Non renseignée' }} · Lot : {{ $dossier->legacy_lot_name ?: 'Non renseigné' }}. Les valeurs source sont conservées sans fusion des dossiers homonymes.</p>
        @endif
        <nav class="gc-dossier-tabs" aria-label="Sections du dossier">
            @foreach (['synthese' => 'Synthèse', 'prestations' => 'Prestations', 'rendez-vous' => 'Rendez-vous et lots', 'apres-visite' => 'Après la visite', 'documents' => 'Documents', 'capcee' => 'Rapports CAPCEE', 'historique' => 'Commentaires et historique'] as $id => $label)
                <a href="#{{ $id }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"><x-nav-icon :name="['synthese' => 'dossiers', 'prestations' => 'services', 'rendez-vous' => 'planning', 'apres-visite' => 'check', 'documents' => 'document', 'capcee' => 'document', 'historique' => 'comment'][$id]" />{{ $label }}</a>
            @endforeach
        </nav>
        <section id="synthese" class="grid gap-4 xl:grid-cols-3" aria-label="Synthèse">
            <article class="gc-card p-5">
                <h2 class="gc-workspace-section-title text-xs font-semibold uppercase tracking-widest text-slate-500"><x-nav-icon name="location" />Lieu d’inspection</h2>
                <p class="mt-4 font-semibold">{{ $dossier->site_name ?: 'Site de l’intervention' }}</p>
                <p class="mt-2 break-words text-sm">{{ $dossier->address }}</p>
                <p class="text-sm">{{ $dossier->postal_code }} {{ $dossier->city }}</p>
            </article>
            <article class="gc-card p-5">
                <h2 class="gc-workspace-section-title text-xs font-semibold uppercase tracking-widest text-slate-500"><x-nav-icon name="users" />Bénéficiaire</h2>
                <p class="mt-4 font-semibold">{{ $dossier->company_name ?: $dossier->legacy_label }}</p>
                <p class="text-sm">{{ $dossier->first_name }} {{ $dossier->last_name }}</p>
                <p class="mt-2 break-words text-sm">{{ $dossier->phone ?: 'Téléphone non renseigné' }}</p>
                <p class="break-all text-sm">{{ $dossier->email ?: 'Email non renseigné' }}</p>
                @if ($dossier->beneficiary_address)<p class="mt-3 break-words text-sm text-slate-500">Adresse administrative : {{ $dossier->beneficiary_address }} {{ $dossier->beneficiary_postal_code }} {{ $dossier->beneficiary_city }}</p>@endif
            </article>
            <article class="gc-card p-5">
                <h2 class="gc-workspace-section-title text-xs font-semibold uppercase tracking-widest text-slate-500"><x-nav-icon name="delegataires" />Intervenants</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Client (classique ou délégataire)</dt><dd class="mt-1 font-medium">{{ $dossier->delegataire?->company_name ?: $dossier->delegataire?->name ?: 'À définir' }}</dd></div>
                    <div><dt class="text-slate-500">Installateur</dt><dd class="mt-1 font-medium">{{ $dossier->installer_name ?: 'À définir' }}</dd><dd class="text-xs text-slate-500">{{ $dossier->installer_siren ? 'SIREN '.$dossier->installer_siren : '' }}</dd></div>
                    <div><dt class="text-slate-500">Suivi planning</dt><dd class="mt-1 font-medium">{{ $dossier->planningUser?->full_name ?: 'Non attribué' }}@if ($dossier->planningUser?->trashed()) <span class="text-xs text-slate-500">(compte supprimé)</span>@endif</dd></div>
                </dl>
            </article>
        </section>
        <section id="prestations" class="gc-card p-5">
            <h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="services" />Prestations du dossier</h2>
            <p class="mt-1 text-sm text-slate-500">Une fiche par prestation, avec autant de rendez-vous que nécessaire. Le contrôle et ses conclusions restent distincts des dates du planning.</p>
            <ul class="mt-4 divide-y divide-slate-100">
                @foreach ($dossier->operations as $operation)
                    @php
                        $state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
                        $linkedAppointment = $operation->appointments->first();
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div><p class="font-medium">{{ ($operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation à rapprocher') }}</p><p class="text-xs text-slate-500">{{ ($operation->service?->type ?: 'Coffrac') }} · Fiche {{ $operation->id }}</p></div>
                        <div class="flex flex-wrap items-center gap-3">
                            <x-dossier-status :status="$operation->legacy_status" />
                            <span class="text-sm text-slate-500">{{ $operation->appointments_count }} RDV lié(s)</span>
                            @if ($linkedAppointment)
                                <a href="{{ route($planningRoute, ['appointment_id' => $linkedAppointment->id, 'technician_id' => $linkedAppointment->technician_id, 'date' => $linkedAppointment->starts_at?->toDateString()]) }}" class="gc-btn-secondary">Voir le RDV</a>
                                @if (in_array($state, ['pending', 'problem', 'awaiting_visit']))
                                    <a href="{{ route('planner.book', ['replace_appointment_id' => $linkedAppointment->id]) }}" class="gc-btn-primary">Replacer le RDV</a>
                                @endif
                            @elseif ($state === 'pending')
                                <a href="{{ route('planner.book', ['crm_appointment_id' => 'dossier-'.$operation->id]) }}" class="gc-btn-primary">Planifier le RDV</a>
                            @elseif ($state === 'problem')
                                <details class="max-w-sm rounded-lg border border-slate-200 p-3">
                                    <summary class="cursor-pointer text-sm font-semibold">Reprendre la prise de RDV</summary>
                                    <form method="POST" action="{{ route('v2.dossiers.operations.reopen', [$dossier, $operation]) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $dossier->version }}">
                                        <label for="reopen-{{ $operation->id }}" class="gc-label">Motif de la reprise</label>
                                        <textarea id="reopen-{{ $operation->id }}" name="comment" class="gc-input" rows="2" maxlength="2000" required></textarea>
                                        <button type="submit" class="gc-btn-primary">Remettre en prise de RDV</button>
                                    </form>
                                </details>
                            @endif
                            @unless ($operation->inspected_on)
                            <details class="text-sm">
                                <summary class="cursor-pointer text-slate-500">Liaison existante</summary>
                                <a href="{{ route('v2.dossiers.appointments.index', [$dossier, $operation]) }}" class="mt-2 inline-block underline">Rattacher un RDV déjà créé</a>
                            </details>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
        <section id="rendez-vous" class="gc-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg font-semibold">Rendez-vous et lots <span class="text-sm font-normal text-slate-500">{{ $appointments->total() }}</span></h2><p class="mt-1 text-sm text-slate-500">Les mêmes rendez-vous que dans le planning. Un créneau passé ne signifie pas que le contrôle a été effectué.</p></div>
                <a href="{{ route($planningRoute) }}" class="gc-btn-secondary">Ouvrir le planning</a>
            </div>
            <ul class="mt-5 divide-y divide-slate-100">
                @forelse ($appointments as $appointment)
                    <li class="grid gap-4 py-5 lg:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-500">RDV #{{ $appointment->id }} · Prestation #{{ $appointment->dossier_operation_id }}</p>
                            <p class="mt-1 font-semibold">{{ $appointment->starts_at?->format('d/m/Y H:i') }} à {{ $appointment->ends_at?->format('H:i') }}</p>
                            <p class="mt-1 text-sm">{{ $appointment->service->name }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $appointment->technician?->full_name ?: 'Technicien indisponible' }}</p>
                            @if ($appointment->trashed())
                                <span class="mt-2 inline-block rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">Retiré du planning</span>
                            @elseif ($appointment->status === \App\Models\Appointment::STATUS_PROBLEM)
                                <span class="mt-2 inline-block rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-800">Problème RDV</span>
                            @else
                                <span class="mt-2 inline-block rounded-full bg-sky-50 px-3 py-1 text-xs text-sky-800">{{ $appointment->ends_at?->isPast() ? 'Créneau passé' : 'Planifié' }}</span>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="break-words text-sm">{{ $appointment->address }}</p>
                            @foreach ($appointment->lotAppointments as $row)
                                @if ($row->lot)<a class="mt-2 block text-sm underline" href="{{ route('manager.lots.show', $row->lot) }}">Lot {{ $row->lot->name }} · Ligne {{ $row->row_number ?? $row->id }}</a>@endif
                            @endforeach
                            @if ($appointment->comment)<details class="mt-3 text-sm"><summary class="cursor-pointer text-slate-600">Commentaire du rendez-vous</summary><x-comment-body :body="$appointment->comment" class="mt-2 max-h-48 overflow-y-auto" /></details>@endif
                        </div>
                        <div class="space-y-3">
                            @unless ($appointment->trashed())
                                <a class="gc-btn-secondary" href="{{ route($planningRoute, ['appointment_id' => $appointment->id, 'technician_id' => $appointment->technician_id, 'date' => $appointment->starts_at?->toDateString()]) }}">Voir / modifier dans le planning</a>
                            @endunless
                            @unless ($dossier->operations->firstWhere('id', $appointment->dossier_operation_id)?->inspected_on)
                            <details class="text-sm">
                                <summary class="cursor-pointer text-slate-500">Corriger le rattachement</summary>
                                <p class="mt-2 text-xs text-slate-500">Retire uniquement le lien avec ce dossier. Le RDV, les documents et le lot ne sont pas supprimés.</p>
                                <form class="mt-3" method="POST" action="{{ route('v2.dossiers.appointments.destroy', [$dossier, $appointment->dossier_operation_id, $appointment->id]) }}">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="version" value="{{ $dossier->version }}">
                                    <button class="text-sm font-semibold text-rose-700 underline" type="submit">Retirer la liaison</button>
                                </form>
                            </details>
                            @endunless
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-sm text-slate-500">Aucun rendez-vous lié. Utilise « Planifier le RDV » sur la prestation à traiter, ou « Liaison existante » si le rendez-vous a déjà été créé.</li>
                @endforelse
            </ul>
            {{ $appointments->links() }}
            @if ($legacyAppointments->total())
                <details class="mt-5 rounded-lg border border-slate-200 p-4">
                    <summary class="cursor-pointer text-sm font-semibold">Toutes les dates source Coffrac ({{ $legacyAppointments->total() }})</summary>
                    <p class="mt-2 text-xs text-slate-500">Inclut les lignes incomplètes conservées hors planning. Le technicien historique par visite n’est pas enregistré dans la source : les RDV importés reprennent celui du dossier.</p>
                    <ul class="mt-3 divide-y divide-slate-100">
                        @foreach ($legacyAppointments as $record)
                            @php($sourceRdv = json_decode($record->payload, true))
                            <li class="py-2 text-sm">#{{ $record->source_id }} · {{ $sourceRdv['date_rdv'] ?: 'Date manquante' }} {{ $sourceRdv['heure'] ?: 'Heure manquante' }} · {{ $sourceRdv['rdv_duree'] }} h · Fiche {{ $sourceRdv['dossier_fiche'] }}</li>
                        @endforeach
                    </ul>
                    {{ $legacyAppointments->links() }}
                </details>
            @endif
        </section>
        @include('crm.dossiers.post-visit')
        <section id="capcee" class="gc-card p-5" data-capcee-status-url="{{ route('v2.capcee.status', $dossier) }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg font-semibold">Rapports CAPCEE</h2><p class="mt-1 text-sm text-slate-500">Transmission des rapports validés à TotalEnergies, distincte du planning et de Global+.</p></div>
                <button type="button" class="gc-btn-secondary" data-capcee-refresh>Actualiser le suivi</button>
            </div>
            <p data-capcee-feedback role="status" class="mt-2 text-sm text-slate-500"></p>
            <div data-capcee-results>@include('crm.capcee.reports')</div>
        </section>
        <div id="documents" class="space-y-6">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                <p class="font-semibold">Visibilité des documents et commentaires</p>
                <p class="mt-1 text-slate-600">Privé : équipe interne uniquement. Public : également visible par le client ou délégataire affecté, selon les droits de son compte. Aucun accès anonyme ni partage avec les autres clients.</p>
                <p class="mt-1 text-slate-600">Les droits de lecture des documents et des commentaires se règlent séparément dans la gestion du client. L’historique de traitement reste interne.</p>
            </div>
            @if ($legacyDocuments->total())
                <section class="gc-card p-5">
                    <h2 class="text-lg font-semibold">Documents historiques Coffrac ({{ $legacyDocuments->total() }})</h2>
                    <p class="mt-1 text-sm text-slate-500">Copies privées collectées, sans accès à la production. Les fichiers non collectés ou vides restent signalés sans lien de téléchargement.</p>
                    <ul class="mt-4 divide-y divide-slate-100">
                        @foreach ($legacyDocuments as $record)
                            <li class="flex flex-wrap items-start justify-between gap-4 py-3 text-sm">
                                <div class="min-w-0 space-y-2">
                                    <p class="break-words font-medium">{{ $record['name'] }}</p>
                                    <x-dossier-sharing :action="route('v2.dossiers.collected-documents.sharing', [$dossier, $record['source_id']])"
                                        :visible="$dossier->delegataire?->kind === 'delegataire' ? $record['delegataire_visible'] : $record['client_visible']"
                                        :label="$record['name']"
                                        :hint="$record['client_visible'] && ! $record['delegataire_visible'] ? 'Règle Coffrac conservée : non partagé avec les délégataires.' : null" />
                                </div>
                                @if ($record['url'])
                                    <a href="{{ $record['url'] }}" class="shrink-0 underline" aria-label="Télécharger {{ $record['name'] }}">Télécharger</a>
                                @else
                                    <span class="text-slate-500">Fichier non collecté ou indisponible</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    {{ $legacyDocuments->links() }}
                </section>
            @endif
            @if ($lotDocuments->total() > 0)
                <section id="documents-lots" class="gc-card p-5">
                    <h2 class="text-lg font-semibold">Documents des lots liés <span class="text-sm font-normal text-slate-500">{{ $lotDocuments->total() }}</span></h2>
                    <p class="mt-1 text-sm text-slate-500">Les fichiers d’origine, sans duplication. Le partage ci-dessous concerne l’espace client de TechCalendar ; il ne déclenche aucun envoi vers un CRM externe.</p>
                    <ul class="mt-4 divide-y divide-slate-100">
                        @foreach ($lotDocuments as $document)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div class="min-w-0 space-y-2"><p class="break-words font-medium">{{ $document->name }}</p><p class="text-xs text-slate-500">{{ $document->lotAppointment?->lot?->name ?: 'Lot d’origine' }} · {{ number_format($document->size / 1024, 1, ',', ' ') }} Ko</p>
                                    <x-dossier-sharing :action="route('v2.dossiers.lot-documents.sharing', [$dossier, $document->id])"
                                        :visible="$document->client_visible && ! $document->is_private" :label="$document->name" />
                                </div>
                                @if ($document->disk === 'local')<a href="{{ route('v2.dossiers.lot-documents.download', [$dossier, $document->id]) }}" class="text-sm underline" aria-label="Télécharger {{ $document->name }}">Télécharger</a>@endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-3">{{ $lotDocuments->links() }}</div>
                </section>
            @endif
            <div class="grid items-start gap-6 xl:grid-cols-2">
                <section id="documents-dossier" class="gc-card min-w-0 scroll-mt-24 p-5">
                    <h2 class="text-lg font-semibold">Documents du dossier <span class="text-sm font-normal text-slate-500">{{ $documents->total() }}</span></h2>
                    <p class="mt-1 text-sm text-slate-500">Stockage privé. Un document ou commentaire reste interne sauf partage explicite avec le client affecté.</p>
                    <ul class="mt-4 divide-y divide-slate-100">
                        @forelse ($documents as $document)
                            <li class="flex flex-wrap items-start justify-between gap-4 py-3">
                                <div class="min-w-0 space-y-2"><p class="break-words font-medium">{{ $document->name }}</p><p class="text-xs text-slate-500">{{ number_format($document->size / 1024, 1, ',', ' ') }} Ko · {{ $document->created_at->format('d/m/Y H:i') }}</p>
                                    <x-dossier-sharing :action="route('v2.documents.sharing', $document)" :visible="$document->client_visible" :label="$document->name" />
                                </div>
                                <a href="{{ route('v2.documents.download', $document) }}" class="shrink-0 text-sm underline" aria-label="Télécharger {{ $document->name }}">Télécharger</a>
                            </li>
                        @empty
                            <li class="py-5 text-sm text-slate-500">Aucun document enregistré.</li>
                        @endforelse
                    </ul>
                    <div class="mt-3">{{ $documents->links() }}</div>
                    <details class="mt-4 rounded-xl border border-slate-200 p-4" @if ($errors->has('document') || $errors->has('name')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold">Ajouter un document</summary>
                        <form class="mt-4 space-y-4" method="POST" enctype="multipart/form-data" action="{{ route('v2.dossiers.documents.store', $dossier) }}">
                            @csrf
                            <div><label for="document_name" class="gc-label">Nom du document</label><input id="document_name" name="name" value="{{ old('name') }}" maxlength="190" class="gc-input" required></div>
                            <div><label for="document" class="gc-label">Fichier (10 Mo maximum)</label><input id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" class="block w-full min-w-0 text-sm" required><p class="mt-2 text-xs text-slate-500">PDF, image, Word ou Excel.</p></div>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="client_visible" value="1">Public : partager avec le client affecté</label><p class="text-xs text-slate-500">Décoché : document privé, réservé à l’équipe interne.</p><button type="submit" class="gc-btn-primary">Ajouter au dossier</button>
                        </form>
                    </details>
                </section>
                <section id="historique" class="gc-card min-w-0 p-5">
                    <h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="comment" />Commentaires et historique</h2>
                    <p class="mt-1 text-sm text-slate-500">Échanges internes et traçabilité des modifications.</p>
                    <form method="POST" action="{{ route('v2.dossiers.comments.store', $dossier) }}" class="mt-4 space-y-3">
                        @csrf
                        <label for="comment_body" class="gc-label">Commentaire (privé par défaut)</label>
                        <textarea id="comment_body" name="body" rows="3" maxlength="10000" class="gc-input" required>{{ old('body') }}</textarea>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="client_visible" value="1">Public : partager avec le client affecté</label><p class="text-xs text-slate-500">Visible uniquement si son compte autorise la lecture des commentaires. Les délégataires n’ont pas ce droit par défaut.</p><button type="submit" class="gc-btn-soft">Ajouter le commentaire</button>
                    </form>
                    <ol class="mt-6 space-y-5 border-l border-slate-200 pl-4">
                        @foreach ($activities as $activity)
                            <li>
                                <p class="text-xs text-slate-500">{{ $activity->created_at?->format('d/m/Y H:i') }} · {{ $activity->author?->full_name ?: (str_starts_with($activity->kind, 'legacy_') ? 'Auteur Coffrac #'.($activity->changed_fields['source_author_id'] ?? '?') : 'Système') }}</p>
                                <x-comment-body :body="$activity->body" class="mt-1 text-sm" />
                                @if (in_array($activity->kind, ['comment', 'legacy_comment', 'post_visit'], true))
                                    <div class="mt-2"><x-dossier-sharing :action="route('v2.dossiers.comments.sharing', [$dossier, $activity->id])"
                                        :visible="app(\App\Services\Crm\ClientAccess::class)->commentVisibleToClient($activity, $dossier->delegataire?->kind ?? 'client')"
                                        :label="'Commentaire #'.$activity->id" /></div>
                                @else
                                    <p class="mt-1 text-xs text-slate-500">Historique interne</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-4">{{ $activities->links() }}</div>
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>
