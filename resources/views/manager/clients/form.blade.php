<x-layouts.app>
    <div class="mx-auto max-w-6xl space-y-6">
        <a class="gc-link" href="{{ route('manager.delegataires') }}">← Clients et délégataires</a>
        <x-workspace-heading :title="$client->exists ? $client->name : 'Nouveau client'" eyebrow="Organisation et accès externes" description="Le client affecté au dossier ou au lot peut être un délégataire ou un client classique. Il est distinct du bénéficiaire." />
        @include('client.notice')
        <form method="POST" action="{{ $client->exists ? route('manager.clients.update', $client) : route('manager.clients.store') }}" class="gc-card space-y-5 p-5">
            @csrf @if ($client->exists) @method('PUT') @endif
            <h2 class="gc-workspace-section-title text-lg font-semibold"><x-nav-icon name="delegataires" />Organisation</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="kind" class="gc-label">Type</label><select id="kind" name="kind" class="gc-input">@foreach (\App\Models\ExternalDelegataire::KINDS as $value => $label)<option value="{{ $value }}" @selected(old('kind', $client->kind) === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="name" class="gc-label">Nom affiché *</label><input id="name" name="name" value="{{ old('name', $client->name) }}" class="gc-input" maxlength="190" required></div>
                <div><label for="company_name" class="gc-label">Raison sociale</label><input id="company_name" name="company_name" value="{{ old('company_name', $client->company_name) }}" class="gc-input" maxlength="190"></div>
                <div><label for="email" class="gc-label">Email de contact</label><input id="email" type="email" name="email" value="{{ old('account_id') ? $client->email : old('email', $client->email) }}" class="gc-input" maxlength="190"></div>
                <div><label for="phone" class="gc-label">Téléphone</label><input id="phone" name="phone" value="{{ old('phone', $client->phone) }}" class="gc-input" maxlength="60"></div>
                <div><label for="is_active" class="gc-label">Accès de l’organisation</label><select id="is_active" name="is_active" class="gc-input"><option value="1" @selected(old('is_active', $client->is_active))>Actif</option><option value="0" @selected(! old('is_active', $client->is_active))>Désactivé</option></select><p class="mt-2 text-xs text-slate-500">Désactiver bloque immédiatement tous ses comptes, sans supprimer ses dossiers.</p></div>
            </div>
            <fieldset class="gc-category-policy space-y-4 rounded-xl border border-slate-200 p-4">
                <legend class="px-2 font-semibold">Catégories de dossiers accessibles</legend>
                @php
                    $categoryMode = old('category_mode', $client->portal_categories === null ? 'inherit' : 'custom');
                    $categorySelection = old('category_mode') ? old('categories', []) : app(\App\Services\Crm\PortalCategoryAccess::class)->effective($client);
                @endphp
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="category_mode" value="inherit" @checked($categoryMode === 'inherit')>Hériter des catégories du type de cette organisation</label>
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="category_mode" value="custom" @checked($categoryMode === 'custom')>Personnaliser pour cette organisation</label>
                <p class="text-xs text-slate-500">La sélection ci-dessous s’applique uniquement en mode personnalisé et remplace les réglages du type. <a class="gc-link" href="{{ route('manager.clients.categories') }}">Gérer les réglages par type</a></p>
                <div class="gc-category-selection">@include('manager.clients.category-checkboxes', ['selectedCategories' => $categorySelection, 'categoryPrefix' => 'organisation'])</div>
                @include('manager.clients.category-warning')
            </fieldset>
            <button class="gc-btn-primary">Enregistrer le client</button>
        </form>
        @if ($client->exists)
            <section class="gc-card space-y-4 p-5">
                <div><h2 class="text-lg font-semibold">Comptes et droits externes</h2><p class="mt-2 text-sm text-slate-500">Chaque personne a son propre compte. Aucun accès aux pages internes, aux autres clients ni aux notes privées. Les changements de type ne réinitialisent pas les droits personnalisés.</p></div>
                @forelse ($accounts as $account)
                    <details class="rounded-xl border border-slate-200 p-4" @if(old('account_id') == $account->id) open @endif><summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2"><span class="font-semibold">{{ $account->full_name }} <span class="text-sm font-normal text-slate-500">{{ $account->email }}</span></span><span class="text-sm">{{ $account->portal_enabled ? 'Accès actif' : 'Accès désactivé' }} · Modifier</span></summary>
                        @include('manager.clients.account', ['account' => $account])
                    </details>
                @empty<p class="py-3 text-sm text-slate-500">Aucun accès créé. Les comptes Coffrac ne sont pas activés automatiquement.</p>@endforelse
                {{ $accounts->links() }}
                <details class="rounded-xl border border-slate-200 p-4" @if(old('account_id') === 'new') open @endif><summary class="cursor-pointer font-semibold">Créer un accès externe</summary>@include('manager.clients.account', ['account' => null])</details>
            </section>
        @endif
    </div>
</x-layouts.app>
