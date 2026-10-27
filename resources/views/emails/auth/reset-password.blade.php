<x-mail::message>
# Réinitialiser votre mot de passe

Bonjour {{ $recipient->full_name ?: '' }},

Vous avez demandé à réinitialiser le mot de passe de votre **espace Cofrac**.
Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.

<x-mail::button :url="$actionUrl">
Choisir mon nouveau mot de passe
</x-mail::button>

Ce lien est valable **{{ $expires }} minutes**. Après ce délai, vous pourrez demander un nouveau lien depuis la page de connexion.

Si vous n’êtes pas à l’origine de cette demande, ignorez cet email : votre mot de passe reste inchangé.
Ne communiquez ce lien à personne.

À bientôt,<br>
L’équipe Genius Contrôle

<x-slot:subcopy>
Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur : [{{ $actionUrl }}]({{ $actionUrl }})
</x-slot:subcopy>
</x-mail::message>
