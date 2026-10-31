import test from 'node:test';
import assert from 'node:assert/strict';
import { initMymarPending, pendingPage, renderPendingRows } from '../../resources/js/mymar-pending.js';

const page = (after = 0, count = 25, next = null) => ({ format: 'cofrac.mymar-pending-bookings', version: 1, read_only: true,
    checked_at: 1793450000, next_after: next, items: Array.from({ length: count }, (_, i) => ({ dossier_id: after + i + 1,
        status_id: 14, phase: 'after_works', first_name: 'Test', last_name: '<img src=x onerror=alert(1)>',
        address: 'Lieu-dit', postal_code: '01560', city: 'Lescheroux', phone: '0600000000' })) });

function fixture({ hidden = true, url = '/planner/book/mar', fetch } = {}) {
    const nodes = [];
    const document = { createElement(tag) {
        const node = { tag, children: [], attrs: {}, handlers: {}, dataset: {}, ownerDocument: document, hidden: false,
            textContent: '', className: '', disabled: false,
            append(...children) { this.children.push(...children); },
            replaceChildren(...children) { this.children = children; },
            setAttribute(key, value) { this.attrs[key] = value; },
            addEventListener(key, callback) { (this.handlers[key] ??= []).push(callback); },
            dispatch(type) { for (const callback of this.handlers[type] ?? []) callback(); },
            set innerHTML(_) { assert.fail('unsafe HTML rendering'); },
        };
        nodes.push(node);
        return node;
    } };
    const panel = document.createElement('section');
    panel.hidden = hidden;
    panel.dataset.url = url;
    const children = Object.fromEntries(['list', 'status', 'refresh', 'previous', 'next', 'pagination', 'page'].map(key => [key, document.createElement('div')]));
    panel.querySelector = selector => children[selector.replace('[data-mymar-', '').replace(']', '')];
    const root = { querySelector: () => panel };
    const calls = [], timers = new Map();
    const browser = { location: { origin: 'https://cofrac.example' }, AbortController, addEventListener() {},
        setTimeout(callback) { const id = Symbol(); timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch: async (...args) => { calls.push(args); return fetch ? fetch(...args) : response(page()); },
    };
    const activate = () => { panel.hidden = false; panel.dispatch('cofrac:mymar:activate'); };
    const deactivate = () => { panel.hidden = true; panel.dispatch('cofrac:mymar:deactivate'); };
    return { root, panel, children, browser, calls, timers, nodes, activate, deactivate };
}
const response = value => ({ ok: true, redirected: false, headers: new Headers({ 'Content-Type': 'application/json; charset=utf-8' }), json: async () => value });
const settle = () => new Promise(resolve => setImmediate(resolve));

test('pending MAR is deferred, same-origin, non-posting and initializes once', async () => {
    const ui = fixture();
    initMymarPending(ui.root, ui.browser);
    initMymarPending(ui.root, ui.browser);
    assert.equal(ui.calls.length, 0);
    ui.activate(); await settle();
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.calls[0][0], 'https://cofrac.example/planner/book/mar?after=0');
    assert.equal(ui.calls[0][1].credentials, 'same-origin');
    assert.equal(ui.calls[0][1].cache, 'no-store');
    assert.equal(ui.calls[0][1].method, undefined);
    assert.match(ui.children.status.textContent, /25 demande/);
    ui.deactivate(); ui.activate(); await settle();
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.panel.attrs['aria-busy'], 'false');
});

test('external and parameterized endpoints never cause a fetch', async () => {
    for (const url of ['https://evil.test/list', '//evil.test/list', '/list?token=secret', '/list#token', 'https://u:p@cofrac.example/list']) {
        const ui = fixture({ hidden: false, url });
        initMymarPending(ui.root, ui.browser); await settle();
        assert.equal(ui.calls.length, 0);
    }
});

test('renders external text safely and includes town without native booking actions', () => {
    const ui = fixture();
    renderPendingRows(ui.children.list, page(0, 1).items);
    const row = ui.children.list.children[0];
    assert.equal(row.attrs.role, 'listitem');
    assert.equal(row.children[0].textContent, '<img src=x onerror=alert(1)> Test');
    assert.equal(row.children[2].textContent, 'Lieu-dit, 01560 Lescheroux');
    assert.equal(row.tag, 'article');
    assert.equal(row.children.some(node => ['button', 'a', 'form'].includes(node.tag)), false);
});

test('uses keyset pagination with previous navigation and refresh preserving rows', async () => {
    const ui = fixture({ hidden: false, fetch: async url => {
        const cursor = Number(new URL(url).searchParams.get('after'));
        return response(page(cursor, cursor ? 1 : 25, cursor ? null : 25));
    } });
    initMymarPending(ui.root, ui.browser); await settle();
    assert.equal(ui.children.next.disabled, false);
    ui.children.next.dispatch('click'); await settle();
    assert.match(ui.calls[1][0], /after=25$/);
    assert.equal(ui.children.page.textContent, 'Page 2');
    assert.equal(ui.children.previous.disabled, false);
    assert.equal(ui.children.next.disabled, true);
    ui.children.previous.dispatch('click'); await settle();
    assert.equal(ui.children.page.textContent, 'Page 1');
    const row = ui.children.list.children[0];
    ui.children.refresh.dispatch('click');
    assert.equal(ui.children.list.children[0], row);
    await settle();
    assert.match(ui.calls.at(-1)[0], /after=0&refresh=1$/);
});

test('tab deactivation aborts and rejects late responses without replacing the list', async () => {
    let resolve;
    const ui = fixture({ fetch: () => new Promise(done => { resolve = done; }) });
    initMymarPending(ui.root, ui.browser);
    ui.activate();
    ui.deactivate();
    assert.equal(ui.calls[0][1].signal.aborted, true);
    resolve(response(page())); await settle();
    assert.equal(ui.children.list.children.some(row => row.tag === 'article'), false);
    assert.equal(ui.panel.attrs['aria-busy'], 'false');
});

test('distinguishes empty results, HTTP failures, login redirects, bad contracts and timeout', async () => {
    for (const failure of [{ ok: false }, { redirected: true }, { headers: new Headers({ 'Content-Type': 'text/html' }) }, { malformed: true }, { timeout: true }]) {
        const ui = fixture({ hidden: false, fetch: (_url, { signal }) => failure.timeout
            ? new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new Error('timeout'))))
            : Promise.resolve({ ...response(failure.malformed ? {} : page()), ...failure }) });
        initMymarPending(ui.root, ui.browser);
        if (failure.timeout) for (const callback of ui.timers.values()) callback();
        await settle();
        assert.match(ui.children.status.textContent, /indisponible/);
        assert.equal(ui.children.list.children.length, 0);
        assert.equal(ui.children.refresh.disabled, false);
    }
    const ui = fixture({ hidden: false, fetch: async () => response(page(0, 0)) });
    initMymarPending(ui.root, ui.browser); await settle();
    assert.match(ui.children.status.textContent, /Aucune demande/);
});

test('rejects unordered, oversized, mismatched-phase and unsafe cursor pages', () => {
    for (const change of [value => { value.items[0].dossier_id = 0; }, value => { value.items.reverse(); },
        value => { value.items.push(value.items[0]); }, value => { value.items[0].phase = 'initial'; },
        value => { value.next_after = 999; }, value => { value.read_only = false; }]) {
        const value = page(); change(value);
        assert.throws(() => pendingPage(value, 0));
    }
});
