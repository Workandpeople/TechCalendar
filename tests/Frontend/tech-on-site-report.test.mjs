import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createOnSiteDraftStore, initOnSiteReports, onSiteDraftValid, onSiteDraftVisit, prepareOnSitePhoto } from '../../resources/js/tech-on-site-report.js';

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
    if (!options.mar) {
        Object.assign(fields, Object.fromEntries(['evidence_only', 'report_context', 'context_signature', 'visit_date', 'visit_time'].map(key => [key, node('')])));
        fields.evidence_only.checked = false;
    }
    const targets = Object.fromEntries(['submit', 'discard', 'feedback', 'draft-status', 'preview', 'recover', 'visit-label', 'visit-fields', 'current-intro'].map(key => [key, node()]));
    const image = node(), caption = node();
    targets.preview.querySelector = selector => selector === 'img' ? image : caption;
    const details = { open: false };
    delete fields.audience;
    const form = node(); form.dataset = { actor: '34', appointment: options.mar ? 'mar-91' : '91' }; form.action = 'https://cofrac.test/tech/planning/appointments/91/on-site-report';
    form.closest = () => details; form.elements = { namedItem: name => fields[name] };
    if (options.recovery) { form.dataset.recovery = 'true'; form.dataset.evidenceRequired = 'true'; }
    form.querySelector = selector => targets[selector.slice('[data-report-'.length, -1)];
    form.reportValidity = () => options.valid !== false;
    form.reset = () => { fields.reason.value = ''; fields.comment.value = ''; };
    const root = node(); root.querySelectorAll = selector => selector === '[data-on-site-report]' ? [form] : [];
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

test('isolates MAR drafts from native appointments and accepts only its safe local confirmation page', async () => {
    const ui = fixture(undefined, { mar: true }); await flush(); await ui.select();
    assert.equal(ui.values.get('34:mar-91').appointment, 'mar-91');
    assert.equal(ui.values.has('34:91'), false);
    ui.setResponse(() => reply({ message: 'Signalement conservé', url: '/tech/mar/appointments/91/on-site-report' }));
    await ui.submitForm();
    assert.equal(ui.view.location.assigned, 'https://cofrac.test/tech/mar/appointments/91/on-site-report');
    assert.equal(ui.values.size, 0);
    assert.equal(ui.requests[0].init.body.has('audience'), false);
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
    assert.equal(stale.fields.comment.value, 'Portail fermé'); assert.equal(stale.preview.hidden, false);
    assert.equal(stale.submit.disabled, true); await stale.submitForm(); assert.equal(stale.requests.length, 0);
    await stale.discard.emit('click'); assert.equal(stale.values.size, 0); assert.equal(stale.submit.disabled, false);
});

const signedDraft = extra => draft({ reportContext: JSON.stringify({ version: 1, issued_at: Math.floor(Date.now() / 1000),
    booking: { appointment_id: 91, technician_id: 34, starts_at: '2026-11-16 10:00:00', first_name: 'Test', last_name: 'Fictif' } }),
    contextSignature: 'c'.repeat(64), ...extra });

test('keeps a signed old visit and photo after rescheduling and requires explicit historical confirmation', async () => {
    const saved = signedDraft({ snapshot: 'b'.repeat(64) });
    const ui = fixture(saved); await flush();
    assert.equal(ui.submit.disabled, true); assert.equal(ui.recover.hidden, false);
    assert.equal(ui.preview.hidden, false); assert.equal(ui.fields.comment.value, saved.comment);
    await ui.recover.emit('click');
    assert.equal(ui.fields.evidence_only.checked, true); assert.equal(ui['visit-fields'].hidden, true);
    assert.match(ui['visit-label'].textContent, /16\/11\/2026 à 10:00/);
    assert.equal(ui.requests.length, 0);
    ui.setResponse(() => reply({ message: 'Preuve enregistrée', url: '/tech/planning' }));
    await ui.submitForm();
    const body = ui.requests[0].init.body;
    assert.equal(body.get('evidence_only'), '1'); assert.equal(body.get('report_context'), saved.reportContext);
    assert.equal(body.get('context_signature'), saved.contextSignature); assert.equal(body.get('snapshot'), saved.snapshot);
    assert.equal(body.get('request_id'), saved.requestId); assert.equal(body.get('photo'), saved.photo);
    assert.equal(body.get('visit_date'), ''); assert.equal(ui.view.location.assigned, 'https://cofrac.test/tech/planning');
});

