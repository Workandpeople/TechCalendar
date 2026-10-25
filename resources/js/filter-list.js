export function filterListUrl(action, entries) {
    const url = new URL(action);
    url.search = '';
    for (const [name, value] of entries) {
        if (name !== 'page' && String(value).trim()) url.searchParams.append(name, String(value).trim());
    }
    url.searchParams.sort();
    return url;
}

export function createFilterLoader(browser, callbacks) {
    let generation = 0;
    let controller;
    let timer;
    const cancel = () => { generation++; controller?.abort(); browser.clearTimeout(timer); };
    const load = (url, options = {}) => {
        cancel();
        const current = generation;
        callbacks.pending();
        const run = async () => {
            controller = new AbortController();
            const request = controller;
            let timeout = false;
            const deadline = browser.setTimeout(() => { timeout = true; request.abort(); }, 20000);
            try {
                const response = await browser.fetch(url.href, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin', cache: 'no-store', signal: request.signal,
                });
                if (!response.ok || response.redirected) throw new Error('Filter response unavailable');
                const html = await response.text();
                if (current === generation) callbacks.success(html, url, options);
            } catch (error) {
                if (current === generation && (error.name !== 'AbortError' || timeout)) callbacks.failure(error, url);
            } finally { browser.clearTimeout(deadline); }
        };
        if (options.delay) timer = browser.setTimeout(run, options.delay);
        else void run();
    };
    return { load, cancel };
}

export function initFilterLists(root = document, browser = window) {
    root.querySelectorAll('[data-ajax-list]').forEach(listing => {
        if (listing.dataset.ajaxReady) return;
        listing.dataset.ajaxReady = '1';
        const key = listing.dataset.ajaxList;
        if (!/^[a-z-]+$/.test(key)) return;
        const form = listing.querySelector('form[data-ajax-filters]');
        if (!form) return;
        const action = new URL(form.action || browser.location.href);
        let composing = false;
        let retryUrl;
        const feedback = root.createElement('div');
        feedback.className = 'text-sm text-slate-600';
        feedback.setAttribute('role', 'status');
        feedback.setAttribute('aria-live', 'polite');
        feedback.hidden = true;
        form.after(feedback);
        const formUrl = () => filterListUrl(action, new browser.FormData(form));
        let renderedFilters = formUrl().href;
        const syncForm = url => {
            for (const input of form.elements) {
                if (!input.name || input.type === 'submit' || input.type === 'button') continue;
                const values = url.searchParams.getAll(input.name);
                if (input.type === 'checkbox' || input.type === 'radio') input.checked = values.includes(input.value);
                else if (input.multiple) for (const option of input.options) option.selected = values.includes(option.value);
                else input.value = values[0] ?? (input.tagName === 'SELECT' ? (input.options[0]?.value || '') : '');
            }
            browser.TechCalendarForms?.refresh(form);
        };
        const busy = value => {
            listing.setAttribute('aria-busy', String(value));
            feedback.hidden = false;
        };
        const failure = (_error, url) => {
            busy(false);
            retryUrl = url;
            feedback.textContent = 'Mise à jour impossible ou session expirée. Les résultats précédents sont conservés. ';
            const retry = root.createElement('button');
            retry.type = 'button'; retry.className = 'underline'; retry.textContent = 'Réessayer';
            retry.addEventListener('click', () => loader.load(retryUrl));
            feedback.append(retry);
        };
        const loader = createFilterLoader(browser, {
            pending() { busy(true); feedback.textContent = 'Mise à jour des résultats…'; },
            failure,
            success(html, url, options) {
                const parsed = new browser.DOMParser().parseFromString(html, 'text/html');
                const incoming = parsed.querySelector(`[data-ajax-list="${key}"]`);
                const replacements = [];
                if (incoming) for (const target of listing.querySelectorAll('[data-ajax-results]')) {
                    const name = target.dataset.ajaxResults;
                    if (!/^[a-z0-9-]+$/.test(name)) return failure(new Error('Invalid fragment key'), url);
                    const fragment = incoming.querySelector(`[data-ajax-results="${name}"]`);
                    if (!fragment || fragment.querySelector('script:not([type="application/json"])')) return failure(new Error('Invalid fragments'), url);
                    replacements.push([target, fragment.innerHTML]);
                }
                if (!replacements.length) return failure(new Error('Missing fragments'), url);
                const restoreFocus = replacements.some(([target]) => target.contains(root.activeElement));
                root.dispatchEvent(new browser.CustomEvent('techcalendar:filters-before-update', { detail: { key } }));
                for (const [target, content] of replacements) target.innerHTML = content;
                syncForm(url);
                renderedFilters = formUrl().href;
                if (options.history !== false && url.href !== browser.location.href) browser.history.pushState(null, '', url.href);
                root.dispatchEvent(new browser.CustomEvent('techcalendar:filters-updated', { detail: { key } }));
                busy(false); feedback.textContent = 'Résultats mis à jour.';
                if (restoreFocus) { replacements[0][0].tabIndex = -1; replacements[0][0].focus({ preventScroll: true }); }
                if (options.scroll) listing.querySelector('[data-ajax-results]')?.scrollIntoView({ block: 'start' });
            },
        });
        listing.addEventListener('submit', event => {
            if (event.target !== form) return;
            event.preventDefault(); loader.load(formUrl());
        });
        form.addEventListener('compositionstart', () => { composing = true; loader.cancel(); });
        form.addEventListener('compositionend', () => { composing = false; loader.load(formUrl(), { delay: 350 }); });
        form.addEventListener('input', event => {
            if (!composing && event.target.matches('input:not([type="checkbox"]):not([type="radio"])')) loader.load(formUrl(), { delay: 350 });
        });
        form.addEventListener('change', event => {
            if (event.target.matches('select,input[type="checkbox"],input[type="radio"]')) loader.load(formUrl());
        });
        listing.addEventListener('click', event => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
                || link.target || link.hasAttribute('download')) return;
            let url = new URL(link.href);
            if (url.origin !== action.origin || url.pathname !== action.pathname || url.hash) return;
            const paginated = link.closest('.gc-pagination');
            if (paginated) {
                const current = formUrl();
                if (current.href !== renderedFilters) url = current;
            } else if (link.matches('[data-category]')) {
                const status = url.searchParams.get('status');
                url = formUrl();
                if (status) url.searchParams.set('status', status);
                else url.searchParams.delete('status');
            }
            event.preventDefault(); syncForm(url); loader.load(url, { scroll: !!paginated });
        });
        browser.addEventListener('popstate', () => {
            const url = new URL(browser.location.href);
            if (url.pathname === action.pathname) { syncForm(url); loader.load(url, { history: false }); }
        });
        browser.addEventListener('pagehide', loader.cancel);
    });
}
