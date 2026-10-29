<x-mail::message>
# {{ $title }}

Bonjour {{ $recipientName }},

{{ $description }}

<x-mail::panel>
**Dossier :** {{ $dossierLabel }}
</x-mail::panel>

<x-mail::button :url="$url">
Consulter mon dossier
</x-mail::button>

Vous pouvez modifier les événements suivis ou désactiver ces emails dans [Mon profil]({{ route('profile') }}#notifications).

À bientôt,<br>
L’équipe Genius Contrôle
</x-mail::message>
