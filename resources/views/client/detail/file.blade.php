<li class="gc-portal-file">
    <span class="gc-portal-file-icon"><x-nav-icon name="document" /></span>
    <div class="gc-portal-file-info">
        <h4>{{ $fileName }} <x-report-outcome :outcome="$fileOutcome ?? null" /></h4>
        <p class="gc-portal-file-meta"><span>{{ $fileLabel }}</span>@if ($fileDate)<span>{{ $fileDate }}</span>@endif @if ($fileSize > 0)<span>{{ $fileSize >= 1048576 ? number_format($fileSize / 1048576, 1, ',', ' ').' Mo' : number_format(max(1, $fileSize / 1024), 0, ',', ' ').' Ko' }}</span>@endif</p>
        @if (filled($fileDescription))<details class="gc-document-more"><summary>Description</summary><x-comment-body :body="$fileDescription" class="gc-portal-file-description" /></details>@endif
    </div>
    <div class="gc-portal-file-actions">
        @if ($fileUrl)
            @if ($filePreview)
                <a class="gc-portal-file-action" href="{{ $fileUrl }}?preview=1" data-document-open data-document-title="{{ $fileName }}" data-document-download="{{ $fileUrl }}" target="_blank" rel="noopener noreferrer" title="Aperçu de {{ $fileName }}" aria-label="Voir {{ $fileName }}"><x-nav-icon name="eye" /><span>Aperçu</span></a>
            @endif
            <a class="gc-portal-file-action" data-tone="download" href="{{ $fileUrl }}" title="Télécharger {{ $fileName }}" aria-label="Télécharger {{ $fileName }}"><x-nav-icon name="download" /><span class="sr-only">Télécharger</span></a>
        @else
            <span class="gc-portal-unavailable">Fichier indisponible</span>
        @endif
        @if ($fileDeleteUrl ?? null)
            <button type="button" class="gc-portal-file-action" data-tone="danger" data-dialog-target="{{ $fileDeleteDialog }}" aria-label="Supprimer {{ $fileName }}" title="Supprimer {{ $fileName }}"><x-nav-icon name="trash" /><span class="sr-only">Supprimer</span></button>
        @endif
    </div>
    @if ($fileDeleteUrl ?? null)
        <x-dossier-dialog :id="$fileDeleteDialog" title="Supprimer votre document ?">
            <form method="POST" action="{{ $fileDeleteUrl }}" class="gc-portal-form">
                @csrf
                @method('DELETE')
                <p>Supprimer <strong class="break-words">{{ $fileName }}</strong> du dossier ?</p>
                <p class="gc-portal-form-intro">Ce document ne sera plus accessible dans le dossier. Une trace sera conservée par notre équipe. Le dossier et son état ne seront pas modifiés.</p>
                <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button type="submit" class="gc-btn-danger"><x-nav-icon name="trash" />Supprimer le document</button></footer>
            </form>
        </x-dossier-dialog>
    @endif
</li>
