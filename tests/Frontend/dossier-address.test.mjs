import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createAddressSearch, initDossierAddress } from '../../resources/js/dossier-address.js';

const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

function browserFixture() {
    let sequence = 0;
    const timers = new Map(), requests = [];
    const browser = {
        location: { href: 'https://example.invalid/v2/dossiers/1' }, addEventListener() {},
        setTimeout(fn, delay) { const id = ++sequence; timers.set(id, { fn, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                requests.push({ url, options, resolve, reject });
                options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
            });
        },
    };
    const run = delay => {
        for (const [id, timer] of timers) if (timer.delay === delay) { timers.delete(id); timer.fn(); return; }
    };
    return { browser, timers, requests, run };
}

const response = (suggestions = [{ id: 'address.1', label: '8 rue Test, Paris' }], status = 200) => ({
    ok: status === 200, status, json: async () => ({ suggestions }),
});

test('debounces the address lookup and cancels older suggestions immediately on input', async () => {
    const ui = browserFixture(), values = [], errors = [];
    const loader = createAddressSearch(ui.browser, { pending() {}, success: data => values.push(data), failure: error => errors.push(error) });
    loader.search(new URL('https://example.invalid?q=Paris'));
    assert.equal(ui.requests.length, 0);
    ui.run(300);
    assert.equal(ui.requests.length, 1);
    loader.search(new URL('https://example.invalid?q=Lyon'));
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].resolve(response());
    await settle();
    assert.deepEqual(values, []);
    ui.run(300);
    ui.requests[1].resolve(response([{ id: 'address.2', label: 'Lyon' }]));
    await settle();
    assert.equal(values[0][0].label, 'Lyon');
    assert.deepEqual(errors, []);
    assert.equal(ui.requests[1].options.credentials, 'same-origin');
});

test('handles expired sessions, malformed payloads and timeouts without retaining old options', async () => {
    for (const status of [401, 403, 419]) {
        const ui = browserFixture(), errors = [];
        const loader = createAddressSearch(ui.browser, { pending() {}, success: () => assert.fail(), failure: error => errors.push(error) });
        loader.search(new URL('https://example.invalid?q=Paris')); ui.run(300);
        ui.requests[0].resolve(response([], status)); await settle();
        assert.equal(errors.length, 1);
    }
    const ui = browserFixture(), errors = [];
    const loader = createAddressSearch(ui.browser, { pending() {}, success: () => assert.fail(), failure: error => errors.push(error) });
    loader.search(new URL('https://example.invalid?q=Paris')); ui.run(300); ui.run(12000); await settle();
    assert.match(errors[0], /expiré/);
    loader.search(new URL('https://example.invalid?q=Paris')); ui.run(300);
    ui.requests[1].resolve({ ok: true, status: 200, json: async () => ({ suggestions: 'invalid' }) }); await settle();
    assert.match(errors[1], /invalide/);
});

