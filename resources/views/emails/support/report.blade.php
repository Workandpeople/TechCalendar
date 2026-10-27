<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Signalement Cofrac</title></head>
<body style="margin:0;background:#edf3f7;font-family:Verdana,sans-serif;color:#20394c;padding:24px;">
<div style="max-width:660px;margin:auto;background:white;border-radius:16px;padding:28px;">
    <img src="{{ rtrim(config('app.url'), '/') }}/images/logo.png" alt="Genius Contrôle" width="64" style="width:64px;height:auto;">
    <p style="color:#1255c9;font-size:12px;font-weight:bold;letter-spacing:1px;">ESPACE COFRAC · {{ $report['kind'] === 'improvement' ? 'AMÉLIORATION' : 'SIGNALEMENT D’ERREUR' }}</p>
    <h1 style="font-size:22px;">{{ $report['title'] }}</h1>
    <p style="font-size:12px;">Référence : {{ $report['id'] }}</p>
    <div style="white-space:pre-wrap;background:#f3f7fb;padding:16px;border-radius:8px;">{{ $report['message'] }}</div>
    <h2 style="font-size:16px;">Contexte</h2>
    @foreach (['received_at' => 'Réception', 'occurred_at' => 'Heure de l’erreur', 'actor_id' => 'Identifiant du compte', 'actor_name' => 'Nom', 'actor_email' => 'Email du compte', 'page' => 'Page (sans paramètres ni jeton)', 'route' => 'Route', 'method' => 'Méthode HTTP', 'status' => 'Code HTTP', 'browser' => 'Navigateur', 'viewport' => 'Dimensions de l’écran', 'environment' => 'Environnement', 'php' => 'Version PHP', 'exception_class' => 'Type d’erreur'] as $key => $label)
        @if (filled($report[$key] ?? null))<p style="font-size:13px;margin:8px 0;"><strong>{{ $label }} :</strong> {{ $report[$key] }}</p>@endif
    @endforeach
    @if (!empty($report['identifiers']))<p style="font-size:13px;"><strong>Identifiants métiers :</strong> {{ json_encode($report['identifiers']) }}</p>@endif
    @if (!empty($report['trace']))
        <h2 style="font-size:16px;">Emplacements techniques</h2>
        @foreach ($report['trace'] as $frame)<p style="font-family:monospace;font-size:12px;">{{ $frame['file'] }}:{{ $frame['line'] }}</p>@endforeach
    @endif
    <p style="font-size:12px;color:#61778a;">{{ count($report['screenshots']) }} capture(s) jointe(s). Aucun cookie, mot de passe, jeton de connexion, corps de requête ou message brut d’exception n’est collecté automatiquement.</p>
    <p style="font-size:12px;color:#61778a;">Genius Contrôle · Rapport transmis à la demande de l’utilisateur.</p>
</div></body></html>
