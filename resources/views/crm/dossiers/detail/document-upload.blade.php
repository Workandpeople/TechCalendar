<details id="documents-dossier" class="gc-dossier-upload" @if ($errors->any()) open @endif>
    <summary class="cursor-pointer"><x-nav-icon name="upload" />Ajouter un document ou un rapport</summary>
    <form method="POST" enctype="multipart/form-data" action="{{ route('v2.dossiers.documents.store', $dossier) }}" class="mt-4 space-y-4" data-document-upload data-can-review="{{ auth()->user()->can('reviewControl', $dossier) ? '1' : '0' }}">
        @csrf
        <input type="hidden" name="version" value="{{ $dossier->version }}">
        <fieldset class="gc-document-tabs">
            <legend class="sr-only">Type de document</legend>
            <label><input type="radio" name="document_type" value="free" @checked(old('document_type', 'free') === 'free')><x-nav-icon name="document" />Document libre</label>
            <label><input type="radio" name="document_type" value="visit" @checked(old('document_type') === 'visit')><x-nav-icon name="planning" />Visite technique</label>
            <label><input type="radio" name="document_type" value="report" @checked(old('document_type') === 'report')><x-nav-icon name="check" />Rapport de contrôle</label>
        </fieldset>
        <div><label for="document_name" class="gc-label">Nom du document *</label><input id="document_name" name="name" maxlength="190" class="gc-input" required value="{{ old('name') }}" placeholder="Ex. Facture travaux, rapport de visite…"></div>
        <fieldset data-operation-fields class="gc-report-fields grid gap-4 sm:grid-cols-2" @disabled(old('document_type', 'free') === 'free') @if (old('document_type', 'free') === 'free') hidden @endif>
            <legend class="sr-only">Informations du rapport</legend>
            <div class="sm:col-span-2"><label class="gc-label" for="report-operation">Fiche / prestation *</label>
                <select class="gc-input" id="report-operation" name="operation_id" required data-report-operation><option value="">Choisir une fiche</option>
                    @foreach ($dossier->operations as $operation)
                        @php($state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'])
                        <option value="{{ $operation->id }}" data-state="{{ $state }}" @selected(old('operation_id') == $operation->id)>#{{ $operation->id }} · {{ $operation->service?->name ?: $operation->legacy_service_name }} · {{ \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['label'] }}</option>
                    @endforeach
                </select>
            </div>
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
            <div class="sm:col-span-2"><label class="gc-label" for="control-reference">Référence du contrôle *</label><input class="gc-input" id="control-reference" name="control_reference" maxlength="190" value="{{ old('control_reference') }}" required></div>
            <div><label class="gc-label" for="report-inspected">Date du rendez-vous réalisé *</label><input class="gc-input" id="report-inspected" name="inspected_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('inspected_on') }}" required></div>
            <div><label class="gc-label" for="report-issued">Date d’émission *</label><input class="gc-input" id="report-issued" name="issued_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('issued_on') }}" required></div>
            <div><label class="gc-label" for="report-outcome">Avis du rapport *</label><select class="gc-input" id="report-outcome" name="outcome" required><option value="">Choisir</option>@foreach (\App\Services\Crm\PostVisitWorkflow::OUTCOMES as $key => $label)<option value="{{ $key }}" @selected(old('outcome') === $key)>{{ $label }}</option>@endforeach</select></div>
            <p class="text-xs text-slate-500 sm:col-span-2">L’avis met à jour la satisfaction physique du lot lié, sans décider de l’état. L’envoi CAPCEE reste une action séparée.</p>
        </fieldset>
        <p class="text-sm font-medium" role="status" data-document-effect></p>
        <div><label class="gc-label" for="document-description">Description / observations</label><textarea id="document-description" name="description" class="gc-input" rows="3" maxlength="10000">{{ old('description') }}</textarea></div>
        <label class="gc-file-drop" for="document"><x-nav-icon name="upload" /><span><strong>Choisir ou déposer un fichier</strong><small>10 Mo maximum · PDF pour les rapports, images ou documents Office pour les pièces libres.</small></span>
            <input id="document" name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" class="block w-full min-w-0 text-sm">
        </label>
        <x-content-audience :value="old('audience', 'client')" />
        <div class="flex justify-end"><button type="submit" class="gc-btn-primary"><x-nav-icon name="upload" />Enregistrer le document</button></div>
    </form>
</details>
