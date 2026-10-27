import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { confirmForcedPlacement } from '../../resources/js/booking-force-placement.js';

function fixture() {
    const accepted = { checked: true };
    const nodes = Object.fromEntries(['technician', 'date', 'customer', 'address'].map(name => [`[data-force-${name}]`, { textContent: '' }]));
    nodes['[data-force-accepted]'] = accepted;
    let focusedCancel = 0, restoredFocus = 0;
    nodes['[value="cancel"]'] = { focus() { focusedCancel++; } };
    const dialog = { open: false, returnValue: 'confirm', handlers: {},
        querySelector: selector => nodes[selector],
        addEventListener(type, callback) { this.handlers[type] = callback; },
        showModal() { this.open = true; },
        finish(value) { this.returnValue = value; this.open = false; this.handlers.close(); },
    };
    const root = { querySelector: () => dialog, activeElement: { focus() { restoredFocus++; } } };
    return { root, dialog, accepted, nodes, get focusedCancel() { return focusedCancel; }, get restoredFocus() { return restoredFocus; } };
}

test('requires a new explicit checkbox confirmation and restores focus', async () => {
    const ui = fixture();
    const promise = confirmForcedPlacement({ technician: 'Camille', date: '02/11 14:00', customer: 'Client', address: '10 rue Test' }, ui.root);
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.dialog.returnValue, '');
    assert.equal(ui.accepted.checked, false);
    assert.equal(ui.focusedCancel, 1);
    ui.accepted.checked = true; ui.dialog.finish('confirm');
    assert.equal(await promise, true);
    assert.equal(ui.accepted.checked, false);
    assert.equal(ui.restoredFocus, 1);
});

test('cancel, Escape, unchecked confirm and a previous confirmation never force a placement', async () => {
    for (const [value, checked] of [['cancel', true], ['', true], ['confirm', false]]) {
        const ui = fixture();
        const promise = confirmForcedPlacement({}, ui.root);
        ui.accepted.checked = checked; ui.dialog.finish(value);
        assert.equal(await promise, false);
    }
    const ui = fixture();
    let promise = confirmForcedPlacement({}, ui.root);
    ui.accepted.checked = true; ui.dialog.finish('confirm');
    assert.equal(await promise, true);
    promise = confirmForcedPlacement({}, ui.root);
    ui.dialog.finish('confirm');
    assert.equal(await promise, false);
});

test('inserts all recap values as plain text and handles missing values', async () => {
    const ui = fixture();
    const promise = confirmForcedPlacement({ technician: '<img src=x onerror=alert(1)>', address: null }, ui.root);
    assert.equal(ui.nodes['[data-force-technician]'].textContent, '<img src=x onerror=alert(1)>');
    assert.equal(ui.nodes['[data-force-address]'].textContent, 'Non renseigné');
    assert.equal(ui.nodes['[data-force-technician]'].innerHTML, undefined);
    ui.dialog.finish('cancel'); await promise;
});

test('does not allow a second confirmation while one is open or when dialog support is unavailable', async () => {
    const ui = fixture();
    const pending = confirmForcedPlacement({}, ui.root);
    assert.equal(await confirmForcedPlacement({}, ui.root), false);
    ui.dialog.finish('cancel'); await pending;
    assert.equal(await confirmForcedPlacement({}, { querySelector: () => null }), false);
    assert.equal(await confirmForcedPlacement({}, { querySelector: () => ({}) }), false);
});

test('normal and forced booking share one single-flight path and never reuse force flags', () => {
    const view = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    assert.match(view, /if \(bookingPlacementBusy \|\| !selectedCalendarEvent/);
    assert.match(view, /delete payload\.force_placement;\s*delete payload\.force_confirmed;/);
    assert.match(view, /if \(!accepted \|\| selectedCalendarEvent !== confirmedEvent\) return;/);
    assert.match(view, /placeSelectedAppointment\(false\)/);
    assert.match(view, /placeSelectedAppointment\(true\)/);
    assert.match(view, /'booking-place-alongside-btn'/);
    assert.match(view, /bookingForcePlacementEnabled \? selectedTechnicians\(\) : selectedAvailableTechnicians/);
});
