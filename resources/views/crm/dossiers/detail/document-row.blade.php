<tr>
    <td><p class="font-semibold">{{ $name }}</p><p class="mt-1 text-xs text-slate-500">{{ $detail }}</p></td>
    <td><x-dossier-sharing :action="$sharing" :visible="$visible" :label="$name" :hint="$hint" :compact="true" /></td>
    <td>
        @if ($download)
            <a href="{{ $download }}" class="gc-link text-sm underline" aria-label="Télécharger {{ $name }}">Télécharger</a>
        @else
            <span class="text-xs text-slate-500">Fichier non collecté ou indisponible</span>
        @endif
    </td>
</tr>
