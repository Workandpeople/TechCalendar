<x-layouts.app>
    <div class="space-y-6">
        <x-workspace-heading title="Mes dossiers" :eyebrow="auth()->user()->externalClient->name" description="Suivez vos inspections, retrouvez les documents partagés et répondez aux demandes de correction." />
        @include('client.notice')
        <form method="GET" class="grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">
            <div><label class="gc-label" for="q">Recherche</label><input class="gc-input" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Référence, bénéficiaire, lot"></div>
            <div><label class="gc-label" for="status">État</label><select class="gc-input" id="status" name="status"><option value="">Tous les états</option>@foreach (\App\Services\Crm\DossierStatusCatalog::STATUSES as $key => $status)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $status['label'] }}</option>@endforeach</select></div>
            <div class="flex items-end gap-3"><button class="gc-btn-primary">Rechercher</button><a class="gc-btn-secondary" href="{{ route('client.dossiers') }}">Effacer</a></div>
        </form>
        <p class="text-sm text-slate-500">{{ $dossiers->total() }} dossier(s)</p>
        <div class="overflow-x-auto rounded-xl border border-slate-200"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr>@foreach (['Dossier / lot', 'Bénéficiaire', 'Lieu d’inspection', 'Prestations et états', ''] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead><tbody class="divide-y divide-slate-100">
            @forelse ($dossiers as $dossier)<tr><td class="p-4 font-medium">{{ $dossier->reference }}<p class="text-xs text-slate-500">{{ $dossier->legacy_lot_name }}</p></td><td class="p-4">{{ $dossier->beneficiaryLabel() }}</td><td class="p-4">{{ $dossier->address }}<p class="text-slate-500">{{ $dossier->postal_code }} {{ $dossier->city }}</p></td><td class="space-y-2 p-4">@foreach ($dossier->operations as $operation)<p>{{ $operation->service?->name ?: $operation->legacy_service_name }} <x-dossier-status :status="$operation->legacy_status" /></p>@endforeach</td><td class="p-4 text-right"><a class="gc-btn-secondary" href="{{ route('client.dossiers.show', $dossier) }}">Voir</a></td></tr>
            @empty<tr><td colspan="5" class="p-8 text-center text-slate-500">Aucun dossier attribué ne correspond à la recherche.</td></tr>@endforelse
        </tbody></table></div>
        {{ $dossiers->links() }}
    </div>
</x-layouts.app>
