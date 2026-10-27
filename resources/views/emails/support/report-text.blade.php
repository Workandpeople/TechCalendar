ESPACE COFRAC - {{ $report['kind'] === 'improvement' ? 'AMÉLIORATION' : 'ERREUR' }}
{{ $report['title'] }}
Référence : {{ $report['id'] }}

{{ $report['message'] }}

@foreach (['received_at' => 'Réception', 'occurred_at' => 'Heure de l’erreur', 'actor_id' => 'Identifiant du compte', 'actor_name' => 'Nom', 'actor_email' => 'Email du compte', 'page' => 'Page (sans paramètres ni jeton)', 'route' => 'Route', 'method' => 'Méthode HTTP', 'status' => 'Code HTTP', 'browser' => 'Navigateur', 'viewport' => 'Dimensions de l’écran', 'environment' => 'Environnement', 'php' => 'Version PHP', 'exception_class' => 'Type d’erreur'] as $key => $label)
@if (filled($report[$key] ?? null)){{ $label }} : {{ $report[$key] }}
@endif
@endforeach

@if (!empty($report['identifiers']))Identifiants métiers : {{ json_encode($report['identifiers']) }}
@endif

@foreach (($report['trace'] ?? []) as $frame)
{{ $frame['file'] }}:{{ $frame['line'] }}
@endforeach

{{ count($report['screenshots']) }} capture(s) jointe(s).
Aucun cookie, mot de passe, jeton, corps de requête ou message brut d’exception n’est collecté automatiquement.
Genius Contrôle - Rapport transmis à la demande de l’utilisateur.
