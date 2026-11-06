import assert from 'node:assert/strict';
import { test } from 'node:test';
import { exportDownloadUrl, initLotReportExports } from '../../resources/js/lot-report-exports.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
const reply = (data, status = 200) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: async () => data });

function fixture() {
    const element = () => ({ dataset: {}, handlers: {}, children: [], disabled: false, attributes: {},
        addEventListener(event, listener) { this.handlers[event] = listener; },
        setAttribute(name, value) { this.attributes[name] = value; },
        append(...nodes) { this.children.push(...nodes); }, replaceChildren(...nodes) { this.children = nodes; },
        querySelectorAll() { return this.children; }, focus() {} });
    const dialog = element(); dialog.open = false;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.handlers.close?.(); };
    const fields = Object.fromEntries(['open', 'search', 'options', 'retry', 'submit', 'feedback', 'recent', 'selected', 'refresh', 'form'].map(key => [key, element()]));
    fields.search.value = '';
    const close = element(); dialog.querySelector = () => close;
    const section = element(); section.dataset = { listUrl: '/espace-client/rapports-lots', lotsUrl: '/espace-client/rapports-lots/selection', createUrl: '/espace-client/rapports-lots', initialLot: 'LOT 299' };
    section.querySelector = query => query === 'dialog' ? dialog : fields[query.replace('[data-export-', '').replace(']', '')];
    const rootHandlers = {};
    const root = { hidden: false, querySelectorAll: () => [section], querySelector: () => ({ content: 'csrf-test' }),
        createElement: element, addEventListener(event, handler) { rootHandlers[event] = handler; } };
    const requests = [], timers = new Map(); let next = 1;
    let lots = ['LOT 299'], exports = [], post = { message: 'Demande enregistrée.' }, custom;
    const browser = { location: { href: 'https://cofrac.test/espace-client', origin: 'https://cofrac.test' },
        setTimeout(fn, delay) { const id = next++; timers.set(id, { fn, delay }); return id; }, clearTimeout(id) { timers.delete(id); },
        async fetch(url, init) {
            requests.push({ url, init });
            if (custom) return custom(url, init);
            if (init.method === 'POST') return reply(post, 202);
            return reply(String(url).includes('/selection') ? { lots } : { exports });
        } };
    initLotReportExports(root, browser);
    return { ...fields, dialog, close, root, browser, section, requests, timers, rootHandlers,
        setLots(value) { lots = value; }, setExports(value) { exports = value; }, setPost(value) { post = value; }, setFetch(value) { custom = value; },
        async openDialog() { fields.open.handlers.click({ currentTarget: fields.open }); await flush(); },
        async tick(delay) { const timer = [...timers].find(([, timer]) => timer.delay === delay); assert.ok(timer, `missing ${delay}ms timer`); timers.delete(timer[0]); await timer[1].fn(); await flush(); },
        async submitForm() { await fields.form.handlers.submit({ preventDefault() {} }); await flush(); },
    };
}

test('loads only on explicit open, requires an exact selection, and posts once with CSRF', async () => {
    const ui = fixture();
    assert.equal(ui.requests.length, 0);
    await ui.openDialog();
    assert.equal(ui.requests.length, 2);
    assert.equal(ui.selected.textContent, 'Lot sélectionné : LOT 299');
    await ui.submitForm();
    const post = ui.requests.filter(request => request.init.method === 'POST');
    assert.equal(post.length, 1);
    assert.equal(post[0].init.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.deepEqual(JSON.parse(post[0].init.body), { lot: 'LOT 299' });
    assert.equal(ui.feedback.textContent, 'Demande enregistrée.');
    ui.search.value = '299'; ui.search.handlers.input();
    assert.equal(ui.submit.disabled, true);
    await ui.tick(300);
    assert.equal(ui.selected.textContent, 'Aucun lot sélectionné.');
    ui.options.children[0].handlers.click();
    assert.equal(ui.selected.textContent, 'Lot sélectionné : LOT 299');
});

test('polls only while open and pending, pauses hidden pages, and resumes without creating another export', async () => {
    const ui = fixture();
    ui.setExports([{ lot: 'LOT 299', status: 'queued', message: 'En attente' }]);
    await ui.openDialog();
    assert.ok([...ui.timers.values()].some(timer => timer.delay === 5000));
    ui.root.hidden = true; ui.rootHandlers.visibilitychange();
    assert.equal(ui.timers.size, 0);
    ui.root.hidden = false; ui.rootHandlers.visibilitychange(); await flush();
    ui.setExports([{ lot: '<script>not HTML</script>', status: 'ready', message: '1 rapport prêt', file_count: 1,
        download_url: '/espace-client/rapports-lots/12345678-1234-1234-1234-123456789abc/telecharger' }]);
    await ui.tick(5000);
    assert.equal(ui.recent.children[0].children[0].textContent, '<script>not HTML</script>');
    assert.equal(ui.recent.children[0].children[2].href, 'https://cofrac.test/espace-client/rapports-lots/12345678-1234-1234-1234-123456789abc/telecharger');
    assert.equal(ui.timers.size, 0);
    ui.close.handlers.click();
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.requests.filter(request => request.init.method === 'POST').length, 0);
});

test('never follows foreign or arbitrary download URLs', () => {
    const origin = 'https://cofrac.test';
    for (const url of ['https://evil.test/file.zip', 'javascript:alert(1)', '/espace-client', '/espace-client/rapports-lots/../login',
        '/espace-client/rapports-lots/12345678-1234-1234-1234-123456789abc/telecharger?secret=1']) {
        assert.equal(exportDownloadUrl(url, origin), null);
    }
});

test('explains request failures and does not automatically retry a POST', async () => {
    const ui = fixture(); await ui.openDialog();
    ui.setFetch(async (url, init) => init.method === 'POST'
        ? reply({ errors: { lot: ['Une archive est déjà en préparation.'] } }, 422) : reply({ exports: [] }));
    await ui.submitForm();
    assert.equal(ui.feedback.textContent, 'Une archive est déjà en préparation.');
    assert.equal(ui.requests.filter(request => request.init.method === 'POST').length, 1);
    ui.setFetch(async () => ({ ok: false, status: 503, headers: { get: () => 'text/html' } }));
    await ui.refresh.handlers.click();
    assert.match(ui.feedback.textContent, /HTTP 503/);
    assert.equal(ui.timers.size, 0);
});

test('distinguishes a search timeout from voluntary cancellation and provides an explicit retry', async () => {
    const ui = fixture();
    ui.setFetch((url, init) => String(url).includes('/selection')
        ? new Promise((resolve, reject) => init.signal.addEventListener('abort', () => {
            const error = new Error('aborted'); error.name = 'AbortError'; reject(error);
        })) : Promise.resolve(reply({ exports: [] })));
    await ui.openDialog();
    await ui.tick(15000);
    assert.match(ui.options.textContent, /trop de temps/);
    assert.equal(ui.retry.hidden, false);
    ui.setFetch(async () => reply({ lots: ['LOT 299'] }));
    await ui.retry.handlers.click();
    assert.equal(ui.retry.hidden, true);
    assert.equal(ui.options.children[0].textContent, 'LOT 299');
});
