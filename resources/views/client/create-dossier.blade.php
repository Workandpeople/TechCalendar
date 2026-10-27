<x-layouts.app>
    <div class="mx-auto max-w-5xl space-y-6">
        <a class="gc-link inline-flex items-center gap-2" href="{{ route('client.dossiers') }}"><span aria-hidden="true">←</span> Retour à mes dossiers</a>
        <x-workspace-heading title="Nouveau dossier" :eyebrow="auth()->user()->externalClient->name" description="Renseignez le bénéficiaire, le lieu de visite et les prestations. Notre équipe prendra ensuite en charge le rendez-vous." />
        @include('client.notice')
        <div class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <x-nav-icon name="book" />
            <p>Votre dossier sera rattaché à <strong>{{ auth()->user()->externalClient->name }}</strong> et transmis en <strong>Prise de RDV</strong>.</p>
        </div>
        @include('crm.dossiers.form-fields', ['portalCreation' => true])
    </div>
</x-layouts.app>
