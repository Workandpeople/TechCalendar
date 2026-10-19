import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reportActions, documentPreviewKind, initDocumentPreview } from '../../resources/js/dossier-documents.js';

test('report actions follow existing states instead of permitting arbitrary status jumps', () => {
    assert.deepEqual(reportActions('visited'), ['store', 'submit_control']);
    assert.deepEqual(reportActions('rt_control'), ['store', 'revise_control', 'validate_report']);
    assert.deepEqual(reportActions('rt_control', false), ['store']);
    assert.deepEqual(reportActions('recontrol'), ['store', 'validate_report']);
    for (const state of ['pending', 'awaiting_visit', 'non_compliant', 'report', 'invoiced', undefined]) {
        assert.deepEqual(reportActions(state), ['store']);
    }
});

function response(mime = 'application/pdf', disposition = 'inline; filename="report.pdf"', extra = {}) {
    return { ok: true, redirected: false, headers: new Headers({ 'Content-Type': mime, 'Content-Disposition': disposition }), ...extra };
}

function element() {
    return {
        handlers: {}, attributes: {}, children: [], hidden: false,
        addEventListener(type, handler) { this.handlers[type] = handler; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this[name]; delete this.attributes[name]; },
        replaceChildren(...children) { this.children = children; },
    };
}

function previewHarness(fetch = async () => response()) {
    const content = element();
    const status = element();
    const title = element();
    const download = element();
    const external = element();
    const close = element();
    const owner = { ...element(), createElement: (tagName) => ({ ...element(), tagName }) };
    const dialog = { ...element(), open: false, ownerDocument: owner,
        showModal() { this.open = true; },
        close() { this.open = false; this.handlers.close(); },
        querySelector: (selector) => ({ '[data-preview-content]': content, '[data-preview-status]': status,
            '[data-preview-title]': title, '[data-preview-download]': download,
            '[data-preview-external]': external, '[data-preview-close]': close })[selector],
    };
    const link = { ...element(), href: 'https://app.test/document/1?preview=1',
        dataset: { documentTitle: '<script>not markup</script>', documentDownload: 'https://app.test/document/1' },
        focus() { this.focused = true; },
    };
    const timers = new Map();
    let nextTimer = 0;
    const calls = [];
    const browser = { ...element(), location: new URL('https://app.test/dossier/1'), navigator: { pdfViewerEnabled: true },
        fetch: (...args) => { calls.push(args); return fetch(...args); },
        setTimeout: (callback) => { const id = ++nextTimer; timers.set(id, callback); return id; },
        clearTimeout: (id) => timers.delete(id),
    };
    initDocumentPreview({ querySelector: () => dialog, querySelectorAll: () => [link] }, browser);
    const click = (extra = {}) => link.handlers.click({ button: 0, preventDefault() { this.defaultPrevented = true; }, ...extra });
    return { content, status, title, download, external, close, owner, dialog, link, browser, calls, timers, click };
}

test('only successful inline PDF and raster responses can become preview elements', () => {
    assert.equal(documentPreviewKind(response()), 'iframe');
    for (const mime of ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'IMAGE/PNG; charset=binary']) {
        assert.equal(documentPreviewKind(response(mime)), 'img');
    }
    for (const mime of ['text/html', 'image/svg+xml', 'application/octet-stream', 'application/zip', 'text/plain']) {
        assert.equal(documentPreviewKind(response(mime)), null);
    }
    for (const invalid of [response('application/pdf', 'attachment'), response('application/pdf', ''),
        response('application/pdf', 'inline-bogus'), response('application/pdf', 'inline', { ok: false }),
        response('application/pdf', 'inline', { redirected: true })]) assert.equal(documentPreviewKind(invalid), null);
});

test('preflights with same-origin HEAD then embeds a PDF without a blocking sandbox or blob copy', async () => {
    const ui = previewHarness();
    await ui.click();
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.title.textContent, ui.link.dataset.documentTitle);
    assert.equal(ui.external.href, ui.link.href);
    assert.equal(ui.download.href, ui.link.dataset.documentDownload);
    const [url, options] = ui.calls[0];
    assert.equal(url, ui.link.href);
    assert.equal(options.method, 'HEAD');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.redirect, 'error');
    const media = ui.content.children[0];
    assert.equal(media.tagName, 'iframe');
    assert.equal(media.src, ui.link.href);
    assert.equal(media.title, ui.link.dataset.documentTitle);
    assert.equal(media.sandbox, undefined);
    media.handlers.load();
    assert.equal(ui.status.hidden, true);
    assert.equal(ui.dialog.attributes['aria-busy'], 'false');
    assert.equal(ui.timers.size, 0);
    ui.close.handlers.click();
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.content.children.length, 0);
    assert.equal(media.src, undefined);
    assert.equal(options.signal.aborted, true);
    assert.equal(ui.link.focused, true);
});

