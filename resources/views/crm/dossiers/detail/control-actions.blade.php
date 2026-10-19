@php
    $controlState = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'];
    $canReview = auth()->user()->can('reviewControl', $dossier);
    $transitions = collect(\App\Services\Crm\PostVisitWorkflow::TRANSITIONS)->filter(fn ($transition, $action) => $action !== 'record_visit' && in_array($controlState, $transition['from'], true) && (! $transition['review'] || $canReview) && ! ($action === 'reopen_control' && $operation->capceeReports->isNotEmpty()));
    $failedOperation = (string) old('_operation') === (string) $operation->id;
@endphp
@if ($controlState === 'awaiting_visit')
    <p class="text-sm text-slate-600">Ajoute un document « Visite technique » après le rendez-vous pour passer en RDV visité.</p>
    <a href="#documents-dossier" class="gc-btn-primary mt-3"><x-nav-icon name="upload" />Ajouter la visite technique</a>
@endif
@if ($transitions->isNotEmpty())
    <form method="POST" action="{{ route('v2.dossiers.operations.post-visit', [$dossier, $operation]) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="version" value="{{ $dossier->version }}">
        <input type="hidden" name="_operation" value="{{ $operation->id }}">
        <div><label class="gc-label" for="state-{{ $operation->id }}">Nouvel état</label><select class="gc-input" id="state-{{ $operation->id }}" name="action" required>
            @foreach ($transitions as $action => $transition)
                <option value="{{ $action }}" @selected($failedOperation && old('action') === $action)>{{ \App\Services\Crm\DossierStatusCatalog::STATUSES[$transition['to']]['label'] }}</option>
            @endforeach
        </select></div>
        @if ($transitions->has('validate_report'))
            <div><label class="gc-label" for="report-{{ $operation->id }}">Rapport pour le passage en Rapport Genius</label><select class="gc-input" id="report-{{ $operation->id }}" name="report_document_id"><option value="">Choisir le rapport</option>
                @foreach ($controlDocuments->filter(fn ($pdf) => $pdf->document_type === 'report' && $pdf->dossier_operation_id === $operation->id) as $pdf)
                    <option value="{{ $pdf->id }}" @selected(($failedOperation ? (int) old('report_document_id') : $operation->report_document_id) === $pdf->id)>{{ $pdf->name }}</option>
                @endforeach
            </select></div>
        @endif
        <p class="text-xs text-slate-500">L’avis est renseigné uniquement lors du dépôt du rapport. Changer l’état ne change ni cet avis, ni la satisfaction du lot, ni la facturation.</p>
        <div><label class="gc-label" for="state-comment-{{ $operation->id }}">Motif (obligatoire)</label><textarea class="gc-input" id="state-comment-{{ $operation->id }}" name="comment" rows="2" maxlength="10000" required>{{ $failedOperation ? old('comment') : '' }}</textarea></div>
        <x-content-audience :value="$failedOperation ? old('audience', 'client') : 'client'" />
        <button type="submit" class="gc-btn-success"><x-nav-icon name="check" />Enregistrer l’état</button>
    </form>
@endif
@if ($operation->control_outcome)
    <p class="mt-3 text-sm">Avis du dernier rapport : <strong>{{ \App\Services\Crm\PostVisitWorkflow::OUTCOMES[$operation->control_outcome] ?? $operation->control_outcome }}</strong></p>
@endif
