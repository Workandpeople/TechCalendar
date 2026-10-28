@php
    $selectedServices = \App\Services\Crm\DossierServiceFilter::ids($filters);
    $selectedCompanies = \App\Services\Crm\DossierCompanyFilter::ids($filters);
    $visited = ($filters['status'] ?? null) === 'visited';
    $selectedPlanningUsers = $visited ? array_map('intval', $filters['planning_user_ids'] ?? []) : [];
    $advancedCount = collect(['department', 'section', 'planning', 'billing'])->filter(fn ($key) => filled($filters[$key] ?? null))->count()
        + (filled($filters['date_from'] ?? null) || filled($filters['date_to'] ?? null) ? 1 : 0);
@endphp
<form id="dossier-filters" method="GET" action="{{ route('v2.dossiers.index') }}" data-dossier-filters class="gc-dossier-filter-panel" aria-labelledby="dossier-filters-heading">
    <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">
    <input type="hidden" name="sort" value="{{ $filters['sort'] ?? '' }}">
    <input type="hidden" name="direction" value="{{ $filters['direction'] ?? '' }}">
    <header class="gc-dossier-filter-heading">
        <div class="gc-dossier-filter-title"><span class="gc-dossier-filter-icon"><x-nav-icon name="search" /></span><div><h2 id="dossier-filters-heading">Rechercher et filtrer</h2><p>Les résultats se mettent à jour automatiquement.</p></div></div>
        <a href="{{ route('v2.dossiers.index') }}" data-dossier-clear class="gc-dossier-filter-reset">Tout effacer</a>
    </header>
    <div class="gc-dossier-filter-main">
        <div class="gc-dossier-filter-search">
            <label for="q" class="gc-label">Recherche</label>
            <div class="gc-dossier-search-input"><x-nav-icon name="search" /><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="gc-input" type="search" maxlength="255" placeholder="Nom, adresse complète, téléphone…"></div>
        </div>
        <div>
            <label for="company_ids" class="gc-label">Sociétés clientes et délégataires</label>
            <select id="company_ids" name="company_ids[]" class="gc-input" multiple size="4" data-multi-select
                data-empty-label="Toutes les sociétés" data-selected-label="sociétés sélectionnées"
                data-search-label="Rechercher une société" data-options-label="Sociétés disponibles"
                data-no-results-label="Aucune société ne correspond à la recherche.">
                @foreach (\App\Models\ExternalDelegataire::KINDS as $kind => $label)
                    <optgroup label="{{ $label }}">
                        @foreach ($delegataires->where('kind', $kind) as $company)<option value="{{ $company->id }}" @selected(in_array($company->id, $selectedCompanies, true))>{{ $company->company_name ?: $company->name }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="service_ids" class="gc-label">Fiches techniques</label>
            <select id="service_ids" name="service_ids[]" class="gc-input" multiple size="4" data-multi-select data-empty-label="Toutes les fiches">
                @foreach ($services as $service)<option value="{{ $service->id }}" @selected(in_array($service->id, $selectedServices, true))>{{ $service->name }}</option>@endforeach
            </select>
        </div>
    </div>
    <div class="gc-dossier-filter-planning" data-dossier-planning-filter @if (! $visited) hidden @endif>
        <label for="planning_user_ids" class="gc-label">Suivi planning</label>
        <select id="planning_user_ids" name="planning_user_ids[]" class="gc-input" multiple size="4" data-multi-select
            data-empty-label="Tous les responsables" data-selected-label="responsables sélectionnés"
            data-search-label="Rechercher un responsable" data-options-label="Responsables disponibles"
            data-no-results-label="Aucun responsable ne correspond à la recherche." @disabled(! $visited)>
            @foreach ($planningUsers as $planningUser)<option value="{{ $planningUser->id }}" @selected(in_array($planningUser->id, $selectedPlanningUsers, true))>{{ $planningUser->full_name }}</option>@endforeach
        </select>
    </div>
    <details class="gc-dossier-filter-advanced" data-dossier-advanced @if ($advancedCount) open @endif>
        <summary>
            <span class="gc-dossier-filter-advanced-label"><x-nav-icon name="settings" />Filtres complémentaires <span data-dossier-advanced-count class="gc-dossier-filter-count" @if (! $advancedCount) hidden @endif>{{ $advancedCount }} actif{{ $advancedCount > 1 ? 's' : '' }}</span></span>
            <span class="gc-dossier-filter-advanced-hint">Lieu, dates, RDV et facturation</span>
            <svg class="gc-dossier-filter-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m5 7.5 5 5 5-5" /></svg>
        </summary>
        <div class="gc-dossier-filter-groups">
            <fieldset class="gc-dossier-filter-group" data-filter-group="location">
                <legend><x-nav-icon name="location" />Localisation</legend>
                <div><label for="department" class="gc-label">Département</label><select id="department" name="department" class="gc-input" data-searchable><option value="">Tous les départements</option>@foreach ($departments as $department)<option value="{{ $department->code }}" @selected(($filters['department'] ?? '') === $department->code)>{{ $department->code }} · {{ $department->name }}</option>@endforeach</select></div>
                <div><label for="section" class="gc-label">Secteur / activité</label><select id="section" name="section" class="gc-input"><option value="">Tous les secteurs</option>@foreach (\App\Services\Crm\DossierSections::LABELS as $key => $label)<option value="{{ $key }}" @selected(($filters['section'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
            </fieldset>
            <fieldset class="gc-dossier-filter-group" data-filter-group="dates">
                <legend><x-nav-icon name="planning" />Période</legend>
                <div><label for="date_field" class="gc-label">Date à utiliser</label><select id="date_field" name="date_field" class="gc-input"><option value="created" @selected(($filters['date_field'] ?? 'created') === 'created')>Création du dossier</option><option value="appointment" @selected(($filters['date_field'] ?? '') === 'appointment')>Date du rendez-vous</option></select></div>
                <div class="gc-dossier-filter-date-range"><div><label for="date_from" class="gc-label">Du</label><input id="date_from" name="date_from" type="date" class="gc-input" value="{{ $filters['date_from'] ?? '' }}"></div><div><label for="date_to" class="gc-label">Au inclus</label><input id="date_to" name="date_to" type="date" class="gc-input" value="{{ $filters['date_to'] ?? '' }}"></div></div>
            </fieldset>
            <fieldset class="gc-dossier-filter-group" data-filter-group="activity">
                <legend><x-nav-icon name="check" />Suivi du dossier</legend>
                <div><label for="planning" class="gc-label">Rendez-vous</label><select id="planning" name="planning" class="gc-input"><option value="">Tous les dossiers</option>@foreach (['unlinked' => 'Sans RDV actif lié', 'upcoming' => 'RDV à venir ou en cours', 'problem' => 'Avec un problème RDV'] as $value => $label)<option value="{{ $value }}" @selected(($filters['planning'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="billing" class="gc-label">Facturation</label><select id="billing" name="billing" class="gc-input"><option value="">Tous les dossiers</option>@foreach (['unbilled' => 'Non facturés', 'invoiced' => 'Facturés', 'review' => 'Historique à vérifier'] as $value => $label)<option value="{{ $value }}" @selected(($filters['billing'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                <p class="gc-dossier-filter-help">La facturation est indépendante de l’état du dossier.</p>
            </fieldset>
        </div>
    </details>
    <div class="gc-dossier-active-filters" data-dossier-active-filters hidden>
        <span class="gc-dossier-active-label">Filtres actifs</span>
        <div class="gc-dossier-filter-chips" data-dossier-filter-chips aria-label="Retirer un filtre actif"></div>
    </div>
    <noscript><div class="p-4"><button type="submit" class="gc-btn-secondary">Appliquer les filtres</button></div></noscript>
</form>
