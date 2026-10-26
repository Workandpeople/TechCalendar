import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dossierListUrl, dossierServiceIds, syncDossierFilters, createDossierLoader, initDossierList } from '../../resources/js/dossier-list.js';

const base = 'https://example.test/v2/dossiers';
const settle = () => new Promise(resolve => setImmediate(resolve));
const payload = { categories: '<nav></nav>', results: '<table></table>', total: 12 };
const response = (data = payload) => ({ ok: true, json: async () => data });

test('keeps the independent billing filter across categories sorting and pagination', () => {
    const entries = new URLSearchParams('billing=partial&status=non_compliant');
    const url = dossierListUrl(base, entries, { page: '2', sort: 'client' });
    assert.equal(url.searchParams.get('billing'), 'partial');
    assert.equal(url.searchParams.get('status'), 'non_compliant');
    assert.equal(dossierListUrl(base, entries, { billing: '' }).searchParams.has('billing'), false);
});

test('builds a canonical filtered URL and resets pagination without carrying unrelated parameters', () => {
    const url = dossierListUrl(`${base}?page=8#table`, new URLSearchParams({
        q: ' Lyon ', per_page: '25', service_id: '3', status: '', all_columns: '0', page: '8', ignored: 'yes', direction: 'asc',
    }));
    assert.equal(url.href, `${base}?q=Lyon&service_ids%5B%5D=3`);
    assert.equal(dossierListUrl(base, new URLSearchParams('service_id=3&q=Lyon')).href, url.href);
});

test('keeps current input while applying categories sort or pagination and supports clearing them', () => {
    const entries = new URLSearchParams('q=Nouveau&status=pending&sort=client&direction=desc&per_page=50');
    let url = dossierListUrl(base, entries, { status: 'problem', page: '2' });
    assert.equal(url.searchParams.get('q'), 'Nouveau');
    assert.equal(url.searchParams.get('page'), '2');
    assert.equal(url.searchParams.get('status'), 'problem');
    url = dossierListUrl(base, entries, { status: null, sort: null, direction: null });
    assert.equal(url.searchParams.has('status'), false);
    assert.equal(url.searchParams.has('sort'), false);
    assert.equal(url.searchParams.has('direction'), false);
    assert.equal(dossierListUrl(base, new URLSearchParams('sort=client')).searchParams.get('direction'), 'asc');
});

test('retains multiple services from forms PHP indexed links sorting pagination and exports', () => {
    const forms = new URLSearchParams('service_ids[]=3&service_ids[]=7&q=Lyon');
    const indexed = new URLSearchParams('service_ids[0]=3&service_ids[1]=7&q=Lyon');
    const url = dossierListUrl(base, forms, { status: 'visited', page: '2' });
    assert.deepEqual(url.searchParams.getAll('service_ids[]'), ['3', '7']);
    assert.equal(dossierListUrl(base, indexed, { status: 'visited', page: '2' }).href, url.href);
    assert.deepEqual(dossierServiceIds(url.searchParams), ['3', '7']);
    assert.deepEqual(dossierServiceIds(new URLSearchParams('service_id=3')), ['3']);
    assert.deepEqual(dossierServiceIds(new URLSearchParams('service_id=99&service_ids[]=3&service_ids[]=7')), ['3', '7']);
    assert.equal(dossierListUrl(base, forms, { service_ids: [] }).searchParams.has('service_ids[]'), false);
    assert.deepEqual(dossierListUrl(base, forms, { service_id: '8' }).searchParams.getAll('service_ids[]'), ['8']);
    assert.equal(dossierListUrl(base, new URLSearchParams('service_id=3')).href,
        dossierListUrl(base, new URLSearchParams('service_ids[]=3')).href);
});

