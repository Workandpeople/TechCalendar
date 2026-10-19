<section id="synthese" class="gc-card gc-dossier-panel" aria-labelledby="dossier-information-title">
    <header class="gc-dossier-panel-heading">
        <h2 id="dossier-information-title"><x-nav-icon name="dossiers" />Dossier</h2>
        <a class="gc-btn-secondary" href="{{ route('v2.dossiers.edit', $dossier) }}" data-dialog-target="modifier-dossier"><x-nav-icon name="edit" /> Modifier</a>
    </header>
    <div class="gc-dossier-panel-body">
        <dl class="gc-dossier-information">
            <div><dt>Numéro de lot</dt><dd>{{ $dossier->legacy_lot_name ?: 'Non renseigné' }}</dd></div>
        </dl>
        <h3 class="gc-dossier-subtitle"><x-nav-icon name="users" />Bénéficiaire</h3>
        <dl class="gc-dossier-information">
            <div><dt>Raison sociale</dt><dd>{{ $dossier->company_name ?: $dossier->legacy_label ?: 'Non renseignée' }}</dd></div>
            <div><dt>Nom et prénom</dt><dd>{{ trim($dossier->first_name.' '.$dossier->last_name) ?: 'Non renseignés' }}</dd></div>
            <div><dt>Téléphone</dt><dd>{{ $dossier->phone ?: 'Non renseigné' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $dossier->email ?: 'Non renseigné' }}</dd></div>
            @if ($dossier->beneficiary_address)<div class="gc-dossier-information-wide"><dt>Adresse administrative</dt><dd>{{ $dossier->beneficiary_address }} {{ $dossier->beneficiary_postal_code }} {{ $dossier->beneficiary_city }}</dd></div>@endif
        </dl>
        <h3 class="gc-dossier-subtitle"><x-nav-icon name="location" />Lieu d’inspection</h3>
        <dl class="gc-dossier-information">
            <div><dt>Nom du site</dt><dd>{{ $dossier->site_name ?: 'Non renseigné' }}</dd></div>
            <div><dt>Adresse de l’intervention</dt><dd>{{ $dossier->address }}<span class="block">{{ $dossier->postal_code }} {{ $dossier->city }}</span></dd></div>
        </dl>
    </div>
</section>
