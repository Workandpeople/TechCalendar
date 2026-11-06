import { readApiJson } from './api-response.js';

export function exportDownloadUrl(value, origin) {
    try {
        const url = new URL(value, origin);
        return url.origin === origin && /^\/espace-client\/rapports-lots\/[a-f0-9-]{36}\/telecharger$/.test(url.pathname)
            && !url.search && !url.hash ? url.href : null;
    } catch { return null; }
}

export function initLotReportExports(root = document, browser = window) {
    root.querySelectorAll('[data-report-exports]').forEach(section => {
        if (section.dataset.initialized) return;
        section.dataset.initialized = 'true';
        const dialog = section.querySelector('dialog');
        const search = section.querySelector('[data-export-search]');
        const options = section.querySelector('[data-export-options]');
        const retry = section.querySelector('[data-export-retry]');
        const submit = section.querySelector('[data-export-submit]');
        const feedback = section.querySelector('[data-export-feedback]');
        const recent = section.querySelector('[data-export-recent]');
        let selected = '', submitting = false, searching = null, searchTimer = null, pollTimer = null, generation = 0, refreshGeneration = 0, checks = 0, opener = null;
        const message = text => { feedback.textContent = text; };
        const request = async (url, init = {}) => {
            const controller = new AbortController();
            let timedOut = false;
            const cancel = () => controller.abort();
            init.signal?.addEventListener('abort', cancel, { once: true });
            if (init.signal?.aborted) controller.abort();
            const timeout = browser.setTimeout(() => { timedOut = true; controller.abort(); }, 15000);
            try {
                const response = await browser.fetch(url, { credentials: 'same-origin', cache: 'no-store', ...init,
                    headers: { Accept: 'application/json', ...init.headers }, signal: controller.signal });
                const data = await readApiJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Demande indisponible. Réessayez plus tard.');
                return data;
            } catch (error) {
                if (timedOut && error.name === 'AbortError') {
                    const timeoutError = new Error('Le serveur met trop de temps à répondre. Réessayez dans un instant.');
                    timeoutError.name = 'RequestTimeoutError';
                    throw timeoutError;
                }
                throw error;
            } finally { browser.clearTimeout(timeout); init.signal?.removeEventListener('abort', cancel); }
        };
        const choose = value => {
            selected = value;
            section.querySelector('[data-export-selected]').textContent = value ? `Lot sélectionné : ${value}` : 'Aucun lot sélectionné.';
            submit.disabled = submitting || !value;
        };
        const loadLots = async () => {
            searching?.abort();
            const controller = new AbortController(); searching = controller;
            const current = ++generation;
            retry.hidden = true;
            const url = new URL(section.dataset.lotsUrl, browser.location.href);
            url.searchParams.set('q', search.value.trim());
            options.textContent = 'Recherche des lots…';
            try {
                const data = await request(url.href, { signal: controller.signal });
                if (current !== generation || !dialog.open) return;
                options.replaceChildren();
                for (const name of data.lots || []) {
                    if (typeof name !== 'string') continue;
                    const button = root.createElement('button');
                    button.type = 'button'; button.className = 'block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-sky-50 focus:bg-sky-50';
                    button.textContent = name; button.setAttribute('aria-pressed', String(selected === name));
                    button.addEventListener('click', () => {
                        choose(name);
                        options.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
                    });
                    options.append(button);
                }
                if (!options.children.length) options.textContent = 'Aucun lot accessible ne correspond à cette recherche.';
                if (data.lots?.length === 1 && data.lots[0] === search.value.trim()) choose(data.lots[0]);
            } catch (error) {
                if (current === generation && error.name !== 'AbortError') {
                    options.textContent = error.message;
                    retry.hidden = false;
                }
            }
        };
        const refresh = async ({ automatic = false } = {}) => {
            browser.clearTimeout(pollTimer);
            if (!dialog.open || root.hidden) return;
            if (!automatic) checks = 0;
            const current = ++refreshGeneration;
            try {
                const data = await request(section.dataset.listUrl);
                if (!dialog.open || current !== refreshGeneration) return;
                recent.replaceChildren();
                const exports = Array.isArray(data.exports) ? data.exports : [];
                for (const item of exports) {
                    const card = root.createElement('article'); card.className = 'rounded-xl border border-slate-200 bg-slate-50 p-3';
                    const title = root.createElement('p'); title.className = 'text-sm font-semibold'; title.textContent = item.lot;
                    const state = root.createElement('p'); state.className = 'mt-1 text-sm text-slate-600'; state.textContent = item.message;
                    card.append(title, state);
                    const url = item.status === 'ready' && exportDownloadUrl(item.download_url, browser.location.origin);
                    if (url) {
                        const link = root.createElement('a'); link.href = url; link.className = 'gc-btn-primary mt-3';
                        link.textContent = `Télécharger le ZIP (${item.file_count} rapports)`; card.append(link);
                    }
                    recent.append(card);
                }
                if (!exports.length) recent.textContent = 'Aucune demande récente.';
                const busy = exports.some(item => ['queued', 'building'].includes(item.status));
                submit.disabled = submitting || !selected || busy;
                if (busy && dialog.open && ++checks < 120) pollTimer = browser.setTimeout(() => refresh({ automatic: true }), 5000);
                else if (busy) message('La préparation continue en arrière-plan. Utilisez Actualiser pour consulter son avancement.');
            } catch (error) {
                if (current === refreshGeneration && dialog.open) message(error.name === 'AbortError' ? 'Le suivi met du temps à répondre. Utilisez Actualiser pour réessayer.' : error.message);
            }
        };
        section.querySelector('[data-export-open]').addEventListener('click', event => {
            if (dialog.open) return;
            opener = event.currentTarget; search.value = root.querySelector('#lot')?.value ?? section.dataset.initialLot ?? '';
            choose(''); message(''); dialog.showModal(); loadLots(); refresh();
        });
        dialog.querySelector('[data-dialog-close]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            browser.clearTimeout(pollTimer); browser.clearTimeout(searchTimer); searching?.abort(); generation++; refreshGeneration++;
            opener?.focus?.({ preventScroll: true });
        });
        search.addEventListener('input', () => {
            choose(''); searching?.abort(); generation++; browser.clearTimeout(searchTimer);
            searchTimer = browser.setTimeout(loadLots, 300);
        });
        section.querySelector('[data-export-refresh]').addEventListener('click', refresh);
        retry.addEventListener('click', loadLots);
        root.addEventListener('visibilitychange', () => {
            browser.clearTimeout(pollTimer);
            if (!root.hidden && dialog.open) refresh();
        });
        section.querySelector('[data-export-form]').addEventListener('submit', async event => {
            event.preventDefault();
            if (submitting || !selected) return;
            submitting = true; submit.disabled = true; message('Enregistrement de la demande…');
            try {
                const data = await request(section.dataset.createUrl, { method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ lot: selected }) });
                message(data.message);
            } catch (error) { message(['AbortError', 'RequestTimeoutError'].includes(error.name) ? 'La confirmation tarde. Actualisez le suivi avant de demander une nouvelle archive.' : error.message); }
            finally { submitting = false; submit.disabled = !selected; refresh(); }
        });
    });
}
