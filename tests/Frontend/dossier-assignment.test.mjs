import assert from 'node:assert/strict';
import { test } from 'node:test';
import { saveDossierAssignment, initDossierAssignment } from '../../resources/js/dossier-assignment.js';

const settle = () => new Promise(resolve => setImmediate(resolve));
const url = 'https://example.test/v2/dossiers/7/suivi-planning';
const response = (status = 200, payload = { planning_user_id: 3, version: 2 }) => ({
    ok: status === 200, status, json: async () => payload,
});

function fixture() {
    const requests = [];
    const timers = new Map();
    let sequence = 0;
    const browser = {
        setTimeout(fn) { const id = ++sequence; timers.set(id, fn); return id; },
        clearTimeout: id => timers.delete(id),
        fetch(url, options) { return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })); },
    };
    const events = {};
    const rootEvents = {};
    let refreshes = 0;
    const cell = () => {
        const elements = {
            '[data-assignment-feedback]': { textContent: '', dataset: {} },
            '[data-assignment-refresh]': { hidden: true },
            '[data-planning-assignee]': { value: '', disabled: false, options: [{ value: '' }, { value: '3' }, { value: '4' }] },
        };
        const current = { dataset: { assignmentId: '7', assignmentUrl: url, assignedId: '', version: '1' },
            setAttribute() {}, querySelector: selector => elements[selector] };
        for (const [selector, element] of Object.entries(elements)) element.closest = query => query === selector ? element : current;
        return current;
    };
    let cells = [cell()];
    const listing = { addEventListener: (name, callback) => { events[name] = callback; }, querySelectorAll: () => cells };
    const root = { addEventListener: (name, callback) => { rootEvents[name] = callback; }, querySelector: () => ({ content: 'csrf-test' }) };
    initDossierAssignment(listing, root, browser, () => { refreshes++; });
    return { browser, requests, timers, events, rootEvents, get refreshes() { return refreshes; },
        get cell() { return cells[0]; },
        get select() { return cells[0].querySelector('[data-planning-assignee]'); },
        get feedback() { return cells[0].querySelector('[data-assignment-feedback]'); },
        replace() { cells = [cell()]; rootEvents['techcalendar:dossiers-updated'](); },
    };
}

test('sends only the assignment and concurrency version with session/CSRF protection', async () => {
    const ui = fixture();
    const result = saveDossierAssignment(ui.browser, url, 'token', '3', '1');
    const request = ui.requests[0];
    assert.equal(request.options.method, 'PATCH');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'token');
    assert.deepEqual(JSON.parse(request.options.body), { planning_user_id: 3, version: 1 });
    request.resolve(response());
    assert.deepEqual(await result, { planning_user_id: 3, version: 2 });
    assert.equal(ui.timers.size, 0);
});

test('clears an assignment using explicit null', async () => {
    const ui = fixture();
    const result = saveDossierAssignment(ui.browser, url, 'token', '', '2');
    assert.deepEqual(JSON.parse(ui.requests[0].options.body), { planning_user_id: null, version: 2 });
    ui.requests[0].resolve(response(200, { planning_user_id: null, version: 3 }));
    assert.equal((await result).planning_user_id, null);
});

test('preserves the pending selection and prevents duplicate writes when an ajax search replaces the table', async () => {
    const ui = fixture();
    ui.select.value = '3';
    const saving = ui.events.change({ target: ui.select });
    assert.equal(ui.select.disabled, true);
    ui.replace();
    assert.equal(ui.select.value, '3');
    assert.equal(ui.select.disabled, true);
    await ui.events.change({ target: ui.select });
    assert.equal(ui.requests.length, 1);
    ui.requests[0].resolve(response()); await saving;
    assert.equal(ui.select.value, '3');
    assert.equal(ui.select.disabled, false);
    assert.equal(ui.cell.dataset.version, '2');
    assert.equal(ui.feedback.textContent, 'Affectation enregistrée.');
    assert.equal(ui.refreshes, 1);
    // A second reassignment uses the newly returned version, not the old one.
    ui.select.value = '4';
    const second = ui.events.change({ target: ui.select });
    assert.equal(JSON.parse(ui.requests[1].options.body).version, 2);
    ui.requests[1].resolve(response(200, { planning_user_id: 4, version: 3 })); await second;
});

for (const status of [401, 403, 409, 419, 422, 429, 500]) {
    test(`displays HTTP ${status} safely and requires a read refresh before retrying`, async () => {
        const ui = fixture();
        ui.select.value = '3';
        const saving = ui.events.change({ target: ui.select });
        ui.requests[0].resolve(response(status, { errors: { planning_user_id: ['<b>Utilisateur indisponible</b>'] } }));
        await saving;
        assert.equal(ui.select.value, '');
        assert.equal(ui.select.disabled, true);
        assert.equal(ui.feedback.dataset.error, 'true');
        assert.equal(ui.refreshes, 0);
        if (status === 422) assert.equal(ui.feedback.textContent, '<b>Utilisateur indisponible</b>');
        const button = ui.cell.querySelector('[data-assignment-refresh]');
        assert.equal(button.hidden, false);
        ui.events.click({ target: button });
        assert.equal(ui.refreshes, 1);
        assert.equal(ui.requests.length, 1); // No automatic replay of a write.
        ui.replace();
        assert.equal(ui.select.disabled, false);
    });
}

test('handles a timed out write as uncertain, never as a confirmed failure/success', async () => {
    const ui = fixture();
    ui.select.value = '3';
    const saving = ui.events.change({ target: ui.select });
    [...ui.timers.values()][0]();
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].reject(Object.assign(new Error('Abort'), { name: 'AbortError' })); await saving;
    assert.match(ui.feedback.textContent, /non confirmé/);
    assert.equal(ui.select.disabled, true);
    assert.equal(ui.timers.size, 0);
});

test('rejects login html or incomplete success responses', async () => {
    const ui = fixture();
    const result = saveDossierAssignment(ui.browser, url, 'token', '3', '1');
    const assertion = assert.rejects(result, /non confirmé/);
    ui.requests[0].resolve(response(200, { message: 'ok' })); await assertion;
    assert.equal(ui.timers.size, 0);
});
