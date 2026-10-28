import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initDossierMailStatus, mailStatusTone } from '../../resources/js/dossier-mail-status.js';

function fixture({ pending = true, observed = false, hidden = false } = {}) {
    const rootEvents = {}, browserEvents = {}, timers = new Map(); let next = 1, observer;
    const element = () => ({ children: [], dataset: {}, handlers: {}, disabled: false,
        append(...nodes) { this.children.push(...nodes); }, replaceChildren(...nodes) { this.children = nodes; },
        addEventListener(type, fn) { this.handlers[type] = fn; } });
    const list = element(), refresh = element(), feedback = element(), panel = element();
    panel.dataset = { pending: String(pending), statusUrl: '/v2/dossiers/12/email/suivi' };
    panel.querySelector = query => ({ '[data-mail-status-list]': list, '[data-mail-status-refresh]': refresh, '[data-mail-status-feedback]': feedback }[query]);
    const root = { hidden, createElement: element, querySelectorAll: () => [panel], addEventListener(type, fn) { rootEvents[type] = fn; } };
    const requests = [], replies = [];
    const browser = {
        setTimeout(fn, delay) { const id = next++; timers.set(id, { fn, delay }); return id; },
        clearTimeout(id) { timers.delete(id); }, addEventListener(type, fn) { browserEvents[type] = fn; },
        async fetch(url, options) { requests.push({ url, options }); return replies.shift() || { ok: true, status: 200, json: async () => ({ messages: [] }) }; },
    };
    if (observed) browser.IntersectionObserver = class { constructor(fn) { observer = fn; } observe() {} };
    initDossierMailStatus(root, browser);
    const text = node => [node.textContent || '', ...node.children.map(text)].join(' ');
    return { root, panel, refresh, feedback, browser, requests, replies, timers, rootEvents, browserEvents,
        visible(value) { observer([{ isIntersecting: value }]); }, text: () => text(list),
        async tick(delay = 8000) {
            const entry = [...timers].find(([, timer]) => timer.delay === delay);
            assert.ok(entry, `no ${delay}ms timer`); timers.delete(entry[0]); await entry[1].fn();
            await new Promise(resolve => setImmediate(resolve));
        },
        reply(messages) { replies.push({ ok: true, status: 200, json: async () => ({ messages }) }); },
    };
}

test('only polls pending mail and stops when accepted without HTML injection or a page reload', async () => {
    const ui = fixture();
    ui.reply([{ status: 'sent', pending: false, label: 'Envoyé', subject: '<img onerror=alert(1)>', recipient: 'test@example.invalid', detail: 'Accepté par le serveur mail.' }]);
    await ui.tick();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].url, '/v2/dossiers/12/email/suivi');
    assert.equal(ui.requests[0].options.cache, 'no-store');
    assert.equal(ui.requests[0].options.credentials, 'same-origin');
    assert.equal(ui.requests[0].options.method, undefined); // GET only, never a new send request.
    assert.match(ui.text(), /Envoyé.*<img onerror=alert\(1\)>/);
    assert.equal(ui.panel.dataset.pending, 'false');
    assert.equal(ui.timers.size, 0);
    assert.equal(ui.refresh.disabled, false);
    assert.equal(ui.feedback.textContent, 'Suivi à jour.');
});

test('does not poll terminal states or invisible/hidden pages; a manual refresh never resends', async () => {
    const terminal = fixture({ pending: false });
    assert.equal(terminal.timers.size, 0);
    terminal.reply([{ status: 'failed', pending: false, label: 'Échec d’envoi' }]);
    await terminal.refresh.handlers.click();
    assert.equal(terminal.requests.length, 1);
    assert.match(terminal.text(), /Échec d’envoi/);
    const ui = fixture({ observed: true });
    assert.equal(ui.timers.size, 0);
    ui.visible(true);
    assert.ok([...ui.timers.values()].some(t => t.delay === 0));
    ui.visible(false);
    assert.equal(ui.timers.size, 0);
    ui.visible(true); ui.root.hidden = true; ui.rootEvents.visibilitychange();
    assert.equal(ui.timers.size, 0);
    ui.root.hidden = false; ui.rootEvents.visibilitychange();
    assert.ok([...ui.timers.values()].some(t => t.delay === 0));
    ui.browserEvents.pagehide(); assert.equal(ui.timers.size, 0);
    ui.browserEvents.pageshow(); assert.ok([...ui.timers.values()].some(t => t.delay === 0));
});

test('bounds automatic checks and lets the user explicitly refresh afterwards', async () => {
    const ui = fixture();
    for (let count = 0; count < 60; count++) {
        ui.reply([{ status: 'queued', pending: true, label: 'En attente' }]);
        await ui.tick();
    }
    assert.equal(ui.requests.length, 60); assert.equal(ui.timers.size, 0);
    assert.match(ui.feedback.textContent, /Actualiser le suivi/);
    ui.reply([{ status: 'sent', pending: false, label: 'Envoyé' }]);
    await ui.refresh.handlers.click();
    assert.equal(ui.requests.length, 61); assert.equal(ui.timers.size, 0);
});

test('pauses on denied access and backs off on rate limits', async () => {
    const denied = fixture();
    denied.replies.push({ ok: false, status: 403 }); await denied.tick();
    assert.equal(denied.timers.size, 0); assert.match(denied.feedback.textContent, /Aucun nouvel email/);
    const limited = fixture();
    limited.replies.push({ ok: false, status: 429 }); await limited.tick();
    assert.ok([...limited.timers.values()].some(t => t.delay === 30000));
    assert.equal(mailStatusTone('sent'), 'bg-emerald-100 text-emerald-900');
    assert.equal(mailStatusTone('failed'), 'bg-rose-100 text-rose-900');
    assert.equal(mailStatusTone('unknown'), 'bg-slate-100 text-slate-700');
});

test('appointment composers reuse the follow-up without sending or retaining a previous appointment', async () => {
    const ui = fixture({ pending: false });
    ui.panel.handlers['cofrac:mail-status']({ detail: { url: '/planner/book/appointments/8/mail-status' } });
    ui.reply([{ status: 'queued', pending: true, label: 'En attente' }]);
    await ui.tick(0);
    assert.equal(ui.requests[0].url, '/planner/book/appointments/8/mail-status');
    ui.panel.handlers['cofrac:mail-status']({ detail: { url: '/planner/book/appointments/9/mail-status', messages: [{ status: 'sent', pending: false, label: 'Envoyé' }] } });
    assert.equal(ui.timers.size, 0);
    assert.match(ui.text(), /Envoyé/);
    ui.panel.handlers['cofrac:mail-status']({ detail: { url: '' } });
    await ui.refresh.handlers.click();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.refresh.disabled, true);
});
