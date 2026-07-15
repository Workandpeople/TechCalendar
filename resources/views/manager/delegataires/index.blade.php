<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm" style="color: var(--gc-text-soft);">Gérant</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color: var(--gc-text);">Gestion des délégataires</h1>
                <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">Liste synchronisée depuis Coffrac. Les délégataires sont consultables uniquement ici.</p>
            </div>
            <form method="POST" action="{{ route('manager.delegataires.sync') }}">
                @csrf
                <button type="submit" class="gc-btn-primary">Récupérer depuis Coffrac</button>
            </form>
        </div>

        @if ($errors->any())
            <div class="gc-alert" style="border-color:#f5c2c7;background:#fff1f2;color:#9f1239;">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="gc-alert">{{ session('status') }}</div>
        @endif

        <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="gc-card p-4">
                <p class="text-sm" style="color:var(--gc-text-soft);">Total local</p>
                <p class="mt-2 text-2xl font-semibold" style="color:var(--gc-text);">{{ $stats['total'] }}</p>
            </div>
            <div class="gc-card p-4">
                <p class="text-sm" style="color:var(--gc-text-soft);">Actifs</p>
                <p class="mt-2 text-2xl font-semibold" style="color:#047857;">{{ $stats['active'] }}</p>
            </div>
            <div class="gc-card p-4">
                <p class="text-sm" style="color:var(--gc-text-soft);">Désactivés</p>
                <p class="mt-2 text-2xl font-semibold" style="color:#9f1239;">{{ $stats['inactive'] }}</p>
            </div>
        </section>

        <section class="gc-card p-4">
            <form method="GET" action="{{ route('manager.delegataires') }}" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="gc-label" for="q">Recherche</label>
                    <input id="q" name="q" type="search" value="{{ $filters['q'] }}" class="gc-input" placeholder="Nom, société, email ou référence Coffrac" />
                </div>
                <div>
                    <label class="gc-label" for="status">Statut</label>
                    <select id="status" name="status" class="gc-input">
                        <option value="">Tous</option>
                        <option value="active" @selected($filters['status'] === 'active')>Actifs</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Désactivés</option>
                    </select>
                </div>
                <div class="md:col-span-3 flex items-center justify-between">
                    <button type="submit" class="gc-btn-secondary">Filtrer</button>
                    <a href="{{ route('manager.delegataires') }}" class="gc-link">Réinitialiser les filtres</a>
                </div>
            </form>
        </section>

        <section class="gc-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b" style="border-color:var(--gc-border);background:#f8f8f8;">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Référence Coffrac</th>
                            <th class="px-4 py-3 font-semibold">Délégataire</th>
                            <th class="px-4 py-3 font-semibold">Email</th>
                            <th class="px-4 py-3 font-semibold">Téléphone</th>
                            <th class="px-4 py-3 font-semibold">Statut</th>
                            <th class="px-4 py-3 font-semibold">Dernière synchro</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($delegataires as $delegataire)
                            <tr class="border-b last:border-b-0" style="border-color:var(--gc-border);">
                                <td class="px-4 py-3 font-mono text-xs">{{ $delegataire->external_id }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold" style="color:var(--gc-text);">{{ $delegataire->name }}</div>
                                    @if ($delegataire->company_name && $delegataire->company_name !== $delegataire->name)
                                        <div class="text-xs" style="color:var(--gc-text-soft);">{{ $delegataire->company_name }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $delegataire->email ?: '--' }}</td>
                                <td class="px-4 py-3">{{ $delegataire->phone ?: '--' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold" style="background:{{ $delegataire->is_active ? '#dcfce7' : '#ffe4e6' }};color:{{ $delegataire->is_active ? '#166534' : '#9f1239' }};">
                                        {{ $delegataire->is_active ? 'Actif' : 'Désactivé' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ $delegataire->last_synced_at?->format('d/m/Y H:i') ?: '--' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center" style="color:var(--gc-text-soft);">Aucun délégataire synchronisé.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t px-4 py-3" style="border-color:var(--gc-border);">
                {{ $delegataires->links() }}
            </div>
        </section>
    </div>
</x-layouts.app>
