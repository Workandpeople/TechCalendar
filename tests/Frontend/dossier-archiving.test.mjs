import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dossierArchivingConfirmation, selectedDossiers, submitDossierArchiving, initDossierArchiving } from '../../resources/js/dossier-archiving.js';

const response = (status = 200, count = 2, extra = {}) => ({
    ok: status === 200, status, json: async () => ({ count, message: 'Données conservées.', ...extra }),
});

function fixture() {
    const requests = [], confirmations = [];
    const timers = new Map(), events = {}, rootEvents = {};
    let timerId = 0, refreshes = 0, confirmation = true;
    const browser = {
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout: id => timers.delete(id),
        confirm(message) { confirmations.push(message); return confirmation; },
        fetch(url, options) { return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })); },
    };
    const node = (selector, dataset = {}, values = {}) => ({
        dataset, ...values,
        matches: query => query === selector,
        closest(query) { return query.split(', ').includes(selector) ? this : null; },
    });
    const makePage = () => ({
        inputs: [7, 8].map(id => node('[data-dossier-select]', { id: String(id), version: '1' }, { checked: false })),
        selectPage: node('[data-dossier-select-page]', {}, { checked: false }),
        single: node('[data-dossier-delete-url]', { dossierDeleteUrl: '/v2/dossiers/7', version: '1', reference: 'REF-7' }),
        buttons: ['cancel', 'delete'].map(action => node('[data-dossier-batch-action]', { dossierBatchAction: action })),
        counter: { textContent: '' },
        reason: { value: '' },
    });
    let page = makePage();
    const batch = { dataset: { dossierBatchUrl: '/v2/dossiers/actions-groupees', batchLimit: '100' } };
    const status = { textContent: '' }, errorBox = { hidden: true }, message = { textContent: '' };
    const refreshButton = { addEventListener: (name, handler) => { events.refresh = handler; } };
    const feedback = {
        setAttribute() {},
        querySelector: selector => ({
            '[data-dossier-archiving-status]': status, '[data-dossier-archiving-error]': errorBox,
            '[data-dossier-archiving-message]': message, '[data-dossier-archiving-refresh]': refreshButton,
        })[selector],
    };
    const listing = {
        addEventListener: (name, handler) => { events[name] = handler; },
        querySelector: selector => ({
            '[data-dossier-archiving-feedback]': feedback, '[data-dossier-selection-count]': page.counter,
            '[data-dossier-select-page]': page.selectPage, '[data-dossier-batch-url]': batch,
            '[data-dossier-batch-reason]': page.reason,
        })[selector],
        querySelectorAll: selector => ({
            '[data-dossier-select]': page.inputs, '[data-dossier-select]:checked': page.inputs.filter(input => input.checked),
            '[data-dossier-batch-action]': page.buttons, '[data-dossier-delete-url]': [page.single],
        })[selector] || [],
    };
    const root = {
        addEventListener: (name, handler) => { rootEvents[name] = handler; },
        querySelector: () => ({ content: 'csrf-token' }),
    };
    initDossierArchiving(listing, root, browser, () => { refreshes++; });
    return {
        browser, listing, requests, timers, confirmations, events, rootEvents, status, errorBox, message, batch,
        get page() { return page; }, get refreshes() { return refreshes; },
        decline() { confirmation = false; },
        selectAll() { page.selectPage.checked = true; events.change({ target: page.selectPage }); },
        select(index = 0) { page.inputs[index].checked = true; events.change({ target: page.inputs[index] }); },
        click(button = page.buttons[1]) { return events.click({ target: button }); },
        replace() { page = makePage(); rootEvents['techcalendar:dossiers-updated'](); },
    };
}

