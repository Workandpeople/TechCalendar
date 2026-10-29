<x-layouts.app>
    <div class="space-y-6" data-ajax-list="client-dossiers">
        <x-workspace-heading title="Mes dossiers" :eyebrow="auth()->user()->externalClient->name" description="Suivez vos inspections, retrouvez les documents partagés et répondez aux demandes de correction.">
            @can('createFromPortal', \App\Models\Dossier::class)
                <a class="gc-btn-primary" href="{{ route('client.dossiers.create') }}"><x-nav-icon name="plus" /> Nouveau dossier</a>
            @endcan
        </x-workspace-heading>
        @include('client.notice')
        <div data-ajax-results="categories">@include('crm.dossiers.categories', ['categoryRoute' => 'client.dossiers'])</div>
        <form data-ajax-filters method="GET" class="grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">
            <div><label class="gc-label" for="q">Recherche</label><input class="gc-input" id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="255" placeholder="Référence, bénéficiaire, lot, adresse complète"></div>
            <div><label class="gc-label" for="status">État</label><select class="gc-input" id="status" name="status"><option value="">Tous les états autorisés</option>@foreach (collect($categories)->except('all') as $key => $status)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $status['label'] }}</option>@endforeach</select></div>
            <div class="flex items-end gap-3"><noscript><button class="gc-btn-primary">Rechercher</button></noscript><a class="gc-btn-secondary" href="{{ route('client.dossiers') }}">Effacer</a></div>
        </form>
        <div data-ajax-results="dossiers">
        <p class="text-sm text-slate-500">{{ $dossiers->total() }} dossier(s)</p>
        <div class="overflow-x-auto rounded-xl border border-slate-200"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Voir</th><th class="p-4">Numéro de lot</th><th class="p-4">Bénéficiaire</th><th class="hidden p-4 md:table-cell">Date RDV</th><th class="p-4">Lieu d’inspection</th><th class="p-4">Prestations et états</th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse ($dossiers as $dossier)
                @php($appointment = $dossier->appointments->first())
                <tr>
                    <td class="p-4"><a class="gc-dossier-open" href="{{ route('client.dossiers.show', $dossier) }}" aria-label="Ouvrir le dossier {{ $dossier->beneficiaryLabel() }}"><x-nav-icon name="eye" /></a></td>
                    <td class="p-4 font-medium">{{ $dossier->legacy_lot_name ?: '—' }}</td>
                    <td class="p-4"><span class="font-medium">{{ $dossier->beneficiaryLabel() }}</span><div class="mt-3 md:hidden">@include('client.partials.appointment-date')</div></td>
                    <td class="hidden p-4 md:table-cell">@include('client.partials.appointment-date')</td>
                    <td class="p-4">{{ $dossier->address }}<p class="text-slate-500">{{ $dossier->postal_code }} {{ $dossier->city }}</p></td>
                    <td class="space-y-2 p-4">@foreach ($dossier->operations as $operation)<p>{{ $operation->service?->name ?: $operation->legacy_service_name }} <x-dossier-status :status="$operation->legacy_status" /></p>@endforeach</td>
                </tr>
            @empty<tr><td colspan="6" class="p-8 text-center text-slate-500">Aucun dossier attribué ne correspond à la recherche.</td></tr>@endforelse
        </tbody></table></div>
        {{ $dossiers->links() }}
        </div>
    </div>
</x-layouts.app>
