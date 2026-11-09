<x-layouts.app>
    @php
        $canBill = auth()->user()->can('reviewBilling', \App\Models\TechnicianIncident::class);
        $planningStatuses = \App\Models\TechnicianIncident::PLANNING_STATUSES;
        $billingStatuses = \App\Models\TechnicianIncident::BILLING_STATUSES;
    @endphp
    <div class="space-y-5">
        <x-workspace-heading title="Signalements techniciens" eyebrow="Suivi des interventions" description="Photos et signalements sur place, à traiter avec la société concernée. Le suivi comptable reste indépendant du traitement planning." />
        @if (session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-3 text-emerald-900">{{ session('status') }}</p>@endif
        @if ($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-3 text-rose-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <nav aria-label="Files de suivi" class="flex flex-wrap gap-3">
            <a class="gc-btn-secondary" href="{{ route('planner.incidents') }}">À traiter <strong data-incident-pending-count>{{ $counts['pending'] }}</strong></a>
            <a class="gc-btn-secondary" href="{{ route('planner.incidents', ['status' => 'all']) }}">Tous les signalements</a>
            @if ($canBill)
                <a class="gc-btn-secondary" href="{{ route('planner.incidents', ['status' => 'all', 'billing' => 'review']) }}">Déplacements à vérifier</a>
                <a class="gc-btn-secondary" href="{{ route('planner.incidents', ['status' => 'all', 'billing' => 'billable']) }}">À facturer</a>
                <span class="self-center text-sm text-amber-900"><strong data-incident-billing-count>{{ $counts['billing'] }}</strong> déplacement(s) à suivre</span>
            @endif
        </nav>
        <form action="{{ route('planner.incidents') }}" method="GET" class="gc-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="gc-label">Rechercher<input class="gc-input" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="150" placeholder="Bénéficiaire, adresse, technicien…"></label>
            <label class="gc-label">Suivi planning<select name="status" class="gc-input"><option value="all" @selected(($filters['status'] ?? 'pending') === 'all')>Tous</option>@foreach ($planningStatuses as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? 'pending') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="gc-label">Motif<select name="reason" class="gc-input"><option value="">Tous les motifs</option>@foreach (\App\Services\Tech\TechnicianOnSiteReports::REASONS as $key => $label)<option value="{{ $key }}" @selected(($filters['reason'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="gc-label">Signalé du<input class="gc-input" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label class="gc-label">Au<input class="gc-input" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            @if ($canBill)
                <label class="gc-label">Comptabilité<select name="billing" class="gc-input"><option value="">Tous les déplacements</option>@foreach ($billingStatuses as $key => $label)<option value="{{ $key }}" @selected(($filters['billing'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            @endif
            @if (isset($filters['dossier_id']))<input type="hidden" name="dossier_id" value="{{ $filters['dossier_id'] }}">@endif
            <div class="flex flex-wrap gap-3 sm:col-span-2 lg:col-span-3"><button class="gc-btn-primary" type="submit">Rechercher</button><a class="gc-btn-secondary" href="{{ route('planner.incidents') }}">Réinitialiser</a></div>
        </form>
        <p class="text-sm text-slate-600">{{ $incidents->total() }} signalement(s) · Du plus récent au plus ancien</p>
        <div class="space-y-4">
            @forelse ($incidents as $incident)
                @php
                    $reopenPlanning = old('_form') === 'incident-'.$incident->id.'-planning';
                    $reopenBilling = old('_form') === 'incident-'.$incident->id.'-billing';
                    $oldNote = is_string(old('note')) ? old('note') : '';
                @endphp
                <article id="incident-{{ $incident->id }}" class="gc-card overflow-hidden" aria-labelledby="incident-title-{{ $incident->id }}">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 bg-slate-50 p-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Signalement #{{ $incident->id }} · {{ $incident->reported_at->format('d/m/Y à H:i') }}</p>
                            <h2 id="incident-title-{{ $incident->id }}" class="mt-1 text-lg font-semibold">{{ $incident->dossier->beneficiaryLabel() }}</h2>
                            <p class="text-sm text-slate-600">{{ $incident->dossier->client?->name ?: $incident->dossier->delegataire?->name ?: 'Société non renseignée' }} · {{ $incident->dossier->reference }}</p>
                        </div>
                        <span class="rounded-lg px-3 py-1 text-sm font-semibold {{ $incident->planning_status === 'pending' ? 'bg-rose-100 text-rose-900' : 'bg-emerald-100 text-emerald-900' }}">{{ $planningStatuses[$incident->planning_status] }}</span>
                    </header>
                    <div class="grid gap-4 p-4 lg:grid-cols-2">
                        <div class="space-y-2">
                            <p class="font-semibold">{{ $incident->reasonLabel() }}</p>
                            <p class="text-sm">{{ $incident->technician?->full_name ?: 'Technicien non renseigné' }} · RDV du {{ $incident->appointment_starts_at?->format('d/m/Y à H:i') ?: 'Date non renseignée' }}</p>
                            <p class="text-sm text-slate-600">{{ $incident->dossier->address }} · {{ $incident->dossier->postal_code }} {{ $incident->dossier->city }}</p>
                            @if (filled($incident->comment))<p class="whitespace-pre-line break-words text-sm">{{ $incident->comment }}</p>@endif
                            <div class="flex flex-wrap gap-2 pt-2">
                                <a class="gc-btn-secondary" href="{{ route('v2.dossiers.show', $incident->dossier) }}">Ouvrir le dossier</a>
                                @if ($incident->document && ! $incident->document->trashed() && ! $incident->dossier->trashed())
                                    <a class="gc-btn-secondary" href="{{ route('v2.documents.download', ['document' => $incident->document, 'preview' => 1]) }}">Voir la photo</a>
                                @else
                                    <span class="self-center text-xs text-slate-500">Photo conservée dans les archives du dossier.</span>
                                @endif
                            </div>
                        </div>
                        <div class="space-y-3">
                            @if ($canBill)<p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-950">{{ $billingStatuses[$incident->billing_status] }}</p>@endif
                            <details class="rounded-lg border border-slate-200 p-3" @if ($reopenPlanning) open @endif>
                                <summary class="cursor-pointer font-semibold">Suivi planning{{ $incident->planning_status === 'pending' ? ' · À traiter' : ' · Traité' }}</summary>
                                @if ($incident->handler)<p class="mt-2 text-xs text-slate-500">Dernier suivi par {{ $incident->handler->full_name }} · {{ $incident->handled_at?->format('d/m/Y H:i') }}</p>@endif
                                <form method="POST" action="{{ route('planner.incidents.update', $incident) }}" class="mt-3 space-y-3">
                                    @csrf @method('PATCH')<input type="hidden" name="section" value="planning"><input type="hidden" name="version" value="{{ $incident->version }}">
                                    <input type="hidden" name="_form" value="incident-{{ $incident->id }}-planning">
                                    <label class="gc-label">État du traitement<select class="gc-input" name="status">@foreach ($planningStatuses as $key => $label)<option value="{{ $key }}" @selected($incident->planning_status === $key)>{{ $label }}</option>@endforeach</select></label>
                                    <label class="gc-label">Note interne de suivi<textarea class="gc-input" rows="3" name="note" maxlength="2000" required>{{ $reopenPlanning ? $oldNote : $incident->planning_note }}</textarea></label>
                                    <button type="submit" class="gc-btn-primary">Enregistrer le suivi</button>
                                </form>
                            </details>
                            @if ($canBill)
                                <details class="rounded-lg border border-amber-200 p-3" @if ($reopenBilling) open @endif>
                                    <summary class="cursor-pointer font-semibold">Suivi du déplacement · Comptabilité</summary>
                                    <p class="mt-2 text-xs text-slate-600">Décision manuelle, distincte de la facturation du dossier. Ne crée ni facture ni paiement.</p>
                                    @if ($incident->billingReviewer)<p class="mt-2 text-xs text-slate-500">Dernier suivi par {{ $incident->billingReviewer->full_name }} · {{ $incident->billing_reviewed_at?->format('d/m/Y H:i') }}</p>@endif
                                    <form method="POST" action="{{ route('planner.incidents.update', $incident) }}" class="mt-3 space-y-3">
                                        @csrf @method('PATCH')<input type="hidden" name="section" value="billing"><input type="hidden" name="version" value="{{ $incident->version }}">
                                        <input type="hidden" name="_form" value="incident-{{ $incident->id }}-billing">
                                        <label class="gc-label">Décision<select name="status" class="gc-input">@foreach ($billingStatuses as $key => $label)<option value="{{ $key }}" @selected($incident->billing_status === $key)>{{ $label }}</option>@endforeach</select></label>
                                        <label class="gc-label">Note / référence de facture<textarea class="gc-input" name="note" rows="3" maxlength="2000" required>{{ $reopenBilling ? $oldNote : $incident->billing_note }}</textarea></label>
                                        <button class="gc-btn-primary" type="submit">Enregistrer la décision</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="gc-card p-6 text-center text-slate-600">Aucun signalement dans cette sélection.</div>
            @endforelse
        </div>
        {{ $incidents->links() }}
    </div>
</x-layouts.app>
