import { readApiJson } from './api-response.js';

const LIFETIME = 24 * 60 * 60 * 1000;
const MAX_PHOTO = 10 * 1024 * 1024;

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
        const actor = form.dataset.actor, appointment = form.dataset.appointment;
        const key = `${actor}:${appointment}`;
        let photo = null, photoName = '', previewUrl = null, saving = Promise.resolve(), timer;
        let busy = false, submitted = false, dirty = false, generation = 0, stale = false, preparing = false;
        const notify = message => { feedback.textContent = message; feedback.hidden = !message; };
        const buttons = () => {
            submit.disabled = busy || preparing || stale || submitted; discard.disabled = busy || preparing || submitted;
            submit.textContent = busy ? 'Enregistrement…' : 'Valider le signalement';
            form.setAttribute?.('aria-busy', String(busy));
            ['reason', 'comment', 'photo'].forEach(name => { field(name).disabled = busy || submitted; });
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
            reason: field('reason').value, comment: field('comment').value, photo, photoName });
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
            generation++; dirty = false; stale = false; view.clearTimeout(timer);
            await saving; await store.remove(key).catch(() => {});
            form.reset(); field('photo').value = ''; photo = null; drawPhoto(); notify(''); buttons();
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
                body.set('photo', photo, photo.type === 'image/jpeg' ? 'signalement.jpg' : 'signalement');
                const response = await view.fetch(form.action, { method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json' }, body, signal: controller.signal });
                const data = await readApiJson(response);
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Signalement non enregistré.');
                const destination = new URL(data.url, view.location.href);
                if (destination.origin !== view.location.origin || !/^(?:\/tech\/planning\/appointments\/\d+|\/tech\/mar\/appointments\/\d+\/on-site-report)$/.test(destination.pathname)
                    || destination.search || destination.hash) throw new Error('Confirmation reçue. Rechargez le rendez-vous pour vérifier le signalement.');
                submitted = true; view.clearTimeout(timer); await saving; await store.remove(key).catch(() => {});
                status.textContent = data.message; view.location.assign(destination.href);
            } catch (error) {
                notify(error.name === 'AbortError' || error.name === 'TypeError'
                    ? 'La confirmation n’a pas été reçue. Gardez cette page ouverte et réessayez avec ce même formulaire : aucun doublon ne sera créé.' : error.message);
            } finally { view.clearTimeout(timeout); busy = false; buttons(); }
        });
        const reveal = () => { if (view.location.hash === '#tech-on-site-report') details.open = true; };
        reveal(); view.addEventListener('hashchange', reveal);
        view.addEventListener('pagehide', () => { save(); if (previewUrl) view.URL.revokeObjectURL(previewUrl); });
        view.addEventListener('pageshow', () => { if (photo) drawPhoto(); });
        root.addEventListener('visibilitychange', () => { if (root.hidden) save(); });
        store.prune().then(() => store.get(key)).then(value => {
            if (dirty || submitted || !value) return;
            if (!onSiteDraftValid(value, actor, appointment)) return store.remove(key);
            if (value.snapshot !== field('snapshot').value) {
                stale = true; buttons(); details.open = true;
                status.textContent = 'Le rendez-vous a changé depuis ce brouillon. Il n’est pas repris automatiquement. Effacez le brouillon avant de signaler le nouveau rendez-vous.';
                return;
            }
            ['reason', 'comment'].forEach(name => { field(name).value = typeof value[name] === 'string' ? value[name] : ''; });
            field('request_id').value = value.requestId;
            photo = value.photo; photoName = value.photoName || 'Photo du brouillon'; drawPhoto();
            field('comment').required = field('reason').value === 'other';
            details.open = true; status.textContent = 'Brouillon retrouvé sur cet appareil. Vérifiez-le puis validez : rien n’a été envoyé automatiquement.';
        }).catch(() => {
            status.textContent = 'Brouillons hors connexion indisponibles. Gardez cette page ouverte jusqu’à la confirmation.';
        });
    });
}
