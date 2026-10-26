<tr>
    <td>
        <div class="gc-document-name"><span class="gc-document-file-icon"><x-nav-icon :name="$reportReference ? 'check' : 'document'" /></span><p class="font-semibold">{{ $name }} <x-report-outcome :outcome="$outcome" /></p></div>
        @if ($description || $reportReference || $outcome)
        <details class="gc-document-more"><summary>{{ $detail }} · Détails<span class="sr-only"> de {{ $name }}</span></summary>
        @if ($description)<x-comment-body :body="$description" class="text-xs gc-document-description" />@endif
        @if ($reportReference || $outcome)
            <dl class="gc-document-report"><div><dt>Réf. contrôle</dt><dd>{{ $reportReference ?: 'Non renseignée' }}</dd></div>
                @if ($reportDate)<div><dt>Visite</dt><dd>{{ $reportDate }}</dd></div>@endif
                @if ($issuedDate)<div><dt>Émission</dt><dd>{{ $issuedDate }}</dd></div>@endif
                @if ($outcome)<div><dt>Conclusion</dt><dd>{{ $outcome }}</dd></div>@endif
            </dl>
        @endif
        </details>
        @else
            <p class="text-xs text-slate-500">{{ $detail }}</p>
        @endif
    </td>
    <td><span class="gc-document-author">{{ $author }}</span><p class="text-xs text-slate-500">{{ $date ?: 'Date non renseignée' }}</p></td>
    <td><x-dossier-sharing :action="$sharing" :visible="$visible" :delegataire-visible="$delegataireVisible" :label="$name" :compact="true" /></td>
    <td>
        <div class="gc-document-actions">
            @if ($download)
                @if ($preview)<a class="gc-btn-info gc-document-action" href="{{ $preview }}" target="_blank" rel="noopener noreferrer" data-document-open data-document-title="{{ $name }}" data-document-download="{{ $download }}" title="Aperçu de {{ $name }}" aria-label="Voir {{ $name }}"><x-nav-icon name="eye" /></a>@endif
                <a href="{{ $download }}" class="gc-btn-primary gc-document-action" title="Télécharger {{ $name }}" aria-label="Télécharger {{ $name }}"><x-nav-icon name="download" /></a>
            @else
                <span class="text-xs text-slate-500">Fichier non collecté ou indisponible</span>
            @endif
            @if ($remove)
                @php($removeDialog = 'supprimer-'.sha1($remove))
                <a href="#{{ $removeDialog }}" class="gc-btn-danger gc-document-action" title="Supprimer {{ $name }}" aria-label="Supprimer {{ $name }}"><x-nav-icon name="trash" /></a>
                <x-dossier-dialog :id="$removeDialog" :title="'Supprimer : '.$name">
                    <form method="POST" action="{{ $remove }}" class="mt-2 space-y-2">@csrf @method('DELETE')<p class="text-xs">Retirer ce document ? La trace est conservée.</p><button class="gc-btn-danger" type="submit">Confirmer la suppression</button></form>
                </x-dossier-dialog>
            @endif
        </div>
    </td>
</tr>
