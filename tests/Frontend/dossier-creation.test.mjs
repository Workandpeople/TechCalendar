import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initDossierCreation } from '../../resources/js/dossier-creation.js';

function fixture({ admin = true, checked = false, valid = true, accepted = true } = {}) {
    const handlers = [], confirmations = [];
    const override = { checked };
    const form = { dataset: {}, querySelector: () => admin ? override : null, reportValidity: () => valid,
        addEventListener: (name, handler) => handlers.push(handler) };
    const root = { querySelectorAll: () => [form] };
    const browser = { confirm: message => { confirmations.push(message); return accepted; },
        fetch() { assert.fail('Duplicate confirmation never sends a request'); } };
    initDossierCreation(root, browser);
    return { form, root, browser, confirmations, override, handlers,
        submit(defaultPrevented = false) {
            const event = { defaultPrevented, preventDefault() { this.defaultPrevented = true; } };
            for (const handler of handlers) handler(event);
            return event;
        } };
}

test('only the explicit admin override requires confirmation and initialization is idempotent', () => {
    const normal = fixture();
    assert.equal(normal.submit().defaultPrevented, false);
    assert.equal(normal.confirmations.length, 0);
    const external = fixture({ admin: false });
    assert.equal(external.handlers.length, 0);
    const admin = fixture({ checked: true });
    initDossierCreation(admin.root, admin.browser);
    assert.equal(admin.handlers.length, 1);
    assert.equal(admin.submit().defaultPrevented, false);
    assert.equal(admin.confirmations.length, 1);
    assert.match(admin.confirmations[0], /même téléphone ou la même adresse/);
    assert.match(admin.confirmations[0], /historique/);
});

test('cancel leaves the form and its values available without sending anything', () => {
    const ui = fixture({ checked: true, accepted: false });
    assert.equal(ui.submit().defaultPrevented, true);
    assert.equal(ui.override.checked, true);
    assert.equal(ui.submit().defaultPrevented, true);
    assert.equal(ui.confirmations.length, 2);
});

test('does not show a confirmation after another validation has blocked submission', () => {
    const blocked = fixture({ checked: true });
    assert.equal(blocked.submit(true).defaultPrevented, true);
    assert.equal(blocked.confirmations.length, 0);
    const invalid = fixture({ checked: true, valid: false });
    invalid.submit();
    assert.equal(invalid.confirmations.length, 0);
});
