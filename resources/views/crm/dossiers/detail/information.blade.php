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
            <div class="gc-dossier-information-wide"><dt>Adresse de l’intervention</dt><dd class="gc-dossier-address-row"><span>{{ $dossier->address }}<span class="block">{{ $dossier->postal_code }} {{ $dossier->city }}</span></span>
                @can('update', $dossier)<button type="button" class="gc-btn-secondary gc-address-edit" data-dialog-target="corriger-adresse"><x-nav-icon name="edit" />Modifier l’adresse</button>@endcan
            </dd></div>
        </dl>
    </div>
</section>
@can('update', $dossier)
    <x-dossier-dialog id="corriger-adresse" title="Modifier l’adresse de l’intervention" class="gc-address-dialog" :reopen="$errors->any() && old('_form') === 'address'">
        <form method="POST" action="{{ route('v2.dossiers.address.update', $dossier) }}" class="space-y-4" data-dossier-address data-suggestions-url="{{ route('v2.dossiers.address.suggestions', $dossier) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="_form" value="address">
            <input type="hidden" name="version" value="{{ $dossier->version }}">
            <input type="hidden" name="mapbox_id" value="{{ old('mapbox_id') }}">
            <p class="text-sm text-slate-600">Recherchez une adresse ou saisissez uniquement le code postal et la ville. Une commune seule donne une position approximative, pas l’adresse exacte.</p>
            <div><label class="gc-label" for="dossier-address-query">Adresse de l’intervention</label>
                <input id="dossier-address-query" name="address_query" type="text" required minlength="3" maxlength="255" class="gc-input" value="{{ old('address_query', \App\Services\Crm\DossierLocation::address($dossier)) }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="dossier-address-options" aria-describedby="dossier-address-status">
                <ul id="dossier-address-options" class="gc-address-suggestions" role="listbox" aria-label="Adresses proposées par Mapbox" hidden></ul>
            </div>
            <p id="dossier-address-status" data-address-status role="status" aria-live="polite" class="text-xs text-slate-500">Saisissez au moins 3 caractères pour rechercher une adresse.</p>
            <p class="text-xs text-slate-500">Les rendez-vous déjà planifiés et l’historique sont conservés. Une localisation sur la commune reste approximative jusqu’à confirmation de l’adresse exacte.</p>
            <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button type="submit" class="gc-btn-primary">Valider</button></footer>
        </form>
    </x-dossier-dialog>
@endcan
