<section id="tracking-list-panel" class="hidden" aria-label="Liste des rendez-vous">
    <form id="tracking-search-form" class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="sm:col-span-2">
            <label class="gc-label" for="tracking_search_query">Rechercher un RDV</label>
            <input id="tracking_search_query" type="search" maxlength="160" class="gc-input" placeholder="Client, adresse, téléphone, technicien, référence…">
        </div>
        <div><label class="gc-label" for="tracking_search_date_from">Du</label><input id="tracking_search_date_from" type="date" class="gc-input"></div>
        <div><label class="gc-label" for="tracking_search_date_to">Au</label><input id="tracking_search_date_to" type="date" class="gc-input"></div>
        <div><label class="gc-label" for="tracking_search_source">Origine</label><select id="tracking_search_source" class="gc-input"><option value="all">Cofrac et MAR</option><option value="cofrac">Cofrac uniquement</option><option value="mar">MAR uniquement</option></select></div>
        <div><label class="gc-label" for="tracking_search_period">Période du RDV</label><select id="tracking_search_period" class="gc-input"><option value="all">Tous les rendez-vous</option><option value="upcoming">À venir</option><option value="ongoing">En cours</option><option value="past">Passés</option></select></div>
        <div><label class="gc-label" for="tracking_search_sort">Trier par</label><select id="tracking_search_sort" class="gc-input"><option value="starts_desc">Date : plus récents en premier</option><option value="starts_asc">Date : plus anciens en premier</option><option value="duration_asc">Durée : plus courts en premier</option><option value="duration_desc">Durée : plus longs en premier</option></select></div>
        <div><label class="gc-label" for="tracking_search_limit">RDV par page</label><select id="tracking_search_limit" class="gc-input"><option value="10">10</option><option value="25">25</option><option value="50" selected>50</option><option value="100">100</option></select></div>
        <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
            <button id="tracking-search-submit" type="submit" class="gc-btn-primary">Rechercher</button>
            <button id="tracking-search-reset" type="button" class="gc-link">Effacer la recherche</button>
            <span class="text-xs text-slate-500">Sans technicien coché : tous. Cofrac : tout l’historique. MAR : période synchronisée indiquée dans les résultats.</span>
        </div>
    </form>
    <p id="tracking-search-status" role="status" class="my-3 hidden rounded-lg p-3 text-sm"></p>
    <p id="tracking-search-mar-coverage" role="status" class="mt-3 text-xs text-slate-500"></p>
    <div id="tracking-search-results" class="mt-4 divide-y divide-slate-200 rounded-xl border border-slate-200"></div>
    <div class="mt-4 flex items-center justify-between gap-3">
        <span id="tracking-search-summary" role="status" class="text-sm text-slate-600"></span>
        <div class="flex gap-2">
            <button id="tracking-list-previous" type="button" class="gc-btn-soft" disabled>Précédent</button>
            <button id="tracking-list-next" type="button" class="gc-btn-soft" disabled>Suivant</button>
        </div>
    </div>
</section>
