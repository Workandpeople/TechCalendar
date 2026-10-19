@php
    $prefix = 'account-'.($account?->id ?? 'new');
    $permissions = $account ? app(\App\Services\Crm\ClientAccess::class)->permissions($account) : $defaults;
    $retry = old('account_id') == ($account?->id ?? 'new');
    $enabled = $retry ? old('portal_enabled', $account?->portal_enabled ?? true) : ($account?->portal_enabled ?? true);
@endphp
<form method="POST" action="{{ $account ? route('manager.clients.accounts.update', [$client, $account]) : route('manager.clients.accounts.store', $client) }}" class="mt-5 space-y-4">
    @csrf @if ($account) @method('PUT') @endif
    <input type="hidden" name="account_id" value="{{ $account?->id ?? 'new' }}">
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (['first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'Email de connexion'] as $field => $label)
            <div><label for="{{ $prefix }}-{{ $field }}" class="gc-label">{{ $label }} *</label><input id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" class="gc-input" value="{{ $retry ? old($field, $account?->$field) : $account?->$field }}" required></div>
        @endforeach
        <div><label for="{{ $prefix }}-enabled" class="gc-label">État du compte</label><select id="{{ $prefix }}-enabled" name="portal_enabled" class="gc-input"><option value="1" @selected($enabled)>Actif</option><option value="0" @selected(! $enabled)>Désactivé</option></select></div>
        <div><label for="{{ $prefix }}-password" class="gc-label">{{ $account ? 'Nouveau mot de passe (facultatif)' : 'Mot de passe initial *' }}</label><input id="{{ $prefix }}-password" name="password" type="password" class="gc-input" autocomplete="new-password" minlength="8" maxlength="72" @required(! $account)></div>
        <div><label for="{{ $prefix }}-confirmation" class="gc-label">Confirmation</label><input id="{{ $prefix }}-confirmation" name="password_confirmation" type="password" class="gc-input" autocomplete="new-password" minlength="8" maxlength="72" @required(! $account)></div>
    </div>
    <fieldset class="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2"><legend class="px-2 text-sm font-semibold">Droits sur les éléments attribués</legend>
        @foreach (\App\Services\Crm\ClientAccess::PERMISSIONS as $key => $label)
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="permissions[{{ $key }}]" value="0"><input type="checkbox" name="permissions[{{ $key }}]" value="1" @checked($retry ? old('permissions.'.$key, $permissions[$key] ?? false) : ($permissions[$key] ?? false))>{{ $label }}</label>
        @endforeach
    </fieldset>
    <p class="text-xs text-slate-500">Le dépôt de justificatifs en non-conformité est autorisé par défaut aux clients et délégataires affectés. Pour interdire tout dépôt, décochez les deux droits d’ajout de documents.</p>
    <p class="text-xs text-slate-500">Ces droits n’ouvrent que les éléments publics des dossiers attribués à ce client. Les documents privés et l’historique interne restent invisibles. Désactiver la lecture des commentaires masque aussi leur section, même si certains commentaires sont publics.</p>
    <p class="text-xs text-slate-500">Les droits d’ajout nécessitent le droit de lecture correspondant. Un nouveau mot de passe impose son changement à la prochaine connexion.</p>
    <button class="gc-btn-primary">{{ $account ? 'Enregistrer les droits' : 'Créer le compte' }}</button>
</form>