test('restores every selected service on history navigation and clears the visible widget without another request', () => {
    const events = [];
    const services = { multiple: true, options: ['3', '7', '8'].map(value => ({ value, selected: false })), dispatchEvent: event => events.push(event.type) };
    const form = { elements: { namedItem: name => name === 'service_ids[]' ? services : null } };
    const browser = { CustomEvent: class { constructor(type) { this.type = type; } } };
    syncDossierFilters(form, new URL(`${base}?service_ids[0]=3&service_ids[1]=7`), browser);
    assert.deepEqual(services.options.map(option => option.selected), [true, true, false]);
    syncDossierFilters(form, new URL(`${base}?service_id=8`), browser);
    assert.deepEqual(services.options.map(option => option.selected), [false, false, true]);
    syncDossierFilters(form, new URL(base), browser);
    assert.deepEqual(services.options.map(option => option.selected), [false, false, false]);
    assert.deepEqual(events, ['techcalendar:selection-sync', 'techcalendar:selection-sync', 'techcalendar:selection-sync']);
});

test('keeps planning users independent of services through sorting pagination and export URLs in visited only', () => {
    const entries = new URLSearchParams('status=visited&service_ids[0]=3&service_ids[1]=7&planning_user_ids[0]=12&planning_user_ids[1]=15&page=8');
    const url = dossierListUrl(base, entries, { sort: 'planning_user', page: '2' });
    assert.deepEqual(url.searchParams.getAll('planning_user_ids[]'), ['12', '15']);
    assert.deepEqual(url.searchParams.getAll('service_ids[]'), ['3', '7']);
    assert.equal(url.searchParams.get('page'), '2');
    const resized = dossierListUrl(base, url.searchParams, { per_page: '100' });
    assert.equal(resized.searchParams.has('page'), false);
    assert.equal(resized.searchParams.get('per_page'), '100');
    assert.deepEqual(resized.searchParams.getAll('planning_user_ids[]'), ['12', '15']);
    assert.equal(dossierListUrl(base, entries, { planning_user_ids: [] }).searchParams.has('planning_user_ids[]'), false);
    for (const status of ['pending', '', null, 'all', 'report']) {
        const changed = dossierListUrl(base, entries, { status });
        assert.equal(changed.searchParams.has('planning_user_ids[]'), false);
        assert.deepEqual(changed.searchParams.getAll('service_ids[]'), ['3', '7']);
    }
});

test('restores both multi selections on history navigation and disables planning outside visited', () => {
    const events = [];
    const select = values => ({ multiple: true, options: values.map(value => ({ value, selected: false })),
        dispatchEvent: event => events.push(event.type) });
    const fields = { status: { value: '' }, 'service_ids[]': select(['3', '7']), 'planning_user_ids[]': select(['12', '15', '18']) };
    const container = { hidden: true };
    const form = { elements: { namedItem: name => fields[name] }, querySelector: () => container };
    const browser = { CustomEvent: class { constructor(type) { this.type = type; } } };
    syncDossierFilters(form, new URL(`${base}?status=visited&service_ids[]=7&planning_user_ids[0]=12&planning_user_ids[1]=18`), browser);
    assert.equal(container.hidden, false);
    assert.equal(fields['planning_user_ids[]'].disabled, false);
    assert.deepEqual(fields['planning_user_ids[]'].options.map(option => option.selected), [true, false, true]);
    assert.deepEqual(fields['service_ids[]'].options.map(option => option.selected), [false, true]);
    syncDossierFilters(form, new URL(`${base}?status=pending&service_ids[]=3&planning_user_ids[]=12`), browser);
    assert.equal(container.hidden, true);
    assert.equal(fields['planning_user_ids[]'].disabled, true);
    assert.deepEqual(fields['planning_user_ids[]'].options.map(option => option.selected), [false, false, false]);
    assert.deepEqual(fields['service_ids[]'].options.map(option => option.selected), [true, false]);
});

function fixture() {
    let id = 0;
    const timers = new Map();
    const requests = [];
    const events = [];
    const browser = {
        clearTimeout: id => timers.delete(id),
        setTimeout(callback, delay) { const key = ++id; timers.set(key, { callback, delay }); return key; },
        fetch(url, options) {
            return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
        },
    };
    const loader = createDossierLoader(browser, {
        pending: () => events.push(['pending']),
        success: (...args) => events.push(['success', ...args]),
        failure: (...args) => events.push(['failure', ...args]),
    });
    const tick = delay => {
        for (const [key, timer] of [...timers]) {
            if (timer.delay === delay) { timers.delete(key); timer.callback(); }
        }
    };
    return { loader, requests, events, timers, tick };
}

