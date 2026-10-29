import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/planner/partials/absence-block-modal.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1];

function element() {
    return {
        children: [], listeners: {}, hidden: false, disabled: false, checked: false, value: '', focused: false,
        addEventListener(name, callback) { this.listeners[name] = callback; },
        append(...children) { this.children.push(...children); },
        replaceChildren() { this.children = []; },
        focus() { this.focused = true; },
        reportValidity() { return true; },
        emit(name) { return this.listeners[name]?.({ preventDefault() {} }); },
    };
}

function fixture() {
    const names = ['form', 'submit', 'errors', 'fields', 'technicians', 'count', 'search', 'open', 'close', 'reload', 'select-visible', 'clear', 'status'];
    const nodes = Object.fromEntries(names.map(name => [name, element()]));
    const dialog = element();
    dialog.open = false;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.emit('close'); };
    const calls = [], events = [];
    let reloads = 0;
    const ui = {
        nodes, dialog, calls, events,
        technicians: [{ id: 10, label: '<img onerror=alert(1)>' }, { id: 20, label: 'Camille TEST (75)' }],
        response: { ok: true, status: 201, json: async () => ({ count: 2, absences: [{ id: 1 }, { id: 2 }], message: 'Deux blocages créés.' }) },
        get reloads() { return reloads; },
    };
    const root = {
        dataset: { techniciansUrl: '/planner/absences/technicians', storeUrl: '/planner/absences' },
        querySelector(selector) { return selector === 'dialog' ? dialog : nodes[selector.match(/data-absence-(.+)\]/)[1]]; },
    };
    runInNewContext(script, {
        document: { currentScript: { closest: () => root }, createElement: element },
        AbortController,
        FormData: class { get(name) { return { date: '2026-11-02', period: 'morning', reason: 'Formation', _token: 'csrf-token' }[name]; } },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        window: { location: { reload: () => reloads++ }, dispatchEvent: event => events.push(event) },
        fetch: async (url, options) => {
            calls.push({ url, options });
            if (options.method === 'POST') return typeof ui.response === 'function' ? ui.response() : ui.response;
            return { ok: true, json: async () => ({ technicians: ui.technicians, max_technicians: 200 }) };
        },
    });
    ui.open = async () => {
        nodes.open.emit('click');
        await new Promise(resolve => setImmediate(resolve));
    };
    return ui;
}

test('loads a fresh active technician list, safely renders names and preserves hidden selections', async () => {
    const ui = fixture();
    await ui.open();
    assert.equal(ui.calls[0].options.cache, 'no-store');
    const first = ui.nodes.technicians.children[0];
    assert.equal(first.children[1].textContent, '<img onerror=alert(1)>');
    assert.equal(first.children[1].innerHTML, undefined);
    ui.nodes['select-visible'].emit('click');
    ui.nodes.search.value = 'camille';
    ui.nodes.search.emit('input');
    assert.equal(first.hidden, true);
    assert.equal(first.children[0].checked, true);
    ui.nodes.clear.emit('click');
    ui.nodes['select-visible'].emit('click');
    assert.equal(first.children[0].checked, false);
    assert.match(ui.nodes.count.textContent, /^1 sélectionné/);
});

test('sends one batch with CSRF, emits the refresh event and never reloads automatically', async () => {
    const ui = fixture();
    await ui.open();
    ui.nodes['select-visible'].emit('click');
    await ui.nodes.form.emit('submit');
    const request = ui.calls.find(({ options }) => options.method === 'POST');
    assert.deepEqual(JSON.parse(request.options.body), { technician_ids: [10, 20], date: '2026-11-02', period: 'morning', reason: 'Formation' });
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(ui.events[0].type, 'planning:absences-created');
    assert.equal(ui.events[0].detail.count, 2);
    assert.equal(ui.reloads, 0);
    assert.equal(ui.nodes.fields.disabled, true);
    assert.equal(ui.nodes.submit.hidden, true);
    assert.equal(ui.nodes.reload.hidden, false);
    await ui.nodes.form.emit('submit');
    assert.equal(ui.calls.filter(({ options }) => options.method === 'POST').length, 1);
    ui.nodes.close.emit('click');
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.nodes.open.focused, true);
});

test('prevents concurrent submits and closing a pending batch', async () => {
    const ui = fixture();
    let finish;
    ui.response = () => new Promise(resolve => { finish = resolve; });
    await ui.open();
    ui.nodes['select-visible'].emit('click');
    const pending = ui.nodes.form.emit('submit');
    await ui.nodes.form.emit('submit');
    ui.nodes.close.emit('click');
    assert.equal(ui.dialog.open, true);
    let prevented = false;
    ui.dialog.listeners.cancel({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(ui.calls.filter(({ options }) => options.method === 'POST').length, 1);
    finish({ ok: true, status: 201, json: async () => ({ count: 2, absences: [{}, {}], message: 'Créés' }) });
    await pending;
    assert.equal(ui.nodes.close.disabled, false);
});

test('shows conflict messages as text without losing selection or emitting success', async () => {
    const ui = fixture();
    ui.response = { ok: false, status: 422, json: async () => ({ message: 'Aucun blocage créé.', errors: { absence_block: ['Camille : RDV #12.', '<script>alert(1)</script>'] } }) };
    await ui.open();
    ui.nodes['select-visible'].emit('click');
    await ui.nodes.form.emit('submit');
    const messages = ui.nodes.errors.children[0].children;
    assert.deepEqual(messages.map(item => item.textContent), ['Aucun blocage créé.', 'Camille : RDV #12.', '<script>alert(1)</script>']);
    assert.equal(messages[2].innerHTML, undefined);
    assert.equal(ui.nodes.errors.focused, true);
    assert.equal(ui.nodes.fields.disabled, false);
    assert.equal(ui.nodes.submit.disabled, false);
    assert.equal(ui.events.length, 0);
    assert.match(ui.nodes.count.textContent, /^2 sélectionné/);
});

test('does not treat expired sessions or uncertain network results as success', async () => {
    for (const response of [
        { ok: true, status: 200, json: async () => { throw new Error('HTML login page'); } },
        () => { throw new Error('Connection lost'); },
    ]) {
        const ui = fixture();
        ui.response = response;
        await ui.open();
        ui.nodes['select-visible'].emit('click');
        await ui.nodes.form.emit('submit');
        assert.equal(ui.events.length, 0);
        assert.match(ui.nodes.errors.children[0].children[0].textContent, /Résultat non confirmé/);
        assert.equal(ui.nodes.submit.disabled, false);
    }
});

test('does not submit an empty or oversized selection', async () => {
    const ui = fixture();
    ui.technicians = Array.from({ length: 201 }, (_, id) => ({ id: id + 1, label: `Technicien ${id}` }));
    await ui.open();
    await ui.nodes.form.emit('submit');
    ui.nodes['select-visible'].emit('click');
    await ui.nodes.form.emit('submit');
    assert.equal(ui.nodes.submit.disabled, true);
    assert.equal(ui.calls.filter(({ options }) => options.method === 'POST').length, 0);
});

test('aborts a stale options request on close and reloads active technicians on reopening', async () => {
    const ui = fixture();
    await ui.open();
    ui.nodes.close.emit('click');
    assert.equal(ui.calls[0].options.signal.aborted, true);
    ui.technicians = [];
    await ui.open();
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.nodes.submit.disabled, true);
    assert.match(ui.nodes.technicians.textContent, /Aucun technicien actif/);
});
