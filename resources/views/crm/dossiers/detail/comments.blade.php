<section id="commentaires" class="gc-card gc-dossier-panel" aria-labelledby="dossier-comments-title">
    <header class="gc-dossier-panel-heading">
        <h2 id="dossier-comments-title"><x-nav-icon name="comment" />Commentaires <span class="gc-dossier-count">{{ $comments->total() }}</span></h2>
        <a href="#ajouter-commentaire" class="gc-btn-info"><x-nav-icon name="plus" /> Ajouter un commentaire</a>
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
                            :visible="app(\App\Services\Crm\ClientAccess::class)->commentVisibleToClient($comment, 'client')"
                            :delegataire-visible="app(\App\Services\Crm\ClientAccess::class)->commentVisibleToClient($comment, 'delegataire')"
                            :label="'Commentaire #'.$comment->id" />
                    </div>
                    @if (in_array($comment->kind, ['comment', 'legacy_comment'], true))
                        <a href="#supprimer-commentaire-{{ $comment->id }}" class="gc-btn-danger mt-3"><x-nav-icon name="trash" />Supprimer</a>
                        <x-dossier-dialog :id="'supprimer-commentaire-'.$comment->id" title="Supprimer le commentaire">
                            <form method="POST" action="{{ route('v2.dossiers.comments.destroy', [$dossier, $comment->id]) }}" class="mt-2 space-y-2">@csrf @method('DELETE')<p class="text-xs">Ce commentaire ne sera plus visible sur la fiche ni dans l’espace externe. Sa trace est conservée.</p><button type="submit" class="gc-btn-danger">Confirmer la suppression</button></form>
                        </x-dossier-dialog>
                    @else
                        <p class="mt-3 text-xs text-slate-500">Commentaire de contrôle : conservé pour la traçabilité du workflow.</p>
                    @endif
                </li>
            @empty
                <li class="gc-dossier-empty">Aucun commentaire pour le moment.</li>
            @endforelse
        </ol>
        {{ $comments->links() }}
        <x-dossier-dialog id="ajouter-commentaire" title="Ajouter un commentaire" :reopen="$errors->any() && old('_form') === 'comment'">
        <form method="POST" action="{{ route('v2.dossiers.comments.store', $dossier) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="_form" value="comment">
            <label for="comment_body" class="gc-label">Ajouter un commentaire (client uniquement par défaut)</label>
            <textarea id="comment_body" name="body" rows="3" maxlength="10000" class="gc-input" required>{{ old('body') }}</textarea>
            <x-content-audience :value="old('body') ? old('audience', 'client') : 'client'" />
            <p class="text-xs text-slate-500">Visible uniquement si son compte autorise la lecture des commentaires. Les délégataires n’ont pas ce droit par défaut.</p>
            <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button type="submit" class="gc-btn-primary"><x-nav-icon name="comment" />Ajouter le commentaire</button></footer>
        </form>
        </x-dossier-dialog>
    </div>
</section>
