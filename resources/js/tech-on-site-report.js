import { readApiJson } from './api-response.js';

const LIFETIME = 24 * 60 * 60 * 1000;
const MAX_PHOTO = 10 * 1024 * 1024;
const visitLabel = date => `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(0, 4)} à ${date.slice(11, 16)}`;

// This check is only for presentation. The server verifies signature, owner and expiry.
export function onSiteDraftVisit(draft, now = Date.now()) {
    try {
        const context = JSON.parse(draft.reportContext);
        const booking = context.booking;
        return context.version === 1 && Number.isInteger(context.issued_at) && context.issued_at * 1000 <= now
            && now - context.issued_at * 1000 < LIFETIME && /^[a-f0-9]{64}$/.test(draft.contextSignature)
            && String(booking?.appointment_id) === draft.appointment && String(booking?.technician_id) === draft.actor
            && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(booking?.starts_at) ? booking : null;
    } catch { return null; }
}

export function onSiteDraftValid(draft, actor, appointment, now = Date.now()) {
    return draft?.version === 1 && draft.actor === actor && draft.appointment === appointment
        && Number.isFinite(draft.savedAt) && draft.savedAt <= now && now - draft.savedAt < LIFETIME
        && (!draft.photo || (draft.photo.size > 0 && draft.photo.size <= MAX_PHOTO));
}

export function createOnSiteDraftStore(indexedDB) {
    let connection;
    const open = () => connection ??= new Promise((resolve, reject) => {
        if (!indexedDB) return reject(new Error('Brouillons indisponibles sur cet appareil.'));
        const request = indexedDB.open('cofrac-on-site-drafts', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('drafts', { keyPath: 'key' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Stockage des brouillons bloqué.'));
    });
    const operation = async (mode, action) => {
        const db = await open();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('drafts', mode);
            let result;
            const request = action(transaction.objectStore('drafts'));
            request.onsuccess = () => { result = request.result; };
            transaction.oncomplete = () => resolve(result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    };
    return {
        get: key => operation('readonly', store => store.get(key)),
        put: draft => operation('readwrite', store => store.put(draft)),
        remove: key => operation('readwrite', store => store.delete(key)),
        async list(actor) {
            if (!/^\d+$/.test(actor)) return [];
            const db = await open();
            return new Promise((resolve, reject) => {
                const transaction = db.transaction('drafts', 'readonly');
                const store = transaction.objectStore('drafts'), rows = [];
                const request = store.openKeyCursor(IDBKeyRange.bound(`${actor}:`, `${actor}:\uffff`));
                let examined = 0;
                request.onsuccess = () => {
                    const cursor = request.result;
                    if (!cursor || ++examined > 60) return;
                    if (!new RegExp(`^${actor}:\\d+$`).test(cursor.key)) { cursor.continue(); return; }
                    const read = store.get(cursor.key);
                    read.onsuccess = () => {
                        const draft = read.result;
                        if (onSiteDraftValid(draft, actor, String(draft?.appointment))) {
                            const visit = onSiteDraftVisit(draft);
                            rows.push({ key: cursor.key, appointment: draft.appointment, recoverable: !!visit,
                                label: visit ? `${visit.first_name} ${visit.last_name} · ${visitLabel(visit.starts_at)}` : `RDV #${draft.appointment} · Ancien brouillon` });
                        }
                        if (rows.length < 30) cursor.continue();
                    };
                };
                transaction.oncomplete = () => resolve(rows);
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error);
            });
        },
        async prune(now = Date.now()) {
            const db = await open();
            await new Promise((resolve, reject) => {
                const transaction = db.transaction('drafts', 'readwrite');
                const request = transaction.objectStore('drafts').openCursor();
                request.onsuccess = () => {
                    const cursor = request.result;
                    if (!cursor) return;
                    if (!Number.isFinite(cursor.value.savedAt) || now - cursor.value.savedAt >= LIFETIME) cursor.delete();
                    cursor.continue();
                };
                transaction.oncomplete = resolve;
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error);
            });
        },
    };
}

export async function prepareOnSitePhoto(file, root = document, view = window) {
    if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > MAX_PHOTO || !file.size) {
        throw new Error('Choisissez une photo JPEG, PNG ou WebP de 10 Mo maximum.');
    }
    if (!view.createImageBitmap) return file;
    let bitmap;
    try { bitmap = await view.createImageBitmap(file, { imageOrientation: 'from-image' }); }
    catch { throw new Error('Cette photo ne peut pas être lue. Reprenez-la au format JPEG puis réessayez.'); }
    try {
        const ratio = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
        const canvas = root.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * ratio));
        canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
        const context = canvas.getContext('2d');
        if (!context) return file;
        context.fillStyle = '#ffffff'; context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob?.size) throw new Error('La photo ne peut pas être préparée. Reprenez-la puis réessayez.');
        return blob;
    } finally { bitmap.close(); }
}

