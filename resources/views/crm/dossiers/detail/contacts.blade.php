<section id="intervenants" class="gc-card gc-dossier-panel" aria-labelledby="dossier-contacts-title">
    <header class="gc-dossier-panel-heading"><h2 id="dossier-contacts-title"><x-nav-icon name="delegataires" />Client et intervenants</h2></header>
    <dl class="gc-dossier-panel-body space-y-4 text-sm">
        <div><dt class="text-xs text-slate-500">Client (classique ou délégataire)</dt><dd class="mt-1 font-semibold">{{ $dossier->delegataire?->company_name ?: $dossier->delegataire?->name ?: 'À définir' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Installateur</dt><dd class="mt-1 font-medium">{{ $dossier->installer_name ?: 'À définir' }}</dd>@if ($dossier->installer_siren)<dd class="mt-1 text-xs text-slate-500">SIREN {{ $dossier->installer_siren }}</dd>@endif</div>
        <div><dt class="text-xs text-slate-500">Suivi planning</dt><dd class="mt-1 font-medium">{{ $dossier->planningUser?->full_name ?: 'Non attribué' }}@if ($dossier->planningUser?->trashed()) <span class="text-xs text-slate-500">(compte supprimé)</span>@endif</dd></div>
    </dl>
</section>
