<template data-dossier-comments-content>
    <div class="gc-dossier-comments-heading">Commentaires du dossier <span>{{ $dossier->comments_count }}</span></div>
    @forelse ($dossier->comments as $comment)
        <div class="gc-dossier-comment">
            <p class="gc-dossier-comment-meta">
                <span>{{ $comment->author?->full_name ?: 'Auteur non renseigné' }}</span>
                <time>{{ $comment->created_at?->format('d/m/Y H:i') }}</time>
                @if ($comment->changed_fields['is_private'] ?? false)<span>Privé</span>@endif
            </p>
            <x-comment-body :body="$comment->body" :limit="240" class="gc-dossier-comment-body" />
        </div>
    @empty
        <p class="gc-dossier-comment-empty">Aucun commentaire pour ce dossier.</p>
    @endforelse
    @if ($dossier->comments_count > 0)<p class="gc-dossier-comments-footer">{{ min(3, $dossier->comments_count) }} dernier(s) commentaire(s). Ouvrir les commentaires pour lire l’historique complet.</p>@endif
</template>
