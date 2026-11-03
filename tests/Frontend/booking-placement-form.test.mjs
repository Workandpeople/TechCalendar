import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createBookingPlacementForm, placementSelection } from '../../resources/js/booking-placement-form.js';

class Element {
    constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.handlers = {}; this.dataset = {}; this.value = ''; }
    append(...children) { children.forEach(child => { child.parent = this; this.children.push(child); }); }
    replaceChildren(...children) { this.children = []; this.append(...children); }
    setAttribute(name, value) { this[name] = value; }
    addEventListener(name, callback) { this.handlers[name] = callback; }
    closest(tag) { return this.tagName === tag.toUpperCase() ? this : this.parent?.closest(tag); }
    focus() { this.focused = true; }
    reportValidity() { return this.valid !== false; }
    showModal() { this.open = true; }
    close() { this.open = false; this.handlers.close?.(); }
    remove() { this.removed = true; }
}
const walk = element => [element, ...element.children.flatMap(walk)];
const tick = () => new Promise(resolve => setImmediate(resolve));
function fixture(onPlace = async () => {}) {
    const root = { createElement: tag => new Element(tag), body: new Element('body'), activeElement: new Element('button') };
    const context = { request: { customer_name: '<img src=x>', address: '12 rue Test', service: { name: 'BAT EQ 127' } },
        technicians: [{ id: 9, name: 'Camille' }, { id: 12, name: 'Olivia' }], duration: 45, forceAllowed: true };
    const form = createBookingPlacementForm({ root, getContext: () => context, onPlace });
    const nodes = walk(root.body), dialog = nodes.find(el => el.tagName === 'DIALOG');
    const fields = Object.fromEntries(nodes.filter(el => el.name).map(el => [el.name, el]));
    return { root, context, form, nodes, dialog, fields, htmlForm: nodes.find(el => el.tagName === 'FORM'),
        force: nodes.find(el => el.textContent === 'Forcer le placement'), fieldset: nodes.find(el => el.tagName === 'FIELDSET'),
        feedback: nodes.find(el => el.className === 'gc-placement-feedback') };
}

test('manual form only offers the displayed technicians and inserts dossier values as text', () => {
    const ui = fixture();
    ui.form.open({ date: '2026-11-04T10:40', resource: { id: '12' } });
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.fields.date.value, '2026-11-04'); assert.equal(ui.fields.time.value, '10:40');
    assert.equal(ui.fields.technician_id.value, '12'); assert.equal(ui.fields.duration_minutes.value, 45);
    assert.deepEqual(ui.fields.technician_id.children.map(el => el.value), [9, 12]);
    assert.ok(ui.nodes.some(el => el.textContent === '<img src=x>'));
    assert.ok(ui.nodes.every(el => el.innerHTML === undefined));
    ui.dialog.close(); assert.equal(ui.root.activeElement.focused, true);
    ui.context.technicians = []; assert.throws(() => ui.form.open(), /cochez au moins/);
});

test('manual input uses Paris time regardless of the browser timezone, including DST', () => {
    const ui = fixture();
    const old = process.env.TZ; process.env.TZ = 'Pacific/Honolulu';
    try {
        ui.form.open({ date: '2026-11-04T10:40' });
        assert.equal(placementSelection(ui.fields).starts_at, '2026-11-04T10:40:00+01:00');
        ui.fields.date.value = '2026-07-01';
        assert.equal(placementSelection(ui.fields).starts_at, '2026-07-01T10:40:00+02:00');
        ui.fields.date.value = '2026-03-29'; ui.fields.time.value = '02:30';
        assert.throws(() => placementSelection(ui.fields), /inexistant/);
    } finally { process.env.TZ = old; }
});

test('manual form validates durations, technician identity and malformed dates', () => {
    const ui = fixture(); ui.form.open({ date: '2026-11-04T10:40' });
    for (const duration of ['0', '29', '481', '45.5', 'abc']) {
        ui.fields.duration_minutes.value = duration; assert.throws(() => placementSelection(ui.fields));
    }
    ui.fields.duration_minutes.value = 45; ui.fields.technician_id.value = 'no';
    assert.throws(() => placementSelection(ui.fields));
    ui.fields.technician_id.value = 9; ui.fields.date.value = '2026-02-30';
    assert.throws(() => placementSelection(ui.fields));
});

test('conflicts expose forcing only when authorized, editing resets it and submissions stay single-flight', async () => {
    let finish, calls = 0, latest;
    const ui = fixture(async options => { calls++; latest = options; await new Promise(resolve => { finish = resolve; }); });
    ui.form.open({ date: '2026-11-04T10:40' });
    ui.htmlForm.handlers.submit({ preventDefault() {} });
    assert.equal(ui.fieldset.disabled, true);
    ui.htmlForm.handlers.submit({ preventDefault() {} });
    assert.equal(calls, 1);
    let prevented = false; ui.dialog.handlers.cancel({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    latest.conflict({ can_force: true }); finish(); await tick();
    assert.equal(ui.force.hidden, false); assert.equal(ui.fieldset.disabled, false);
    ui.force.handlers.click(); assert.equal(latest.forced, true); finish(); await tick();
    ui.htmlForm.handlers.input(); assert.equal(ui.force.hidden, true);
    ui.context.forceAllowed = false; latest.conflict({ can_force: true }); assert.equal(ui.force.hidden, true);
});

test('MAR keeps its configured duration and does not offer an unsupported comment or forced placement', async () => {
    let request;
    const ui = fixture(async options => { request = options; options.conflict({ can_force: true }); });
    ui.context.request.is_mymar = true; ui.context.duration = 60; ui.context.forceAllowed = false;
    ui.form.open({ date: '2026-11-04T10:40' });
    assert.equal(ui.fields.duration_minutes.readOnly, true);
    assert.equal(ui.fields.comment.closest('label').hidden, true);
    ui.htmlForm.handlers.submit({ preventDefault() {} }); await tick();
    assert.equal(request.values.duration_minutes, 60); assert.equal(ui.force.hidden, true);
});

test('invalid forms, removed technicians and submission errors leave the modal open', async () => {
    let calls = 0;
    const ui = fixture(async () => { calls++; throw new Error('Le dossier a changé.'); });
    ui.form.open({ date: '2026-11-04T10:40' });
    ui.htmlForm.valid = false; ui.htmlForm.handlers.submit({ preventDefault() {} }); assert.equal(calls, 0);
    ui.htmlForm.valid = true; ui.fields.technician_id.value = 99;
    ui.htmlForm.handlers.submit({ preventDefault() {} }); assert.equal(calls, 0);
    ui.fields.technician_id.value = 9; ui.htmlForm.handlers.submit({ preventDefault() {} }); await tick();
    assert.equal(calls, 1); assert.equal(ui.dialog.open, true);
    assert.equal(ui.feedback.textContent, 'Le dossier a changé.'); assert.equal(ui.fieldset.disabled, false);
});
