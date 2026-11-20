import { test } from 'node:test';
import assert from 'node:assert/strict';
import { incidentStatusUrl, initIncidentStatuses } from '../../resources/js/incident-status.js';

test('status decision addresses remain same-origin and on the bounded incident routes', () => {
    const base = 'https://cofrac.example/planner/incidents';
    for (const path of ['/planner/incidents/12/status', '/planner/incidents/12/status/resume'])
        assert.equal(incidentStatusUrl(path, base), `https://cofrac.example${path}`);
    for (const path of ['https://evil.test/planner/incidents/1/status', '//evil.test/x', '/planner/incidents/0/status',
        '/planner/incidents/1/status?token=x', '/planner/incidents/1/status#x', '/login'])
        assert.throws(() => incidentStatusUrl(path, base));
});

function fixture() {
    const element = () => ({ hidden: false, disabled: false, value: '', handlers: {}, addEventListener(k, fn) { this.handlers[k] = fn; } });
    const fields = Object.fromEntries(['context', 'target_status', 'planning_status', 'note'].map(k => [k, element()]));
    fields.target_status.replaceChildren = function (...options) { this.options = options; };
    fields.target_status.add = function (option) { this.options.push(option); };
    const form = element(), button = element(), message = element(), current = element(), reload = element(), resume = element();
    form.elements = { namedItem: key => fields[key] }; form.action = '/planner/incidents/12/status'; form.reportValidity = () => true;
    form.querySelector = () => button;
    const panel = element(); panel.dataset = { contextUrl: form.action, resumeUrl: `${form.action}/resume` };
    panel.querySelector = selector => ({ form, '[data-status-message]': message, '[data-status-current]': current,
        '[data-status-reload]': reload, '[data-status-resume]': resume })[selector];
    return { panel, fields, form, message, current, reload, resume, root: { querySelectorAll: () => [panel] } };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
async function browser(run) {
    const saved = Object.fromEntries(['document', 'window', 'fetch', 'Option', 'FormData'].map(k => [k, globalThis[k]]));
    const calls = [], environment = { response: { context: 'signed', current_label: 'État actuel', statuses: [{ value: 'pending', label: '<script>literal</script>' }] }, reloads: 0 };
    globalThis.document = { querySelector: () => ({ content: 'csrf-synthetic' }) };
    globalThis.window = { location: { href: 'https://cofrac.example/planner/incidents', reload() { environment.reloads++; } } };
    globalThis.Option = class { constructor(label, value) { this.text = label; this.value = value; } };
    globalThis.FormData = class { constructor(form) { this.form = form; } };
    globalThis.fetch = async (url, options) => { calls.push({ url, options }); return { ok: true, status: 200,
        headers: { get: () => 'application/json' }, json: async () => environment.response }; };
    try { await run(calls, environment); } finally { Object.assign(globalThis, saved); }
}

test('status forms load lazily once, use safe text and do not write during consultation', async () => browser(async (calls) => {
    const ui = fixture(); initIncidentStatuses(ui.root); initIncidentStatuses(ui.root);
    assert.equal(calls.length, 0);
    ui.panel.open = true; ui.panel.handlers.toggle(); await flush();
    assert.equal(calls.length, 1); assert.equal(calls[0].options.method, undefined);
    assert.equal(ui.fields.target_status.options[1].text, '<script>literal</script>');
    assert.equal(ui.fields.target_status.value, ''); assert.equal(ui.fields.context.value, 'signed');
    ui.panel.handlers.toggle(); await flush(); assert.equal(calls.length, 1);
}));

test('double clicks produce a single decision and uncertain results only offer reconciliation', async () => browser(async (calls, env) => {
    const ui = fixture(); initIncidentStatuses(ui.root); ui.panel.open = true; ui.panel.handlers.toggle(); await flush();
    env.response = { state: 'uncertain', message: 'Confirmation en attente' };
    ui.form.handlers.submit({ preventDefault() {} }); ui.form.handlers.submit({ preventDefault() {} }); await flush();
    assert.equal(calls.length, 2); assert.equal(calls[1].options.method, 'POST');
    assert.equal(ui.form.hidden, true); assert.equal(ui.resume.hidden, false); assert.equal(env.reloads, 0);
    env.response = { state: 'confirmed', message: 'Confirmé' };
    ui.resume.handlers.click(); await flush();
    assert.equal(calls[2].url, 'https://cofrac.example/planner/incidents/12/status/resume');
    assert.equal(calls[2].options.body, undefined); assert.equal(env.reloads, 1);
}));

test('session expiration keeps the form hidden without exposing a login page or posting', async () => browser(async (calls) => {
    globalThis.fetch = async () => ({ ok: false, status: 419 });
    const ui = fixture(); initIncidentStatuses(ui.root); ui.panel.open = true; ui.panel.handlers.toggle(); await flush();
    assert.match(ui.message.textContent, /Session expirée/); assert.equal(ui.form.hidden, true); assert.equal(calls.length, 0);
}));
