<x-layouts.app>
    <div class="space-y-6">
        <x-workspace-heading title="Mes lots" :eyebrow="auth()->user()->externalClient->name" description="Consultez l’avancement et les résultats des lots attribués à votre organisation." />
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">@forelse ($lots as $lot)<a class="gc-card space-y-3 p-5 transition-shadow hover:shadow-md" href="{{ route('client.lots.show', $lot) }}"><h2 class="text-lg font-semibold">{{ $lot->name }}</h2><p class="text-sm text-slate-500">{{ \App\Models\Lot::types()[$lot->type] ?? $lot->type }}</p><p>{{ \App\Models\Lot::statusLabelFor($lot->status) }}</p><p class="text-sm">{{ $lot->appointments_count }} dossier(s) · Réception {{ $lot->received_at?->format('d/m/Y') ?: 'non renseignée' }}</p></a>@empty<p class="py-6 text-slate-500">Aucun lot attribué.</p>@endforelse</div>
        {{ $lots->links() }}
    </div>
</x-layouts.app>
