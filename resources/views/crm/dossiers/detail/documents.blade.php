<section id="documents" class="gc-card gc-dossier-panel" aria-labelledby="dossier-documents-title">
    <header class="gc-dossier-panel-heading">
        <h2 id="dossier-documents-title"><x-nav-icon name="document" />Documents <span class="gc-dossier-count">{{ $documentCount }}</span></h2>
        <span class="text-xs text-slate-500">Dossier, lots et archives</span>
    </header>
    <div class="gc-dossier-panel-body">
        <details id="documents-dossier" class="gc-dossier-upload" @if ($errors->has('document') || $errors->has('name')) open @endif>
            <summary><x-nav-icon name="upload" /> Ajouter un document</summary>
            <form class="mt-4 space-y-4" method="POST" enctype="multipart/form-data" action="{{ route('v2.dossiers.documents.store', $dossier) }}">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="document_name" class="gc-label">Nom du document</label><input id="document_name" name="name" value="{{ old('name') }}" maxlength="190" class="gc-input" required></div>
                    <div><label for="document" class="gc-label">Fichier (10 Mo maximum)</label><input id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" class="block w-full min-w-0 text-sm" required><p class="mt-2 text-xs text-slate-500">PDF, image, Word ou Excel.</p></div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="client_visible" value="1">Public : partager avec le client affecté</label>
                <p class="text-xs text-slate-500">Décoché : document privé, réservé à l’équipe interne.</p>
                <div class="flex justify-end"><button type="submit" class="gc-btn-primary">Ajouter au dossier</button></div>
            </form>
        </details>
        <div class="gc-dossier-documents-scroll mt-4" role="region" aria-label="Liste des documents" tabindex="0">
            <table class="gc-dossier-documents-table">
                <thead><tr><th scope="col">Fichier</th><th scope="col">Visibilité</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    @foreach ($documents as $document)
                        @include('crm.dossiers.detail.document-row', [
                            'name' => $document->name,
                            'detail' => 'Dossier · '.number_format($document->size / 1024, 1, ',', ' ').' Ko · '.$document->created_at->format('d/m/Y H:i'),
                            'download' => route('v2.documents.download', $document),
                            'sharing' => route('v2.documents.sharing', $document),
                            'visible' => $document->client_visible, 'hint' => null,
                        ])
                    @endforeach
                    @foreach ($legacyDocuments as $record)
                        @include('crm.dossiers.detail.document-row', [
                            'name' => $record['name'], 'detail' => 'Archive Coffrac', 'download' => $record['url'],
                            'sharing' => route('v2.dossiers.collected-documents.sharing', [$dossier, $record['source_id']]),
                            'visible' => $dossier->delegataire?->kind === 'delegataire' ? $record['delegataire_visible'] : $record['client_visible'],
                            'hint' => $record['client_visible'] && ! $record['delegataire_visible'] ? 'Règle Coffrac conservée : non partagé avec les délégataires.' : null,
                        ])
                    @endforeach
                    @foreach ($lotDocuments as $document)
                        @include('crm.dossiers.detail.document-row', [
                            'name' => $document->name,
                            'detail' => ($document->lotAppointment?->lot?->name ?: 'Lot d’origine').' · '.number_format($document->size / 1024, 1, ',', ' ').' Ko',
                            'download' => $document->disk === 'local' ? route('v2.dossiers.lot-documents.download', [$dossier, $document->id]) : null,
                            'sharing' => route('v2.dossiers.lot-documents.sharing', [$dossier, $document->id]),
                            'visible' => $document->client_visible && ! $document->is_private, 'hint' => null,
                        ])
                    @endforeach
                    @if ($documents->isEmpty() && $legacyDocuments->isEmpty() && $lotDocuments->isEmpty())
                        <tr><td colspan="3" class="gc-dossier-empty">Aucun document sur cette page. Ajoute une pièce avec le bouton ci-dessus.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        @foreach (['Documents du dossier' => $documents, 'Documents historiques Coffrac' => $legacyDocuments, 'Documents des lots liés' => $lotDocuments] as $label => $pages)
            @if ($pages->hasPages())<div class="mt-4"><p class="mb-2 text-xs font-semibold text-slate-500">{{ $label }}</p>{{ $pages->links() }}</div>@endif
        @endforeach
        <span id="documents-lots"></span>
        <details class="mt-4 text-xs text-slate-500">
            <summary class="cursor-pointer font-semibold">Qui peut voir les documents et commentaires ?</summary>
            <p class="mt-2">Privé : équipe interne uniquement. Public : également visible par le client ou délégataire affecté, selon les droits de son compte. Aucun accès anonyme ni partage avec les autres clients.</p>
            <p class="mt-2">Les droits de lecture des documents et des commentaires se règlent séparément dans la gestion du client. L’historique de traitement reste interne. Aucun partage ne déclenche d’envoi vers un CRM externe.</p>
        </details>
    </div>
</section>
