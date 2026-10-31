import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { initDatePickers } from '../../resources/js/date-picker.js';

function harness(overrides = {}) {
    const handlers = {};
    let opened = 0;
    const input = { dataset: {}, addEventListener: (type, callback) => { handlers[type] = callback; },
        showPicker: () => { opened++; }, ...overrides };
    const root = { querySelectorAll(selector) {
        assert.equal(selector, 'input[type="date"][data-date-picker]');
        return [input];
    } };
    initDatePickers(root);
    return { input, root, handlers, opened: () => opened };
}

test('opens report date pickers anywhere in the field, once per click, without changing the value', () => {
    const ui = harness({ value: '2026-10-28' });
    initDatePickers(ui.root);
    let prevented = false;
    ui.handlers.click({ button: 0, preventDefault() { prevented = true; } });
    assert.equal(ui.opened(), 1);
    assert.equal(prevented, true);
    assert.equal(ui.input.value, '2026-10-28');
});

test('leaves unsupported or denied native pickers usable without cancelling the click', () => {
    for (const showPicker of [undefined, () => { throw new Error('NotAllowedError'); }]) {
        const ui = harness({ showPicker });
        assert.doesNotThrow(() => ui.handlers.click({ button: 0, preventDefault() { assert.fail('native fallback cancelled'); } }));
    }
});

test('does not open disabled or readonly inputs or intercept modified clicks', () => {
    for (const attributes of [{ disabled: true }, { readOnly: true }]) {
        const ui = harness(attributes);
        ui.handlers.click({ button: 0 });
        assert.equal(ui.opened(), 0);
    }
    for (const event of [{ defaultPrevented: true }, { button: 2 }, { ctrlKey: true }, { metaKey: true }, { altKey: true }]) {
        const ui = harness();
        ui.handlers.click(event);
        assert.equal(ui.opened(), 0);
    }
});

test('both report dates opt in and the app initializes the helper', () => {
    const view = readFileSync(new URL('../../resources/views/crm/dossiers/detail/document-upload.blade.php', import.meta.url), 'utf8');
    for (const id of ['report-inspected', 'report-issued']) assert.match(view, new RegExp(`id="${id}"[^>]+data-date-picker`));
    assert.match(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), /initDatePickers\(\)/);
});
