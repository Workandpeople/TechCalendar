<details id="documents-dossier" class="gc-dossier-upload" @if ($errors->any()) open @endif>
    <summary class="cursor-pointer"><x-nav-icon name="upload" />Ajouter un document ou un rapport</summary>
    <form method="POST" enctype="multipart/form-data" action="{{ route('v2.dossiers.documents.store', $dossier) }}" class="mt-4 space-y-4" data-document-upload>
        @csrf
        <input type="hidden" name="version" value="{{ $dossier->version }}">
        <fieldset class="gc-document-tabs">
            <legend class="sr-only">Type de document</legend>
            <label><input type="radio" name="document_type" value="free" @checked(old('document_type', 'free') === 'free')><x-nav-icon name="document" />Document libre</label>
            <label><input type="radio" name="document_type" value="report" @checked(old('document_type') === 'report')><x-nav-icon name="check" />Rapport de contrôle</label>
        </fieldset>
        <div><label for="document_name" class="gc-label">Nom du document *</label><input id="document_name" name="name" maxlength="190" class="gc-input" required value="{{ old('name') }}" placeholder="Ex. Facture travaux, rapport de visite…"></div>
        <fieldset data-report-fields class="gc-report-fields grid gap-4 sm:grid-cols-2" @disabled(old('document_type', 'free') !== 'report') @if (old('document_type', 'free') !== 'report') hidden @endif>
            <legend class="sr-only">Informations du rapport</legend>
            <div class="sm:col-span-2"><label class="gc-label" for="report-operation">Fiche / prestation *</label>
                <select class="gc-input" id="report-operation" name="operation_id" required data-report-operation><option value="">Choisir une fiche</option>
                    @foreach ($dossier->operations as $operation)
                        @php($state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'])
                        <option value="{{ $operation->id }}" data-state="{{ $state }}" @selected(old('operation_id') == $operation->id)>#{{ $operation->id }} · {{ $operation->service?->name ?: $operation->legacy_service_name }} · {{ \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2"><label class="gc-label" for="control-reference">Référence du contrôle *</label><input class="gc-input" id="control-reference" name="control_reference" maxlength="190" value="{{ old('control_reference') }}" required></div>
            <div><label class="gc-label" for="report-inspected">Date du rendez-vous réalisé *</label><input class="gc-input" id="report-inspected" name="inspected_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('inspected_on') }}" required></div>
            <div><label class="gc-label" for="report-issued">Date d’émission *</label><input class="gc-input" id="report-issued" name="issued_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('issued_on') }}" required></div>
            <div><label class="gc-label" for="report-outcome">Statut / conclusion *</label><select class="gc-input" id="report-outcome" name="outcome" required><option value="">Choisir</option>@foreach (\App\Services\Crm\PostVisitWorkflow::OUTCOMES as $key => $label)<option value="{{ $key }}" @selected(old('outcome') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div><label class="gc-label" for="report-action">Après l’ajout</label><select class="gc-input" id="report-action" name="workflow_action" data-report-action>
                <option value="store" @selected(old('workflow_action', 'store') === 'store')>Enregistrer sans changer l’état</option>
                <option value="submit_control" data-states="visited" @selected(old('workflow_action') === 'submit_control')>Soumettre au contrôle RT</option>
                @can('reviewControl', $dossier)
                    <option value="revise_control" data-states="rt_control" @selected(old('workflow_action') === 'revise_control')>Actualiser la conclusion RT</option>
                    <option value="validate_report" data-states="rt_control recontrol" @selected(old('workflow_action') === 'validate_report')>Valider le rapport et mettre à jour le lot</option>
                @endcan
            </select></div>
            <p class="text-xs text-slate-500 sm:col-span-2">La validation suit le workflow existant et vos droits. Un simple dépôt ne change ni l’état ni la satisfaction du lot. L’envoi CAPCEE reste une action séparée.</p>
        </fieldset>
        <div><label class="gc-label" for="document-description">Description / observations</label><textarea id="document-description" name="description" class="gc-input" rows="3" maxlength="10000">{{ old('description') }}</textarea></div>
        <label class="gc-file-drop" for="document"><x-nav-icon name="upload" /><span><strong>Choisir ou déposer un fichier</strong><small>10 Mo maximum · PDF pour les rapports, images ou documents Office pour les pièces libres.</small></span>
            <input id="document" name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" class="block w-full min-w-0 text-sm">
        </label>
        <x-content-audience :value="old('audience', 'internal')" />
        <div class="flex justify-end"><button type="submit" class="gc-btn-primary"><x-nav-icon name="upload" />Enregistrer le document</button></div>
    </form>
</details>
