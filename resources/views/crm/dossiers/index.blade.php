<x-layouts.app :crm-navigation="true">
    <div class="space-y-6" data-dossier-list data-preferences-key="techcalendar:dossiers:v1:{{ auth()->id() }}">
        <x-workspace-heading title="Les dossiers" eyebrow="Suivi des inspections" description="Retrouvez une inspection, consultez ses pièces et faites avancer ses prestations, de la prise de RDV au rapport.">
            <a href="{{ route('v2.dossiers.export', $filters) }}" data-dossier-export data-export-url="{{ route('v2.dossiers.export') }}" class="gc-btn-success"><x-nav-icon name="download" />Exporter les résultats filtrés</a>
            <a href="{{ route('v2.dossiers.create') }}" class="gc-btn-primary"><x-nav-icon name="plus" /> Nouveau dossier</a>
        </x-workspace-heading>
        @include('crm.dossiers.notice')
        <section aria-labelledby="dossier-categories-heading" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="dossier-categories-heading" class="gc-workspace-section-title font-semibold"><x-nav-icon name="dossiers" /> Catégories de dossiers</h2>
                <p class="text-xs text-slate-500">Compteurs selon les filtres · Un dossier peut avoir plusieurs états selon ses prestations.</p>
            </div>
            <div data-dossier-categories>@include('crm.dossiers.categories')</div>
        </section>
        <form method="GET" action="{{ route('v2.dossiers.index') }}" data-dossier-filters class="grid grid-cols-1 items-end gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-4">
            <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] ?? '' }}">
            <input type="hidden" name="direction" value="{{ $filters['direction'] ?? '' }}">
            <div class="xl:col-span-2">
                <label for="q" class="gc-label">Rechercher un dossier</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="gc-input bg-white" type="search" maxlength="120" placeholder="Référence, bénéficiaire, ville, installateur…">
            </div>
            <div>
                <label for="delegataire_id" class="gc-label">Client (classique ou délégataire)</label>
                <select id="delegataire_id" name="delegataire_id" class="gc-input bg-white">
                    <option value="">Tous</option>
                    @foreach ($delegataires as $delegataire)<option value="{{ $delegataire->id }}" @selected(($filters['delegataire_id'] ?? '') == $delegataire->id)>{{ $delegataire->company_name ?: $delegataire->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="service_id" class="gc-label">Prestation</label>
                <select id="service_id" name="service_id" class="gc-input bg-white">
                    <option value="">Toutes</option>
                    @foreach ($services as $service)<option value="{{ $service->id }}" @selected(($filters['service_id'] ?? '') == $service->id)>{{ $service->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="planning" class="gc-label">Rendez-vous</label>
                <select id="planning" name="planning" class="gc-input bg-white">
                    <option value="">Tous les dossiers</option>
                    @foreach (['unlinked' => 'Sans RDV actif lié', 'upcoming' => 'RDV à venir ou en cours', 'problem' => 'Avec un problème RDV'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['planning'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="per_page" class="gc-label">Lignes par page</label>
                <select id="per_page" name="per_page" class="gc-input bg-white">@foreach ([25, 50, 100] as $size)<option @selected(($filters['per_page'] ?? 25) == $size)>{{ $size }}</option>@endforeach</select>
            </div>
            <div>
                <label for="billing" class="gc-label">Facturation (indépendante du statut)</label>
                <select id="billing" name="billing" class="gc-input bg-white">
                    <option value="">Tous les dossiers</option>
                    @foreach (['unbilled' => 'Non facturés', 'invoiced' => 'Facturés', 'review' => 'Historique à vérifier'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['billing'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-3">
                <noscript><button type="submit" class="gc-btn-secondary">Appliquer les filtres</button></noscript>
                <a href="{{ route('v2.dossiers.index') }}" data-dossier-clear class="text-sm underline">Effacer</a>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="all_columns" value="1" @checked($filters['all_columns'] ?? false)> Afficher toutes les colonnes</label>
        </form>
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
