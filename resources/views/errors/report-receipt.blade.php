@extends('errors.frame', ['title' => $delivery === 'sent' ? 'Merci pour votre signalement' : 'Votre signalement'])
@section('content')
    <p class="eyebrow">RETOUR UTILISATEUR</p>
    <h1>{{ $delivery === 'sent' ? 'Merci pour votre aide' : 'Votre signalement est enregistré' }}</h1>
    <p role="status">{{ $message }}</p>
    <p class="reference">Référence : {{ $reference }}</p>
    <div class="actions"><a class="button" href="/">Retour à mon espace</a></div>
    @if ($delivery === 'failed')<p class="hint">Vous pouvez revenir à la page précédente et réessayer l’envoi. La même référence sera réutilisée.</p>@endif
@endsection
