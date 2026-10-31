import test from 'node:test';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { initMymarActions, validateActionResult } from '../../resources/js/mymar-actions.js';

function fixture({ fetch, storage = new Map(), override = {} } = {}) {
    const nodes = [], calls = [], events = [];
    const document = { createElement(tag) {
        const value = { tag, children: [], handlers: {}, style: {}, value: '', disabled: false, textContent: '',
            classList: { add() {} }, setAttribute() {}, reportValidity: () => true,
            append(...elements) { this.children.push(...elements); }, addEventListener(key, fn) { this.handlers[key] = fn; },
            showModal() { this.open = true; }, close() { this.open = false; }, remove() { this.removed = true; },
            set innerHTML(_) { assert.fail('Unsafe external HTML'); },
        }; nodes.push(value); return value;
    } };
    document.body = document.createElement('body');
    const resume = document.createElement('button');
    const panel = { ownerDocument: document, dataset: { placeUrl: '/mar/place', problemUrl: '/mar/problem',
        statusUrl: '/mar/status/__REQUEST__', csrf: 'csrf', userId: '1' }, querySelector: () => resume,
        dispatchEvent(event) { events.push(event); } };
    const browser = { location: { origin: 'https://cofrac.test' }, crypto: { randomUUID }, AbortController,
        sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
        Event, CustomEvent, dispatchEvent: event => events.push(event), setTimeout, clearTimeout,
        TechCalendarDateTime: { serializeAppointmentInput: input => `${input}:00+01:00` },
        fetch: async (url, options) => { calls.push([url, options]); return fetch(url, options); }, ...override };
    return { root: { querySelector: () => panel }, browser, nodes, calls, panel, storage, events, resume };
}
const json = (body, status = 200) => ({ ok: status < 400, status, redirected: false,
    headers: new Headers({ 'Content-Type': 'application/json' }), json: async () => body });
const result = (id, state) => ({ request_id: id, state, confirmed: state === 'confirmed', terminal: ['confirmed', 'rejected'].includes(state), message: state });
const payload = { dossier_id: 12, expected_revision: 'a'.repeat(64), technician_id: 3, starts_at: '2026-11-05T10:00:00+01:00', duration_minutes: 60 };

test('never sends during initialization and posts once with CSRF and a persistent request identity', async () => {
    const ui = fixture({ fetch: async (_url, options) => json(result(JSON.parse(options.body).request_id, 'confirmed')) });
    const actions = initMymarActions(ui.root, ui.browser);
    assert.equal(ui.calls.length, 0);
    const response = await actions.place(payload);
    assert.equal(response.confirmed, true);
    assert.equal(ui.calls[0][1].headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(ui.calls[0][1].credentials, 'same-origin');
    assert.equal(ui.calls[0][1].method, 'POST');
    assert.deepEqual(JSON.parse(ui.storage.get('cofrac.mar.confirmations.1')), {});
});

test('a timeout does not retry POST; reopening or reloading only offers receipt verification', async () => {
    const ui = fixture({ fetch: async () => { throw new Error('Network lost'); } });
    const actions = initMymarActions(ui.root, ui.browser);
    assert.equal(await actions.place(payload), null);
    assert.equal(await actions.place(payload), null);
    assert.equal(ui.calls.length, 1);
    const record = JSON.parse(ui.storage.get('cofrac.mar.confirmations.1'))['12'];
    const reloaded = fixture({ storage: ui.storage, fetch: async (_url, options) => {
        assert.equal(options.method, 'GET'); return json(result(record.id, 'confirmed'));
    } });
    const restored = initMymarActions(reloaded.root, reloaded.browser);
    restored.resume();
    const verify = reloaded.nodes.findLast(node => node.textContent === 'Vérifier la confirmation');
    await verify.handlers.click();
    assert.equal(reloaded.calls.length, 1);
    assert.match(reloaded.calls[0][0], new RegExp(`/mar/status/${record.id}$`));
    assert.deepEqual(JSON.parse(reloaded.storage.get('cofrac.mar.confirmations.1')), {});
    assert.equal(verify.hidden, true);
});

test('definitive validation errors do not leave a misleading in-flight command', async () => {
    const ui = fixture({ fetch: async () => json({ errors: { starts_at: ['Créneau indisponible'] } }, 422) });
    await assert.rejects(initMymarActions(ui.root, ui.browser).place(payload), /Créneau indisponible/);
    assert.deepEqual(JSON.parse(ui.storage.get('cofrac.mar.confirmations.1')), {});
});

test('malformed success and rejected HTML are never displayed as confirmed delivery', async () => {
    for (const fetch of [async () => json({ confirmed: true }), async () => ({ ...json({}), headers: new Headers({ 'Content-Type': 'text/html' }) })]) {
        const ui = fixture({ fetch });
        assert.equal(await initMymarActions(ui.root, ui.browser).place(payload), null);
        assert.equal(Object.keys(JSON.parse(ui.storage.get('cofrac.mar.confirmations.1'))).length, 1);
    }
    assert.throws(() => validateActionResult({ ...result('id', 'queued'), confirmed: true }, 'id'));
});

test('choosing a MAR visit requires an explicit hour duration before starting the existing analysis', () => {
    const ui = fixture();
    initMymarActions(ui.root, ui.browser).choose({ dossier_id: 12, first_name: 'Test', last_name: '<script>not HTML</script>' });
    const select = ui.nodes.find(node => node.tag === 'select');
    const search = ui.nodes.find(node => node.textContent === 'Chercher un créneau');
    assert.equal(search.disabled, true);
    assert.equal(select.children[0].value, '');
    select.value = '120'; select.handlers.change(); search.handlers.click();
    assert.equal(ui.calls.length, 0);
    assert.equal(ui.events.at(-1).type, 'cofrac:mymar:select');
    assert.deepEqual(ui.events.at(-1).detail, { dossier_id: 12, duration_minutes: 120 });
});

test('does not initialize action endpoints pointing to a different origin', () => {
    const ui = fixture(); ui.panel.dataset.placeUrl = 'https://evil.test/place';
    assert.equal(initMymarActions(ui.root, ui.browser), null);
    assert.equal(ui.calls.length, 0);
});
