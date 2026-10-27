import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { requestAppointmentPlacement, confirmAppointmentAction, initAppointmentAssignments } from '../../resources/js/appointment-placement.js';

const response = (status, body) => ({ ok: status === 200, status, json: async () => body });
const conflict = response(422, { can_force: true, conflicts: [{ message: 'Absence' }, { message: 'Chevauchement' }] });
const data = { technician_id: 7, version: 2, updated_at: '2026-10-27T19:00:00+01:00' };
function fixture(responses, approved = true) {
    const calls = [], confirmations = [];
    const browser = { setTimeout, clearTimeout, fetch: async (url, options) => {
        calls.push({ url, options, data: JSON.parse(options.body) });
        const value = responses.shift();
        if (value instanceof Error) throw value;
        return value;
    } };
    const root = { querySelector: () => ({ content: 'csrf-fixture' }) };
    return { browser, root, calls, confirmations, confirm: async info => { confirmations.push(info); return approved; } };
}

test('saves without a dialog when there is no conflict and strips stale force flags', async () => {
    const ui = fixture([response(200, { technician_id: 7, version: 3 })]);
    assert.equal((await requestAppointmentPlacement('/appointment', { ...data, force_placement: true, force_confirmed: true }, ui)).version, 3);
    assert.equal(ui.confirmations.length, 0);
    assert.equal(ui.calls.length, 1);
    assert.deepEqual(ui.calls[0].data, data);
    assert.equal(ui.calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-fixture');
});

test('canceling a conflict never sends a forced placement', async () => {
    const ui = fixture([conflict], false);
    assert.equal(await requestAppointmentPlacement('/appointment', data, ui), null);
    assert.equal(ui.calls.length, 1);
    assert.deepEqual(ui.confirmations[0].messages, ['Absence', 'Chevauchement']);
    assert.equal(ui.confirmations[0].requireCheck, true);
});

test('forces only after confirmation with the original concurrency tokens', async () => {
    const ui = fixture([conflict, response(200, { version: 3 })]);
    await requestAppointmentPlacement('/appointment', data, ui);
    assert.equal(ui.calls.length, 2);
    assert.deepEqual(ui.calls[1].data, { ...data, force_placement: true, force_confirmed: true });
});

test('authorization and stale snapshots cannot trigger the force modal', async () => {
    for (const status of [403, 404, 419, 422, 500]) {
        const ui = fixture([response(status, { errors: { version: ['Fiche périmée'] } })]);
        await assert.rejects(requestAppointmentPlacement('/appointment', data, ui), /Fiche périmée/);
        assert.equal(ui.confirmations.length, 0);
        assert.equal(ui.calls.length, 1);
    }
});

test('a new conflict after confirmation is not retried automatically', async () => {
    const ui = fixture([conflict, conflict]);
    await assert.rejects(requestAppointmentPlacement('/appointment', data, ui));
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.confirmations.length, 1);
});

test('uncertain network or non-JSON success responses are never replayed', async () => {
    for (const result of [new Error('network'), response(200, null)]) {
        const ui = fixture([result]);
        await assert.rejects(requestAppointmentPlacement('/appointment', data, ui), /non confirmé/);
        assert.equal(ui.calls.length, 1);
    }
});

test('modal labels and conflict messages use plain text and confirmation is checked afresh', async () => {
    let finish, removed = false;
    const nodes = { h2: {}, ul: { items: [], append(item) { this.items.push(item); } }, input: { checked: false, addEventListener() {} },
        '[value="confirm"]': {}, label: {}, '[value="cancel"]': { focus() {} } };
    const dialog = { querySelector: selector => nodes[selector], setAttribute() {}, showModal() {},
        addEventListener(name, callback) { finish = callback; }, remove() { removed = true; } };
    const root = { body: { append() {} }, createElement: tag => tag === 'dialog' ? dialog : {}, activeElement: { focus() {} } };
    const pending = confirmAppointmentAction({ title: 'Conflit', messages: ['<script>unsafe</script>'], requireCheck: true }, root);
    assert.equal(nodes.ul.items[0].textContent, '<script>unsafe</script>');
    assert.equal(nodes['[value="confirm"]'].disabled, true);
    dialog.returnValue = 'confirm'; finish();
    assert.equal(await pending, false);
    assert.equal(removed, true);
});

test('modify and close controls share one action group and technician forms are bound dynamically', () => {
    const view = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');
    assert.match(view, /gc-appointment-modal-actions[\s\S]*tracking-open-edit[\s\S]*tracking-detail-close/);
    assert.match(view, /props\.can_cancel !== true/);
    const module = readFileSync(new URL('../../resources/js/appointment-placement.js', import.meta.url), 'utf8');
    assert.match(module, /root\.addEventListener\('change'/);
    assert.match(module, /pending\.has\(id\)/);
});

test('assignment refreshes the dossier snapshot, technician name and planning link without a reload', async () => {
    const ui = fixture([response(200, { technician_id: 7, technician_name: 'Camille Martin', version: 3, updated_at: 'new-snapshot' })]);
    const callbacks = {}, version = { value: 2 }, label = { dataset: { appointmentTechnician: '12' } };
    const link = { dataset: { appointmentPlanning: '12' }, href: 'https://cofrac.test/planner?appointment_id=12&technician_id=4' };
    const detail = { dataset: { dossierId: '42' }, querySelectorAll: selector => ({
        '[name="version"]': [version], '[data-appointment-technician]': [label], '[data-appointment-planning]': [link],
    }[selector] || []) };
    const select = { value: '7' }, feedback = {};
    const form = { dataset: { appointmentEditor: '12', dossierId: '42', technicianId: '4' },
        elements: { technician_id: select, version: { value: 2 }, updated_at: { value: 'old-snapshot' } },
        action: '/assignment', reportValidity: () => true, querySelector: () => feedback, querySelectorAll: () => [],
        closest: selector => selector === '.gc-dossier-detail' ? detail : form };
    const done = new Promise(resolve => { ui.root.dispatchEvent = resolve; });
    ui.root.addEventListener = (name, callback) => { callbacks[name] = callback; };
    ui.root.querySelectorAll = () => [form];
    ui.browser.CustomEvent = class { constructor(name, options) { this.type = name; this.detail = options.detail; } };
    initAppointmentAssignments(ui.root, ui.browser);
    callbacks.submit({ target: form, preventDefault() {} });
    assert.equal((await done).type, 'techcalendar:appointment-assigned');
    assert.equal(version.value, 3);
    assert.equal(form.elements.updated_at.value, 'new-snapshot');
    assert.equal(label.textContent, 'Camille Martin');
    assert.equal(new URL(link.href).searchParams.get('technician_id'), '7');
    assert.equal(new URL(link.href).searchParams.get('appointment_id'), '12');
    assert.equal(select.disabled, false);
});
