<x-layouts.app :crm-navigation="true">
    @php
        $planningRoute = (auth()->user()->admin || auth()->user()->role === 0) ? 'manager.appointments' : 'planner.tracking';
        $documentCount = $documents->total() + $legacyDocuments->total() + $lotDocuments->total();
    @endphp
    <div class="gc-dossier-detail space-y-5">
        <a href="{{ route('v2.dossiers.index') }}" class="gc-link text-sm">← Tous les dossiers</a>
        <x-workspace-heading :title="$dossier->beneficiaryLabel()" :eyebrow="$dossier->reference" :description="'Créé le '.$dossier->created_at?->format('d/m/Y').($dossier->legacy_lot_name ? ' · Lot '.$dossier->legacy_lot_name : '')">
            <a class="gc-btn-secondary" href="#composer-sms"><x-nav-icon name="comment" /> SMS</a>
            <a class="gc-btn-secondary" href="{{ route('v2.dossiers.edit', $dossier) }}"><x-nav-icon name="edit" /> Modifier les informations</a>
        </x-workspace-heading>
        @include('crm.dossiers.notice')
        @php($billingSummary = \App\Services\Crm\DossierBillingService::summary($dossier))
        <a href="#facturation" class="inline-block"><x-billing-badge :label="$billingSummary['label']" :tone="$billingSummary['tone']" /></a>
        <nav class="gc-dossier-tabs" aria-label="Sections du dossier">
            @foreach (['documents' => ['Documents', 'document'], 'commentaires' => ['Commentaires', 'comment'], 'synthese' => ['Dossier', 'dossiers'], 'prestations' => ['Statut et fiches', 'services'], 'facturation' => ['Facturation', 'check'], 'rendez-vous' => ['Rendez-vous', 'planning'], 'apres-visite' => ['Avis et contrôle', 'check'], 'capcee' => ['CAPCEE', 'upload'], 'historique' => ['Historique', 'tracking']] as $id => [$label, $icon])
                <a href="#{{ $id }}"><x-nav-icon :name="$icon" />{{ $label }}</a>
            @endforeach
        </nav>
        <div class="gc-dossier-columns">
            <div class="gc-dossier-main" aria-label="Documents, commentaires et informations du dossier">
                @include('crm.dossiers.detail.documents')
                @include('crm.dossiers.detail.comments')
                @include('crm.dossiers.detail.sms')
                @include('crm.dossiers.detail.information')
                @include('crm.dossiers.post-visit')
                @include('crm.dossiers.detail.capcee')
            </div>
            <aside class="gc-dossier-side" aria-label="Suivi du dossier">
                @include('crm.dossiers.detail.operations')
                @include('crm.dossiers.detail.billing')
                @include('crm.dossiers.detail.appointments')
                @include('crm.dossiers.detail.contacts')
                @include('crm.dossiers.detail.history')
            </aside>
        </div>
    </div>
</x-layouts.app>
