import { initDossierAssignment } from './dossier-assignment.js';
import { initDossierArchiving } from './dossier-archiving.js';
import { readDossierPreferences, saveDossierPreferences, preferredDossierUrl } from './dossier-preferences.js';
import { initDossierFilterPanel, syncDossierPlanningFilter } from './dossier-filter-panel.js';

const filterNames = ['q', 'lot', 'status', 'delegataire_id', 'company_ids[]', 'service_id', 'service_ids[]', 'planning_user_ids[]', 'planning', 'billing', 'per_page', 'sort', 'direction', 'all_columns', 'department', 'section', 'date_from', 'date_to'];
const arrayFilterName = name => /^(service_ids|planning_user_ids|company_ids)(?:\[\d*\])?$/.exec(name)?.[1];
const legacyArrayFilters = { service_ids: 'service_id', company_ids: 'delegataire_id' };

function dossierArrayIds(entries, key, fallback) {
    const parameters = Array.from(entries);
    const multiple = parameters.filter(([name]) => arrayFilterName(name) === key);
    return [...new Set((multiple.length ? multiple : parameters.filter(([name]) => name === fallback))
        .map(([, value]) => String(value).trim()).filter(Boolean))];
}

export function dossierServiceIds(entries) {
    return dossierArrayIds(entries, 'service_ids', 'service_id');
}

export function dossierCompanyIds(entries) {
    return dossierArrayIds(entries, 'company_ids', 'delegataire_id');
}

export function syncDossierFilters(form, url, browser) {
    for (const name of filterNames) {
        const input = form.elements.namedItem(name);
        if (!input) continue;
        if (input.multiple) {
            const key = arrayFilterName(name);
            const selected = new Set(dossierArrayIds(url.searchParams, key, legacyArrayFilters[key]));
            for (const option of input.options) option.selected = selected.has(option.value);
            input.dispatchEvent(new browser.CustomEvent('techcalendar:selection-sync'));
            continue;
        }
        const value = url.searchParams.get(name) ?? (name === 'per_page' ? '10' : '');
        if (input.type === 'checkbox') input.checked = value === '1';
        else if (input.value !== value) input.value = value;
    }
    syncDossierPlanningFilter(form, browser);
}

