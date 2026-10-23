<x-layouts.app>
    @php
        $states = $dossier->operations->map(fn ($operation) => \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key']);
        $correctionCount = $states->filter(fn ($state) => $state === 'non_compliant')->count();
        $recontrolCount = $states->filter(fn ($state) => $state === 'recontrol')->count();
        $documentCount = ($documents?->total() ?? 0) + ($collected?->total() ?? 0) + ($lotDocuments?->total() ?? 0);
        $canUpload = auth()->user()->can('uploadDocument', $dossier);
        $canComment = auth()->user()->can('comment', $dossier);
        $canRespond = auth()->user()->can('respondToNonConformity', $dossier);
    @endphp
    <div class="gc-dossier-detail gc-portal-dossier">
        <nav class="gc-portal-breadcrumb" aria-label="Fil d’Ariane"><a href="{{ route('client.dossiers') }}"><span aria-hidden="true">←</span> Mes dossiers</a><span aria-hidden="true">/</span><span>Suivi du dossier</span></nav>
        @include('client.notice')
        <header class="gc-portal-hero">
            <div class="gc-portal-hero-copy">
                <p class="gc-portal-eyebrow"><x-nav-icon name="dossiers" />Votre espace de suivi</p>
                <h1>{{ $dossier->beneficiaryLabel() }}</h1>
                <p class="gc-portal-address"><x-nav-icon name="location" /><span>{{ $dossier->address }}<br>{{ $dossier->postal_code }} {{ $dossier->city }}</span></p>
                <div class="gc-portal-meta">
                    @if ($dossier->legacy_lot_name)<span><x-nav-icon name="lots" />{{ $dossier->legacy_lot_name }}</span>@endif
                    @if ($dossier->created_at)<span>Ouvert le {{ $dossier->created_at->format('d/m/Y') }}</span>@endif
                </div>
            </div>
            <div class="gc-portal-hero-status" data-attention="{{ $correctionCount ? 'true' : 'false' }}">
                <span class="gc-portal-icon"><x-nav-icon :name="$correctionCount ? 'alert' : 'tracking'" /></span>
                <div>
                    <p class="gc-portal-eyebrow">{{ $correctionCount ? 'À vous de jouer' : 'Votre suivi' }}</p>
                    <h2>{{ $correctionCount ? 'Des éléments sont attendus' : ($recontrolCount ? 'Votre réponse est en cours d’examen' : 'Votre dossier, au même endroit') }}</h2>
                    <p>{{ $correctionCount ? ($canUpload ? 'Ajoutez les justificatifs demandés, puis sollicitez le recontrôle.' : 'Consultez les éléments partagés et les actions disponibles pour votre compte.') : 'Retrouvez les étapes de vos prestations et les pièces partagées par notre équipe.' }}</p>
                    <a href="#client-follow-up" class="gc-portal-text-link">{{ $correctionCount ? 'Voir ce que je dois faire' : 'Consulter le suivi' }} <span aria-hidden="true">↓</span></a>
                </div>
            </div>
        </header>
        <nav class="gc-portal-nav" aria-label="Rubriques du dossier">
            <a href="#client-follow-up" data-tone="amber"><x-nav-icon name="tracking" /><span>Suivi des prestations</span><strong>{{ $dossier->operations->count() }}</strong></a>
            @if ($documents !== null)<a href="#client-documents" data-tone="blue"><x-nav-icon name="document" /><span>Documents</span><strong>{{ $documentCount }}</strong></a>@endif
            @if ($comments !== null)<a href="#client-comments" data-tone="teal"><x-nav-icon name="comment" /><span>Échanges</span><strong>{{ $comments->total() }}</strong></a>@endif
            <a href="#client-appointments" data-tone="slate"><x-nav-icon name="planning" /><span>Rendez-vous</span><strong>{{ $appointments->total() }}</strong></a>
        </nav>
        <section class="gc-portal-panel" id="client-follow-up" aria-labelledby="client-follow-up-title">
            <header class="gc-portal-panel-heading" data-tone="amber"><div><span class="gc-portal-icon"><x-nav-icon name="tracking" /></span><div><h2 id="client-follow-up-title">Où en est mon dossier ?</h2><p>Une étape et une prochaine action pour chaque prestation.</p></div></div></header>
            <div class="gc-portal-follow-up">
                @include('client.non-conformity')
                @forelse ($dossier->operations as $operation)
                    @php($state = \App\Services\Crm\DossierStatusCatalog::describe($operation->legacy_status)['key'])
                    @continue(in_array($state, ['non_compliant', 'recontrol'], true))
                    <article class="gc-portal-operation">
                        <span class="gc-portal-icon"><x-nav-icon :name="\App\Support\WorkspaceUi::status($state)[0]" /></span>
                        <div><h3>{{ $operation->service?->name ?: $operation->legacy_service_name ?: 'Prestation' }}</h3><p>{{ match ($state) {
                            'pending' => 'Notre équipe prépare la prise de rendez-vous.',
                            'problem' => 'Un problème de rendez-vous est signalé. Notre équipe organise la suite.',
                            'awaiting_visit' => 'Le rendez-vous est placé. Retrouvez les informations de visite ci-dessous.',
                            'visited' => 'La visite est enregistrée. Notre équipe prépare le contrôle du dossier.',
                            'rt_control' => 'Le dossier est en cours de vérification par notre équipe technique.',
                            'report', 'invoiced' => 'Le rapport est validé. Les documents autorisés sont accessibles ci-dessous.',
                            'cancelled' => 'Cette prestation est annulée.',
                            default => 'Notre équipe doit encore préciser l’état de cette prestation.',
                        } }}</p></div><x-dossier-status :status="$operation->legacy_status" />
                    </article>
                @empty
                    <div class="gc-portal-empty"><x-nav-icon name="tracking" /><h3>Le suivi se prépare</h3><p>Aucune prestation n’est encore renseignée sur ce dossier.</p></div>
                @endforelse
            </div>
        </section>
        <div class="gc-portal-columns">
            <div class="gc-portal-main">
                @if ($documents !== null) @include('client.detail.documents') @endif
                @if ($comments !== null) @include('client.detail.comments') @endif
                @if ($documents === null && $comments === null)
                    <section class="gc-portal-panel gc-portal-empty"><x-nav-icon name="lock" /><h2>Un espace adapté à vos accès</h2><p>Votre compte permet de suivre les prestations et rendez-vous. Contactez notre équipe pour un accès aux pièces ou aux échanges.</p></section>
                @endif
            </div>
            @include('client.detail.information')
        </div>
        <p class="gc-portal-security"><x-nav-icon name="lock" />Espace privé · Seuls les éléments partagés avec votre organisation sont affichés.</p>
    </div>
</x-layouts.app>
