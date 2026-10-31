const terminalStates = new Set(['confirmed', 'rejected']);

export function validateActionResult(value, id) {
    if (!value || value.request_id !== id || !['queued', 'dispatching', 'uncertain', 'confirmed', 'rejected'].includes(value.state)
        || value.confirmed !== (value.state === 'confirmed') || value.terminal !== terminalStates.has(value.state)
        || typeof value.message !== 'string') throw new Error('Réponse MAR non vérifiable.');
    return value;
}

export function initMymarActions(root = document, browser = window) {
    const panel = root.querySelector('[data-mymar-pending]');
    if (!panel || !browser.crypto?.randomUUID || typeof browser.fetch !== 'function') return null;
    const urls = {};
    try {
        for (const [key, attribute] of [['place', 'placeUrl'], ['problem', 'problemUrl'], ['status', 'statusUrl']]) {
            const url = new URL(panel.dataset[attribute], browser.location.origin);
            if (url.origin !== browser.location.origin || url.search || url.hash || url.username || url.password) return null;
            urls[key] = url.href;
        }
    } catch { return null; }
    const document = panel.ownerDocument;
    const storageKey = `cofrac.mar.confirmations.${panel.dataset.userId}`;
    let records = {};
    try {
        const stored = JSON.parse(browser.sessionStorage.getItem(storageKey) || '{}');
        if (stored && typeof stored === 'object') for (const [key, row] of Object.entries(stored).slice(0, 100)) {
            if (/^[0-9]+$/.test(key) && /^[a-f0-9-]{36}$/.test(row?.id || '')) records[key] = { id: row.id, action: row.action };
        }
    } catch { /* Server-side unique keys still prevent duplicate active commands. */ }
    const resumeButton = panel.querySelector('[data-mymar-resume]');
    const save = () => {
        try { browser.sessionStorage.setItem(storageKey, JSON.stringify(records)); } catch {}
        if (resumeButton) resumeButton.style.display = Object.keys(records).length ? '' : 'none';
    };
    const node = (tag, text = '', className = '') => { const element = document.createElement(tag); element.textContent = text; element.className = className; return element; };
    const button = text => { const value = node('button', text, 'gc-btn-secondary'); value.type = 'button'; return value; };
    let dialog = null;
    const open = title => {
        dialog?.close(); dialog?.remove();
        dialog = node('dialog', '', 'gc-mymar-action-dialog');
        const header = node('header', '', 'flex items-center justify-between gap-3');
        header.append(node('h2', title, 'font-semibold text-lg'));
        const close = button('Fermer'); close.addEventListener('click', () => dialog.close()); header.append(close);
        dialog.append(header); document.body.append(dialog); dialog.showModal();
        return dialog;
    };
    const changed = () => panel.dispatchEvent(new browser.Event('cofrac:mymar:changed'));
    const request = async (url, method, data = null) => {
        const controller = new browser.AbortController();
        const timer = browser.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await browser.fetch(url, { method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': panel.dataset.csrf },
                ...(data ? { body: JSON.stringify(data) } : {}) });
            if (response.redirected || !/^application\/json(?:;|$)/i.test(response.headers.get('Content-Type') || '')) throw new Error('Confirmation MAR non reçue.');
            const result = await response.json();
            if (!response.ok) {
                const error = new Error(Object.values(result.errors || {})[0]?.[0] || result.message || 'Confirmation MAR indisponible.');
                error.definitelyNotQueued = [403, 409, 419, 422, 429].includes(response.status);
                throw error;
            }
            return result;
        } finally { browser.clearTimeout(timer); }
    };
    const track = (dossierId, record, initial = null) => {
        const view = open('Confirmation MYMAR');
        const message = node('p', initial?.message || 'Le résultat n’a pas encore été confirmé. Ne renvoyez pas cette demande.', 'mt-4');
        message.setAttribute('role', 'status');
        view.append(message, node('p', `Référence : ${record.id}`, 'text-sm mt-3 break-all'));
        const verify = button('Vérifier la confirmation'); verify.classList.add('mt-4'); view.append(verify);
        verify.hidden = initial?.terminal === true;
        verify.addEventListener('click', async () => {
            verify.disabled = true;
            try {
                const result = validateActionResult(await request(urls.status.replace('__REQUEST__', record.id), 'GET'), record.id);
                message.textContent = result.message;
                if (result.terminal) { delete records[dossierId]; save(); verify.hidden = true; changed(); }
            } catch (error) { message.textContent = `${error.message} Conservez cette référence et ne créez pas une deuxième demande.`; }
            finally { verify.disabled = false; }
        });
    };
    const submit = async (action, payload) => {
        const key = String(payload.dossier_id);
        if (records[key]) { track(key, records[key]); return null; }
        const record = { id: browser.crypto.randomUUID(), action };
        records[key] = record; save();
        try {
            const result = validateActionResult(await request(urls[action], 'POST', { ...payload, request_id: record.id }), record.id);
            if (result.terminal) { delete records[key]; save(); changed(); }
            if (!result.confirmed) track(key, record, result);
            return result;
        } catch (error) {
            if (error.definitelyNotQueued) { delete records[key]; save(); throw error; }
            track(key, record);
            return null;
        }
    };
    const actions = {
        choose(item) {
            if (records[item.dossier_id]) { track(item.dossier_id, records[item.dossier_id]); return; }
            const view = open('Placer le rendez-vous MAR');
            view.append(node('p', `${item.last_name} ${item.first_name}`, 'mt-4 font-semibold'));
            const label = node('label', 'Durée de la visite', 'block mt-4');
            const select = node('select', '', 'gc-input mt-2'); select.setAttribute('aria-label', 'Durée de la visite MAR');
            const empty = node('option', 'Choisir une durée'); empty.value = ''; select.append(empty);
            for (let hours = 1; hours <= 8; hours++) { const option = node('option', `${hours} h`); option.value = String(hours * 60); select.append(option); }
            label.append(select); view.append(label);
            const choose = button('Chercher un créneau'); choose.disabled = true; choose.classList.add('mt-4'); view.append(choose);
            select.addEventListener('change', () => { choose.disabled = !select.value; });
            choose.addEventListener('click', () => {
                if (!select.value) return;
                view.close();
                browser.dispatchEvent(new browser.CustomEvent('cofrac:mymar:select', { detail: { dossier_id: item.dossier_id, duration_minutes: Number(select.value) } }));
            });
        },
        problem(item) {
            if (records[item.dossier_id]) { track(item.dossier_id, records[item.dossier_id]); return; }
            const view = open('Signaler un problème RDV MAR');
            const form = node('form', '', 'mt-4 grid gap-4');
            const input = (tag, title, type = null) => { const label = node('label', title); const field = node(tag, '', 'gc-input mt-2'); if (type) field.type = type; label.append(field); form.append(label); return field; };
            const reason = input('input', 'Motif (facultatif)'); reason.maxLength = 100;
            const comment = input('textarea', 'Commentaire'); comment.required = true; comment.minLength = 3; comment.maxLength = 255;
            const recall = input('input', 'Date de rappel (facultative)', 'datetime-local');
            const status = node('p'); status.setAttribute('role', 'status'); form.append(status);
            const send = button('Enregistrer dans MYMAR'); send.type = 'submit'; send.classList.add('gc-btn-primary'); form.append(send);
            view.append(form);
            form.addEventListener('submit', async event => {
                event.preventDefault(); if (send.disabled || !form.reportValidity()) return;
                send.disabled = true;
                try {
                    const result = await submit('problem', { dossier_id: item.dossier_id, expected_revision: item.expected_revision,
                        comment: comment.value.trim(), reason: reason.value.trim() || null,
                        recall_at: recall.value ? browser.TechCalendarDateTime.serializeAppointmentInput(recall.value) : null });
                    if (result?.confirmed) { view.close(); const success = open('Problème enregistré'); success.append(node('p', result.message, 'mt-4')); }
                } catch (error) { status.textContent = error.message; }
                finally { send.disabled = false; }
            });
        },
        place: payload => submit('place', payload),
        resume() { const entry = Object.entries(records)[0]; if (entry) track(...entry); },
    };
    resumeButton?.addEventListener('click', () => actions.resume());
    save();
    return actions;
}