export function dossierListUrl(base, entries, changes = {}) {
    const url = new URL(base);
    url.search = '';
    url.hash = '';
    const multiple = new Map();
    const setMultiple = (key, values) => {
        if (legacyArrayFilters[key]) url.searchParams.delete(legacyArrayFilters[key]);
        url.searchParams.delete(`${key}[]`);
        for (const value of [...new Set(values.map(value => String(value).trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'en', { numeric: true }))) url.searchParams.append(`${key}[]`, value);
    };
    for (const [name, value] of entries) {
        const key = arrayFilterName(name);
        if (key) { if (!multiple.has(key)) multiple.set(key, []); multiple.get(key).push(value); }
        else if (filterNames.includes(name) && String(value).trim() !== '') url.searchParams.set(name, String(value).trim());
    }
    for (const [key, values] of multiple) setMultiple(key, values);
    for (const [name, value] of Object.entries(changes)) {
        const key = arrayFilterName(name);
        if (key) { setMultiple(key, value === null || value === '' ? [] : Array.isArray(value) ? value : [value]); continue; }
        if (!filterNames.includes(name) && name !== 'page') continue;
        const legacyKey = Object.keys(legacyArrayFilters).find(key => legacyArrayFilters[key] === name);
        if (legacyKey) url.searchParams.delete(`${legacyKey}[]`);
        if (value === null || value === '') url.searchParams.delete(name);
        else url.searchParams.set(name, value);
    }
    for (const [key, legacy] of Object.entries(legacyArrayFilters)) {
        if (url.searchParams.has(legacy)) setMultiple(key, [url.searchParams.get(legacy)]);
    }
    if (url.searchParams.get('status') !== 'visited') url.searchParams.delete('planning_user_ids[]');
    if (!url.searchParams.has('sort')) url.searchParams.delete('direction');
    else if (!url.searchParams.has('direction')) url.searchParams.set('direction', 'asc');
    if (url.searchParams.get('per_page') === '10') url.searchParams.delete('per_page');
    if (url.searchParams.get('all_columns') === '0') url.searchParams.delete('all_columns');
    url.searchParams.sort();
    return url;
}

export function dossierCategoryScope(url) {
    const scope = dossierListUrl(url.href, url.searchParams);
    for (const name of ['per_page', 'sort', 'direction', 'all_columns']) scope.searchParams.delete(name);
    return scope.href;
}

export function selectDossierCategory(categories, url) {
    const selected = url.searchParams.get('status') || 'all';
    categories.querySelectorAll('[data-category]').forEach(link => {
        link.href = dossierListUrl(url.href, url.searchParams, { status: link.dataset.category === 'all' ? null : link.dataset.category }).href;
        if (link.dataset.category === selected) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
    });
}

export function setDossierLoadingState(results, skeleton, stage, pending, quiet = false) {
    if (skeleton) {
        if (pending && !quiet && !results.hidden) skeleton.style.minHeight = `${Math.min(results.getBoundingClientRect().height, 1100)}px`;
        skeleton.hidden = !pending || quiet;
        results.hidden = pending && !quiet;
    }
    results.setAttribute('aria-busy', String(pending));
    stage?.setAttribute('aria-busy', String(pending));
}

export function dossierAssignmentAffectsListing(url) {
    return url.searchParams.get('sort') === 'planning_user'
        || dossierArrayIds(url.searchParams, 'planning_user_ids').length > 0;
}

export function replaceDossierResults(results, html, root, browser, quiet = false) {
    if (!quiet) { results.innerHTML = html; results.style.minHeight = ''; return; }
    const focus = root.activeElement;
    const assignment = results.contains(focus) ? focus?.closest('[data-planning-assignment]') : null;
    const appointment = results.contains(focus) ? focus?.closest('[data-appointment-editor]') : null;
    const focusSelector = assignment ? `[data-assignment-id="${browser.CSS.escape(assignment.dataset.assignmentId)}"] [data-planning-assignee]`
        : appointment ? `[data-appointment-editor="${browser.CSS.escape(appointment.dataset.appointmentEditor)}"] [name="technician_id"]` : null;
    const scroll = [];
    for (let node = results.parentElement; node; node = node.parentElement) {
        scroll.push([node, node.scrollLeft, node.scrollTop]);
    }
    const tableLeft = results.querySelector('.gc-dossier-table-scroll')?.scrollLeft || 0;
    const viewport = { left: browser.scrollX, top: browser.scrollY, behavior: 'instant' };
    // Keep enough space when an assignment removes a row from the current filter.
    results.style.minHeight = `${Math.ceil(results.getBoundingClientRect().height)}px`;
    results.innerHTML = html;
    const table = results.querySelector('.gc-dossier-table-scroll');
    if (table) table.scrollLeft = tableLeft;
    if (focusSelector) (results.querySelector(focusSelector) || results).focus({ preventScroll: true });
    for (const [node, left, top] of scroll) { node.scrollLeft = left; node.scrollTop = top; }
    browser.scrollTo(viewport);
}

// Cancel on input, not after the debounce: an older response must never overwrite newer filters.
export function createDossierLoader(browser, { pending, success, failure }) {
    let generation = 0;
    let controller;
    let debounceTimer;
    let timeoutTimer;
    let lastRequest;
    let loading = false;
    const cancel = () => {
        loading = false;
        generation++;
        controller?.abort();
        browser.clearTimeout(debounceTimer);
        browser.clearTimeout(timeoutTimer);
    };
    const load = (url, options = {}) => {
        lastRequest = { url, options };
        cancel();
        loading = true;
        const current = generation;
        pending(url, options);
        const run = async () => {
            const request = new AbortController();
            controller = request;
            let timedOut = false;
            const timeout = browser.setTimeout(() => { timedOut = true; request.abort(); }, 20000);
            timeoutTimer = timeout;
            try {
                const response = await browser.fetch(url.href, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                        ...(options.categories === false ? { 'X-Dossier-Categories': 'unchanged' } : {}) },
                    credentials: 'same-origin', cache: 'no-store', signal: request.signal,
                });
                if (!response.ok) {
                    const error = new Error('Dossier listing unavailable');
                    error.status = response.status;
                    throw error;
                }
                const payload = await response.json();
                if ((typeof payload.categories !== 'string' && !(options.categories === false && payload.categories === null)) || typeof payload.results !== 'string'
                    || !Number.isInteger(payload.total) || payload.total < 0) throw new Error('Invalid listing response');
                if (payload.page !== undefined && (!Number.isInteger(payload.page) || payload.page < 1)) throw new Error('Invalid listing page');
                if (current === generation) {
                    const rendered = new URL(url.href);
                    if (payload.page === 1) rendered.searchParams.delete('page');
                    else if (payload.page !== undefined) rendered.searchParams.set('page', String(payload.page));
                    lastRequest = { url: rendered, options };
                    success(payload, rendered, options);
                }
            } catch (error) {
                if (current === generation && (error.name !== 'AbortError' || timedOut)) failure(error, url, options);
            } finally {
                browser.clearTimeout(timeout);
                if (current === generation) loading = false;
            }
        };
        if (options.delay) debounceTimer = browser.setTimeout(run, options.delay);
        else void run();
    };
    const refresh = (fallback, { quiet = false } = {}) => load(lastRequest?.url || fallback, {
        ...lastRequest?.options, delay: 0, scroll: false, categories: true,
        quiet: quiet && (!lastRequest || lastRequest.url.href === fallback.href),
    });
    return { load, cancel, refresh, get isLoading() { return loading; } };
}

