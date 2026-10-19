<x-layouts.app :crm-navigation="true">
    <div class="mx-auto max-w-6xl space-y-6">
        <a class="gc-link" href="{{ $dossier->exists ? route('v2.dossiers.show', $dossier) : route('v2.dossiers.index') }}">← Retour aux dossiers</a>
        <x-workspace-heading :title="$dossier->exists ? 'Modifier le dossier' : 'Nouveau dossier'" eyebrow="Informations du dossier" description="Renseignez séparément le bénéficiaire, le lieu d’inspection et l’installateur. Les champs marqués d’un astérisque sont obligatoires." />
        @include('crm.dossiers.notice')
        @include('crm.dossiers.form-fields')
    </div>
</x-layouts.app>
