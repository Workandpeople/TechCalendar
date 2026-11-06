import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createOnSiteDraftStore, initOnSiteReports, onSiteDraftValid, prepareOnSitePhoto } from '../../resources/js/tech-on-site-report.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
const photo = () => new Blob(['synthetic jpeg'], { type: 'image/jpeg' });
const reply = (data, status = 200) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: async () => data });
function node(value = '') {
    return { value, required: false, disabled: false, hidden: false, handlers: {},
        addEventListener(type, handler) { this.handlers[type] = handler; },
        async emit(type, extra = {}) { return this.handlers[type]?.({ preventDefault() {}, ...extra }); },
        focus() { this.focused = true; }, removeAttribute(name) { delete this[name]; },
    };
}
function fixture(initial, options = {}) {
    const fields = Object.fromEntries(Object.entries({ request_id: '533e775e-c494-4897-843d-4a50077a750b', snapshot: 'a'.repeat(64),
        reason: 'absent', comment: '', audience: 'client', photo: '', _token: 'csrf-form-token' }).map(([key, value]) => [key, node(value)]));
    const targets = Object.fromEntries(['submit', 'discard', 'feedback', 'draft-status', 'preview'].map(key => [key, node()]));
    const image = node(), caption = node();
    targets.preview.querySelector = selector => selector === 'img' ? image : caption;
    const details = { open: false };
    const form = node(); form.dataset = { actor: '34', appointment: '91' }; form.action = 'https://cofrac.test/tech/planning/appointments/91/on-site-report';
    form.closest = () => details; form.elements = { namedItem: name => fields[name] };
    form.querySelector = selector => targets[selector.slice('[data-report-'.length, -1)];
    form.reportValidity = () => options.valid !== false;
    form.reset = () => { fields.reason.value = ''; fields.comment.value = ''; fields.audience.value = 'client'; };
    const root = node(); root.querySelectorAll = () => [form];
    const timers = new Map(), requests = [], writes = [], values = new Map(initial ? [['34:91', initial]] : []);
    let next = 1, response = () => reply({ message: 'Signalement enregistré', url: '/tech/planning/appointments/91' });
    const storage = { async prune() {}, async get(key) { return values.get(key); },
        async put(value) { if (options.storageFailure) throw Error('quota'); values.set(value.key, value); writes.push(value); },
        async remove(key) { values.delete(key); } };
    class BrowserURL extends URL { static createObjectURL() { return 'blob:synthetic'; } static revokeObjectURL() {} }
    const view = { URL: BrowserURL, navigator: { onLine: options.online !== false }, location: {
        href: 'https://cofrac.test/tech/planning/appointments/91', origin: 'https://cofrac.test', hash: '#tech-on-site-report',
        assign(url) { this.assigned = url; },
    }, FormData: class extends Map { constructor() { super(Object.entries(fields).map(([key, field]) => [key, field.value])); } },
    confirm: () => true, handlers: {}, addEventListener(type, handler) { this.handlers[type] = handler; },
    setTimeout(handler, delay) { const id = next++; timers.set(id, { handler, delay }); return id; }, clearTimeout(id) { timers.delete(id); },
    async fetch(url, init) { requests.push({ url, init }); return response(); } };
    initOnSiteReports(root, view, storage);
    return { fields, ...targets, form, root, view, details, timers, requests, values, writes, image, caption,
        setResponse(handler) { response = handler; },
        async select(file = photo()) { fields.photo.files = file ? [file] : []; await fields.photo.emit('change'); },
        async submitForm() { await form.emit('submit'); },
    };
}
const draft = extra => ({ version: 1, key: '34:91', actor: '34', appointment: '91', savedAt: Date.now(), snapshot: 'a'.repeat(64),
    requestId: '533e775e-c494-4897-843d-4a50077a750b', reason: 'access', comment: 'Portail fermé', audience: 'client', photo: photo(), ...extra });

test('validates draft ownership expiry size and format before restoring anything', () => {
    assert.equal(onSiteDraftValid(draft(), '34', '91'), true);
    for (const extra of [{ version: 2 }, { actor: '12' }, { appointment: '92' }, { savedAt: 0 }, { savedAt: Date.now() + 10000 },
        { photo: { size: 10 * 1024 * 1024 + 1 } }]) assert.equal(onSiteDraftValid(draft(extra), '34', '91'), false);
});

test('does not allow a report without a photo and does not make background POST requests', async () => {
    const ui = fixture(); await flush();
    assert.equal(ui.details.open, true);
    await ui.submitForm();
    assert.match(ui.feedback.textContent, /photo sur place/);
    assert.equal(ui.fields.photo.focused, true);
    assert.equal(ui.requests.length, 0);
});

test('keeps an offline draft and its photo under the account and appointment without automatic replay', async () => {
    const ui = fixture(undefined, { online: false }); await flush();
    await ui.select(); ui.fields.comment.value = 'Client absent'; await ui.fields.comment.emit('input');
    await ui.submitForm();
    const saved = ui.values.get('34:91');
    assert.equal(saved.comment, 'Client absent'); assert.equal(saved.photo.type, 'image/jpeg');
    assert.equal(saved.requestId, ui.fields.request_id.value);
    assert.match(ui.feedback.textContent, /hors connexion/);
    ui.view.navigator.onLine = true;
    assert.equal(ui.view.handlers.online, undefined);
    assert.equal(ui.requests.length, 0);
});

