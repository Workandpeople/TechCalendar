import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initCapceeReports } from '../../resources/js/capcee-reports.js';

function fixture() {
    const handlers = new Map();
    const timers = new Map();
    let nextTimer = 0;
    let active = true;
    const results = { querySelector: () => active ? {} : null, set innerHTML(html) { this.html = html; active = html.includes('data-capcee-active'); } };
    const feedback = { textContent: '' };
    const button = { addEventListener: (name, handler) => handlers.set(`button:${name}`, handler) };
    const section = { dataset: { capceeStatusUrl: '/v2/dossiers/1/capcee/suivi' }, querySelector: selector => ({ '[data-capcee-results]': results, '[data-capcee-feedback]': feedback, '[data-capcee-refresh]': button })[selector] };
    const root = { hidden: false, querySelector: () => section, addEventListener: (name, handler) => handlers.set(name, handler) };
    const calls = [];
    const browser = {
        setTimeout: (callback, delay) => { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
        clearTimeout: id => timers.delete(id), addEventListener: (name, handler) => handlers.set(name, handler),
        fetch: async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => ({ html: '<p>Terminé</p>', active: false }) }; },
    };
    initCapceeReports(root, browser);
    return { root, browser, results, feedback, button, handlers, timers, calls, emit: name => handlers.get(name)() };
}

test('updates a protected status fragment and stops polling when complete', async () => {
    const ui = fixture();
    const timer = [...ui.timers.values()].find(t => t.delay === 5000);
    assert(timer);
    await timer.callback();
    assert.equal(ui.calls[0].options.credentials, 'same-origin');
    assert.equal(ui.results.html, '<p>Terminé</p>');
    assert.equal(ui.feedback.textContent, 'Suivi à jour.');
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.timers.size, 0);
});

test('preserves the last result and retries when the status endpoint fails', async () => {
    const ui = fixture();
    ui.browser.fetch = async () => ({ ok: false, status: 503 });
    await ui.emit('button:click');
    assert.equal(ui.results.html, undefined);
    assert.match(ui.feedback.textContent, /momentanément indisponible/);
    assert([...ui.timers.values()].some(t => t.delay === 5000));
});

test('stops automatic polling when authorization is lost', async () => {
    const ui = fixture();
    ui.browser.fetch = async () => ({ ok: false, status: 403 });
    await ui.emit('button:click');
    assert.match(ui.feedback.textContent, /accès refusé/);
    assert.equal(ui.timers.size, 0);
});

test('does not run concurrent requests and aborts pending calls when leaving', async () => {
    const ui = fixture();
    let resolve;
    let signal;
    ui.browser.fetch = async (url, options) => { signal = options.signal; return new Promise(done => { resolve = done; }); };
    const pending = ui.emit('button:click');
    const initialSignal = signal;
    await ui.emit('button:click');
    assert.equal(signal, initialSignal);
    ui.emit('pagehide');
    assert.equal(signal.aborted, true);
    resolve({ ok: true, json: async () => ({ html: '', active: false }) });
    await pending;
    assert.equal(ui.timers.size, 0);
});

test('suspends polling while hidden and refreshes when returning', async () => {
    const ui = fixture();
    ui.root.hidden = true;
    await ui.emit('visibilitychange');
    assert.equal(ui.timers.size, 0);
    ui.root.hidden = false;
    await ui.emit('visibilitychange');
    await Promise.resolve();
    assert.equal(ui.calls.length, 1);
});

test('does nothing on other pages', () => {
    initCapceeReports({ querySelector: () => null }, {});
});
