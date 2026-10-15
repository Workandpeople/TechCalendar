export function initCapceeReports(root = document, browser = window) {
    const section = root.querySelector('[data-capcee-status-url]');
    if (!section) return;
    const results = section.querySelector('[data-capcee-results]');
    const feedback = section.querySelector('[data-capcee-feedback]');
    const button = section.querySelector('[data-capcee-refresh]');
    let timer;
    let controller;
    let stopped = false;

    const schedule = () => {
        browser.clearTimeout(timer);
        if (!stopped && !root.hidden && results.querySelector('[data-capcee-active]')) {
            timer = browser.setTimeout(refresh, 5000);
        }
    };
    const refresh = async () => {
        if (controller || stopped) return;
        browser.clearTimeout(timer);
        controller = new AbortController();
        const timeout = browser.setTimeout(() => controller?.abort(), 15000);
        button.disabled = true;
        try {
            const response = await browser.fetch(section.dataset.capceeStatusUrl, {
                credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal,
            });
            if (!response.ok) {
                if ([401, 403, 419].includes(response.status)) stopped = true;
                throw new Error('status');
            }
            const data = await response.json();
            if (typeof data.html !== 'string' || typeof data.active !== 'boolean') throw new Error('payload');
            // Authenticated, same-origin Blade fragment; user data remains server-escaped.
            results.innerHTML = data.html;
            feedback.textContent = data.active ? 'Suivi automatique des envois en cours.' : 'Suivi à jour.';
        } catch {
            feedback.textContent = stopped ? 'Session expirée ou accès refusé. Recharge la page.'
                : 'Suivi momentanément indisponible. Le traitement continue en arrière-plan.';
        } finally {
            browser.clearTimeout(timeout);
            controller = null;
            button.disabled = false;
            schedule();
        }
    };
    button.addEventListener('click', refresh);
    root.addEventListener('visibilitychange', () => {
        if (root.hidden) browser.clearTimeout(timer);
        else if (results.querySelector('[data-capcee-active]')) refresh();
    });
    browser.addEventListener('pagehide', () => {
        stopped = true;
        browser.clearTimeout(timer);
        controller?.abort();
    });
    schedule();
}
