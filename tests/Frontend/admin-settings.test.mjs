import { test } from 'node:test';
import assert from 'node:assert/strict';
import { settingMatches, changedSettings, initAdminSettings } from '../../resources/js/admin-settings.js';

const row = { search: 'Clé API OpenAI OPENAI_API_KEY Import Excel', source: 'bdd', missing: 'false', group: 'API' };

test('searches labels, env keys and descriptions without accents and combines filters', () => {
    assert.equal(settingMatches(row, { search: 'cle openai', source: 'bdd', group: 'API' }), true);
    assert.equal(settingMatches(row, { search: 'OPENAI_API_KEY excel' }), true);
    assert.equal(settingMatches(row, { search: 'mail' }), false);
    assert.equal(settingMatches(row, { source: 'env' }), false);
    assert.equal(settingMatches(row, { group: 'Mail' }), false);
});
test('keeps missing values distinct from defaults and handles zero as a configured value', () => {
    assert.equal(settingMatches({ ...row, source: 'fallback', missing: 'true' }, { source: 'missing' }), true);
    assert.equal(settingMatches({ ...row, source: 'fallback', missing: 'true' }, { source: 'fallback' }), false);
    assert.equal(settingMatches({ ...row, source: 'fallback', missing: 'false' }, { source: 'fallback' }), true);
});
test('only marks modified settings and never submits an empty password as a change', () => {
    const fields = [{ value: '37', dataset: { savedValue: '37' } },
        { value: 'new', dataset: { savedValue: 'old' } },
        { value: '', type: 'password', dataset: { savedValue: '' } },
        { value: 'secret', type: 'password', dataset: { savedValue: '' } }];
    assert.deepEqual(changedSettings(fields), [fields[1], fields[3]]);
});
function node(extra = {}) {
    return { dataset: {}, value: '', hidden: false, disabled: false, textContent: '', handlers: {},
        addEventListener(event, callback) { this.handlers[event] = callback; }, ...extra };
}
function fixture(response) {
    const inputs = [node({ name: 'settings[host]', value: 'old', dataset: { savedValue: 'old' } }),
        node({ name: 'settings[token]', type: 'password', dataset: { savedValue: '' } })];
    const rows = [node({ dataset: { ...row } }), node({ dataset: { search: 'SMTP host', source: 'env', missing: 'false' } })];
    const section = node({ dataset: { group: 'API' }, querySelectorAll: () => rows });
    const search = node(), group = node(), source = node(), dirty = node(), count = node(), empty = node(), reset = node();
    const cards = ['database', 'mail'].map(key => {
        const button = node({ hidden: true }), result = node({ dataset: { state: 'idle' } });
        const sender = key === 'mail' ? node() : null;
        return node({ dataset: { diagnostic: key }, button, result, sender,
            querySelector: selector => ({ '[data-diagnostic-run]': button, '[data-diagnostic-result]': result, '[data-diagnostic-mail-sender]': sender })[selector] });
    });
    const all = node({ hidden: true });
    const diagnostics = node({ dataset: { url: '/admin/settings/diagnostics' },
        querySelectorAll: selector => selector === 'select' ? cards.map(card => card.sender).filter(Boolean) : cards,
        querySelector: () => all });
    const form = node({ querySelectorAll: () => inputs });
    const nodes = { '[data-settings-form]': form, '[data-settings-search]': search, '[data-settings-group]': group,
        '[data-settings-source]': source, '[data-settings-count]': count, '[data-settings-dirty]': dirty,
        '[data-diagnostics]': diagnostics, '[data-settings-empty]': empty, '[data-settings-reset]': reset };
    const workspace = node({ querySelector: selector => nodes[selector], querySelectorAll: () => [section] });
    const root = { querySelector: selector => selector.startsWith('meta') ? { content: 'csrf-fixture' } : workspace };
    const requests = [];
    const browser = { setTimeout: () => 1, clearTimeout() {}, async fetch(url, options) {
        requests.push({ url, options });
        return response ? response(JSON.parse(options.body), requests.length) : { ok: true, json: async () => ({
            key: JSON.parse(options.body).check, status: 'ok', message: 'Connexion réussie', duration_ms: 12,
        }) };
    } };
    initAdminSettings(root, browser);
    return { root, browser, workspace, requests, rows, section, inputs, cards, all, form, search, group, source, dirty, count, empty, reset };
}

