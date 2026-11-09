export function validIncidentCounts(data) {
    return data && ['pending', 'latest_id'].every(key => Number.isSafeInteger(data[key]) && data[key] >= 0)
        && (data.billing === null || (Number.isSafeInteger(data.billing) && data.billing >= 0));
}

export function initTechnicianIncidentNotifications(root = document, browser = window) {
    const panel = root.querySelector('[data-incident-notifications]');
    if (!panel || panel.dataset.ready) return;
    panel.dataset.ready = 'true';
    let latest = Number(panel.dataset.latestId) || 0;
    let timer, controller, paused = false, stopped = false, delay = 45000;
    const cancel = () => { browser.clearTimeout(timer); controller?.abort(); };
    const schedule = () => {
        browser.clearTimeout(timer);
        if (!root.hidden && !paused && !stopped) timer = browser.setTimeout(check, delay);
    };
    const check = async () => {
        if (controller || root.hidden || paused || stopped) return;
        const current = new AbortController(); controller = current;
        const timeout = browser.setTimeout(() => current.abort(), 10000);
        try {
            const response = await browser.fetch(panel.dataset.countsUrl, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: current.signal });
            if (response.redirected || [401, 403, 419].includes(response.status)) { stopped = true; return; }
            if (!response.ok) throw new Error('Incident counts unavailable');
            const counts = await response.json();
            if (controller !== current || current.signal.aborted) return;
            if (!validIncidentCounts(counts)) throw new Error('Invalid incident counts');
            root.querySelectorAll('[data-incident-pending-count]').forEach(node => { node.textContent = String(counts.pending); });
            root.querySelectorAll('[data-incident-billing-count]').forEach(node => { node.textContent = String(counts.billing ?? 0); });
            if (counts.latest_id > latest) root.querySelector('[data-incident-alert]')?.classList.remove('hidden');
            latest = Math.max(latest, counts.latest_id);
            delay = 45000;
        } catch {
            delay = Math.min(delay * 2, 300000);
        } finally {
            browser.clearTimeout(timeout);
            if (controller === current) controller = null;
            schedule();
        }
    };
    root.addEventListener('visibilitychange', () => { cancel(); if (!root.hidden) schedule(); });
    browser.addEventListener('pagehide', () => { paused = true; cancel(); });
    browser.addEventListener('pageshow', () => { paused = false; schedule(); });
    schedule();
}