test('recovers a legacy draft only as a declared past visit with a fresh current snapshot', async () => {
    const ui = fixture(draft({ snapshot: 'b'.repeat(64) })); await flush();
    await ui.recover.emit('click');
    assert.equal(ui.fields.evidence_only.checked, true); assert.equal(ui['visit-fields'].hidden, false);
    assert.equal(ui.fields.evidence_only.disabled, true);
    ui.fields.evidence_only.checked = false; await ui.fields.evidence_only.emit('change');
    assert.equal(ui.fields.evidence_only.checked, true);
    assert.equal(ui.fields.visit_date.required, true); assert.equal(ui.fields.visit_time.required, true);
    ui.fields.visit_date.value = '2026-11-16'; ui.fields.visit_time.value = '10:05';
    await ui.submitForm();
    const body = ui.requests[0].init.body;
    assert.equal(body.get('snapshot'), 'a'.repeat(64)); assert.equal(body.get('report_context'), '');
    assert.equal(body.get('visit_date'), '2026-11-16'); assert.equal(body.get('visit_time'), '10:05');
    assert.equal(body.get('evidence_only'), '1');
});

test('keeps historical recovery locked after another reload of the same draft', async () => {
    const ui = fixture(draft({ evidenceOnly: true, historicalRecovery: true, visitDate: '2026-11-16', visitTime: '10:05' })); await flush();
    assert.equal(ui.fields.evidence_only.checked, true); assert.equal(ui.fields.evidence_only.disabled, true);
    assert.equal(ui.fields.visit_date.value, '2026-11-16');
});

test('forces evidence only for a signed draft recovered from planning without needing the new appointment page', async () => {
    const ui = fixture(signedDraft(), { recovery: true }); await flush();
    assert.equal(ui.submit.disabled, true); assert.equal(ui.fields.evidence_only.disabled, true);
    await ui.recover.emit('click');
    assert.equal(ui.fields.evidence_only.checked, true); assert.equal(ui.submit.disabled, false);
    assert.equal(ui.requests.length, 0);
    await ui.submitForm(); assert.equal(ui.requests[0].init.body.get('evidence_only'), '1');
});

test('does not claim a forged expired or foreign context to be a signed draft visit', () => {
    assert.equal(onSiteDraftVisit(signedDraft()).appointment_id, 91);
    for (const changes of [{ contextSignature: '' }, { reportContext: 'broken' }, { actor: '88' }, { appointment: '90' },
        { reportContext: JSON.stringify({ version: 1, issued_at: 1, booking: {} }) }]) {
        assert.equal(onSiteDraftVisit(signedDraft(changes)), null);
    }
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

test('explains an authorization refusal in French and preserves the report and photo without automatic replay', async () => {
    const ui = fixture(); await flush(); await ui.select();
    const requestId = ui.fields.request_id.value;
    ui.setResponse(() => reply({ message: 'This action is unauthorized.' }, 403));
    await ui.submitForm();
    assert.match(ui.feedback.textContent, /Rechargez sa fiche depuis votre planning/);
    assert.doesNotMatch(ui.feedback.textContent, /unauthorized/);
    assert.equal(ui.values.get('34:91').requestId, requestId);
    assert.equal(ui.values.get('34:91').photo.type, 'image/jpeg');
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.submit.disabled, false);
    assert.equal(ui.view.location.assigned, undefined);
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

test('lists only bounded native draft metadata for the signed-in account, not every stored photograph', async () => {
    const values = new Map();
    for (let id = 1; id <= 70; id++) {
        values.set(`34:${id}`, draft({ key: `34:${id}`, appointment: String(id) }));
        values.set(`35:${id}`, draft({ key: `35:${id}`, actor: '35', appointment: String(id) }));
    }
    values.set('34:mar-91', draft({ key: '34:mar-91', appointment: 'mar-91' }));
    const reads = [], previousRange = globalThis.IDBKeyRange;
    globalThis.IDBKeyRange = { bound: (lower, upper) => ({ lower, upper }) };
    const db = { transaction() {
        const transaction = {};
        const store = {
            openKeyCursor(range) {
                const request = {}, keys = [...values.keys()].sort().filter(key => key >= range.lower && key <= range.upper);
                let index = 0;
                const step = () => setImmediate(() => {
                    let advanced = false;
                    request.result = index < keys.length ? { key: keys[index++], continue() { advanced = true; step(); } } : null;
                    request.onsuccess();
                    setImmediate(() => { if (!advanced) transaction.oncomplete(); });
                });
                step(); return request;
            },
            get(key) { reads.push(key); const request = {}; setImmediate(() => { request.result = values.get(key); request.onsuccess(); }); return request; },
        };
        transaction.objectStore = () => store; return transaction;
    } };
    const indexedDB = { open() { const request = {}; setImmediate(() => { request.result = db; request.onsuccess(); }); return request; } };
    try {
        const rows = await createOnSiteDraftStore(indexedDB).list('34');
        assert.equal(rows.length, 30); assert.equal(reads.length, 30);
        assert.ok(reads.every(key => /^34:\d+$/.test(key)));
        assert.ok(rows.every(row => !('photo' in row) && !('comment' in row) && /^34:\d+$/.test(row.key)));
        assert.deepEqual(await createOnSiteDraftStore(indexedDB).list('invalid'), []);
    } finally { globalThis.IDBKeyRange = previousRange; }
});
