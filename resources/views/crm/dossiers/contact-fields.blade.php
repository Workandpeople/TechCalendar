@php
    $contact = old($role.'_contact', $dossier->{$role.'_contact'} ?? \App\Services\Crm\DossierContacts::details($dossier->$role));
    $selected = old($role.'_id', $dossier->{$role.'_id'});
@endphp
<section class="gc-card p-5 gc-party-card" data-contact-form data-party="{{ $role }}">
    <h2 class="gc-workspace-section-title mb-3 text-lg font-semibold"><x-nav-icon name="delegataires" />{{ $role === 'client' ? 'Client' : 'Délégataire' }}</h2>
    <label class="gc-label" for="{{ $role }}_id">Choisir un compte existant</label>
    <select id="{{ $role }}_id" name="{{ $role }}_id" class="gc-input" data-contact-select>
        <option value="">Saisie manuelle · aucun accès plateforme</option>
        @foreach ($delegataires->where('kind', $role) as $organisation)
            @if ($organisation->is_active || $organisation->id == $selected)
                <option value="{{ $organisation->id }}" data-contact="{{ json_encode(\App\Services\Crm\DossierContacts::details($organisation)) }}" @selected($organisation->id == $selected)>{{ $organisation->company_name ?: $organisation->name }}</option>
            @endif
        @endforeach
    </select>
    <p class="mt-2 mb-4 text-xs text-slate-500">La sélection préremplit les coordonnées. Elles restent modifiables pour ce dossier. Sans compte lié, aucun accès externe n’est accordé.</p>
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach (['name' => 'Nom du contact', 'company_name' => 'Raison sociale', 'email' => 'Email', 'phone' => 'Téléphone', 'address' => 'Adresse', 'postal_code' => 'Code postal', 'city' => 'Ville'] as $field => $label)
            <div class="{{ $field === 'address' ? 'sm:col-span-2' : '' }}"><label class="gc-label" for="{{ $role }}-{{ $field }}">{{ $label }}</label>
                <input id="{{ $role }}-{{ $field }}" class="gc-input" data-contact-field="{{ $field }}" name="{{ $role }}_contact[{{ $field }}]" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" maxlength="{{ ['phone' => 60, 'address' => 500, 'postal_code' => 10][$field] ?? 190 }}" value="{{ $contact[$field] ?? '' }}">
            </div>
        @endforeach
    </div>
</section>
