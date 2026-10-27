<section id="client-documents" class="gc-portal-panel" aria-labelledby="client-documents-title">
    <header class="gc-portal-panel-heading" data-tone="blue">
        <div><span class="gc-portal-icon"><x-nav-icon name="document" /></span><div><h2 id="client-documents-title">Documents partagés <span class="gc-portal-count">{{ $documentCount }}</span></h2><p>Consultez vos pièces sans quitter le dossier.</p></div></div>
        @if ($canUpload)<a href="#client-document-upload" class="gc-btn-primary"><x-nav-icon name="plus" />Ajouter un document</a>@endif
    </header>
    <div class="gc-portal-panel-body">
        @if ($documentCount === 0)
            <div class="gc-portal-empty"><span class="gc-portal-icon"><x-nav-icon name="document" /></span><h3>Aucune pièce partagée pour le moment</h3><p>Les documents accessibles à votre organisation apparaîtront ici.</p></div>
        @endif
        @if ($documents->total())
            <ul class="gc-portal-files" aria-label="Documents du dossier">
                @foreach ($documents as $document)
                    @include('client.detail.file', ['fileName' => $document->name, 'fileDescription' => $document->description,
                        'fileDate' => $document->created_at?->format('d/m/Y'), 'fileSize' => $document->size,
                        'fileOutcome' => $document->document_type === 'report' ? $document->control_outcome : null,
                        'fileUrl' => route('client.documents.download', $document),
                        'filePreview' => \App\Services\Crm\DocumentResponseHeaders::previewable($document->mime_type),
                        'fileLabel' => match ($document->document_type) { 'report' => 'Rapport', 'visit' => 'Visite technique', default => 'Document' }])
                @endforeach
            </ul>
            {{ $documents->withQueryString()->links() }}
        @endif
        @if ($collected?->total())
            <h3 class="gc-portal-subheading">Documents historiques <span>{{ $collected->total() }}</span></h3>
            <ul class="gc-portal-files" aria-label="Documents historiques">
                @foreach ($collected as $document)
                    @include('client.detail.file', ['fileName' => $document['name'], 'fileDescription' => $document['description'],
                        'fileDate' => $document['display_date'], 'fileSize' => $document['size'], 'fileUrl' => $document['url'], 'fileOutcome' => $document['outcome'],
                        'filePreview' => $document['previewable'], 'fileLabel' => 'Document'])
                @endforeach
            </ul>
            {{ $collected->links() }}
        @endif
        @if ($lotDocuments?->total())
            <h3 class="gc-portal-subheading">Documents du lot <span>{{ $lotDocuments->total() }}</span></h3>
            <ul class="gc-portal-files" aria-label="Documents du lot">
                @foreach ($lotDocuments as $document)
                    @include('client.detail.file', ['fileName' => $document->name, 'fileDescription' => null,
                        'fileDate' => $document->created_at?->format('d/m/Y'), 'fileSize' => $document->size, 'fileOutcome' => null,
                        'fileUrl' => route('client.dossiers.lot-documents.download', [$dossier, $document->id]),
                        'filePreview' => \App\Services\Crm\DocumentResponseHeaders::previewable($document->mime),
                        'fileLabel' => 'Document du lot'])
                @endforeach
            </ul>
            {{ $lotDocuments->links() }}
        @endif
        <p class="gc-portal-hint"><x-nav-icon name="lock" />Ces pièces sont partagées avec votre organisation, jamais en accès libre.</p>
    </div>
    @if ($canUpload)
        <x-dossier-dialog id="client-document-upload" title="Ajouter un document" :reopen="old('_form') === 'client-document'">
            <form class="gc-portal-form" method="POST" enctype="multipart/form-data" action="{{ route('client.dossiers.upload', $dossier) }}">
                @csrf
                <input type="hidden" name="_form" value="client-document">
                <p class="gc-portal-form-intro">Transmettez une pièce à notre équipe. L’ajout seul ne change pas l’état du dossier et ne demande pas de recontrôle.</p>
                <div><label class="gc-label" for="document-name">Nom du document</label><input id="document-name" name="name" maxlength="190" required class="gc-input" placeholder="Ex. : plan, facture ou photo" value="{{ old('_form') === 'client-document' ? old('name') : '' }}"></div>
                <div class="gc-portal-file-input"><label class="gc-label" for="document-file">Fichier, {{ \App\Services\DocumentUpload::LIMIT_LABEL }} maximum</label><input id="document-file" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" required aria-describedby="document-formats" data-document-max-bytes="{{ \App\Services\DocumentUpload::MAX_BYTES }}"><p id="document-formats">PDF, image JPG / PNG / WebP, Word ou Excel.</p></div>
                <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button class="gc-btn-primary" type="submit"><x-nav-icon name="upload" />Transmettre le document</button></footer>
            </form>
        </x-dossier-dialog>
    @endif
    <x-document-preview />
</section>
