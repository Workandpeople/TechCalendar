@php($appointment = $card['appointment'])
<li class="tech-appointment" data-appointment-id="{{ $appointment->id }}" data-problem="{{ $appointment->status === 'problem' ? 'true' : 'false' }}">
    <div class="tech-appointment-top"><div class="tech-time"><time datetime="{{ $appointment->starts_at->toIso8601String() }}">{{ $appointment->starts_at->format('H:i') }}</time><span>→ {{ $appointment->ends_at->format('H:i') }}</span></div>
        @if ($appointment->status === 'problem')<span class="tech-state is-problem">Problème RDV</span>@elseif ($card['ongoing'])<span class="tech-state">En cours</span>@else<span class="tech-state is-scheduled">Planifié</span>@endif
    </div>
    <h3>@if ($card['detail_url'])<a href="{{ $card['detail_url'] }}">{{ $card['customer'] }}</a>@else{{ $card['customer'] }}@endif</h3>
    <p class="tech-service"><x-nav-icon name="services" />{{ $card['service'] }}</p>
    <p class="tech-address"><x-nav-icon name="location" /><span>{{ $card['address'] ?: 'Adresse non renseignée' }}</span></p>
    @if ($card['detail_url'])<a class="tech-documents-button" href="{{ $card['detail_url'] }}#tech-documents"><x-nav-icon name="document" /><span>Documents du dossier<small>Consulter et télécharger les pièces</small></span><span aria-hidden="true">›</span></a>@endif
    <div class="tech-contact-actions">
        @if ($card['phone']['href'] ?? null)<a href="{{ $card['phone']['href'] }}"><x-nav-icon name="phone" />Appeler<span class="sr-only"> {{ $card['customer'] }}</span></a>@else<span>Téléphone non renseigné</span>@endif
        @if ($card['maps_url'])<a href="{{ $card['maps_url'] }}" target="_blank" rel="noopener noreferrer"><x-nav-icon name="location" />Itinéraire<span class="sr-only"> vers {{ $card['customer'] }}</span></a>@endif
    </div>
</li>
