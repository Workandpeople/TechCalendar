export function pendingPage(value, after) {
    if (!value || value.format !== 'cofrac.mymar-pending-bookings' || value.version !== 1 || value.read_only !== true
        || !Array.isArray(value.items) || value.items.length > 25 || !Number.isSafeInteger(value.checked_at)
        || (value.next_after !== null && (!Number.isSafeInteger(value.next_after) || value.next_after <= after))) throw new Error('invalid page');
    let last = after;
    for (const item of value.items) {
        if (!item || !Number.isSafeInteger(item.dossier_id) || item.dossier_id <= last
            || ![1, 14].includes(item.status_id) || item.phase !== (item.status_id === 14 ? 'after_works' : 'initial')
            || ['first_name', 'last_name', 'address', 'phone'].some(key => typeof item[key] !== 'string' || item[key].length > 4096)) throw new Error('invalid item');
        last = item.dossier_id;
    }
    if (value.next_after !== null && (value.items.length !== 25 || value.next_after !== last)) throw new Error('invalid cursor');
    return value;
}

export function renderPendingRows(list, items, actions = null) {
    list.replaceChildren();
    for (const item of items) {
        const row = list.ownerDocument.createElement('article');
        row.className = 'rounded-xl border p-3 grid gap-2 sm:grid-cols-2';
        row.setAttribute('role', 'listitem');
        const name = list.ownerDocument.createElement('strong');
        name.textContent = `${item.last_name} ${item.first_name}`.trim() || 'Bénéficiaire non renseigné';
        const phase = list.ownerDocument.createElement('span');
        phase.className = 'text-sm font-semibold sm:text-right';
        phase.textContent = item.phase === 'after_works' ? 'MAR · Visite après travaux' : 'MAR · Visite initiale';
        const address = list.ownerDocument.createElement('span');
        address.className = 'text-sm';
        const locality = [item.postal_code, item.city].filter(Boolean).join(' ');
        address.textContent = [item.address, locality && !item.address.includes(locality) ? locality : ''].filter(Boolean).join(', ') || 'Adresse non renseignée';
        const phone = list.ownerDocument.createElement('strong');
        phone.className = 'text-sm sm:text-right';
        phone.textContent = item.phone || 'Téléphone non renseigné';
        row.append(name, phase, address, phone);
        if (actions) {
            const controls = list.ownerDocument.createElement('div');
            controls.className = 'flex flex-wrap gap-2 sm:col-span-2';
            for (const [title, method] of [['Placer le RDV', 'choose'], ['Problème RDV', 'problem']]) {
                const button = list.ownerDocument.createElement('button');
                button.type = 'button'; button.className = 'gc-btn-secondary'; button.textContent = title;
                button.addEventListener('click', () => actions[method](item)); controls.append(button);
            }
            row.append(controls);
        }
        list.append(row);
    }
}

export function initMymarPending(root = document, browser = window) {
    const panel = root.querySelector('[data-mymar-pending]');
    if (!panel || typeof browser.fetch !== 'function' || typeof browser.AbortController !== 'function') return;
    if (panel.dataset.mymarInitialized) return;
    panel.dataset.mymarInitialized = 'true';
    const list = panel.querySelector('[data-mymar-list]');
    const status = panel.querySelector('[data-mymar-status]');
    const refresh = panel.querySelector('[data-mymar-refresh]');
    const previous = panel.querySelector('[data-mymar-previous]');
    const next = panel.querySelector('[data-mymar-next]');
    const pager = panel.querySelector('[data-mymar-pagination]');
    if (!list || !status || !refresh || !previous || !next || !pager || !panel.querySelector('[data-mymar-page]')) return;
    let endpoint;
    try {
        endpoint = new URL(panel.dataset.url, browser.location.origin);
        if (endpoint.origin !== browser.location.origin || endpoint.username || endpoint.password || endpoint.hash || endpoint.search) return;
    } catch { return; }
    let after = 0, history = [], nextAfter = null, loaded = false, lastLoaded = 0, request = null, version = 0;
    const controls = busy => {
        panel.setAttribute('aria-busy', String(busy));
        refresh.disabled = busy;
        previous.disabled = busy || history.length === 0;
        next.disabled = busy || nextAfter === null;
    };
    const load = async (cursor = after, { fresh = false, back = false } = {}) => {
        request?.abort();
        const own = ++version;
        const controller = new browser.AbortController();
        request = controller;
        controls(true);
        status.textContent = loaded ? 'Actualisation des demandes MAR…' : 'Chargement des demandes MAR…';
        if (!loaded) {
            const skeleton = list.ownerDocument.createElement('div');
            skeleton.className = 'animate-pulse rounded-xl h-32 bg-slate-100';
            skeleton.setAttribute('aria-hidden', 'true');
            list.replaceChildren(skeleton);
        }
        const timer = browser.setTimeout(() => controller.abort(), 12000);
        try {
            const url = new URL(endpoint.href);
            url.searchParams.set('after', String(cursor));
            if (fresh) url.searchParams.set('refresh', '1');
            const response = await browser.fetch(url.href, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            if (!response.ok || response.redirected || !/^application\/json(?:;|$)/i.test(response.headers.get('Content-Type') || '')) throw new Error('unavailable');
            const value = pendingPage(await response.json(), cursor);
            if (own !== version || controller.signal.aborted) return;
            if (cursor !== after) {
                if (back) history.pop();
                else history.push(after);
            }
            after = cursor;
            nextAfter = value.next_after;
            loaded = true;
            lastLoaded = Date.now();
            const actions = value.actions_enabled === true ? browser.CofracMymarActions : null;
            renderPendingRows(list, value.items, actions);
            const note = panel.querySelector('[data-mymar-readonly-note]');
            if (note) note.hidden = Boolean(actions);
            status.textContent = value.items.length ? `${value.items.length} demande(s) sur cette page${actions ? '' : ' · lecture seule'}` : 'Aucune demande MAR à placer sur cette page.';
            panel.querySelector('[data-mymar-page]').textContent = `Page ${history.length + 1}`;
            pager.hidden = history.length === 0 && nextAfter === null;
        } catch {
            if (own !== version) return;
            if (!loaded) list.replaceChildren();
            status.textContent = 'Lecture des demandes MAR indisponible. Réessayez avec « Actualiser ».';
        } finally {
            browser.clearTimeout(timer);
            if (own === version) { request = null; controls(false); }
        }
    };
    const activate = () => { if (!panel.hidden && (!loaded || Date.now() - lastLoaded > 30000)) void load(); };
    panel.addEventListener('cofrac:mymar:activate', activate);
    const deactivate = () => { ++version; request?.abort(); request = null; controls(false); };
    panel.addEventListener('cofrac:mymar:deactivate', deactivate);
    panel.addEventListener('cofrac:mymar:changed', () => { loaded = false; if (!panel.hidden) void load(after, { fresh: true }); });
    browser.addEventListener('pagehide', deactivate);
    refresh.addEventListener('click', () => { if (!panel.hidden) void load(after, { fresh: true }); });
    previous.addEventListener('click', () => { if (!panel.hidden && !request && history.length) void load(history.at(-1), { back: true }); });
    next.addEventListener('click', () => { if (!panel.hidden && !request && nextAfter !== null) void load(nextAfter); });
    controls(false);
    activate();
}
