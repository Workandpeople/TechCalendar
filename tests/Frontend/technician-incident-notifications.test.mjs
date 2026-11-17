import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initTechnicianIncidentNotifications, validIncidentCounts } from '../../resources/js/technician-incident-notifications.js';

function fixture({ hidden = false, enabled = true } = {}) {
    const events = {}, browserEvents = {}, timers = new Map(), requests = [], replies = [];
    let next = 1;
    const counter = { textContent: '1' }, billing = { textContent: '1' };
    const changeCounter = { textContent: '0' };
    const changeAlert = { shown: false, classList: { remove() { changeAlert.shown = true; }, add() { changeAlert.shown = false; } } };
    const alert = { shown: false, classList: { remove(value) { if (value === 'hidden') alert.shown = true; } } };
    const panel = { dataset: { latestId: '1', countsUrl: '/planner/incidents/counts' } };
    const root = { hidden,
        querySelector: selector => selector === '[data-incident-alert]' ? alert : selector === '[data-appointment-change-alert]' ? changeAlert : enabled ? panel : null,
        querySelectorAll: selector => selector === '[data-appointment-change-count]' ? [changeCounter] : selector === '[data-incident-billing-count]' ? [billing] : [counter],
        addEventListener(name, fn) { events[name] = fn; },
    };
    const browser = {
        setTimeout(fn, delay) { const id = next++; timers.set(id, { fn, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        addEventListener(name, fn) { browserEvents[name] = fn; },
        async fetch(url, options) { requests.push({ url, options }); return replies.shift() || { ok: true, status: 200, json: async () => ({ pending: 1, billing: 1, latest_id: 1 }) }; },
    };
    initTechnicianIncidentNotifications(root, browser);
    return { root, browser, events, browserEvents, timers, requests, replies, alert, counter, billing, changeCounter, changeAlert,
        async tick(delay = 45000) {
            const entry = [...timers].find(([, timer]) => timer.delay === delay);
            assert.ok(entry, `Missing timer ${delay}`); timers.delete(entry[0]); await entry[1].fn();
        },
        reply(counts) { replies.push({ ok: true, status: 200, json: async () => counts }); },
    };
}

test('updates indicators and announces new incidents even if other incidents were resolved', async () => {
    const ui = fixture();
    await ui.tick(); assert.equal(ui.alert.shown, false);
    ui.reply({ pending: 1, billing: 0, latest_id: 2 });
    await ui.tick(); assert.equal(ui.alert.shown, true);
    assert.equal(ui.counter.textContent, '1'); assert.equal(ui.billing.textContent, '0');
    assert.equal(ui.requests[0].options.method, undefined);
    assert.equal(ui.requests[0].options.cache, 'no-store');
    assert.equal(ui.requests[0].options.credentials, 'same-origin');
});

test('has no polling on external or hidden pages and respects bfcache pause/resume', async () => {
    assert.equal(fixture({ enabled: false }).timers.size, 0);
    const ui = fixture({ hidden: true }); assert.equal(ui.timers.size, 0);
    ui.root.hidden = false; ui.events.visibilitychange(); await ui.tick();
    ui.browserEvents.pagehide(); assert.equal(ui.timers.size, 0);
    ui.browserEvents.pageshow(); assert.equal(ui.timers.size, 1);
    ui.root.hidden = true; ui.events.visibilitychange(); assert.equal(ui.timers.size, 0);
});

test('backs off on unavailable services and never treats malformed HTML or data as notifications', async () => {
    const ui = fixture();
    ui.replies.push({ ok: true, status: 200, json: async () => { throw new Error('HTML'); } });
    await ui.tick(); assert.equal(ui.alert.shown, false);
    assert.ok([...ui.timers.values()].some(timer => timer.delay === 90000));
    ui.reply({ pending: '<img>', latest_id: 1000, billing: 1 }); await ui.tick(90000);
    assert.equal(ui.alert.shown, false); assert.equal(ui.counter.textContent, '1');
    ui.reply({ pending: 2, latest_id: 2, billing: null }); await ui.tick(180000);
    assert.equal(ui.alert.shown, true);
    assert.ok([...ui.timers.values()].some(timer => timer.delay === 45000));
});

test('stops after expired or revoked sessions rather than repeatedly probing', async () => {
    for (const status of [401, 403, 419]) {
        const ui = fixture(); ui.replies.push({ ok: false, status }); await ui.tick();
        assert.equal(ui.timers.size, 0); ui.browserEvents.pageshow(); assert.equal(ui.timers.size, 0);
    }
    const ui = fixture(); ui.replies.push({ ok: true, redirected: true }); await ui.tick(); assert.equal(ui.timers.size, 0);
});

test('validates counters without allowing markup or negative/unbounded values', () => {
    assert.equal(validIncidentCounts({ pending: 0, latest_id: 0, billing: null }), true);
    for (const bad of [{ pending: -1, latest_id: 0, billing: 0 }, { pending: 1, latest_id: Infinity, billing: 0 }, { pending: 1, latest_id: 1 }]) {
        assert.equal(Boolean(validIncidentCounts(bad)), false);
    }
});

test('announces a new version of an already pending appointment without another poll or any write', async () => {
    const ui = fixture();
    ui.reply({ pending: 1, billing: 1, latest_id: 1, appointment_changes: { pending: 1, revision: 1 } });
    await ui.tick();
    assert.equal(ui.changeCounter.textContent, '1'); assert.equal(ui.changeAlert.shown, true);
    ui.changeAlert.shown = false;
    ui.reply({ pending: 1, billing: 1, latest_id: 1, appointment_changes: { pending: 1, revision: 2 } });
    await ui.tick(); assert.equal(ui.changeAlert.shown, true); assert.equal(ui.requests.length, 2);
    ui.reply({ pending: 1, billing: 1, latest_id: 1, appointment_changes: { pending: 0, revision: 0 } });
    await ui.tick(); assert.equal(ui.changeCounter.textContent, '0'); assert.equal(ui.changeAlert.shown, false);
    assert.ok(ui.requests.every(request => request.options.method == null));
    assert.equal(validIncidentCounts({ pending: 0, billing: null, latest_id: 0, appointment_changes: { pending: '<img>', revision: 1 } }), false);
});

test('does not miss edits and acknowledgements that leave the aggregate count and revision unchanged', async () => {
    const ui = fixture();
    for (const fingerprint of ['a'.repeat(64), 'b'.repeat(64)]) {
        ui.changeAlert.shown = false;
        ui.reply({ pending: 0, billing: null, latest_id: 1, appointment_changes: { pending: 2, revision: 3, fingerprint } });
        await ui.tick(); assert.equal(ui.changeAlert.shown, true);
    }
    assert.equal(validIncidentCounts({ pending: 0, billing: null, latest_id: 0,
        appointment_changes: { pending: 2, revision: 3, fingerprint: ['a'.repeat(64)] } }), false);
});
