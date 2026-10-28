import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cofracLabel } from '../../resources/js/cofrac-label.js';

test('formats legacy service type labels without renaming protocol identifiers', () => {
    assert.equal(cofracLabel('COFFRAC - BAR EN 101'), 'COFRAC - BAR EN 101');
    assert.equal(cofracLabel('Coffrac coffrac'), 'Cofrac cofrac');
    assert.equal(cofracLabel('coffrac-123'), 'cofrac-123'); // Display function, never used on identifiers.
    assert.equal(cofracLabel(null), '');
});
