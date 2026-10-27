<x-layouts.app>
    <div class="tech-workspace tech-intervention">
        <nav class="tech-back" aria-label="Navigation intervention"><a href="{{ route('tech.planning', ['date' => $appointment->starts_at->toDateString()]) }}"><span aria-hidden="true">‹</span>Mon planning</a><span>{{ $appointment->starts_at->locale('fr')->isoFormat('ddd D MMM') }}</span></nav>
        <header class="tech-intervention-header">
            <div class="tech-intervention-time"><x-nav-icon name="planning" /><time datetime="{{ $appointment->starts_at->toIso8601String() }}">{{ $appointment->starts_at->format('H:i') }} – {{ $appointment->ends_at->format('H:i') }}</time>@if ($appointment->status === 'problem')<span class="tech-state is-problem">Problème RDV</span>@endif</div>
            <h1>{{ $card['customer'] }}</h1><p class="tech-service">{{ $card['service'] }}</p>
            <p class="tech-address"><x-nav-icon name="location" /><span>{{ $card['address'] ?: 'Adresse non renseignée' }}</span></p>
            <div class="tech-contact-actions">@if ($card['phone']['href'] ?? null)<a href="{{ $card['phone']['href'] }}"><x-nav-icon name="phone" />{{ $card['phone']['label'] }}</a>@endif @if ($card['maps_url'])<a href="{{ $card['maps_url'] }}" target="_blank" rel="noopener noreferrer"><x-nav-icon name="location" />Itinéraire</a>@endif</div>
        </header>
        <section id="tech-documents" class="tech-panel tech-files-panel" aria-labelledby="tech-documents-heading">
            <header class="tech-files-heading"><span class="tech-file-icon"><x-nav-icon name="document" /></span><div><h2 id="tech-documents-heading">Documents du dossier <span>{{ $documentCount }}</span></h2><p>Vos pièces utiles, à portée de main.</p></div></header>
            <form class="tech-document-search" method="GET" action="{{ route('tech.appointments.show', $appointment) }}#tech-documents" role="search" aria-label="Rechercher un document"><label class="sr-only" for="document-q">Nom du document</label><input id="document-q" type="search" name="document_q" value="{{ $documentSearch }}" maxlength="120" class="gc-input" placeholder="Rechercher un document…"><button type="submit" class="tech-small-button"><x-nav-icon name="search" /><span class="sr-only">Rechercher</span></button></form>
            @if ($documentSearch !== '')<p class="tech-search-summary">Recherche : « {{ $documentSearch }} » <a href="{{ route('tech.appointments.show', $appointment) }}#tech-documents">Tout afficher</a></p>@endif
            @if ($documentCount === 0)<div class="tech-empty"><span class="tech-empty-icon"><x-nav-icon name="document" /></span><h3>{{ $documentSearch !== '' ? 'Aucun document trouvé' : 'Aucun document pour le moment' }}</h3><p>{{ $documentSearch !== '' ? 'Essayez un autre nom ou affichez toutes les pièces.' : 'Les pièces ajoutées au dossier apparaîtront ici.' }}</p></div>@endif
            @foreach ($documentGroups as $label => $files)
                @if ($files?->total())<div class="tech-file-group"><h3>{{ $label }} <span>{{ $files->total() }}</span></h3><ul class="tech-files" aria-label="{{ $label }}">@foreach ($files as $file)@include('tech.partials.file')@endforeach</ul>{{ $files->links() }}</div>@endif
            @endforeach
        </section>
        @if ($appointment->comment)<section class="tech-panel tech-instructions" aria-labelledby="tech-instructions-heading"><header><x-nav-icon name="comment" /><h2 id="tech-instructions-heading">Informations du rendez-vous</h2></header><x-comment-body :body="$appointment->comment" /></section>@endif
        <p class="tech-readonly"><x-nav-icon name="lock" />Documents en lecture seule. L’accès suit l’affectation de votre intervention.</p>
        <a class="tech-return" href="{{ route('tech.planning', ['date' => $appointment->starts_at->toDateString()]) }}">Retour à mes interventions</a>
        <x-document-preview />
    </div>
</x-layouts.app>
