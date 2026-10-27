<x-mail::message>
# Confirmer votre adresse email

Bonjour {{ $recipient->full_name ?: '' }},

Pour confirmer l’adresse email de votre **espace Cofrac**, cliquez sur le bouton ci-dessous.

<x-mail::button :url="$actionUrl">
Confirmer mon adresse email
</x-mail::button>

Si vous n’avez pas créé de compte, vous pouvez ignorer cet email.

À bientôt,<br>
L’équipe Genius Contrôle

<x-slot:subcopy>
Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur : [{{ $actionUrl }}]({{ $actionUrl }})
</x-slot:subcopy>
</x-mail::message>