test('renders an image directly and reports decoding errors with accessible fallback links', async () => {
    const ui = previewHarness(async () => response('image/png'));
    await ui.click();
    const media = ui.content.children[0];
    assert.equal(media.tagName, 'img');
    assert.equal(media.alt, ui.link.dataset.documentTitle);
    media.handlers.error();
    assert.equal(ui.status.hidden, false);
    assert.match(ui.status.textContent, /Aperçu indisponible/);
    assert.equal(ui.content.children.length, 0);
    assert.equal(ui.dialog.attributes['aria-busy'], 'false');
});

test('never embeds attachments, login pages, permission errors or network failures', async () => {
    for (const fetch of [async () => response('text/html'), async () => response('application/pdf', 'attachment'),
        async () => response('application/pdf', 'inline', { ok: false }), async () => { throw new Error('Network or redirect'); }]) {
        const ui = previewHarness(fetch);
        await ui.click();
        assert.equal(ui.content.children.length, 0);
        assert.equal(ui.status.hidden, false);
        assert.match(ui.status.textContent, /Aperçu indisponible/);
        assert.equal(ui.timers.size, 0);
    }
});

test('offers fallback instead of embedding on browsers without a native PDF viewer', async () => {
    const ui = previewHarness();
    ui.browser.navigator.pdfViewerEnabled = false;
    await ui.click();
    assert.equal(ui.content.children.length, 0);
    assert.match(ui.status.textContent, /Aperçu indisponible/);
});

test('preserves modified clicks and refuses cross-origin, credentialed or non-HTTP URLs', async () => {
    const ui = previewHarness();
    for (const extra of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { shiftKey: true }, { button: 1 }, { defaultPrevented: true }]) {
        await ui.click(extra);
    }
    for (const href of ['https://other.test/file.pdf', 'javascript:alert(1)', 'data:application/pdf,test', 'https://user:pass@app.test/file']) {
        ui.link.href = href;
        await ui.click();
    }
    ui.link.href = 'https://app.test/file';
    ui.link.dataset.documentDownload = 'https://other.test/file';
    await ui.click();
    assert.equal(ui.calls.length, 0);
    assert.equal(ui.dialog.open, false);
});

test('discards a response received after closing or after a newer preview was opened', async () => {
    const pending = [];
    const ui = previewHarness(() => new Promise((resolve) => pending.push(resolve)));
    const first = ui.click();
    ui.dialog.close();
    pending[0](response());
    await first;
    assert.equal(ui.content.children.length, 0);
    const second = ui.click();
    const third = ui.click();
    pending[2](response('image/png'));
    await third;
    pending[1](response());
    await second;
    assert.equal(ui.content.children[0].tagName, 'img');
    assert.equal(ui.calls[1][1].signal.aborted, true);
});

test('times out hung requests or media and cleans up on page exit', async () => {
    const ui = previewHarness(() => new Promise(() => {}));
    void ui.click();
    [...ui.timers.values()][0]();
    assert.equal(ui.calls[0][1].signal.aborted, true);
    assert.match(ui.status.textContent, /trop de temps/);
    const ready = previewHarness();
    await ready.click();
    [...ready.timers.values()][0]();
    assert.equal(ready.content.children.length, 0);
    await ready.click();
    ready.browser.handlers.pagehide();
    assert.equal(ready.content.children.length, 0);
    assert.equal(ready.timers.size, 0);
});

test('reports an enforced parent CSP block but ignores report-only violations', async () => {
    const ui = previewHarness();
    await ui.click();
    const event = { disposition: 'report', effectiveDirective: 'frame-src', blockedURI: ui.link.href };
    ui.owner.handlers.securitypolicyviolation(event);
    assert.equal(ui.content.children.length, 1);
    ui.owner.handlers.securitypolicyviolation({ ...event, disposition: 'enforce' });
    assert.equal(ui.content.children.length, 0);
    assert.match(ui.status.textContent, /Aperçu indisponible/);
});

test('retains native links when modal dialogs or fetch are unavailable', () => {
    for (const dialog of [null, {}]) {
        initDocumentPreview({ querySelector: () => dialog }, {});
    }
});
