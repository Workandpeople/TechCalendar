<section id="client-comments" class="gc-portal-panel" aria-labelledby="client-comments-title">
    <header class="gc-portal-panel-heading" data-tone="teal">
        <div><span class="gc-portal-icon"><x-nav-icon name="comment" /></span><div><h2 id="client-comments-title">Échanges avec notre équipe <span class="gc-portal-count">{{ $comments->total() }}</span></h2><p>Les messages partagés, du plus récent au plus ancien.</p></div></div>
        @if ($canComment)<a class="gc-btn-info" href="#client-comment-create"><x-nav-icon name="plus" />Écrire un message</a>@endif
    </header>
    <div class="gc-portal-panel-body">
        <ol class="gc-portal-comments">
            @forelse ($comments as $comment)
                <li class="gc-portal-comment" data-own="{{ $comment->author_id === auth()->id() ? 'true' : 'false' }}">
                    <header><span><x-nav-icon name="comment" />{{ $comment->author_id === auth()->id() ? 'Vous' : 'Message partagé' }}</span><time datetime="{{ $comment->created_at?->toIso8601String() }}">{{ $comment->created_at?->format('d/m/Y à H:i') }}</time></header>
                    <x-comment-excerpt :body="$comment->body" />
                </li>
            @empty
                <li class="gc-portal-empty"><span class="gc-portal-icon"><x-nav-icon name="comment" /></span><h3>Les échanges commencent ici</h3><p>Aucun commentaire partagé pour le moment.</p></li>
            @endforelse
        </ol>
        {{ $comments->withQueryString()->links() }}
    </div>
    @if ($canComment)
        <x-dossier-dialog id="client-comment-create" title="Écrire un message" :reopen="old('_form') === 'client-comment'">
            <form method="POST" class="gc-portal-form" action="{{ route('client.dossiers.comments', $dossier) }}">
                @csrf
                <input type="hidden" name="_form" value="client-comment">
                <p class="gc-portal-form-intro">Votre message sera partagé avec notre équipe et les comptes autorisés de votre organisation. Il ne déclenche pas de demande de recontrôle.</p>
                <div><label for="body" class="gc-label">Votre commentaire</label><textarea id="body" name="body" rows="6" maxlength="10000" class="gc-input" required placeholder="Précisez votre question ou les corrections apportées…">{{ old('_form') === 'client-comment' ? old('body') : '' }}</textarea></div>
                <footer class="gc-dialog-actions"><button type="button" class="gc-btn-secondary" data-dialog-close>Annuler</button><button class="gc-btn-primary" type="submit"><x-nav-icon name="comment" />Envoyer le commentaire</button></footer>
            </form>
        </x-dossier-dialog>
    @endif
</section>