export function initOnSiteReports(root = document, view = window, store = createOnSiteDraftStore(view.indexedDB)) {
    root.querySelectorAll('[data-on-site-report]').forEach(form => {
        const field = name => form.elements.namedItem(name);
        const details = form.closest('details');
        const submit = form.querySelector('[data-report-submit]');
        const discard = form.querySelector('[data-report-discard]');
        const feedback = form.querySelector('[data-report-feedback]');
        const status = form.querySelector('[data-report-draft-status]');
        const preview = form.querySelector('[data-report-preview]');
        const recover = form.querySelector('[data-report-recover]');
        const visitCaption = form.querySelector('[data-report-visit-label]');
        const visitFields = form.querySelector('[data-report-visit-fields]');
        const currentIntro = form.querySelector('[data-report-current-intro]');
        const mode = field('evidence_only');
        let evidenceRequired = form.dataset.evidenceRequired === 'true';
        const currentSnapshot = field('snapshot').value;
        const actor = form.dataset.actor, appointment = form.dataset.appointment;
        const key = `${actor}:${appointment}`;
        let photo = null, photoName = '', previewUrl = null, saving = Promise.resolve(), timer;
        let busy = false, submitted = false, dirty = false, generation = 0, stale = form.dataset.recovery === 'true', preparing = false;
        const notify = message => { feedback.textContent = message; feedback.hidden = !message; };
        const historical = () => !!mode?.checked;
        const updateMode = () => {
            if (!mode) return;
            if (evidenceRequired) mode.checked = true;
            const visit = onSiteDraftVisit({ actor, appointment, reportContext: field('report_context').value,
                contextSignature: field('context_signature').value });
            const manual = historical() && !visit;
            visitFields.hidden = !manual; visitFields.disabled = !manual || busy || submitted;
            ['visit_date', 'visit_time'].forEach(name => { field(name).required = manual; field(name).disabled = !manual || busy || submitted; });
            currentIntro.hidden = historical();
            visitCaption.hidden = !historical() || !visit;
            if (visit) visitCaption.textContent = `Preuve pour le RDV d’origine du ${visitLabel(visit.starts_at)}. Le RDV actuel reste inchangé.`;
            mode.disabled = busy || submitted || evidenceRequired;
        };
        const buttons = () => {
            updateMode();
            submit.disabled = busy || preparing || stale || submitted; discard.disabled = busy || preparing || submitted;
            submit.textContent = busy ? 'Enregistrement…' : historical() ? 'Enregistrer la preuve uniquement' : 'Valider le signalement';
            form.setAttribute?.('aria-busy', String(busy));
            ['reason', 'comment', 'photo'].forEach(name => { field(name).disabled = busy || submitted; });
            if (recover) recover.disabled = busy || preparing || submitted;
        };
        const drawPhoto = () => {
            if (previewUrl) view.URL.revokeObjectURL(previewUrl);
            previewUrl = photo ? view.URL.createObjectURL(photo) : null;
            preview.hidden = !photo;
            if (photo) {
                preview.querySelector('img').src = previewUrl;
                preview.querySelector('[data-report-photo-name]').textContent = photoName;
            } else preview.querySelector('img').removeAttribute('src');
            field('photo').required = !photo;
        };
        const draft = () => ({ version: 1, key, actor, appointment, savedAt: Date.now(),
            requestId: field('request_id').value, snapshot: field('snapshot').value,
            reason: field('reason').value, comment: field('comment').value, photo, photoName,
            ...(mode ? { reportContext: field('report_context').value, contextSignature: field('context_signature').value,
                evidenceOnly: historical(), historicalRecovery: evidenceRequired,
                visitDate: field('visit_date').value, visitTime: field('visit_time').value } : {}) });
        const save = () => {
            view.clearTimeout(timer);
            if (submitted || stale || !dirty) return saving;
            const value = draft();
            saving = saving.catch(() => {}).then(() => store.put(value)).then(() => {
                if (!submitted) status.textContent = 'Brouillon enregistré sur cet appareil pour 24 h. Rien n’est envoyé avant validation.';
            }).catch(() => {
                status.textContent = 'Le brouillon ne peut pas être enregistré sur cet appareil. Gardez cette page ouverte jusqu’à la confirmation.';
            });
            return saving;
        };
        const change = () => {
            dirty = true;
            field('comment').required = field('reason').value === 'other';
            view.clearTimeout(timer); timer = view.setTimeout(save, 400);
        };
        ['reason', 'comment'].forEach(name => field(name).addEventListener('input', change));
        if (mode) {
            mode.addEventListener('change', () => { buttons(); change(); });
            ['visit_date', 'visit_time'].forEach(name => field(name).addEventListener('input', change));
            recover.addEventListener('click', async () => {
                if (busy || preparing || submitted || !stale) return;
                mode.checked = true; evidenceRequired = true; stale = false; recover.hidden = true;
                // Legacy drafts require a declared past date and a freshly authorised current booking.
                if (!onSiteDraftVisit(draft())) field('snapshot').value = currentSnapshot;
                buttons(); dirty = true; await save();
                status.textContent = 'Photo et commentaire conservés. Vérifiez la date de l’ancien passage puis validez la preuve uniquement. Le nouveau RDV ne sera pas modifié.';
            });
        }
        field('photo').addEventListener('change', async () => {
            const current = ++generation;
            dirty = true;
            preparing = true; buttons(); notify('');
            try {
                const file = field('photo').files?.[0];
                const prepared = file ? await prepareOnSitePhoto(file, root, view) : null;
                if (current !== generation) return;
                photo = prepared; photoName = file ? 'Photo du signalement' : '';
                drawPhoto(); change(); await save();
            } catch (error) {
                if (current !== generation) return;
                photo = null; field('photo').value = ''; drawPhoto(); notify(error.message);
            } finally { if (current === generation) { preparing = false; buttons(); } }
        });
        discard.addEventListener('click', async () => {
            if (busy || preparing) return;
            if (!view.confirm('Effacer le brouillon et sa photo sur cet appareil ?')) return;
            generation++; dirty = false; stale = false; evidenceRequired = form.dataset.evidenceRequired === 'true'; view.clearTimeout(timer);
            await saving; await store.remove(key).catch(() => {});
            if (form.dataset.recovery === 'true') { view.location.assign('/tech/planning'); return; }
            form.reset(); field('photo').value = ''; photo = null; drawPhoto(); notify(''); buttons();
            if (recover) recover.hidden = true;
            field('comment').required = field('reason').value === 'other';
            status.textContent = 'Brouillon effacé. Rien n’a été envoyé.';
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy || preparing || submitted || stale) return;
            if (!photo) { notify('Prenez une photo sur place pour valider le signalement.'); field('photo').focus(); return; }
            if (!form.reportValidity()) return;
            busy = true; buttons(); notify('');
            await save();
            if (view.navigator?.onLine === false) {
                notify('Vous êtes hors connexion. Le signalement n’est pas envoyé. Réessayez manuellement une fois le réseau rétabli.');
                busy = false; buttons(); return;
            }
            const controller = new AbortController();
            const timeout = view.setTimeout(() => controller.abort(), 90000);
            try {
                const body = new (view.FormData || FormData)(form);
                ['reason', 'comment'].forEach(name => body.set(name, field(name).value));
                if (mode) {
                    body.set('evidence_only', historical() ? '1' : '0');
                    ['visit_date', 'visit_time'].forEach(name => body.set(name, historical() && !visitFields.hidden ? field(name).value : ''));
                }
                body.set('photo', photo, photo.type === 'image/jpeg' ? 'signalement.jpg' : 'signalement');
                const response = await view.fetch(form.action, { method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json' }, body, signal: controller.signal });
                const data = await readApiJson(response);
                if (response.status === 403) throw new Error('Le signalement n’est pas autorisé sur ce RDV dans son état actuel. Rechargez sa fiche depuis votre planning pour vérifier votre affectation et son état. Le brouillon reste sur cet appareil.');
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Signalement non enregistré.');
                const destination = new URL(data.url, view.location.href);
                if (destination.origin !== view.location.origin || !/^(?:\/tech\/planning(?:\/appointments\/\d+)?|\/tech\/mar\/appointments\/\d+\/on-site-report)$/.test(destination.pathname)
                    || destination.search || destination.hash) throw new Error('Confirmation reçue. Rechargez le rendez-vous pour vérifier le signalement.');
                submitted = true; view.clearTimeout(timer); await saving; await store.remove(key).catch(() => {});
                status.textContent = data.message; view.location.assign(destination.href);
            } catch (error) {
                notify(error.name === 'AbortError' || error.name === 'TypeError'
                    ? 'La confirmation n’a pas été reçue. Gardez cette page ouverte et réessayez avec ce même formulaire : aucun doublon ne sera créé.' : error.message);
            } finally { view.clearTimeout(timeout); busy = false; buttons(); }
        });
        buttons();
        const reveal = () => { if (details && view.location.hash === '#tech-on-site-report') details.open = true; };
        reveal(); view.addEventListener('hashchange', reveal);
        view.addEventListener('pagehide', () => { save(); if (previewUrl) view.URL.revokeObjectURL(previewUrl); });
        view.addEventListener('pageshow', () => { if (photo) drawPhoto(); });
        root.addEventListener('visibilitychange', () => { if (root.hidden) save(); });
        store.prune().then(() => store.get(key)).then(value => {
            if (dirty || submitted || !value) return;
            if (!onSiteDraftValid(value, actor, appointment)) return store.remove(key);
            const changed = value.snapshot !== field('snapshot').value;
            ['reason', 'comment'].forEach(name => { field(name).value = typeof value[name] === 'string' ? value[name] : ''; });
            field('request_id').value = value.requestId;
            photo = value.photo; photoName = value.photoName || 'Photo du brouillon'; drawPhoto();
            if (mode) {
                evidenceRequired ||= value.historicalRecovery === true;
                field('report_context').value = typeof value.reportContext === 'string' ? value.reportContext : '';
                field('context_signature').value = typeof value.contextSignature === 'string' ? value.contextSignature : '';
                field('visit_date').value = value.visitDate || ''; field('visit_time').value = value.visitTime || '';
                mode.checked = evidenceRequired || !!value.evidenceOnly;
                if (changed || form.dataset.recovery === 'true') {
                    stale = true; recover.hidden = false;
                    field('snapshot').value = value.snapshot;
                }
            } else if (changed) stale = true;
            field('comment').required = field('reason').value === 'other';
            if (details) details.open = true;
            buttons();
            status.textContent = stale
                ? 'Le rendez-vous a changé ou ce brouillon est repris depuis le planning. La photo et le commentaire sont conservés. Confirmez la reprise comme preuve de l’ancien passage ; aucun envoi automatique.'
                : 'Brouillon retrouvé sur cet appareil. Vérifiez-le puis validez : rien n’a été envoyé automatiquement.';
        }).catch(() => {
            status.textContent = 'Brouillons hors connexion indisponibles. Gardez cette page ouverte jusqu’à la confirmation.';
        });
    });
}

export function initOnSiteReportDrafts(root = document, view = window, store = createOnSiteDraftStore(view.indexedDB)) {
    root.querySelectorAll('[data-on-site-drafts]').forEach(section => {
        const actor = section.dataset.actor;
        const list = section.querySelector('[data-drafts-list]');
        const host = section.querySelector('[data-drafts-form]');
        store.prune().then(() => store.list(actor)).then(rows => {
            if (!rows.length) return;
            section.hidden = false;
            rows.forEach(row => {
                const item = root.createElement('li'), button = root.createElement('button');
                button.type = 'button'; button.className = 'gc-btn-secondary'; button.textContent = row.label;
                item.append(button); list.append(item);
                button.addEventListener('click', async () => {
                    // One mounted form avoids duplicate listeners or concurrent writes to the same draft.
                    if (host.childElementCount) return;
                    const draft = await store.get(row.key).catch(() => null);
                    if (!onSiteDraftValid(draft, actor, row.appointment) || !/^\d+$/.test(row.appointment)) return;
                    if (!onSiteDraftVisit(draft)) {
                        const link = root.createElement('a');
                        link.href = `${section.dataset.appointmentBase}/${row.appointment}#tech-on-site-report`;
                        link.textContent = 'Ancien brouillon : ouvrir la fiche pour confirmer la date. Si le RDV a été réattribué, contactez le planning ; votre photo reste conservée sur cet appareil.';
                        host.append(link); return;
                    }
                    const content = section.querySelector('template').content.cloneNode(true);
                    const form = content.querySelector('form');
                    form.dataset.appointment = row.appointment;
                    form.action = `${section.dataset.appointmentBase}/${row.appointment}/on-site-report`;
                    form.elements.namedItem('snapshot').value = draft.snapshot;
                    host.append(content); initOnSiteReports(host, view, store);
                    list.querySelectorAll('button').forEach(control => { control.disabled = true; });
                    host.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
                });
            });
        }).catch(() => {});
    });
}
