@php
    $controlState = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
    $outcomes = \App\Services\Crm\PostVisitWorkflow::OUTCOMES;
    $candidates = $visitCandidates->get($operation->id, collect());
    $canReview = auth()->user()->can('reviewControl', $dossier);
    $failedOperation = (string) old('_operation') === (string) $operation->id;
@endphp
                @foreach (\App\Services\Crm\PostVisitWorkflow::TRANSITIONS as $action => $transition)
                    @continue (! in_array($controlState, $transition['from'], true) || ($transition['review'] && ! $canReview))
                    @if ($action === 'record_visit' && $candidates->isEmpty())
                        <p class="mt-4 text-sm text-slate-500">Aucun créneau terminé : la visite pourra être confirmée après le rendez-vous. Les rendez-vous en problème doivent d’abord être traités dans le planning.</p>
                        @continue
                    @endif
                    @if ($action === 'reopen_control' && $operation->capceeReports->isNotEmpty())
                        <p class="mt-4 text-sm text-slate-500">Un envoi CAPCEE est lié : sa correction doit passer par une nouvelle version du rapport, pas par un retrait de validation locale seul.</p>
                        @continue
                    @endif
                    @php
                        $actionLabel = match ($action) {
                            'record_visit' => 'Confirmer la visite effectuée',
                            'submit_control' => 'Saisir la conclusion et soumettre au RT',
                            'revise_control' => 'Renseigner / corriger la conclusion',
                            'request_correction' => 'Demander une correction au client',
                            'validate_report' => 'Valider le rapport et mettre à jour le lot',
                            'reopen_control' => 'Retirer la validation pour réexamen',
                        };
                        $failedForm = $failedOperation && old('action') === $action;
                        $prefix = 'control-'.$operation->id.'-'.$action;
                        $actionStyle = match ($action) {
                            'validate_report', 'record_visit' => 'gc-btn-success',
                            'request_correction' => 'gc-btn-warning',
                            'reopen_control' => 'gc-btn-danger',
                            default => 'gc-btn-primary',
                        };
                        $actionIcon = match ($action) {
                            'validate_report', 'record_visit' => 'check',
                            'request_correction', 'reopen_control' => 'alert',
                            default => 'edit',
                        };
                    @endphp
                    <details class="gc-control-action mt-4 rounded-lg border border-slate-200 p-3" data-control-action="{{ $action }}" @if ($failedForm || $action === 'submit_control') open @endif>
                        <summary class="cursor-pointer text-sm font-semibold"><span class="inline-flex items-center gap-2"><x-nav-icon :name="$actionIcon" />{{ $actionLabel }}</span></summary>
                        <form method="POST" action="{{ route('v2.dossiers.operations.post-visit', [$dossier, $operation]) }}" class="mt-4 space-y-4">
                            @csrf
                            <input type="hidden" name="version" value="{{ $dossier->version }}">
                            <input type="hidden" name="action" value="{{ $action }}">
                            <input type="hidden" name="_operation" value="{{ $operation->id }}">
                            @if ($action === 'record_visit')
                                <div><label class="gc-label" for="{{ $prefix }}-rdv">Rendez-vous effectué</label>
                                    <select class="gc-input" id="{{ $prefix }}-rdv" name="appointment_id" required>
                                        @foreach ($candidates as $candidate)<option value="{{ $candidate->id }}" @selected($failedForm && (int) old('appointment_id') === $candidate->id)>{{ $candidate->starts_at->format('d/m/Y H:i') }} · RDV #{{ $candidate->id }}</option>@endforeach
                                    </select>
                                </div>
                            @endif
                            @if (in_array($action, ['submit_control', 'revise_control'], true) || ($action === 'validate_report' && $controlState === 'recontrol'))
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div><label class="gc-label" for="{{ $prefix }}-date">Date réelle de la visite</label><input class="gc-input" type="date" id="{{ $prefix }}-date" name="inspected_on" max="{{ now()->toDateString() }}" value="{{ $failedForm ? old('inspected_on') : $operation->inspected_on?->toDateString() }}" required></div>
                                    <div><label class="gc-label" for="{{ $prefix }}-outcome">Conclusion du contrôle</label><select class="gc-input" id="{{ $prefix }}-outcome" name="outcome" required><option value="">Choisir une conclusion</option>@foreach ($outcomes as $value => $label)<option value="{{ $value }}" @selected(($failedForm ? old('outcome') : $operation->control_outcome) === $value)>{{ $label }}</option>@endforeach</select></div>
                                </div>
                            @endif
                            @if ($action === 'validate_report')
                                <p class="text-sm text-slate-600">Vérifie la conclusion et le PDF avant validation. Le document reste dans le dossier, sans copie ni envoi vers Coffrac, Global+ ou CAPCEE.</p>
                                <div><label class="gc-label" for="{{ $prefix }}-pdf">Rapport PDF du dossier</label><select class="gc-input" id="{{ $prefix }}-pdf" name="report_document_id" required><option value="">Choisir le rapport</option>@foreach ($controlDocuments as $pdf)<option value="{{ $pdf->id }}" @selected($failedForm && (int) old('report_document_id') === $pdf->id)>{{ $pdf->name }}</option>@endforeach</select></div>
                                @if ($controlDocuments->isEmpty())<p class="text-sm text-amber-800">Ajoute d’abord le PDF dans <a href="#documents-dossier" class="underline">Documents du dossier</a>.</p>@endif
                            @endif
                            @if ($action === 'reopen_control')<p class="text-sm text-amber-800">La satisfaction physique sera retirée des statistiques jusqu’à la prochaine validation. Le rapport précédent et l’historique sont conservés.</p>@endif
                            <div><label class="gc-label" for="{{ $prefix }}-comment">{{ in_array($action, ['submit_control', 'revise_control'], true) ? 'Observations du contrôle' : 'Commentaire / motif' }} <span class="text-slate-500">(obligatoire)</span></label><textarea class="gc-input" id="{{ $prefix }}-comment" name="comment" rows="3" maxlength="10000" required>{{ $failedForm ? old('comment') : '' }}</textarea></div>
                            <div>
                                <x-content-audience :value="$failedForm ? old('audience', 'internal') : ($action === 'request_correction' ? 'client' : 'internal')" />
                                @if ($action === 'request_correction')<p class="mt-2 text-sm text-slate-600">Indique précisément ce que le client doit corriger. Le dossier restera en non-conformité jusqu’à sa transmission pour recontrôle.</p>@endif
                            </div>
                            <div class="flex justify-end"><button type="submit" class="{{ $actionStyle }}" @disabled($action === 'validate_report' && ($controlDocuments->isEmpty() || ($controlState !== 'recontrol' && ! $operation->control_submitted_at)))><x-nav-icon :name="$actionIcon" />{{ $actionLabel }}</button></div>
                        </form>
                    </details>
                @endforeach
