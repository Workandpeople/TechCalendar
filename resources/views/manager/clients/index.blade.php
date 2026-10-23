<x-layouts.app>
    <div class="space-y-6">
        <x-workspace-heading title="Clients et délégataires" eyebrow="Relations clients" description="Retrouvez vos clients, attribuez leurs dossiers et gérez leurs accès externes. Les utilisateurs de votre équipe se gèrent séparément.">
            <a href="{{ route('manager.clients.create') }}" class="gc-btn-primary"><x-nav-icon name="plus" /> Ajouter un client</a>
        </x-workspace-heading>
        @include('client.notice')
        <a href="{{ route('manager.clients.categories') }}" class="gc-btn-secondary"><x-nav-icon name="settings" />Catégories et accès par type</a>
        <form method="GET" class="grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><label for="q" class="gc-label">Recherche</label><input id="q" name="q" class="gc-input" value="{{ $filters['q'] }}" placeholder="Nom, société, email"></div>
            <div><label for="kind" class="gc-label">Type</label><select id="kind" name="kind" class="gc-input"><option value="">Tous les clients</option>@foreach (\App\Models\ExternalDelegataire::KINDS as $value => $label)<option value="{{ $value }}" @selected($filters['kind'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="status" class="gc-label">État</label><select id="status" name="status" class="gc-input"><option value="">Tous</option><option value="active" @selected($filters['status'] === 'active')>Actifs</option><option value="inactive" @selected($filters['status'] === 'inactive')>Désactivés</option></select></div>
            <div class="flex items-end gap-3"><button class="gc-btn-primary">Filtrer</button><a href="{{ route('manager.delegataires') }}" class="gc-btn-secondary">Effacer</a></div>
        </form>
        <div class="overflow-x-auto rounded-xl border border-slate-200"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr>@foreach (['Client', 'Type', 'Contact', 'Accès', 'État', ''] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead><tbody class="divide-y divide-slate-100">
            @forelse ($delegataires as $client)<tr><td class="p-4 font-semibold">{{ $client->name }}@if ($client->company_name && $client->company_name !== $client->name)<p class="mt-1 text-xs font-normal text-slate-500">{{ $client->company_name }}</p>@endif</td><td class="p-4">{{ \App\Models\ExternalDelegataire::KINDS[$client->kind] ?? $client->kind }}</td><td class="p-4">{{ $client->email ?: 'Non renseigné' }}<p class="text-slate-500">{{ $client->phone }}</p></td><td class="p-4">{{ $client->accounts_count }} compte(s)</td><td class="p-4">{{ $client->is_active ? 'Actif' : 'Désactivé' }}</td><td class="p-4 text-right"><a class="gc-btn-secondary" href="{{ route('manager.clients.edit', $client) }}">Gérer</a></td></tr>
            @empty<tr><td colspan="6" class="p-8 text-center text-slate-500">Aucun client ne correspond à la recherche.</td></tr>@endforelse
        </tbody></table></div>
        {{ $delegataires->links() }}
    </div>
</x-layouts.app>
