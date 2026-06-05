<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Acces {{ config('app.name') }}</title>
</head>
<body style="font-family:Arial,sans-serif;color:#1f2937;line-height:1.5;">
    <p>Bonjour {{ $user->full_name }},</p>
    <p>Un compte a ete cree pour vous sur {{ config('app.name') }}.</p>
    <p><strong>Email :</strong> {{ $user->email }}<br>
       <strong>Mot de passe temporaire :</strong> {{ $plainPassword }}</p>
    <p>Pour des raisons de securite, vous devrez changer ce mot de passe lors de votre premiere connexion.</p>
    <p>Cordialement.</p>
</body>
</html>
