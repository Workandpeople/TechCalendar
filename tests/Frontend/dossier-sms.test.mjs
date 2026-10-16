import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewMatches } from '../../resources/js/dossier-sms.js';

test('discards a delayed preview when any SMS input changed', () => {
    const values = { recipient: '0612345678', body: 'Bonjour', appointment_id: '123' };
    const form = { elements: { namedItem: (name) => ({ value: values[name] }) } };
    const snapshot = { ...values };
    assert.equal(previewMatches(form, snapshot), true);
    for (const key of Object.keys(values)) {
        values[key] += 'changed';
        assert.equal(previewMatches(form, snapshot), false);
        values[key] = snapshot[key];
    }
});
