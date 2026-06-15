<x-mail::message>
# {{ $eventLabel }}

Bonjour {{ $recipient->full_name }},

{{ $intro }}

<x-mail::panel>
**Client :** {{ trim($appointment->customer_first_name.' '.$appointment->customer_last_name) ?: 'Non renseigné' }}

**Téléphone :** {{ $appointment->customer_phone ?: 'Non renseigné' }}

**Prestation :** {{ $appointment->service ? $appointment->service->type.' - '.$appointment->service->name : 'Non renseignée' }}

**Date :** {{ $appointment->starts_at?->format('d/m/Y') ?? 'Non renseignée' }}

**Horaire :** {{ $appointment->starts_at?->format('H:i') ?? '--:--' }} - {{ $appointment->ends_at?->format('H:i') ?? '--:--' }}

**Durée :** {{ $appointment->duration_minutes ? $appointment->duration_minutes.' min' : 'Non renseignée' }}

**Adresse :** {{ $appointment->address ?: 'Non renseignée' }}

@if ($appointment->comment)
**Commentaire :** {{ $appointment->comment }}
@endif
</x-mail::panel>

Merci de vérifier votre planning avant intervention.

Merci,<br>
{{ config('app.name') }}
</x-mail::message>
