import { initDossierAssignment } from './dossier-assignment.js';

const filterNames = ['q', 'status', 'delegataire_id', 'service_id', 'planning', 'billing', 'per_page', 'sort', 'direction', 'all_columns'];

export function dossierListUrl(base, entries, changes = {}) {
    const url = new URL(base);
    url.search = '';
    url.hash = '';
    for (const [name, value] of entries) {
        if (filterNames.includes(name) && String(value).trim() !== '') url.searchParams.set(name, String(value).trim());
    }
    for (const [name, value] of Object.entries(changes)) {
        if (!filterNames.includes(name) && name !== 'page') continue;
        if (value === null || value === '') url.searchParams.delete(name);
        else url.searchParams.set(name, value);
    }
    if (!url.searchParams.has('sort')) url.searchParams.delete('direction');
    else if (!url.searchParams.has('direction')) url.searchParams.set('direction', 'asc');
    if (url.searchParams.get('per_page') === '25') url.searchParams.delete('per_page');
    if (url.searchParams.get('all_columns') === '0') url.searchParams.delete('all_columns');
    url.searchParams.sort();
    return url;
}

// Cancel on input, not after the debounce: an older response must never overwrite newer filters.
export function createDossierLoader(browser, { pending, success, failure }) {
    let generation = 0;
    let controller;
    let debounceTimer;
    let timeoutTimer;
    let lastRequest;
    const cancel = () => {
        generation++;
        controller?.abort();
        browser.clearTimeout(debounceTimer);
        browser.clearTimeout(timeoutTimer);
    };
    const load = (url, options = {}) => {
        lastRequest = { url, options };
        cancel();
        const current = generation;
        pending();
        const run = async () => {
            const request = new AbortController();
            controller = request;
            let timedOut = false;
            const timeout = browser.setTimeout(() => { timedOut = true; request.abort(); }, 20000);
            timeoutTimer = timeout;
            try {
                const response = await browser.fetch(url.href, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin', cache: 'no-store', signal: request.signal,
                });
                if (!response.ok) {
                    const error = new Error('Dossier listing unavailable');
                    error.status = response.status;
                    throw error;
                }
                const payload = await response.json();
                if (typeof payload.categories !== 'string' || typeof payload.results !== 'string'
                    || !Number.isInteger(payload.total) || payload.total < 0) throw new Error('Invalid listing response');
                if (current === generation) success(payload, url, options);
            } catch (error) {
                if (current === generation && (error.name !== 'AbortError' || timedOut)) failure(error, url, options);
            } finally {
                browser.clearTimeout(timeout);
            }
        };
        if (options.delay) debounceTimer = browser.setTimeout(run, options.delay);
        else void run();
    };
    const refresh = fallback => load(lastRequest?.url || fallback, { ...lastRequest?.options, delay: 0, scroll: false });
    return { load, cancel, refresh };
}

export function initDossierList(root = document, browser = window) {
    const listing = root.querySelector('[data-dossier-list]');
    if (!listing) return;
    const form = listing.querySelector('[data-dossier-filters]');
    const categories = listing.querySelector('[data-dossier-categories]');
    const results = listing.querySelector('[data-dossier-results]');
    const feedback = listing.querySelector('[data-dossier-feedback]');
    const status = listing.querySelector('[data-dossier-status]');
    const spinner = listing.querySelector('[data-dossier-loader]');
    const errorBox = listing.querySelector('[data-dossier-error]');
    const errorMessage = listing.querySelector('[data-dossier-error-message]');
    let retry;
    let composing = false;
    let renderedUrl = new URL(browser.location.href);
    const formUrl = (changes = {}) => dossierListUrl(form.action, new browser.FormData(form), changes);
    const exportLink = listing.querySelector('[data-dossier-export]');
    exportLink?.addEventListener('click', () => {
        // Export the current inputs, including a search whose debounced request is still pending.
        exportLink.href = dossierListUrl(exportLink.dataset.exportUrl, new browser.FormData(form)).href;
    });
    const syncForm = (url) => {
        for (const name of filterNames) {
            const input = form.elements.namedItem(name);
            if (!input) continue;
            const value = url.searchParams.get(name) ?? (name === 'per_page' ? '25' : '');
            if (input.type === 'checkbox') input.checked = value === '1';
            else if (input.value !== value) input.value = value;
        }
    };
    const busy = (value) => {
        feedback.hidden = false;
        spinner.hidden = !value;
        results.setAttribute('aria-busy', String(value));
        categories.setAttribute('aria-busy', String(value));
    };
    const loader = createDossierLoader(browser, {
        pending() {
            busy(true);
            errorBox.hidden = true;
            status.textContent = 'Mise à jour des dossiers…';
        },
        success(payload, url, options) {
            const restoreFocus = results.contains(root.activeElement) || categories.contains(root.activeElement);
            root.dispatchEvent(new browser.CustomEvent('techcalendar:dossiers-before-update'));
            // These fragments come exclusively from the authenticated, same-origin Blade endpoint.
            categories.innerHTML = payload.categories;
            results.innerHTML = payload.results;
            root.dispatchEvent(new browser.CustomEvent('techcalendar:dossiers-updated'));
            renderedUrl = url;
            if (exportLink) exportLink.href = dossierListUrl(exportLink.dataset.exportUrl, url.searchParams).href;
            if (options.history !== false && url.href !== browser.location.href) browser.history.pushState(null, '', url.href);
            busy(false);
            status.textContent = `${payload.total.toLocaleString('fr-FR')} dossier(s) trouvé(s).`;
            if (restoreFocus) {
                const target = options.focus ? listing.querySelector(options.focus) : results;
                (target || results).focus({ preventScroll: true });
            }
            if (options.scroll) results.scrollIntoView({ behavior: 'instant', block: 'start' });
        },
        failure(error, url, options) {
            busy(false);
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
    initDossierAssignment(listing, root, browser, () => loader.refresh(renderedUrl));
    form.addEventListener('submit', event => {
        event.preventDefault();
        loader.load(formUrl());
    });
    const search = form.elements.namedItem('q');
    search.addEventListener('compositionstart', () => { composing = true; loader.cancel(); busy(false); status.textContent = ''; });
    search.addEventListener('compositionend', () => { composing = false; loader.load(formUrl(), { delay: 300 }); });
    search.addEventListener('input', () => { if (!composing) loader.load(formUrl(), { delay: 300 }); });
    form.addEventListener('change', event => {
        if (event.target !== search) loader.load(formUrl());
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
        loader.load(url, options);
    });
    listing.querySelector('[data-dossier-retry]').addEventListener('click', () => retry?.());
    const restoreLocation = () => {
        const url = new URL(browser.location.href);
        if (url.pathname !== new URL(form.action).pathname) return;
        syncForm(url);
        loader.load(url, { history: false });
    };
    browser.addEventListener('popstate', restoreLocation);
    browser.addEventListener('pageshow', event => { if (event.persisted) restoreLocation(); });
    browser.addEventListener('pagehide', loader.cancel);
}
