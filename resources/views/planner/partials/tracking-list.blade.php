<section id="tracking-list-panel" class="hidden" aria-label="Liste des rendez-vous">
    <form id="tracking-search-form" class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="sm:col-span-2">
            <label class="gc-label" for="tracking_search_query">Rechercher un RDV</label>
            <input id="tracking_search_query" type="search" maxlength="160" class="gc-input" placeholder="Client, adresse, téléphone, technicien, référence…">
        </div>
        <div><label class="gc-label" for="tracking_search_date_from">Du</label><input id="tracking_search_date_from" type="date" class="gc-input"></div>
        <div><label class="gc-label" for="tracking_search_date_to">Au</label><input id="tracking_search_date_to" type="date" class="gc-input"></div>
        <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
            <button id="tracking-search-submit" type="submit" class="gc-btn-primary">Rechercher</button>
            <button id="tracking-search-reset" type="button" class="gc-link">Effacer la recherche</button>
            <span class="text-xs text-slate-500">Sans date : passé, présent et futur. Sans technicien coché : tous.</span>
        </div>
    </form>
    <p id="tracking-search-status" role="status" class="my-3 hidden rounded-lg p-3 text-sm"></p>
    <div id="tracking-search-results" class="mt-4 divide-y divide-slate-200 rounded-xl border border-slate-200"></div>
    <div class="mt-4 flex items-center justify-between gap-3">
        <span id="tracking-search-summary" role="status" class="text-sm text-slate-600"></span>
        <div class="flex gap-2">
            <button id="tracking-list-previous" type="button" class="gc-btn-soft" disabled>Précédent</button>
            <button id="tracking-list-next" type="button" class="gc-btn-soft" disabled>Suivant</button>
        </div>
    </div>
</section>