function editorFixture() {
    const ui = browserFixture();
    class Node {
        constructor() { this.handlers = {}; this.attrs = new Map(); this.children = []; this.hidden = true; this.value = ''; }
        addEventListener(name, fn) { this.handlers[name] = fn; }
        setAttribute(key, value) { this.attrs.set(key, String(value)); }
        removeAttribute(key) { this.attrs.delete(key); }
        getAttribute(key) { return this.attrs.get(key); }
        replaceChildren(...children) { this.children = children; }
        focus() { ui.focused = this; }
        scrollIntoView() {}
        set innerHTML(_) { assert.fail('Suggestions must use textContent, not HTML.'); }
    }
    const input = new Node(), identifier = new Node(), list = new Node(), status = new Node(), submit = new Node(), dialog = new Node();
    list.id = 'dossier-address-options'; input.value = 'Adresse initiale';
    const form = new Node();
    form.elements = { address_query: input, mapbox_id: identifier };
    form.dataset = { suggestionsUrl: 'https://example.invalid/v2/dossiers/1/adresse/suggestions' };
    form.querySelector = selector => selector === '[data-address-status]' ? status : selector === '[role="listbox"]' ? list : submit;
    form.closest = () => dialog;
    form.contains = node => [input, identifier, list, status, submit].includes(node);
    const root = { querySelectorAll: () => [form], createElement: () => new Node() };
    initDossierAddress(root, ui.browser);
    const key = value => {
        const event = { key: value, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
        input.handlers.keydown(event);
        return event;
    };
    return { ...ui, input, identifier, list, status, submit, dialog, form, key };
}

test('selects Mapbox suggestions with the keyboard, escapes HTML and clears stale place IDs when typing', async () => {
    const ui = editorFixture();
    ui.input.value = '8 rue'; ui.input.handlers.input(); ui.run(300);
    ui.requests[0].resolve(response([{ id: 'address.1', label: '<img src=x onerror=alert(1)>' }])); await settle();
    assert.equal(ui.list.hidden, false);
    assert.equal(ui.list.children[0].textContent, '<img src=x onerror=alert(1)>');
    ui.key('ArrowDown');
    assert.equal(ui.input.getAttribute('aria-activedescendant'), 'dossier-address-options-0');
    const enter = ui.key('Enter');
    assert.equal(enter.prevented, true);
    assert.equal(ui.identifier.value, 'address.1');
    assert.equal(ui.list.hidden, true);
    ui.input.value = 'Autre adresse'; ui.input.handlers.input();
    assert.equal(ui.identifier.value, '');
});

test('Escape dismisses suggestions without closing the modal and close cancels pending lookups', async () => {
    const ui = editorFixture();
    ui.input.value = '8 rue'; ui.input.handlers.input(); ui.run(300);
    ui.requests[0].resolve(response()); await settle();
    assert.equal(ui.key('Escape').stopped, true);
    assert.equal(ui.list.hidden, true);
    ui.input.value = '8 rue autre'; ui.input.handlers.input(); ui.run(300);
    ui.dialog.handlers.close(); await settle();
    assert.equal(ui.requests[1].options.signal.aborted, true);
    assert.equal(ui.list.hidden, true);
});

test('submit validates manually edited text rather than a previously selected place ID', async () => {
    const ui = editorFixture();
    ui.input.value = '8 rue'; ui.input.handlers.input(); ui.run(300);
    ui.requests[0].resolve(response()); await settle();
    ui.list.children[0].handlers.click();
    assert.equal(ui.identifier.value, 'address.1');
    ui.input.value = 'Adresse changée sans événement input';
    ui.form.handlers.submit();
    assert.equal(ui.identifier.value, '');
    assert.equal(ui.submit.disabled, true);
    assert.match(ui.status.textContent, /recalcul/);
});

test('creation fallback reveals postcode and city and selects an explicitly approximate location safely', async () => {
    const { initCreationAddress } = await import('../../resources/js/dossier-address.js');
    const ui = browserFixture();
    class Node {
        constructor() { this.value = ''; this.handlers = {}; this.attrs = new Map(); this.children = []; this.hidden = false; }
        addEventListener(name, fn) { this.handlers[name] = fn; }
        setAttribute(key, value) { this.attrs.set(key, String(value)); }
        removeAttribute(key) { this.attrs.delete(key); }
        replaceChildren(...children) { this.children = children; }
        focus() {} scrollIntoView() {} setCustomValidity(value) { this.validity = value; } reportValidity() {}
        set innerHTML(value) { assert.fail('Unsafe HTML rendering'); }
    }
    const fields = Object.fromEntries(['address', 'postal_code', 'city', 'mapbox_id', 'address_precision'].map(key => [key, new Node()]));
    fields.address.value = 'Lieu-dit introuvable'; fields.address_precision.value = 'address';
    const form = new Node(); form.elements = fields;
    const elements = Object.fromEntries(['[data-address-fallback]', '[data-address-locality]', '[data-address-approximate]', '[data-creation-address-status]', '#creation-address-options', '#creation-city-options'].map(selector => [selector, new Node()]));
    elements['#creation-address-options'].id = 'address-options'; elements['#creation-city-options'].id = 'city-options';
    const section = new Node(); section.dataset = { suggestionsUrl: '/espace-client/dossiers/adresse/suggestions' };
    section.closest = () => form; section.contains = () => true; section.querySelector = selector => elements[selector];
    initCreationAddress({ querySelectorAll: () => [section], createElement: () => new Node() }, ui.browser);
    assert.equal(elements['[data-address-locality]'].hidden, true);
    assert.equal(fields.city.disabled, true);
    elements['[data-address-fallback]'].handlers.click();
    assert.equal(elements['[data-address-locality]'].hidden, false);
    assert.equal(fields.address.required, false);
    assert.equal(fields.postal_code.required, true);
    fields.postal_code.value = '01560'; fields.city.value = 'Lescheroux';
    fields.city.handlers.input(); ui.run(300);
    assert.match(ui.requests[0].url, /mode=city/);
    assert.match(ui.requests[0].url, /01560/);
    ui.requests[0].resolve(response([{ id: 'commune.01212_01560', label: '<img onerror=alert(1)>', feature_type: 'place', postal_code: '01560', city: 'Lescheroux' }]));
    await settle();
    const option = elements['#creation-city-options'].children[0];
    assert.match(option.textContent, /<img/);
    option.handlers.click();
    assert.equal(fields.mapbox_id.value, 'commune.01212_01560');
    assert.equal(fields.address_precision.value, 'city');
    assert.equal(fields.address.value, 'Lieu-dit introuvable');
    assert.equal(elements['[data-address-approximate]'].hidden, false);
    fields.city.handlers.input();
    assert.equal(fields.mapbox_id.value, '');
});

test('keeps a canonical commune selection valid when the correction form is submitted', async () => {
    const ui = editorFixture();
    ui.input.value = '32380 BIVE'; ui.input.handlers.input(); ui.run(300);
    ui.requests[0].resolve(response([{ id: 'commune.32055_32380', label: '32380 Bivès, Gers, France', feature_type: 'place' }]));
    await settle();
    ui.list.children[0].handlers.click();
    assert.equal(ui.input.value, '32380 Bivès, Gers, France');
    assert.match(ui.status.textContent, /Commune sélectionnée/);
    ui.input.handlers.change();
    ui.form.handlers.submit();
    assert.equal(ui.identifier.value, 'commune.32055_32380');
});
