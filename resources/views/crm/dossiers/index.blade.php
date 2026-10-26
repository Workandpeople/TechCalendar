<x-layouts.app :crm-navigation="true">
    <div class="space-y-6" data-dossier-list data-preferences-key="techcalendar:dossiers:v1:{{ auth()->id() }}">
        <x-workspace-heading title="Les dossiers" eyebrow="Suivi des inspections" description="Retrouvez une inspection, consultez ses pièces et faites avancer ses prestations, de la prise de RDV au rapport.">
            <a href="{{ route('v2.dossiers.export', $filters) }}" data-dossier-export data-export-url="{{ route('v2.dossiers.export') }}" class="gc-btn-success"><x-nav-icon name="download" />Exporter les résultats filtrés</a>
            <a href="{{ route('v2.dossiers.create') }}" class="gc-btn-primary"><x-nav-icon name="plus" /> Nouveau dossier</a>
        </x-workspace-heading>
        @include('crm.dossiers.notice')
        @include('crm.dossiers.filters')
        <section aria-labelledby="dossier-categories-heading" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="dossier-categories-heading" class="gc-workspace-section-title font-semibold"><x-nav-icon name="dossiers" /> Catégories de dossiers</h2>
                <p class="text-xs text-slate-500">Compteurs selon les filtres · Un dossier peut avoir plusieurs états selon ses prestations.</p>
            </div>
            <div data-dossier-categories>@include('crm.dossiers.categories')</div>
        </section>
        <div data-dossier-feedback hidden>
            <p role="status" aria-live="polite" class="flex items-center gap-2 text-sm text-slate-500">
                <svg data-dossier-loader hidden class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3"/></svg>
                <span data-dossier-status></span>
            </p>
            <div data-dossier-error hidden class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <p role="alert" data-dossier-error-message></p>
                <button type="button" data-dossier-retry class="mt-2 font-semibold underline">Réessayer</button>
            </div>
        </div>
        <div data-dossier-results class="space-y-6" aria-busy="false" tabindex="-1">
            @include('crm.dossiers.results')
        </div>
        <div data-dossier-map-config data-mapbox-token="{{ $mapboxToken }}" hidden></div>
        @if ($mapboxToken)
            <link href="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.css" rel="stylesheet">
            <script src="https://api.mapbox.com/mapbox-gl-js/v3.6.0/mapbox-gl.js"></script>
        @endif
    </div>
</x-layouts.app>
