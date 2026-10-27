import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const counts = source.slice(source.indexOf('const updateNativeBookingCount ='), source.indexOf('let nativeBookingQueueRefresh ='));
const refresh = source.slice(source.indexOf('let nativeBookingQueueRefresh ='), source.indexOf('const refreshBookingCrmAppointments ='));
const selection = source.slice(source.indexOf('const analyzeCrmAppointment ='), source.indexOf('const lotProcessingSelectFor ='));

test('counts unique dossiers rather than prestations and updates desktop/mobile badges', () => {
    const summary = {};
    const badges = [0, 1].map(() => ({ classList: { toggle: (name, value) => { summary[name] = value; } }, setAttribute() {} }));
    const ui = {
        bookingConnectorsEnabled: false, bookingIsReplacementMode: false,
        bookingCrmAppointments: [{ dossier_id: 1 }, { dossier_id: 1, replace_appointment_id: 42 }, { dossier_id: 2 }],
        document: { getElementById: () => summary, querySelectorAll: () => badges },
    };
    const update = runInNewContext(`${counts}\nupdateNativeBookingCount;`, ui);
    update();
    assert.equal(summary.textContent, '2 dossier(s) · 3 prestation(s)');
    for (const badge of badges) {
        assert.equal(badge.textContent, '2');
        assert.equal(badge.title, '2 dossier(s) en prise de RDV');
    }
    ui.bookingCrmAppointments = [];
    update();
    assert.equal(summary.textContent, '0 dossier(s) · 0 prestation(s)');
    assert.equal(summary.hidden, true);
});

test('does not overwrite native sidebar counts in legacy or replacement mode', () => {
    for (const [legacy, replacement] of [[true, false], [false, true]]) {
        runInNewContext(`${counts}\nupdateNativeBookingCount();`, {
            bookingConnectorsEnabled: legacy, bookingIsReplacementMode: replacement,
        });
    }
});

function refreshHarness() {
    const calls = [], rendered = [];
    const context = {
        bookingConnectorsEnabled: false, bookingIsReplacementMode: false,
        bookingCrmIndexUrl: '/planner/book/crm-appointments', AbortController,
        document: { visibilityState: 'visible' },
        fetch: (url, options) => new Promise((resolve, reject) => calls.push({ url, options, resolve, reject })),
        renderBookingCrmAppointments: (...args) => rendered.push(args),
    };
    const run = runInNewContext(`${refresh}\nrefreshNativeBookingQueue;`, context);
    return { context, run, calls, rendered };
}

test('refreshes read-only on return to the tab preserving search and page and ignoring obsolete responses', async () => {
    const ui = refreshHarness();
    const first = ui.run(), second = ui.run();
    assert.equal(ui.calls[0].options.signal.aborted, true);
    assert.equal(ui.calls[1].options.cache, 'no-store');
    assert.equal(ui.calls[1].options.method, undefined);
    ui.calls[1].resolve({ ok: true, json: async () => ({ appointments: [{ dossier_id: 2 }] }) });
    await second;
    ui.calls[0].resolve({ ok: true, json: async () => ({ appointments: [{ dossier_id: 1 }] }) });
    await first;
    assert.equal(ui.rendered.length, 1);
    assert.equal(ui.rendered[0][0][0].dossier_id, 2);
    assert.equal(ui.rendered[0][2].preserveUi, true);
    assert.match(source, /window\.addEventListener\('focus', refreshNativeBookingQueue\)/);
});

test('retains the list on network failures authentication errors or malformed replies', async () => {
    for (const result of ['network', 'auth', 'malformed']) {
        const ui = refreshHarness();
        const pending = ui.run();
        if (result === 'network') ui.calls[0].reject(new Error('offline'));
        else ui.calls[0].resolve({ ok: result !== 'auth', json: async () => ({ appointments: null }) });
        await pending;
        assert.equal(ui.rendered.length, 0);
    }
});

test('never refreshes when hidden or on the legacy connector or replacement screen', async () => {
    const ui = refreshHarness();
    for (const [legacy, replacement, visible] of [[true, false, true], [false, true, true], [false, false, false]]) {
        Object.assign(ui.context, { bookingConnectorsEnabled: legacy, bookingIsReplacementMode: replacement });
        ui.context.document.visibilityState = visible ? 'visible' : 'hidden';
        await ui.run();
    }
    assert.equal(ui.calls.length, 0);
});

test('opens the existing replacement workflow while leaving unscheduled analysis unchanged', async () => {
    const redirected = [], analyzed = [];
    const run = runInNewContext(`${selection}\nanalyzeCrmAppointment;`, {
        crmAppointmentById: id => id === 'scheduled' ? { replace_appointment_id: 42 } : { dossier_id: 1 },
        bookingReplacementPageUrl: '/planner/appointments/modify', bookingConnectorsEnabled: false,
        window: { location: { assign: url => redirected.push(url) } },
        analyzeAppointment: (...args) => analyzed.push(args),
    });
    await run('scheduled');
    assert.deepEqual(redirected, ['/planner/appointments/modify?replace_appointment_id=42']);
    assert.equal(analyzed.length, 0);
    await run('new');
    assert.equal(analyzed[0][0].crm_appointment_id, 'new');
});
