export function previewMatches(form, snapshot) {
    return ['recipient', 'body', 'appointment_id'].every((name) => form.elements.namedItem(name).value === snapshot[name]);
}

export function initDossierSms(root = document, browser = window) {
    const section = root.querySelector('[data-dossier-sms]');
    if (!section) return;
    const form = section.querySelector('[data-sms-form]');
    const panel = form.querySelector('[data-sms-preview-panel]');
    const preview = form.querySelector('[data-sms-preview]');
    const send = form.querySelector('[data-sms-send]');
    const feedback = form.querySelector('[data-sms-feedback]');
    const token = form.elements.namedItem('preview_token');
    const confirmed = form.elements.namedItem('confirmed');
    const body = form.elements.namedItem('body');
    let previewRequest;
    let stopped = false;
    let timer;
    let statusRequest;
    const invalidate = () => {
        token.value = '';
        confirmed.checked = false;
        send.disabled = true;
        panel.hidden = true;
    };
    form.querySelectorAll('[data-sms-input]').forEach((input) => {
        input.addEventListener('input', invalidate);
        input.addEventListener('change', invalidate);
    });
    form.querySelector('[data-sms-template]').addEventListener('change', (event) => {
        const value = event.target.selectedOptions[0]?.dataset.body;
        if (value !== undefined) body.value = value;
        invalidate();
    });
    form.querySelectorAll('[data-sms-variable]').forEach((button) => button.addEventListener('click', () => {
        body.setRangeText(button.dataset.smsVariable, body.selectionStart, body.selectionEnd, 'end');
        invalidate();
        body.focus();
    }));
    confirmed.addEventListener('change', () => { send.disabled = !confirmed.checked || !token.value; });
    form.addEventListener('submit', (event) => {
        if (!token.value || !confirmed.checked) event.preventDefault();
        else send.disabled = true;
    });
    preview.addEventListener('click', async () => {
        if (previewRequest) return;
        const snapshot = Object.fromEntries(['recipient', 'body', 'appointment_id'].map((name) => [name, form.elements.namedItem(name).value]));
        invalidate();
        preview.disabled = true;
        feedback.textContent = 'Vérification du numéro et des variables…';
        previewRequest = new AbortController();
        const timeout = browser.setTimeout(() => previewRequest?.abort(), 15000);
        try {
            const response = await browser.fetch(form.dataset.previewUrl, {
                method: 'POST', credentials: 'same-origin', signal: previewRequest.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.elements.namedItem('_token').value },
                body: JSON.stringify(snapshot),
            });
            const data = await response.json();
            if (!response.ok) {
                feedback.textContent = response.status === 422 ? Object.values(data.errors || {}).flat().join(' ') : 'Aperçu indisponible. Vérifie ta session et réessaie.';
                return;
            }
            if (!previewMatches(form, snapshot)) {
                feedback.textContent = 'Le formulaire a changé. Prépare un nouvel aperçu.';
                return;
            }
            token.value = data.preview_token;
            panel.querySelector('[data-sms-preview-heading]').textContent = `${data.mode === 'simulate' ? 'Simulation sans livraison' : 'Envoi réel'} vers ${data.recipient}`;
            const pricing = data.mode === 'simulate' ? 'Simulation : aucun crédit débité.' : 'Estimation, tarif fournisseur applicable.';
            panel.querySelector('[data-sms-preview-meta]').textContent = `Expéditeur : ${data.sender || 'SMSFactor par défaut'} · ${data.metrics.characters} caractères · environ ${data.metrics.segments} SMS (${data.metrics.unicode ? 'Unicode' : 'GSM-7'}). ${pricing}`;
            panel.querySelector('[data-sms-preview-body]').textContent = data.body;
            panel.hidden = false;
            feedback.textContent = 'Vérifie le destinataire et le texte, puis confirme. Aperçu valable 20 minutes.';
        } catch {
            feedback.textContent = 'Impossible de préparer l’aperçu. Aucun SMS envoyé.';
        } finally {
            browser.clearTimeout(timeout);
            previewRequest = null;
            preview.disabled = false;
        }
    });

    const results = section.querySelector('[data-sms-results]');
    const refresh = section.querySelector('[data-sms-refresh]');
    const statusFeedback = section.querySelector('[data-sms-status-feedback]');
    const schedule = () => {
        browser.clearTimeout(timer);
        if (!stopped && !root.hidden && results.querySelector('[data-sms-active]')) timer = browser.setTimeout(update, 5000);
    };
    const update = async () => {
        if (statusRequest || stopped) return;
        browser.clearTimeout(timer);
        statusRequest = new AbortController();
        const timeout = browser.setTimeout(() => statusRequest?.abort(), 15000);
        refresh.disabled = true;
        try {
            const response = await browser.fetch(section.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: statusRequest.signal });
            if (!response.ok) {
                if ([401, 403, 419].includes(response.status)) stopped = true;
                throw new Error('status');
            }
            const data = await response.json();
            if (typeof data.html !== 'string') throw new Error('payload');
            // Same-origin authenticated Blade fragment, with server-escaped message data.
            results.innerHTML = data.html;
            statusFeedback.textContent = data.active ? 'Suivi automatique en cours.' : 'Suivi à jour.';
        } catch {
            statusFeedback.textContent = stopped ? 'Session expirée. Recharge la page.' : 'Suivi momentanément indisponible. Aucun nouvel envoi déclenché.';
        } finally {
            browser.clearTimeout(timeout);
            statusRequest = null;
            refresh.disabled = false;
            schedule();
        }
    };
    refresh.addEventListener('click', update);
    root.addEventListener('visibilitychange', () => { if (root.hidden) browser.clearTimeout(timer); else schedule(); });
    browser.addEventListener('pagehide', () => { stopped = true; browser.clearTimeout(timer); previewRequest?.abort(); statusRequest?.abort(); });
    schedule();
}
