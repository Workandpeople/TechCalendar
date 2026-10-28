<section id="historique" class="gc-card gc-dossier-panel" aria-labelledby="dossier-history-title">
    <header class="gc-dossier-panel-heading"><h2 id="dossier-history-title"><x-nav-icon name="tracking" />Historique <span class="gc-dossier-count">{{ $activities->total() }}</span></h2></header>
    <div class="gc-dossier-panel-body">
        <p class="mb-4 text-xs text-slate-500">Traçabilité interne des modifications. Les échanges se trouvent dans <a href="#commentaires" class="gc-link underline">Commentaires</a>.</p>
        <ol class="gc-dossier-history">
            @forelse ($activities as $activity)
                <li>
                    <time class="text-xs font-semibold text-slate-600" datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->format('d/m/Y H:i') }}</time>
                    <p class="mt-1 text-xs text-slate-500">{{ $activity->author?->full_name ?: (str_starts_with($activity->kind, 'legacy_') ? 'Auteur Cofrac #'.($activity->changed_fields['source_author_id'] ?? '?') : 'Système') }}</p>
                    <x-comment-body :body="$activity->body" class="mt-2 text-sm" />
                </li>
            @empty
                <li class="text-sm text-slate-500">Aucune modification enregistrée.</li>
            @endforelse
        </ol>
        <div class="mt-4">{{ $activities->links() }}</div>
    </div>
</section>