test('initialisation is idempotent and never starts a network test on page load', () => {
    const ui = fixture();
    initAdminSettings(ui.root, ui.browser);
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.all.hidden, false);
    assert.equal(ui.all.disabled, false);
    assert.match(ui.count.textContent, /^2 variables/);
});
test('filters rows immediately and resets filters without discarding edits', () => {
    const ui = fixture();
    ui.search.value = 'absent'; ui.search.handlers.input();
    assert.equal(ui.section.hidden, true); assert.equal(ui.empty.hidden, false);
    ui.inputs[0].value = 'pending'; ui.inputs[0].handlers.input();
    ui.reset.handlers.click();
    assert.equal(ui.inputs[0].value, 'pending'); assert.equal(ui.empty.hidden, true);
    assert.equal(ui.rows[0].hidden, false); assert.equal(ui.rows[1].hidden, false);
});
test('retains hidden changed fields and does not silently save defaults as BDD overrides', () => {
    const ui = fixture();
    ui.inputs[0].value = 'pending'; ui.inputs[0].handlers.input();
    ui.search.value = 'absent'; ui.search.handlers.input();
    const data = new FormData();
    ui.inputs.forEach(input => data.set(input.name, input.value));
    ui.form.handlers.formdata({ formData: data });
    assert.equal(data.get('settings[host]'), 'pending');
    assert.equal(data.has('settings[token]'), false);
    let prevented = false;
    ui.form.handlers.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    ui.inputs[0].value = 'old'; ui.inputs[0].handlers.input();
    ui.form.handlers.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});
test('reveals a filtered invalid field before native validation focuses it', () => {
    const ui = fixture();
    ui.search.value = 'absent'; ui.search.handlers.input();
    ui.form.handlers.invalid();
    assert.equal(ui.search.value, ''); assert.equal(ui.section.hidden, false);
});
test('prevents connection tests until changes are saved', async () => {
    const ui = fixture();
    ui.inputs[0].value = 'pending'; ui.inputs[0].handlers.input();
    assert.equal(ui.all.disabled, true);
    await ui.all.handlers.click();
    assert.equal(ui.requests.length, 0);
    ui.inputs[0].value = 'old'; ui.inputs[0].handlers.input();
    assert.equal(ui.all.disabled, false);
});
test('tests only the chosen SMTP sender with CSRF and no unsaved values', async () => {
    const ui = fixture();
    ui.cards[1].sender.value = '42';
    await ui.cards[1].button.handlers.click();
    assert.equal(ui.requests.length, 1);
    const request = ui.requests[0];
    assert.equal(request.url, '/admin/settings/diagnostics');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'csrf-fixture');
    assert.deepEqual(JSON.parse(request.options.body), { check: 'mail', mail_sender_id: 42 });
    assert.equal(ui.cards[1].result.dataset.state, 'ok');
    assert.equal(ui.cards[0].result.dataset.state, 'idle');
    ui.cards[1].sender.handlers.change();
    assert.equal(ui.cards[1].result.dataset.state, 'idle');
});
test('runs all tests sequentially and restores buttons afterward', async () => {
    const ui = fixture();
    await ui.all.handlers.click();
    assert.deepEqual(ui.requests.map(request => JSON.parse(request.options.body).check), ['database', 'mail']);
    assert.equal(ui.all.disabled, false);
    assert.equal(ui.cards[1].sender.disabled, false);
});
test('stops the test sequence on authentication and rate limit failures', async () => {
    for (const status of [401, 403, 419, 429]) {
        const ui = fixture(() => ({ ok: false, status }));
        await ui.all.handlers.click();
        assert.equal(ui.requests.length, 1);
        assert.equal(ui.cards[0].result.dataset.state, 'error');
        assert.equal(ui.cards[1].result.dataset.state, 'idle');
        assert.equal(ui.all.disabled, false);
    }
});
test('rejects unexpected JSON and renders server messages as text only', async () => {
    const ui = fixture(({ check }) => ({ ok: true, json: async () => ({ key: check, status: 'ok', message: '<script>bad()</script>' }) }));
    await ui.cards[0].button.handlers.click();
    assert.match(ui.cards[0].result.textContent, /<script>bad\(\)<\/script>/);
    assert.equal(ui.cards[0].result.innerHTML, undefined);
    const broken = fixture(() => ({ ok: true, json: async () => ({ key: 'wrong', status: 'ok' }) }));
    await broken.all.handlers.click();
    assert.equal(broken.requests.length, 1);
    assert.equal(broken.cards[0].result.dataset.state, 'error');
});
