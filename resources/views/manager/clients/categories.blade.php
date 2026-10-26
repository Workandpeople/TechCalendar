<x-layouts.app>
    <div class="mx-auto max-w-6xl space-y-6">
        <a class="gc-link" href="{{ route('manager.delegataires') }}">← Clients et délégataires</a>
        <x-workspace-heading title="Catégories et accès aux dossiers" eyebrow="Espace externe" description="Définissez les catégories autorisées par défaut. Chaque organisation peut hériter de ces réglages ou avoir sa propre sélection." />
        @include('client.notice')
        @include('manager.clients.category-warning')
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (\App\Models\ExternalDelegataire::KINDS as $kind => $label)
                <form method="POST" action="{{ route('manager.clients.categories.update', $kind) }}" class="gc-card space-y-5 p-5">
                    @csrf @method('PUT')
                    <input type="hidden" name="_category_kind" value="{{ $kind }}">
                    <h2 class="text-lg font-semibold">{{ $label === 'Client' ? 'Partenaires clients' : 'Délégataires' }}</h2>
                    @if ($kind === 'client')
                        <p class="text-sm">Tous les dossiers attribués sont accessibles, sauf les dossiers supprimés. Les droits sur les documents et commentaires restent applicables.</p>
                    @else
                    <p class="text-sm text-slate-500">Appliqué aux organisations de ce type qui n’ont pas de réglage personnalisé. Rapport Genius est toujours accessible.</p>
                    @include('manager.clients.category-checkboxes', ['selectedCategories' => old('_category_kind') === $kind ? old('categories', []) : $defaults[$kind], 'categoryPrefix' => $kind, 'categoryKind' => $kind])
                    <button class="gc-btn-primary" type="submit">Enregistrer pour ce type</button>
                    @endif
                </form>
            @endforeach
        </div>
    </div>
</x-layouts.app>
