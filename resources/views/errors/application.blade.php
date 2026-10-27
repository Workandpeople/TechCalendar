@extends('errors.frame')
@section('content')
    <p class="eyebrow">VOTRE ESPACE RESTE NOTRE PRIORITÉ</p>
    <div class="code" aria-label="Erreur {{ $status }}">{{ $status }}</div>
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>
    <div class="actions">
        <a class="button" href="/">Retour à mon espace</a>
        @if (in_array($status, [401, 404, 419], true))<a class="button" href="/login">Se connecter</a>@endif
    </div>
    @if ($status !== 404 && $errorToken)
    <section class="diagnostic" aria-label="Signaler cette erreur">
        <p>Un problème à nous signaler ? Envoyez le diagnostic au développeur pour nous aider à le résoudre.</p>
        <p class="reference">Référence : {{ $reference }}</p>
        <form action="/support/error" method="POST">
            <input type="hidden" name="error_token" value="{{ $errorToken }}">
            <button type="submit">Envoyer au développeur</button>
        </form>
        <p class="hint">Aucun envoi automatique. Le diagnostic ne contient ni mot de passe, ni cookie, ni contenu de formulaire. Ce lien d’envoi reste valable 30 minutes.</p>
    </section>
    @endif
@endsection
