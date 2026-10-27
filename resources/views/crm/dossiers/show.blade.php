<x-layouts.app :crm-navigation="true">
    @php
        $planningRoute = (auth()->user()->admin || auth()->user()->role === 0) ? 'manager.appointments' : 'planner.tracking';
        $documentCount = $documents->total() + $legacyDocuments->total() + $lotDocuments->total();
    @endphp
    <div class="gc-dossier-detail space-y-5" data-dossier-id="{{ $dossier->id }}">
        <a href="{{ route('v2.dossiers.index') }}" class="gc-link text-sm">← Tous les dossiers</a>
        <x-workspace-heading :title="$dossier->beneficiaryLabel()" eyebrow="Détail du dossier" :description="'Créé le '.$dossier->created_at?->format('d/m/Y').($dossier->legacy_lot_name ? ' · Lot '.$dossier->legacy_lot_name : '')">
            @if ($smsUnavailable === null)<a class="gc-btn-secondary" href="#composer-sms"><x-nav-icon name="comment" /> SMS</a>@endif
            <a class="gc-btn-secondary" href="{{ route('v2.dossiers.edit', $dossier) }}" data-dialog-target="modifier-dossier"><x-nav-icon name="edit" /> Modifier les informations</a>
        </x-workspace-heading>
        @can('delete', $dossier)
            <div class="flex justify-end"><a href="#supprimer-dossier" class="gc-link text-sm"><x-nav-icon name="trash" />Supprimer le dossier</a></div>
            <x-dossier-dialog id="supprimer-dossier" title="Supprimer ce dossier ?">
                <form method="POST" action="{{ route('v2.dossiers.destroy', $dossier) }}" class="space-y-3">
                    @csrf @method('DELETE')<input type="hidden" name="version" value="{{ $dossier->version }}">
                    <p>Le dossier sera retiré des listes actives et des accès externes. Ses pièces, rendez-vous, historique et marquage de facturation seront conservés. Un gérant pourra le restaurer.</p>
                    <label class="gc-label">Motif (facultatif)<textarea name="reason" class="gc-input" maxlength="1000" rows="2"></textarea></label>
                    <footer class="gc-dialog-actions"><button type="button" data-dialog-close class="gc-btn-secondary">Annuler</button><button type="submit" class="gc-btn-danger">Confirmer la suppression</button></footer>
                </form>
            </x-dossier-dialog>
        @endcan
        @include('crm.dossiers.notice')
        @php($billingSummary = \App\Services\Crm\DossierBillingService::summary($dossier))
        <div class="flex flex-wrap items-center gap-2">
            <a href="#facturation" class="inline-block"><x-billing-badge :label="$billingSummary['label']" :tone="$billingSummary['tone']" /></a>
            @if ($billingSummary['tone'] !== 'slate')<x-billing-date :date="\App\Services\Crm\DossierBillingService::firstMarkedAt($dossier)" />@endif
        </div>
        <nav class="gc-dossier-tabs" aria-label="Sections du dossier">
            @foreach (['documents' => ['Documents', 'document'], 'commentaires' => ['Commentaires', 'comment'], 'synthese' => ['Dossier', 'dossiers'], 'prestations' => ['Statut et fiches', 'services'], 'facturation' => ['Facturation', 'check'], 'rendez-vous' => ['Rendez-vous', 'planning'], 'capcee' => ['CAPCEE', 'upload'], 'historique' => ['Historique', 'tracking']] as $id => [$label, $icon])
                <a href="#{{ $id }}"><x-nav-icon :name="$icon" />{{ $label }}</a>
            @endforeach
        </nav>
        <div class="gc-dossier-columns">
            <div class="gc-dossier-main" aria-label="Documents, commentaires et informations du dossier">
                @include('crm.dossiers.detail.documents')
                @include('crm.dossiers.detail.comments')
                @include('crm.dossiers.detail.mail')
                @if ($smsUnavailable === null)@include('crm.dossiers.detail.sms')@endif
                @include('crm.dossiers.detail.information')
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
        <x-dossier-dialog id="modifier-dossier" title="Modifier les informations du dossier" :reopen="$errors->any() && old('_form') === 'information'">
            @include('crm.dossiers.form-fields')
        </x-dossier-dialog>
    </div>
</x-layouts.app>
