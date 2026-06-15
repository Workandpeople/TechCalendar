<x-mail::message>
# Bienvenue {{ $user->full_name }}

Un compte a été créé pour vous sur **{{ config('app.name') }}**.

<x-mail::panel>
**Email :** {{ $user->email }}

**Mot de passe temporaire :** {{ $plainPassword }}
</x-mail::panel>

Pour des raisons de sécurité, vous devrez changer ce mot de passe lors de votre première connexion.

Merci,<br>
{{ config('app.name') }}
</x-mail::message>
