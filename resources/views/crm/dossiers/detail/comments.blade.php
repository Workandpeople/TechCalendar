<section id="commentaires" class="gc-card gc-dossier-panel" aria-labelledby="dossier-comments-title">
    <header class="gc-dossier-panel-heading">
        <h2 id="dossier-comments-title"><x-nav-icon name="comment" />Commentaires <span class="gc-dossier-count">{{ $comments->total() }}</span></h2>
        <a href="#ajouter-commentaire" class="gc-btn-soft"><x-nav-icon name="plus" /> Ajouter un commentaire</a>
    </header>
    <div class="gc-dossier-panel-body">
        <ol class="gc-dossier-comments">
            @forelse ($comments as $comment)
                <li class="gc-dossier-comment">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-semibold">{{ $comment->author?->full_name ?: ($comment->kind === 'legacy_comment' ? 'Auteur Coffrac #'.($comment->changed_fields['source_author_id'] ?? '?') : 'Système') }}</p>
                        <time class="text-xs text-slate-500" datetime="{{ $comment->created_at?->toIso8601String() }}">{{ $comment->created_at?->format('d/m/Y H:i') }}</time>
                    </div>
                    <x-comment-body :body="$comment->body" class="mt-3 text-sm" />
                    <div class="gc-dossier-comment-sharing mt-3">
                        <x-dossier-sharing :action="route('v2.dossiers.comments.sharing', [$dossier, $comment->id])"
                            :visible="app(\App\Services\Crm\ClientAccess::class)->commentVisibleToClient($comment, $dossier->delegataire?->kind ?? 'client')"
                            :label="'Commentaire #'.$comment->id" />
                    </div>
                </li>
            @empty
                <li class="gc-dossier-empty">Aucun commentaire pour le moment.</li>
            @endforelse
        </ol>
        {{ $comments->links() }}
        <form id="ajouter-commentaire" method="POST" action="{{ route('v2.dossiers.comments.store', $dossier) }}" class="gc-dossier-compose mt-5 space-y-3">
            @csrf
            <label for="comment_body" class="gc-label">Ajouter un commentaire (privé par défaut)</label>
            <textarea id="comment_body" name="body" rows="3" maxlength="10000" class="gc-input" required>{{ old('body') }}</textarea>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="client_visible" value="1">Public : partager avec le client affecté</label>
            <p class="text-xs text-slate-500">Visible uniquement si son compte autorise la lecture des commentaires. Les délégataires n’ont pas ce droit par défaut.</p>
            <div class="flex justify-end"><button type="submit" class="gc-btn-primary"><x-nav-icon name="comment" />Ajouter le commentaire</button></div>
        </form>
    </div>
</section>