test('confirms the exact number and distinguishes reversible deletion from business cancellation', () => {
    assert.match(dossierArchivingConfirmation('delete', 14), /14 dossier\(s\) sélectionné\(s\) sur cette page/);
    assert.match(dossierArchivingConfirmation('delete', 1, 'REF-7'), /REF-7 \(1 dossier\)/);
    assert.match(dossierArchivingConfirmation('delete', 1), /réversible/);
    assert.match(dossierArchivingConfirmation('delete', 2), /suppression individuelle/);
    assert.doesNotMatch(dossierArchivingConfirmation('delete', 1, 'REF-7'), /sera refusé/);
    assert.match(dossierArchivingConfirmation('cancel', 2), /Seules ces prestations/);
    assert.match(dossierArchivingConfirmation('cancel', 2), /facturation seront conservés/);
    assert.throws(() => dossierArchivingConfirmation('delete', 0));
});

test('sends the trimmed optional cancellation reason and locks it while the batch is pending', async () => {
    const ui = fixture();
    ui.selectAll();
    ui.page.reason.value = '  Ancien doublon  ';
    const saving = ui.click(ui.page.buttons[0]);
    assert.equal(JSON.parse(ui.requests[0].options.body).reason, 'Ancien doublon');
    assert.equal(ui.page.reason.disabled, true);
    ui.requests[0].resolve(response());
    await saving;
    ui.replace();
    assert.equal(ui.page.reason.disabled, false);
    assert.equal(ui.page.reason.value, '');
});

test('surfaces mixed-state refusal with individual deletion guidance and never retries automatically', async () => {
    const ui = fixture();
    ui.selectAll();
    const saving = ui.click();
    ui.requests[0].resolve(response(422, 0, { errors: { dossiers: ['REF-8 : utilise la suppression individuelle explicite.'] } }));
    await saving;
    assert.match(ui.message.textContent, /REF-8.*suppression individuelle explicite/);
    assert.equal(ui.page.single.disabled, true);
    assert.equal(ui.requests.length, 1);
    ui.events.refresh(); ui.replace();
    const individual = ui.click(ui.page.single);
    assert.equal(ui.requests[1].options.method, 'DELETE');
    ui.requests[1].resolve(response(200, 1));
    await individual;
});

test('starts with no implicit selection and toggles only the displayed page', () => {
    const ui = fixture();
    assert.equal(ui.page.counter.textContent, '0');
    assert.equal(ui.page.buttons.every(button => button.disabled), true);
    ui.select();
    assert.equal(ui.page.selectPage.indeterminate, true);
    assert.deepEqual(selectedDossiers(ui.listing), [{ id: 7, version: 1 }]);
    ui.selectAll();
    assert.equal(ui.page.counter.textContent, '2');
    assert.equal(ui.page.selectPage.checked, true);
    assert.equal(ui.page.selectPage.indeterminate, false);
    ui.page.selectPage.checked = false;
    ui.events.change({ target: ui.page.selectPage });
    assert.equal(ui.page.counter.textContent, '0');
});

test('sends only selected IDs and versions with explicit confirmation session and CSRF protection', async () => {
    const ui = fixture();
    ui.select();
    const saving = ui.click(ui.page.buttons[0]);
    const request = ui.requests[0];
    assert.equal(request.url, '/v2/dossiers/actions-groupees');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.cache, 'no-store');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(JSON.parse(request.options.body), { action: 'cancel', dossiers: [{ id: 7, version: 1 }], confirmed: true });
    assert.match(ui.confirmations[0], /1 dossier\(s\)/);
    request.resolve(response(200, 1));
    await saving;
    assert.equal(ui.refreshes, 1);
    assert.equal(ui.timers.size, 0);
    assert.equal(ui.status.textContent, 'Données conservées.');
    assert.equal(ui.page.single.disabled, true); // Wait for the post-write read.
    ui.replace();
    assert.equal(ui.page.single.disabled, false);
    assert.equal(ui.page.counter.textContent, '0');
});

