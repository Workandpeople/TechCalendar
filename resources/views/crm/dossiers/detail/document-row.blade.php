<tr>
    <td>
        <div class="gc-document-name"><span class="gc-document-file-icon"><x-nav-icon :name="$reportReference ? 'check' : 'document'" /></span><div><p class="font-semibold">{{ $name }}</p><p class="mt-1 text-xs text-slate-500">{{ $detail }}</p></div></div>
        @if ($description)<x-comment-body :body="$description" class="mt-2 text-xs gc-document-description" />@endif
        @if ($reportReference || $outcome)
            <dl class="gc-document-report"><div><dt>Réf. contrôle</dt><dd>{{ $reportReference ?: 'Non renseignée' }}</dd></div>
                @if ($reportDate)<div><dt>Visite</dt><dd>{{ $reportDate }}</dd></div>@endif
                @if ($issuedDate)<div><dt>Émission</dt><dd>{{ $issuedDate }}</dd></div>@endif
                @if ($outcome)<div><dt>Conclusion</dt><dd>{{ $outcome }}</dd></div>@endif
            </dl>
        @endif
    </td>
    <td><span class="gc-document-author">{{ $author }}</span><p class="mt-2 text-xs text-slate-500">{{ $date ?: 'Date non renseignée' }}</p></td>
    <td><x-dossier-sharing :action="$sharing" :visible="$visible" :delegataire-visible="$delegataireVisible" :label="$name" :compact="true" /></td>
    <td>
        <div class="gc-document-actions">
            @if ($download)
                @if ($preview)<a class="gc-btn-info" href="{{ $preview }}" target="_blank" rel="noopener" data-document-open data-document-title="{{ $name }}" data-document-download="{{ $download }}" aria-label="Voir {{ $name }}"><x-nav-icon name="eye" />Aperçu</a>@endif
                <a href="{{ $download }}" class="gc-btn-primary" aria-label="Télécharger {{ $name }}"><x-nav-icon name="download" />Télécharger</a>
            @else
                <span class="text-xs text-slate-500">Fichier non collecté ou indisponible</span>
            @endif
            @if ($remove)
                <details class="gc-remove-confirm"><summary class="gc-btn-danger"><x-nav-icon name="trash" />Supprimer<span class="sr-only"> {{ $name }}</span></summary>
                    <form method="POST" action="{{ $remove }}" class="mt-2 space-y-2">@csrf @method('DELETE')<p class="text-xs">Retirer ce document ? La trace est conservée.</p><button class="gc-btn-danger" type="submit">Confirmer la suppression</button></form>
                </details>
            @endif
        </div>
    </td>
</tr>
