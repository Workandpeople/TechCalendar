import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dossierListUrl, dossierServiceIds, syncDossierFilters, createDossierLoader, initDossierList, dossierCategoryScope, selectDossierCategory, setDossierLoadingState, dossierAssignmentAffectsListing, replaceDossierResults } from '../../resources/js/dossier-list.js';

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
        q: ' Lyon ', per_page: '10', service_id: '3', status: '', all_columns: '0', page: '8', ignored: 'yes', direction: 'asc',
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
        pending: (...args) => events.push(['pending', ...args]),
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

test('reuses category counters only when their actual filtering scope is unchanged', () => {
    const current = new URL(`${base}?status=visited&service_ids[]=3&planning_user_ids[]=12`);
    const scope = dossierCategoryScope(current);
    for (const changes of ['&page=3', '&sort=client&direction=desc', '&per_page=100', '&all_columns=1']) {
        assert.equal(dossierCategoryScope(new URL(current.href + changes)), scope);
    }
    for (const changes of ['&q=Lyon', '&department=75', '&date_from=2026-10-01', '&billing=invoiced']) {
        assert.notEqual(dossierCategoryScope(new URL(current.href + changes)), scope);
    }
    assert.notEqual(dossierCategoryScope(new URL(`${base}?status=pending&service_ids[]=3`)), scope);
    assert.notEqual(dossierCategoryScope(new URL(`${base}?status=visited&service_ids[]=3&planning_user_ids[]=15`)), scope);
});

test('canonicalizes implicit defaults and reordered multiselections before deciding whether pagination is stale', () => {
    const rendered = new URL(`${base}?status=visited&service_ids[0]=7&service_ids[1]=3&planning_user_ids[]=15&planning_user_ids[]=12`);
    const form = dossierListUrl(base, new URLSearchParams('status=visited&date_field=created&per_page=10&service_ids[]=3&service_ids[]=7&planning_user_ids[]=12&planning_user_ids[]=15'));
    assert.equal(form.href, dossierListUrl(base, rendered.searchParams).href);
    assert.equal(dossierCategoryScope(form), dossierCategoryScope(rendered));
    assert.equal(dossierCategoryScope(new URL(`${base}?status=pending&date_field=created`)), dossierCategoryScope(new URL(`${base}?status=pending`)));
});

test('requests just one page and permits omitted counters only when explicitly requested', async () => {
    const ui = fixture();
    const url = new URL(`${base}?status=visited&page=3&per_page=10`);
    ui.loader.load(url, { categories: false });
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].url, url.href);
    assert.equal(ui.requests[0].options.headers['X-Dossier-Categories'], 'unchanged');
    ui.requests[0].resolve(response({ ...payload, categories: null })); await settle();
    assert.equal(ui.events.at(-1)[0], 'success');
    assert.equal(ui.requests.length, 1); // No prefetch of other pages.

    ui.loader.load(new URL(`${base}?status=pending`));
    assert.equal(ui.requests[1].options.headers['X-Dossier-Categories'], undefined);
    ui.requests[1].resolve(response({ ...payload, categories: null })); await settle();
    assert.equal(ui.events.at(-1)[0], 'failure');
});

test('refreshes counters after a planning write even if the previous request only changed page', async () => {
    const ui = fixture();
    ui.loader.load(new URL(`${base}?status=visited&page=2`), { categories: false });
    ui.requests[0].resolve(response({ ...payload, categories: null })); await settle();
    ui.loader.refresh(new URL(base));
    assert.equal(ui.requests[1].options.headers['X-Dossier-Categories'], undefined);
    ui.requests[1].resolve(response()); await settle();
    assert.equal(ui.events.at(-1)[0], 'success');
});

test('selects the requested category immediately and can restore the last rendered category on failure', () => {
    const links = ['all', 'pending', 'visited'].map(category => ({
        dataset: { category }, current: undefined,
        setAttribute(name, value) { this.current = value; }, removeAttribute() { this.current = undefined; },
    }));
    const categories = { querySelectorAll: () => links };
    selectDossierCategory(categories, new URL(`${base}?status=visited&page=3&per_page=50&planning_user_ids[]=12`));
    assert.deepEqual(links.map(link => link.current), [undefined, undefined, 'page']);
    assert.equal(new URL(links[1].href).searchParams.get('per_page'), '50');
    assert.equal(new URL(links[1].href).searchParams.has('page'), false);
    assert.equal(new URL(links[1].href).searchParams.has('planning_user_ids[]'), false);
    assert.deepEqual(new URL(links[2].href).searchParams.getAll('planning_user_ids[]'), ['12']);
    selectDossierCategory(categories, new URL(`${base}?status=pending`));
    assert.deepEqual(links.map(link => link.current), [undefined, 'page', undefined]);
    selectDossierCategory(categories, new URL(base));
    assert.deepEqual(links.map(link => link.current), ['page', undefined, undefined]);
});