export function initDossierList(root = document, browser = window) {
    const listing = root.querySelector('[data-dossier-list]');
    if (!listing) return;
    const form = listing.querySelector('[data-dossier-filters]');
    const categories = listing.querySelector('[data-dossier-categories]');
    const results = listing.querySelector('[data-dossier-results]');
    const skeleton = listing.querySelector('[data-dossier-skeleton]');
    const stage = listing.querySelector('[data-dossier-stage]');
    const feedback = listing.querySelector('[data-dossier-feedback]');
    const status = listing.querySelector('[data-dossier-status]');
    const spinner = listing.querySelector('[data-dossier-loader]');
    const errorBox = listing.querySelector('[data-dossier-error]');
    const errorMessage = listing.querySelector('[data-dossier-error-message]');
    let retry;
    let composing = false;
    let focusWasInResults = false;
    let renderedUrl = new URL(browser.location.href);
    const preferencesKey = listing.dataset?.preferencesKey;
    const storePreferences = url => {
        if (!preferencesKey) return;
        try { saveDossierPreferences(browser.localStorage, preferencesKey, url); } catch { /* Storage can be disabled. */ }
    };
    const formUrl = (changes = {}) => dossierListUrl(form.action, new browser.FormData(form), changes);
    const exportLink = listing.querySelector('[data-dossier-export]');
    exportLink?.addEventListener('click', () => {
        // Export the current inputs, including a search whose debounced request is still pending.
        exportLink.href = dossierListUrl(exportLink.dataset.exportUrl, new browser.FormData(form)).href;
    });
    const syncForm = (url, revealAdvanced = false) => {
        syncDossierFilters(form, url, browser);
        filterPanel.sync({ revealAdvanced });
    };
    const busy = (value, quiet = false) => {
        if (!quiet) feedback.hidden = false;
        spinner.hidden = !value || quiet;
        setDossierLoadingState(results, skeleton, stage, value, quiet);
        categories.setAttribute('aria-busy', String(value));
    };
    const loader = createDossierLoader(browser, {
        pending(url, options) {
            if (options.quiet) { busy(true, true); errorBox.hidden = true; return; }
            focusWasInResults ||= results.contains(root.activeElement);
            root.dispatchEvent(new browser.CustomEvent('techcalendar:dossiers-before-update'));
            filterPanel.sync();
            selectDossierCategory(categories, url);
            busy(true);
            errorBox.hidden = true;
            const label = categories.querySelector('[aria-current="page"] .gc-dossier-category-label')?.textContent.trim();
            status.textContent = label ? `Chargement : ${label}…` : 'Chargement des dossiers…';
            if (options.scroll) (skeleton || results).scrollIntoView({ behavior: 'instant', block: 'start' });
        },
        success(payload, url, options) {
            const restoreFocus = (focusWasInResults && root.activeElement === root.body)
                || results.contains(root.activeElement) || categories.contains(root.activeElement);
            root.dispatchEvent(new browser.CustomEvent('techcalendar:dossiers-before-update'));
            // These fragments come exclusively from the authenticated, same-origin Blade endpoint.
            if (payload.categories !== null) categories.innerHTML = payload.categories;
            selectDossierCategory(categories, url);
            const activeCount = categories.querySelector('[aria-current="page"] .gc-dossier-category-count');
            if (activeCount) activeCount.textContent = payload.total.toLocaleString('fr-FR');
            replaceDossierResults(results, payload.results, root, browser, options.quiet);
            filterPanel.sync();
            listing.querySelectorAll('[data-dossier-section]').forEach(link => {
                const active = link.dataset.dossierSection === (url.searchParams.get('section') || '');
                link.href = dossierListUrl(form.action, url.searchParams, { section: link.dataset.dossierSection }).href;
                link.classList.toggle('gc-btn-primary', active);
                link.classList.toggle('gc-btn-secondary', !active);
                if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
            });
            root.dispatchEvent(new browser.CustomEvent('techcalendar:dossiers-updated'));
            renderedUrl = url;
            storePreferences(url);
            if (exportLink) exportLink.href = dossierListUrl(exportLink.dataset.exportUrl, url.searchParams).href;
            if (options.history !== false && url.href !== browser.location.href) {
                if (options.history === 'replace') browser.history.replaceState(null, '', url.href);
                else browser.history.pushState(null, '', url.href);
            }
            busy(false, options.quiet);
            focusWasInResults = false;
            if (!options.quiet) status.textContent = `${payload.total.toLocaleString('fr-FR')} dossier(s) trouvé(s).`;
            if (restoreFocus && !options.quiet) {
                const target = options.focus ? listing.querySelector(options.focus) : results;
                (target || results).focus({ preventScroll: true });
            }
            if (options.scroll) results.scrollIntoView({ behavior: 'instant', block: 'start' });
        },
        failure(error, url, options) {
            busy(false);
            selectDossierCategory(categories, renderedUrl);
            if (focusWasInResults && root.activeElement === root.body) results.focus({ preventScroll: true });
            focusWasInResults = false;
            status.textContent = '';
            errorMessage.textContent = error.status === 401
                ? 'Ta session a expiré. Recharge la page pour te reconnecter.'
                : error.status === 403
                    ? 'Tu ne disposes plus des droits nécessaires pour consulter ces dossiers.'
                    : error.status === 422
                        ? 'Un filtre est invalide. Vérifie les valeurs ou efface les filtres.'
                        : 'Impossible de mettre à jour les dossiers. Les résultats précédents sont conservés. Réessaie dans un instant.';
            errorBox.hidden = false;
            retry = () => loader.load(url, options);
        },
    });
    const load = (url, options = {}) => loader.load(url, {
        ...options, categories: dossierCategoryScope(url) !== dossierCategoryScope(renderedUrl),
    });
    const filterPanel = initDossierFilterPanel(form, categories, root, browser, () => load(formUrl()));
    initDossierAssignment(listing, root, browser, ({ afterSave = false } = {}) => {
        if (afterSave && !loader.isLoading && !dossierAssignmentAffectsListing(renderedUrl)) return;
        loader.refresh(renderedUrl, { quiet: true });
    });
    root.addEventListener('techcalendar:appointment-assigned', () => loader.refresh(renderedUrl, { quiet: true }));
    initDossierArchiving(listing, root, browser, () => loader.refresh(renderedUrl, { quiet: true }));
    form.addEventListener('submit', event => {
        event.preventDefault();
        load(formUrl());
    });
    const search = form.elements.namedItem('q');
    const textFilters = [search, form.elements.namedItem('lot')].filter(Boolean);
    for (const input of textFilters) {
        input.addEventListener('compositionstart', () => { composing = true; loader.cancel(); busy(false); focusWasInResults = false; selectDossierCategory(categories, renderedUrl); status.textContent = ''; });
        input.addEventListener('compositionend', () => { composing = false; load(formUrl(), { delay: 300 }); });
        input.addEventListener('input', () => { if (!composing) load(formUrl(), { delay: 300 }); });
    }
    form.addEventListener('change', event => {
        if (!textFilters.includes(event.target)) load(formUrl());
    });
    listing.addEventListener('change', event => {
        if (event.target.matches('[data-dossier-page-size]')) {
            load(formUrl(), { focus: '[data-dossier-page-size]' });
        } else if (event.target.matches('[data-dossier-all-columns]')) {
            load(formUrl(), { focus: '[data-dossier-all-columns]' });
        }
    });
    listing.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || link.target || link.hasAttribute('download')) return;
        const destination = new URL(link.href);
        const base = new URL(form.action);
        if (destination.origin !== base.origin || destination.pathname !== base.pathname) return;
        let url;
        const options = {};
        if (link.matches('[data-dossier-clear]')) {
            url = new URL(form.action);
        } else if (link.matches('[data-dossier-section]')) {
            url = formUrl({ section: link.dataset.dossierSection });
        } else if (link.matches('[data-category]')) {
            url = formUrl({ status: destination.searchParams.get('status') });
            options.focus = `[data-category="${browser.CSS.escape(link.dataset.category)}"]`;
        } else if (link.matches('.gc-dossier-sort')) {
            url = formUrl({ sort: destination.searchParams.get('sort'), direction: destination.searchParams.get('direction') });
            options.focus = `th[data-column="${browser.CSS.escape(link.closest('th').dataset.column)}"] a`;
        } else if (link.closest('.gc-pagination')) {
            url = formUrl();
            // A pending search takes precedence over pagination links from the old result set.
            if (url.href === dossierListUrl(base.href, renderedUrl.searchParams).href) {
                url.searchParams.set('page', destination.searchParams.get('page') || '1');
            }
            options.scroll = true;
        } else return;
        event.preventDefault();
        syncForm(url);
        load(url, options);
    });
    listing.querySelector('[data-dossier-retry]').addEventListener('click', () => retry?.());
    const restoreLocation = () => {
        const url = new URL(browser.location.href);
        if (url.pathname !== new URL(form.action).pathname) return;
        syncForm(url, true);
        load(url, { history: false });
    };
    browser.addEventListener('popstate', restoreLocation);
    browser.addEventListener('pageshow', event => { if (event.persisted) restoreLocation(); });
    browser.addEventListener('pagehide', loader.cancel);
    if (preferencesKey) {
        try {
            const preferred = preferredDossierUrl(renderedUrl, readDossierPreferences(browser.localStorage, preferencesKey));
            if (preferred.href !== renderedUrl.href) {
                syncForm(preferred);
                load(preferred, { history: 'replace' });
            } else storePreferences(renderedUrl);
        } catch { /* Keep the server-rendered page when storage is unavailable. */ }
    }
}
