import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const validation = source.slice(source.indexOf('const validateForm ='), source.indexOf('const initValidatedForms ='));

for (const valid of [false, true]) {
    test(`validates associated submit buttons outside the form: valid=${valid}`, () => {
        const input = { type: 'text', dataset: {}, value: '' };
        const inside = { type: 'submit', disabled: valid };
        const footer = { type: 'submit', disabled: valid };
        const cancel = { type: 'button', disabled: false };
        const form = {
            elements: [input, inside, footer, cancel],
            querySelectorAll: (selector) => selector === 'input, select, textarea' ? [input] : [],
        };
        const result = runInNewContext(`${validation}\nvalidateForm(form);`, {
            form,
            renderPasswordRules() {},
            fieldError: () => valid ? '' : 'Required',
            setFieldState() {},
        });
        assert.equal(result, valid);
        assert.equal(inside.disabled, !valid);
        assert.equal(footer.disabled, !valid);
        assert.equal(cancel.disabled, false);
    });
}