test('shows an accessible skeleton immediately without discarding the previous results', () => {
    const attributes = new Map();
    const results = { hidden: false, innerHTML: 'previous page', getBoundingClientRect: () => ({ height: 720 }),
        setAttribute: (name, value) => attributes.set(`results:${name}`, value) };
    const stage = { setAttribute: (name, value) => attributes.set(`stage:${name}`, value) };
    const skeleton = { hidden: true, style: {} };
    setDossierLoadingState(results, skeleton, stage, true);
    assert.equal(skeleton.hidden, false);
    assert.equal(results.hidden, true);
    assert.equal(skeleton.style.minHeight, '720px');
    assert.equal(attributes.get('stage:aria-busy'), 'true');
    setDossierLoadingState(results, skeleton, stage, true);
    assert.equal(skeleton.style.minHeight, '720px');
    setDossierLoadingState(results, skeleton, stage, false);
    assert.equal(skeleton.hidden, true);
    assert.equal(results.hidden, false);
    assert.equal(results.innerHTML, 'previous page');
    assert.equal(attributes.get('stage:aria-busy'), 'false');
});

test('passes the requested category to the immediate pending state and ignores an older category response', async () => {
    const ui = fixture();
    const first = new URL(`${base}?status=visited`), latest = new URL(`${base}?status=report`);
    ui.loader.load(first);
    assert.equal(ui.events[0][1].href, first.href);
    ui.loader.load(latest);
    assert.equal(ui.events.at(-1)[1].href, latest.href);
    ui.requests[0].resolve(response()); await settle();
    assert.equal(ui.events.some(event => event[0] === 'success'), false);
    ui.requests[1].resolve(response()); await settle();
    assert.equal(ui.events.at(-1)[2].href, latest.href);
});

test('keeps quiet refreshes visible and reserves skeletons for explicit navigation', () => {
    const results = { hidden: false, setAttribute() {}, getBoundingClientRect() { throw new Error('No skeleton measurement'); } };
    const skeleton = { hidden: true, style: {} };
    setDossierLoadingState(results, skeleton, null, true, true);
    assert.equal(skeleton.hidden, true);
    assert.equal(results.hidden, false);
    setDossierLoadingState(results, skeleton, null, false, true);
    assert.equal(results.hidden, false);
});

test('requires a listing read only for assignment-dependent filters or sorting', () => {
    for (const params of ['status=visited', 'status=visited&page=3&sort=modified_at', 'status=visited&q=Paris&planning=unlinked']) {
        assert.equal(dossierAssignmentAffectsListing(new URL(`${base}?${params}`)), false);
    }
    for (const params of ['status=visited&sort=planning_user', 'status=visited&planning_user_ids[]=12', 'status=visited&planning_user_ids[0]=12']) {
        assert.equal(dossierAssignmentAffectsListing(new URL(`${base}?${params}`)), true);
    }
});

test('refreshes quietly on the same page but never hides pending navigation behind a write refresh', async () => {
    const ui = fixture(), current = new URL(`${base}?status=visited&page=3`);
    ui.loader.refresh(current, { quiet: true });
    assert.equal(ui.events[0][2].quiet, true);
    assert.equal(ui.events[0][2].scroll, false);
    assert.equal(ui.loader.isLoading, true);
    ui.requests[0].resolve(response()); await settle();
    assert.equal(ui.loader.isLoading, false);
    ui.loader.load(new URL(`${base}?status=report`), { delay: 300 });
    ui.loader.refresh(current, { quiet: true });
    assert.equal(ui.events.at(-1)[2].quiet, false);
    assert.equal(ui.requests[1].url, `${base}?status=report`);
    ui.requests[1].resolve(response()); await settle();
    assert.equal(ui.loader.isLoading, false);
});

test('quiet replacements preserve height, focus and both page and table scroll positions', () => {
    const assignment = { dataset: { assignmentId: '7' } };
    const focused = { closest: selector => selector === '[data-planning-assignment]' ? assignment : null };
    const focusCalls = [], scrollCalls = [];
    const target = { focus: options => focusCalls.push(options) };
    const table = { scrollLeft: 420 };
    const ancestor = { scrollLeft: 2, scrollTop: 640, parentElement: null };
    const results = { parentElement: ancestor, contains: node => node === focused, style: {},
        getBoundingClientRect: () => ({ height: 720.1 }), querySelector: selector => selector === '.gc-dossier-table-scroll' ? table : target,
        set innerHTML(value) { this.html = value; ancestor.scrollTop = 0; table.scrollLeft = 0; } };
    replaceDossierResults(results, '<table>new</table>', { activeElement: focused }, {
        scrollX: 0, scrollY: 500, CSS: { escape: value => value }, scrollTo: position => scrollCalls.push(position),
    }, true);
    assert.equal(results.style.minHeight, '721px');
    assert.equal(results.html, '<table>new</table>');
    assert.equal(table.scrollLeft, 420);
    assert.equal(ancestor.scrollTop, 640);
    assert.equal(ancestor.scrollLeft, 2);
    assert.deepEqual(focusCalls, [{ preventScroll: true }]);
    assert.deepEqual(scrollCalls, [{ left: 0, top: 500, behavior: 'instant' }]);
    replaceDossierResults(results, '<table>other page</table>', {}, {}, false);
    assert.equal(results.style.minHeight, '');
});
