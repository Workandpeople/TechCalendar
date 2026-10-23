<li class="gc-portal-file">
    <span class="gc-portal-file-icon"><x-nav-icon name="document" /></span>
    <div class="gc-portal-file-info">
        <h4>{{ $fileName }}</h4>
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
    </div>
</li>