test('uses the existing DELETE route for a single row without touching the batch selection', async () => {
    const ui = fixture();
    ui.selectAll();
    const saving = ui.click(ui.page.single);
    assert.equal(ui.requests[0].url, '/v2/dossiers/7');
    assert.equal(ui.requests[0].options.method, 'DELETE');
    assert.deepEqual(JSON.parse(ui.requests[0].options.body), { version: 1, confirmed: true });
    assert.match(ui.confirmations[0], /REF-7 \(1 dossier\)/);
    ui.requests[0].resolve(response(200, 1));
    await saving;
});

test('makes no request when confirmation is declined and leaves selection intact', async () => {
    const ui = fixture();
    ui.selectAll(); ui.decline();
    await ui.click();
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.page.counter.textContent, '2');
    assert.equal(ui.page.buttons[1].disabled, false);
});

test('prevents empty or over-limit batches even if a click is triggered programmatically', async () => {
    const ui = fixture();
    await ui.click();
    ui.selectAll(); ui.batch.dataset.batchLimit = '1';
    await ui.click();
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.confirmations.length, 0);
});

test('clears explicit selection after pagination filtering or any quiet replacement', async () => {
    const ui = fixture();
    ui.selectAll();
    ui.replace();
    assert.deepEqual(selectedDossiers(ui.listing), []);
    assert.equal(ui.page.counter.textContent, '0');
    await ui.click();
    assert.equal(ui.requests.length, 0);
});

test('keeps in-flight writes independent of AJAX replacement and prevents double submissions', async () => {
    const ui = fixture();
    ui.selectAll();
    const saving = ui.click();
    await ui.click(ui.page.single);
    ui.replace();
    assert.equal(ui.page.single.disabled, true);
    assert.equal(ui.page.inputs.every(input => input.disabled), true);
    await ui.click(ui.page.single);
    assert.equal(ui.requests.length, 1);
    ui.requests[0].resolve(response());
    await saving;
    assert.equal(ui.page.single.disabled, true);
    ui.replace();
    assert.equal(ui.page.single.disabled, false);
    assert.equal(ui.refreshes, 1);
});

for (const status of [401, 403, 404, 409, 419, 422, 429, 500]) {
    test(`handles HTTP ${status} without replaying the mutation and requires a successful read`, async () => {
        const ui = fixture();
        ui.selectAll();
        const saving = ui.click();
        ui.requests[0].resolve(response(status, 0, { errors: { dossiers: ['<b>Sélection invalide</b>'] } }));
        await saving;
        assert.equal(ui.errorBox.hidden, false);
        assert.equal(ui.page.single.disabled, true);
        if (status === 422) assert.equal(ui.message.textContent, '<b>Sélection invalide</b>');
        await ui.click(ui.page.single);
        assert.equal(ui.requests.length, 1);
        ui.events.refresh();
        assert.equal(ui.refreshes, 1);
        assert.equal(ui.page.single.disabled, true);
        ui.replace();
        assert.equal(ui.page.single.disabled, false);
        assert.equal(ui.errorBox.hidden, true);
        assert.equal(ui.requests.length, 1);
    });
}

test('marks timed-out writes as uncertain and never automatically retries them', async () => {
    const ui = fixture();
    ui.selectAll();
    const saving = ui.click();
    [...ui.timers.values()][0]();
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].reject(Object.assign(new Error('Abort'), { name: 'AbortError' }));
    await saving;
    assert.match(ui.message.textContent, /Résultat non confirmé/);
    assert.equal(ui.page.single.disabled, true);
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.timers.size, 0);
});

test('does not accept partial or malformed success responses', async () => {
    const ui = fixture();
    const saving = submitDossierArchiving(ui.browser, '/batch', 'token', 'POST', { dossiers: [{ id: 7, version: 1 }, { id: 8, version: 1 }] });
    const assertion = assert.rejects(saving, /Résultat non confirmé/);
    ui.requests[0].resolve(response(200, 1));
    await assertion;
    assert.equal(ui.timers.size, 0);
});

test('does not initialize mutation handlers for unauthorized or unrelated pages', () => {
    initDossierArchiving({ querySelector: () => null }, {}, {}, () => { throw new Error('Unexpected refresh'); });
});
