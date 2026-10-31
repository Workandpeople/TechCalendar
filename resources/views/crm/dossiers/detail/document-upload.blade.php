<x-dossier-dialog id="documents-dossier" title="Ajouter un document ou un rapport" :reopen="$errors->any() && (old('_form') === 'document' || (! old('_form') && $errors->has('document')))">
    <form method="POST" enctype="multipart/form-data" action="{{ route('v2.dossiers.documents.store', $dossier) }}" class="mt-4 space-y-4" data-document-upload data-can-review="{{ auth()->user()->can('reviewControl', $dossier) ? '1' : '0' }}">
        @csrf
        <input type="hidden" name="_form" value="document">
        <input type="hidden" name="version" value="{{ $dossier->version }}">
        <fieldset class="gc-document-tabs">
            <legend class="sr-only">Type de document</legend>
            <label><input type="radio" name="document_type" value="free" @checked(old('document_type', 'free') === 'free')><x-nav-icon name="document" />Document libre</label>
            <label><input type="radio" name="document_type" value="visit" @checked(old('document_type') === 'visit')><x-nav-icon name="planning" />Visite technique</label>
            <label><input type="radio" name="document_type" value="report" @checked(old('document_type') === 'report')><x-nav-icon name="check" />Rapport de contrôle</label>
        </fieldset>
        <div data-document-name><label for="document_name" class="gc-label">Nom du document *</label><input id="document_name" name="name" maxlength="190" class="gc-input" required value="{{ old('name') }}" placeholder="Ex. Facture travaux, plan…"><p class="text-xs text-slate-500">Le nom du bénéficiaire sera ajouté automatiquement.</p></div>
        <fieldset data-operation-fields class="gc-report-fields grid gap-4 sm:grid-cols-2" @disabled(old('document_type', 'free') === 'free') @if (old('document_type', 'free') === 'free') hidden @endif>
            <legend class="sr-only">Informations du rapport</legend>
            <div class="sm:col-span-2" data-operation-picker><label class="gc-label" for="report-operation">Prestation concernée *</label>
                <select class="gc-input" id="report-operation" name="operation_id" required data-report-operation><option value="">Choisir une fiche</option>
                    @foreach ($dossier->operations as $operation)
                        @php($state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'])
                        <option value="{{ $operation->id }}" data-state="{{ $state }}" @selected(old('operation_id', $dossier->operations->count() === 1 ? $operation->id : null) == $operation->id)>{{ $operation->service?->name ?: $operation->legacy_service_name }} · {{ \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <p class="text-xs text-slate-500 sm:col-span-2" data-operation-summary hidden></p>
        </fieldset>
        <fieldset data-visit-fields class="grid gap-4" disabled hidden>
            <legend class="sr-only">Visite effectuée</legend>
            <div><label class="gc-label" for="visit-appointment">Rendez-vous réalisé *</label><select id="visit-appointment" class="gc-input" name="appointment_id" required data-visit-appointment><option value="">Choisir le rendez-vous</option>
                @foreach ($visitCandidates->flatten(1) as $candidate)
                    <option value="{{ $candidate->id }}" data-operation="{{ $candidate->dossier_operation_id }}" @selected(old('appointment_id') == $candidate->id)>{{ $candidate->starts_at->format('d/m/Y H:i') }}</option>
                @endforeach
            </select></div>
        </fieldset>
        <fieldset data-report-fields class="gc-report-fields grid gap-4 sm:grid-cols-2" disabled hidden>
            <legend class="sr-only">Avis du rapport</legend>
            <div class="sm:col-span-2"><label class="gc-label" for="control-reference">Référence du contrôle *</label><input class="gc-input" id="control-reference" name="control_reference" maxlength="190" value="{{ old('control_reference') }}" required><p class="text-xs text-slate-500">Nom automatique : Rapport de contrôle - référence du contrôle.</p></div>
            <div><label class="gc-label" for="report-inspected">Date du rendez-vous réalisé *</label><input class="gc-input" id="report-inspected" name="inspected_on" type="date" data-date-picker max="{{ now()->toDateString() }}" value="{{ old('inspected_on') }}" required></div>
            <div><label class="gc-label" for="report-issued">Date d’émission *</label><input class="gc-input" id="report-issued" name="issued_on" type="date" data-date-picker max="{{ now()->toDateString() }}" value="{{ old('issued_on') }}" required></div>
            <div><label class="gc-label" for="report-outcome">Avis du rapport *</label><select class="gc-input" id="report-outcome" name="outcome" required><option value="">Choisir</option>@foreach (\App\Services\Crm\PostVisitWorkflow::OUTCOMES as $key => $label)<option value="{{ $key }}" @selected(old('outcome') === $key)>{{ $label }}</option>@endforeach</select></div>
            <p class="text-xs text-slate-500 sm:col-span-2">L’avis met à jour la satisfaction physique du lot lié, sans décider de l’état.</p>
            @if ($dossier->delegataire?->kind === 'delegataire' && $dossier->delegataire->capcee_enabled)
                <fieldset class="sm:col-span-2 space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-3">
                    <legend class="font-semibold">Transmission automatique CAPCEE</legend>
                    <p class="text-xs">Ce PDF sera préparé pour CAPCEE après validation en « Rapport Genius ». Si ces identifiants manquent, le rapport est conservé localement et la transmission reste bloquée. Aucun email bénéficiaire.</p>
                    <label class="gc-label">Référence EMMY (distincte de la référence du contrôle)<input class="gc-input" name="capcee_emmy_reference" maxlength="255" value="{{ old('capcee_emmy_reference') }}"></label>
                    <label class="gc-label">Objet CAPCEE<select class="gc-input" name="capcee_object_type"><option value="">À renseigner</option>@foreach (['Operation__c' => 'Opération', 'Action__c' => 'Action'] as $key => $label)<option value="{{ $key }}" @selected(old('capcee_object_type') === $key)>{{ $label }}</option>@endforeach</select></label>
                    <label class="gc-label">Type d’inspection<select class="gc-input" name="capcee_inspection_type"><option value="">À renseigner</option><option value="site" @selected(old('capcee_inspection_type') === 'site')>Sur site</option><option value="contact" @selected(old('capcee_inspection_type') === 'contact')>Par contact</option></select></label>
                </fieldset>
            @endif
        </fieldset>
        <p class="text-sm font-medium" role="status" data-document-effect></p>
        <div><label class="gc-label" for="document-description">Description / observations</label><textarea id="document-description" name="description" class="gc-input" rows="3" maxlength="10000">{{ old('description') }}</textarea></div>
        <label class="gc-file-drop" for="document"><x-nav-icon name="upload" /><span><strong>Choisir ou déposer un fichier</strong><small>{{ \App\Services\DocumentUpload::LIMIT_LABEL }} maximum par fichier · PDF pour les rapports, images ou documents Office pour les pièces libres.</small></span>
            <input id="document" name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" class="block w-full min-w-0 text-sm" data-document-max-bytes="{{ \App\Services\DocumentUpload::MAX_BYTES }}">
        </label>
        <x-content-audience :value="old('audience', 'client')" />
        <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button type="submit" class="gc-btn-primary"><x-nav-icon name="upload" />Enregistrer le document</button></footer>
    </form>
</x-dossier-dialog>
