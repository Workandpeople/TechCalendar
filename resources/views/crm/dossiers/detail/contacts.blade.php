<section id="intervenants" class="gc-card gc-dossier-panel" aria-labelledby="dossier-contacts-title">
    <header class="gc-dossier-panel-heading"><h2 id="dossier-contacts-title"><x-nav-icon name="delegataires" />Client et intervenants</h2></header>
    <dl class="gc-dossier-panel-body space-y-4 text-sm">
        @foreach (['client' => 'Client', 'delegataire' => 'Délégataire'] as $role => $label)
            @php($contact = $dossier->{$role.'_contact'} ?? \App\Services\Crm\DossierContacts::details($dossier->$role))
            <div class="gc-party-summary" data-party="{{ $role }}"><dt class="text-xs font-semibold">{{ $label }}</dt>
                <dd class="mt-1 font-semibold">{{ ($contact['company_name'] ?? '') ?: ($contact['name'] ?? '') ?: 'À définir' }}</dd>
                @foreach (['email', 'phone', 'address', 'postal_code', 'city'] as $field)@if (filled($contact[$field] ?? null))<dd class="mt-1 text-xs">{{ $contact[$field] }}</dd>@endif @endforeach
                <dd class="mt-2 text-xs text-slate-500">{{ $dossier->{$role.'_id'} ? 'Compte lié · selon les droits de partage' : 'Coordonnées seules · aucun accès externe' }}</dd>
            </div>
        @endforeach
        <div><a href="{{ route('v2.dossiers.edit', $dossier) }}" class="gc-btn-secondary"><x-nav-icon name="edit" />Modifier les intervenants</a></div>
        <div><dt class="text-xs text-slate-500">Installateur</dt><dd class="mt-1 font-medium">{{ $dossier->installer_name ?: 'À définir' }}</dd>@if ($dossier->installer_siren)<dd class="mt-1 text-xs text-slate-500">SIREN {{ $dossier->installer_siren }}</dd>@endif</div>
        <div><dt class="text-xs text-slate-500">Suivi planning</dt><dd class="mt-1 font-medium">{{ $dossier->planningUser?->full_name ?: 'Non attribué' }}@if ($dossier->planningUser?->trashed()) <span class="text-xs text-slate-500">(compte supprimé)</span>@endif</dd></div>
    </dl>
</section>