test('debounces typing to one request and keeps same origin credentials', async () => {
    const ui = fixture();
    for (const q of ['L', 'Ly', 'Lyon']) ui.loader.load(new URL(`${base}?q=${q}`), { delay: 300 });
    assert.equal(ui.requests.length, 0);
    ui.tick(300);
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].url, `${base}?q=Lyon`);
    assert.equal(ui.requests[0].options.credentials, 'same-origin');
    assert.equal(ui.requests[0].options.headers.Accept, 'application/json');
    ui.requests[0].resolve(response()); await settle();
    assert.deepEqual(ui.events.at(-1).slice(0, 2), ['success', payload]);
    assert.equal(ui.timers.size, 0);
});

test('aborts immediately on new input and ignores stale success even before the next debounce finishes', async () => {
    const ui = fixture();
    ui.loader.load(new URL(base));
    ui.loader.load(new URL(`${base}?q=Lyon`), { delay: 300 });
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].resolve(response()); await settle();
    assert.equal(ui.events.some(event => event[0] === 'success'), false);
    ui.tick(300);
    ui.requests[1].resolve(response()); await settle();
    assert.equal(ui.events.filter(event => event[0] === 'success').length, 1);
});

test('ignores an old error after a more recent response', async () => {
    const ui = fixture();
    ui.loader.load(new URL(base)); ui.loader.load(new URL(`${base}?status=pending`));
    ui.requests[1].resolve(response()); await settle();
    ui.requests[0].reject(new Error('Network error')); await settle();
    assert.equal(ui.events.at(-1)[0], 'success');
    assert.equal(ui.events.filter(event => event[0] === 'failure').length, 0);
});

for (const status of [401, 403, 422, 500]) {
    test(`reports HTTP ${status} without replacing the last successful results`, async () => {
        const ui = fixture();
        ui.loader.load(new URL(base));
        ui.requests[0].resolve({ ok: false, status }); await settle();
        assert.equal(ui.events.at(-1)[0], 'failure');
        assert.equal(ui.events.at(-1)[1].status, status);
        assert.equal(ui.timers.size, 0);
    });
}

test('rejects malformed responses and handles a timed out request', async () => {
    const ui = fixture();
    ui.loader.load(new URL(base));
    ui.requests[0].resolve(response({ results: '<html>login</html>' })); await settle();
    assert.equal(ui.events.at(-1)[0], 'failure');
    ui.loader.load(new URL(base)); ui.tick(20000);
    assert.equal(ui.requests[1].options.signal.aborted, true);
    ui.requests[1].reject(Object.assign(new Error('Abort'), { name: 'AbortError' })); await settle();
    assert.equal(ui.events.at(-1)[0], 'failure');
});

test('cancels pending searches on leaving and does not initialize on unrelated pages', () => {
    const ui = fixture();
    ui.loader.load(new URL(base), { delay: 300 }); ui.loader.cancel(); ui.tick(300);
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.timers.size, 0);
    initDossierList({ querySelector: () => null }, {});
});

test('refreshes the current page after assignment and discards stale reads without losing pending filters', async () => {
    const ui = fixture();
    ui.loader.refresh(new URL(`${base}?status=visited&page=2`));
    assert.equal(ui.requests[0].url, `${base}?status=visited&page=2`);
    ui.loader.load(new URL(`${base}?q=Alice&status=visited`), { delay: 300 });
    ui.loader.refresh(new URL(base));
    assert.equal(ui.requests[1].url, `${base}?q=Alice&status=visited`);
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].resolve(response()); await settle();
    assert.equal(ui.events.some(event => event[0] === 'success'), false);
    ui.requests[1].resolve(response()); await settle();
    assert.equal(ui.events.at(-1)[0], 'success');
    assert.equal(ui.timers.size, 0);
});
