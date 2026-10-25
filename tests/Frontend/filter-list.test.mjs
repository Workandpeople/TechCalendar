import assert from 'node:assert/strict';
import { test } from 'node:test';
import { filterListUrl, createFilterLoader, initFilterLists } from '../../resources/js/filter-list.js';

const settle = () => new Promise(resolve => setImmediate(resolve));
const url = new URL('https://example.test/manager/users');
function fixture() {
    const requests = [], events = [], timers = new Map(); let next = 0;
    const browser = { clearTimeout: id => timers.delete(id), setTimeout(fn, ms) { timers.set(++next, { fn, ms }); return next; },
        fetch(url, options) { return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })); } };
    const loader = createFilterLoader(browser, { pending: () => events.push('pending'), success: () => events.push('success'), failure: () => events.push('failure') });
    const tick = ms => { for (const [id, timer] of [...timers]) if (timer.ms === ms) { timers.delete(id); timer.fn(); } };
    return { requests, events, loader, tick };
}
test('keeps multiple selections and resets pagination when filtering', () => {
    assert.equal(filterListUrl(url, new URLSearchParams('page=3&role=0&department[]=01&department[]=02&q=Test')).search,
        '?department%5B%5D=01&department%5B%5D=02&q=Test&role=0');
});
test('cancels older requests and debounces typing while sending same-origin credentials', async () => {
    const ui = fixture(); ui.loader.load(url); ui.loader.load(url, { delay: 350 });
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].resolve({ ok: true, text: async () => 'old' }); await settle();
    assert.equal(ui.events.includes('success'), false);
    ui.tick(350); ui.requests[1].resolve({ ok: true, text: async () => 'new' }); await settle();
    assert.equal(ui.events.at(-1), 'success');
    assert.equal(ui.requests[1].options.cache, 'no-store');
    assert.equal(ui.requests[1].options.credentials, 'same-origin');
});
test('rejects authentication redirects server failures and request timeouts without replacing results', async () => {
    for (const response of [{ ok: true, redirected: true }, { ok: false, status: 500 }]) {
        const ui = fixture(); ui.loader.load(url); ui.requests[0].resolve(response); await settle();
        assert.equal(ui.events.at(-1), 'failure');
    }
    const ui = fixture(); ui.loader.load(url); ui.tick(20000);
    ui.requests[0].reject(Object.assign(new Error('aborted'), { name: 'AbortError' })); await settle();
    assert.equal(ui.events.at(-1), 'failure');
});

test('paginates an implicit default filter and keeps pending search values on category changes', () => {
    const handlers = {}, requests = [];
    const fields = [{ name: 'status', value: 'active', tagName: 'SELECT', options: [{ value: 'active' }] },
        { name: 'q', value: '', tagName: 'INPUT' }];
    const form = { action: url.href, elements: fields, after() {}, addEventListener() {} };
    const listing = { dataset: { ajaxList: 'users' }, querySelector: () => form, setAttribute() {},
        addEventListener: (name, handler) => { handlers[name] = handler; } };
    const root = { querySelectorAll: () => [listing],
        createElement: () => ({ setAttribute() {}, hidden: false, textContent: '' }) };
    const browser = { location: url, addEventListener() {}, clearTimeout() {}, setTimeout: () => 1,
        FormData: class { *[Symbol.iterator]() { for (const field of fields) yield [field.name, field.value]; } },
        fetch(href) { requests.push(new URL(href)); return new Promise(() => {}); } };
    initFilterLists(root, browser);
    const click = (href, category = false) => {
        let prevented = false;
        const link = { href, target: '', hasAttribute: () => false, matches: () => category,
            closest: () => category ? null : {} };
        handlers.click({ button: 0, target: { closest: () => link }, preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        return requests.at(-1);
    };
    // The rendered default is active even when the initial URL has no status parameter.
    assert.equal(click(`${url}?page=2`).searchParams.get('page'), '2');
    fields[1].value = 'Pending search';
    const filtered = click(`${url}?page=3`);
    assert.equal(filtered.searchParams.has('page'), false);
    assert.equal(filtered.searchParams.get('q'), 'Pending search');
    const category = click(`${url}?status=disabled`, true);
    assert.equal(category.searchParams.get('status'), 'disabled');
    assert.equal(category.searchParams.get('q'), 'Pending search');
});
