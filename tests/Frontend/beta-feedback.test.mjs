import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initBetaFeedback, screenshotError, newFeedbackId } from '../../resources/js/beta-feedback.js';

const png = { name: 'capture.png', size: 1024, type: 'image/png', lastModified: 123 };

test('validates screenshot count type and per-file size before starting an upload', () => {
    assert.equal(screenshotError([]), '');
    assert.equal(screenshotError([png, { ...png, type: 'image/jpeg' }, { ...png, type: 'image/webp', size: 4 * 1024 ** 2 }]), '');
    assert.match(screenshotError(Array(4).fill(png)), /maximum 3/);
    assert.match(screenshotError([{ ...png, type: 'image/svg+xml' }]), /PNG, JPEG ou WebP/);
    assert.match(screenshotError([{ ...png, size: 4 * 1024 ** 2 + 1 }]), /4 Mo maximum/);
});

test('generates a valid random UUID on mobile browsers without randomUUID', () => {
    assert.equal(newFeedbackId({ getRandomValues: bytes => bytes.fill(7) }), '07070707-0707-4707-8707-070707070707');
    assert.equal(newFeedbackId({ randomUUID: () => 'native-uuid' }), 'native-uuid');
});

function node() {
    const listeners = {};
    return { listeners, dataset: {}, children: [], disabled: false, textContent: '',
        addEventListener: (key, fn) => { (listeners[key] ||= []).push(fn); },
        async emit(type, data = {}) { for (const fn of listeners[type] || []) await fn({ target: this, preventDefault() {}, ...data }); },
        append(...children) { this.children.push(...children); }, replaceChildren() { this.children = []; },
        focus() { this.focused = true; },
    };
}

function harness() {
    const trigger = node(), dialog = node(), form = node(), files = node(), previews = node(), status = node(), submit = node(), fields = node(), close = node(), heading = node();
    files.files = [];
    files.setCustomValidity = value => { files.error = value; };
    form.action = '/support/feedback';
    form.elements = Object.fromEntries(Object.entries({ submission_id: 'initial-id', kind: 'bug', title: 'Problème document',
        message: 'Le document ne se charge pas.', page_path: '/', viewport: '' }).map(([key, value]) => [key, { value }]));
    form.reportValidity = () => !files.error;
    form.reset = () => { form.elements.title.value = ''; form.elements.message.value = ''; files.files = []; };
    dialog.open = false;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.emit('close'); };
    dialog.querySelector = () => heading;
    const widget = node();
    const map = { '[data-beta-open]': trigger, dialog, '[data-beta-form]': form, '[data-beta-files]': files,
        '[data-beta-previews]': previews, '[data-beta-status]': status, '[data-beta-submit]': submit, '[data-beta-fields]': fields };
    widget.querySelector = key => map[key];
    widget.querySelectorAll = () => [close];
    const root = { querySelectorAll: () => [widget], createElement: node };
    const calls = [], revoked = [], created = [];
    let response = { ok: true, status: 200, json: async () => ({ reference: 'server-id', delivery: 'sent', message: 'Signalement transmis.' }) };
    const browser = {
        location: { pathname: '/v2/dossiers/123', search: '?token=DO-NOT-SEND', href: 'https://cofrac.test/v2/dossiers/123?token=DO-NOT-SEND' },
        innerWidth: 390, innerHeight: 844, crypto: { randomUUID: () => `new-id-${calls.length}` },
        URL: { createObjectURL: file => { created.push(file); return `blob:${created.length}`; }, revokeObjectURL: url => revoked.push(url) },
        FormData: class { constructor(source) { this.values = Object.fromEntries(Object.entries(source.elements).map(([key, field]) => [key, field.value])); this.files = [...files.files]; } },
        AbortController, setTimeout: () => 1, clearTimeout() {},
        fetch: async (url, options) => { calls.push({ url, options }); if (response instanceof Error) throw response; return response; },
    };
    initBetaFeedback(root, browser);
    return { root, browser, widget, trigger, dialog, form, files, previews, status, submit, fields, calls, created, revoked,
        respond: next => { response = next; }, open: () => trigger.emit('click'), send: () => form.emit('submit') };
}

test('opening and previewing never send a report and only pathname and viewport are collected', async () => {
    const ui = harness();
    ui.files.files = [png];
    await ui.open();
    assert.equal(ui.calls.length, 0);
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.form.elements.page_path.value, '/v2/dossiers/123');
    assert.equal(ui.form.elements.viewport.value, '390x844');
    assert.equal(ui.previews.children.length, 1);
    ui.dialog.close();
    assert.deepEqual(ui.revoked, ['blob:1']);
    assert.equal(ui.trigger.focused, true);
});

test('submits a multipart snapshot before disabling fields and resets only on confirmed success', async () => {
    const ui = harness();
    await ui.open();
    ui.files.files = [png];
    await ui.send();
    assert.equal(ui.calls[0].options.credentials, 'same-origin');
    assert.equal(ui.calls[0].options.headers.Accept, 'application/json');
    assert.equal(ui.calls[0].options.body.files[0], png);
    assert.equal(ui.calls[0].options.body.values.title, 'Problème document');
    assert.doesNotMatch(JSON.stringify(ui.calls[0].options.body), /DO-NOT-SEND/);
    assert.equal(ui.form.elements.message.value, '');
    assert.equal(ui.form.elements.submission_id.value, 'new-id-1');
    assert.equal(ui.fields.disabled, false);
    assert.match(ui.status.textContent, /Signalement transmis.*server-id/);
});

test('failed requests preserve text files and the idempotency key, edited messages use a new key', async () => {
    const ui = harness();
    await ui.open();
    ui.respond({ ok: false, status: 503, json: async () => ({ message: 'Email indisponible.', reference: 'saved-id' }) });
    ui.files.files = [png];
    await ui.send();
    assert.equal(ui.form.elements.message.value, 'Le document ne se charge pas.');
    assert.equal(ui.files.files[0], png);
    assert.match(ui.status.textContent, /Email indisponible.*saved-id/);
    await ui.send();
    assert.equal(ui.calls[0].options.body.values.submission_id, ui.calls[1].options.body.values.submission_id);
    ui.form.elements.message.value = 'Le document ne se charge toujours pas.';
    await ui.send();
    assert.notEqual(ui.calls[2].options.body.values.submission_id, ui.calls[1].options.body.values.submission_id);
});

test('blocks unsafe images and duplicate binding and shows session failures in French', async () => {
    const ui = harness();
    initBetaFeedback(ui.root, ui.browser);
    ui.files.files = [{ ...png, type: 'image/svg+xml' }];
    await ui.send();
    assert.equal(ui.calls.length, 0);
    assert.equal(ui.trigger.listeners.click.length, 1);
    ui.files.files = [];
    ui.respond({ ok: false, status: 401, json: async () => ({ message: 'Unauthenticated.' }) });
    await ui.send();
    assert.match(ui.status.textContent, /session a expiré/);
    assert.doesNotMatch(ui.status.textContent, /Unauthenticated/);
});

test('double submit while an upload is in flight cannot send twice', async () => {
    const ui = harness();
    let finish;
    ui.browser.fetch = (_url, options) => { ui.calls.push(options); return new Promise(resolve => { finish = resolve; }); };
    const pending = ui.send();
    await ui.send();
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.fields.disabled, true);
    finish({ ok: true, json: async () => ({ reference: 'done', message: 'Envoyé.' }) });
    await pending;
    assert.equal(ui.fields.disabled, false);
});
