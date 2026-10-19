<section id="documents" class="gc-card gc-dossier-panel" aria-labelledby="dossier-documents-title">
    <header class="gc-dossier-panel-heading">
        <h2 id="dossier-documents-title"><x-nav-icon name="document" />Documents <span class="gc-dossier-count">{{ $documentCount }}</span></h2>
        <a href="#documents-dossier" class="gc-btn-primary"><x-nav-icon name="plus" />Ajouter</a>
    </header>
    <div class="gc-dossier-panel-body">
        @include('crm.dossiers.detail.document-upload')
        <div class="gc-document-legend mt-4" aria-label="Niveaux de partage">
            <span data-audience="internal">Interne</span><span data-audience="client">Client</span><span data-audience="shared">Client + délégataire</span>
        </div>
        <p class="mt-2 text-xs text-slate-500">L’auteur et les dates restent visibles. « Aperçu » ouvre les PDF et images sans quitter la fiche.</p>
        <div class="gc-dossier-documents-scroll mt-4">
            <table class="gc-dossier-documents-table">
                <thead><tr><th scope="col">Document / description</th><th scope="col">Ajouté par / date</th><th scope="col">Accès externes</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @foreach ($documents as $document)
                        @include('crm.dossiers.detail.document-row', [
                            'name' => $document->name, 'description' => $document->description,
                            'detail' => ($document->document_type === 'report' ? 'Rapport de contrôle' : 'Document libre').' · '.number_format($document->size / 1024, 0, ',', ' ').' Ko',
                            'author' => $document->author?->full_name ?: 'Auteur non renseigné', 'date' => $document->created_at?->format('d/m/Y H:i'),
                            'reportReference' => $document->control_reference, 'reportDate' => $document->inspected_on?->format('d/m/Y'), 'issuedDate' => $document->issued_on?->format('d/m/Y'),
                            'outcome' => \App\Services\Crm\PostVisitWorkflow::OUTCOMES[$document->control_outcome] ?? null,
                            'download' => route('v2.documents.download', $document),
                            'preview' => \App\Services\Crm\DocumentResponseHeaders::previewable($document->mime_type) ? route('v2.documents.download', ['document' => $document, 'preview' => 1]) : null,
                            'remove' => route('v2.documents.destroy', $document),
                            'sharing' => route('v2.documents.sharing', $document),
                            'visible' => $document->client_visible, 'delegataireVisible' => $document->delegataire_visible,
                        ])
                    @endforeach
                    @foreach ($legacyDocuments as $record)
                        @include('crm.dossiers.detail.document-row', [
                            'name' => $record['name'], 'detail' => 'Archive Coffrac', 'description' => $record['description'],
                            'author' => $record['author_name'], 'date' => $record['created_at'],
                            'reportReference' => $record['control_reference'], 'reportDate' => $record['inspected_on'], 'issuedDate' => $record['issued_on'], 'outcome' => $record['outcome'],
                            'download' => $record['url'], 'preview' => $record['previewable'] ? $record['url'].'?preview=1' : null,
                            'remove' => route('v2.dossiers.collected-documents.destroy', [$dossier, $record['source_id']]),
                            'sharing' => route('v2.dossiers.collected-documents.sharing', [$dossier, $record['source_id']]),
                            'visible' => $record['client_visible'], 'delegataireVisible' => $record['delegataire_visible'],
                        ])
                    @endforeach
                    @foreach ($lotDocuments as $document)
                        @include('crm.dossiers.detail.document-row', [
                            'name' => $document->name, 'description' => null,
                            'detail' => 'Lot '.($document->lotAppointment?->lot?->name ?: '#'.$document->lot_appointment_id).' · '.number_format($document->size / 1024, 0, ',', ' ').' Ko',
                            'author' => $document->uploader?->full_name ?: 'Auteur non renseigné', 'date' => $document->created_at?->format('d/m/Y H:i'),
                            'reportReference' => null, 'reportDate' => null, 'issuedDate' => null, 'outcome' => null,
                            'download' => $document->disk === 'local' ? route('v2.dossiers.lot-documents.download', [$dossier, $document->id]) : null,
                            'preview' => $document->disk === 'local' && \App\Services\Crm\DocumentResponseHeaders::previewable($document->mime) ? route('v2.dossiers.lot-documents.download', ['dossier' => $dossier, 'document' => $document->id, 'preview' => 1]) : null, 'remove' => null,
                            'sharing' => route('v2.dossiers.lot-documents.sharing', [$dossier, $document->id]),
                            'visible' => $document->client_visible && ! $document->is_private, 'delegataireVisible' => $document->delegataire_visible && ! $document->is_private,
                        ])
                    @endforeach
                    @if ($documentCount === 0)<tr><td colspan="4" class="gc-dossier-empty"><x-nav-icon name="document" />Aucun document. Ajoutez la première pièce ou un rapport de contrôle ci-dessus.</td></tr>@endif
                </tbody>
            </table>
        </div>
        @if ($documents->hasPages())<div class="mt-4">{{ $documents->links() }}</div>@endif
        @if ($legacyDocuments->hasPages())<div class="mt-4">{{ $legacyDocuments->links() }}</div>@endif
        @if ($lotDocuments->hasPages())<div id="documents-lots" class="mt-4">{{ $lotDocuments->links() }}</div>@endif
        <p class="mt-4 text-xs text-slate-500"><x-nav-icon name="lock" />Les fichiers restent privés sur le serveur. Le partage ne crée aucun lien anonyme et n’envoie rien aux CRM externes.</p>
    </div>
    <dialog class="gc-document-preview" data-document-preview aria-labelledby="preview-title">
        <header><h2 id="preview-title" data-preview-title>Aperçu du document</h2><button type="button" class="gc-btn-secondary" data-preview-close><x-nav-icon name="close" />Fermer</button></header>
        <p class="gc-document-preview-status" data-preview-status role="status" aria-live="polite"></p>
        <div class="gc-document-preview-content" data-preview-content></div>
        <footer>
            <p>Si l’aperçu reste vide, ouvrez le document dans un nouvel onglet ou téléchargez-le.</p>
            <div class="gc-document-preview-links"><a class="gc-btn-secondary" data-preview-external target="_blank" rel="noopener noreferrer">Nouvel onglet<span class="sr-only"> : aperçu du document</span></a><a class="gc-btn-primary" data-preview-download><x-nav-icon name="download" />Télécharger</a></div>
        </footer>
    </dialog>
</section>