test('restores only the matching draft and never silently applies it to a modified appointment', async () => {
    const ui = fixture(draft()); await flush();
    assert.equal(ui.fields.comment.value, 'Portail fermé'); assert.equal(ui.fields.photo.required, false);
    assert.equal(ui.preview.hidden, false); assert.equal(ui.requests.length, 0);
    const stale = fixture(draft({ snapshot: 'b'.repeat(64) })); await flush();
    assert.match(stale['draft-status'].textContent, /rendez-vous a changé/);
    assert.equal(stale.submit.disabled, true); await stale.submitForm(); assert.equal(stale.requests.length, 0);
    await stale.discard.emit('click'); assert.equal(stale.values.size, 0); assert.equal(stale.submit.disabled, false);
});

test('submits the normal CSRF form token and one photo then deletes the successful draft', async () => {
    const ui = fixture(); await flush(); await ui.select(); await ui.submitForm();
    assert.equal(ui.requests.length, 1);
    const request = ui.requests[0];
    assert.equal(request.init.method, 'POST'); assert.equal(request.init.credentials, 'same-origin');
    assert.equal(request.init.body.get('_token'), 'csrf-form-token');
    assert.equal(request.init.body.get('request_id'), ui.fields.request_id.value);
    assert.equal(request.init.body.get('photo').type, 'image/jpeg');
    assert.equal(ui.view.location.assigned, 'https://cofrac.test/tech/planning/appointments/91');
    assert.equal(ui.values.size, 0); assert.equal(ui.submit.disabled, true);
    await ui.view.handlers.pagehide(); await flush(); assert.equal(ui.values.size, 0);
});

test('preserves the same idempotency key after a lost response and never retries on its own', async () => {
    const ui = fixture(); await flush(); await ui.select();
    ui.setResponse(() => { throw new TypeError('offline'); });
    await ui.submitForm();
    assert.match(ui.feedback.textContent, /confirmation n’a pas été reçue/);
    assert.equal(ui.requests.length, 1); assert.equal(ui.values.size, 1); assert.equal(ui.submit.disabled, false);
    ui.setResponse(() => reply({ message: 'Enregistré', url: '/tech/planning/appointments/91' })); await ui.submitForm();
    assert.equal(ui.requests.length, 2);
    assert.equal(ui.requests[0].init.body.get('request_id'), ui.requests[1].init.body.get('request_id'));
});

test('freezes the inputs and prevents double submission while the request is running', async () => {
    const ui = fixture(); await flush(); await ui.select();
    let finish;
    ui.setResponse(() => new Promise(resolve => { finish = resolve; }));
    const pending = ui.submitForm(); await flush();
    assert.equal(ui.submit.textContent, 'Enregistrement…'); assert.equal(ui.fields.reason.disabled, true);
    await ui.submitForm(); assert.equal(ui.requests.length, 1);
    finish(reply({ message: 'Enregistré', url: '/tech/planning/appointments/91' })); await pending;
    assert.equal(ui.values.size, 0);
});

test('shows server validation and session errors without deleting the local photo or following login', async () => {
    const ui = fixture(); await flush(); await ui.select();
    ui.setResponse(() => reply({ errors: { snapshot: ['Rendez-vous modifié.'] } }, 422));
    await ui.submitForm(); assert.equal(ui.feedback.textContent, 'Rendez-vous modifié.'); assert.equal(ui.values.size, 1);
    ui.setResponse(() => reply({}, 419)); await ui.submitForm();
    assert.match(ui.feedback.textContent, /session a expiré/); assert.equal(ui.values.size, 1); assert.equal(ui.view.location.assigned, undefined);
});

test('does not follow foreign confirmation links or remove a draft on an ambiguous response', async () => {
    const ui = fixture(); await flush(); await ui.select();
    ui.setResponse(() => reply({ url: 'https://foreign.test/tech/planning/appointments/91' }));
    await ui.submitForm(); assert.equal(ui.view.location.assigned, undefined); assert.equal(ui.values.size, 1);
    assert.match(ui.feedback.textContent, /Rechargez/);
});

test('explains unavailable local storage but can still send explicitly while online', async () => {
    const ui = fixture(undefined, { storageFailure: true }); await flush(); await ui.select();
    assert.match(ui['draft-status'].textContent, /ne peut pas être enregistré/);
    await ui.submitForm(); assert.equal(ui.requests.length, 1);
});

test('checks the photo type and size before creating or uploading a draft', async () => {
    await assert.rejects(prepareOnSitePhoto(new Blob(['svg'], { type: 'image/svg+xml' }), {}, {}), /JPEG/);
    await assert.rejects(prepareOnSitePhoto({ type: 'image/jpeg', size: 10 * 1024 * 1024 + 1 }, {}, {}), /10 Mo/);
    const ui = fixture(); await flush(); await ui.select(new Blob(['svg'], { type: 'image/svg+xml' }));
    assert.equal(ui.preview.hidden, true); assert.equal(ui.requests.length, 0);
});

test('shrinks large images and releases the decoded bitmap before drafting', async () => {
    let closed = false, canvas;
    const root = { createElement() { canvas = { getContext: () => ({ fillRect() {}, drawImage() {} }), toBlob: callback => callback(photo()) }; return canvas; } };
    const view = { createImageBitmap: async () => ({ width: 4000, height: 3000, close() { closed = true; } }) };
    const prepared = await prepareOnSitePhoto(photo(), root, view);
    assert.equal(canvas.width, 1600); assert.equal(canvas.height, 1200); assert.equal(prepared.type, 'image/jpeg'); assert.equal(closed, true);
});

test('handles missing IndexedDB without sending any data elsewhere', async () => {
    const store = createOnSiteDraftStore(undefined);
    await assert.rejects(store.get('34:91'), /indisponibles/);
    await assert.rejects(store.put(draft()), /indisponibles/);
});
