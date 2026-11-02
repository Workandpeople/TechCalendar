import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { initAppointmentReschedule, reschedulePayload, applyRescheduledAppointment } from '../../resources/js/appointment-reschedule.js';

const context = { version: 3, appointment_id: 12, updated_at: '2026-11-02T12:00:00+01:00', customer: '<img src=x>',
    service: 'BAT EQ 127', address: '<script>Paris</script>', starts_at: '2026-11-03T14:00:00+01:00', duration_minutes: 45,
    technician_id: 7, technicians: [{ id: 7, name: '<svg onload=evil()>', qualified: true }, { id: 8, name: 'Autre technicien', qualified: false }] };
const saved = { message: 'RDV replacé.', dossier_id: 42, operation_id: 4, version: 4, appointment_id: 12,
    updated_at: '2026-11-02T12:01:00+01:00', technician_id: 8, technician_name: 'Autre technicien',
    starts_at: '2026-11-04T10:40:00+01:00', ends_at: '2026-11-04T11:25:00+01:00', planning_url: '/planner/tracking?appointment_id=12' };
const response = (body, status = 200) => ({ ok: status === 200, status, json: async () => body });
const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };

function fixture() {
    class Node {
        constructor() { this.handlers = {}; this.dataset = {}; this.children = []; this.hidden = false; this.classList = { add() {}, remove() {} }; }
        addEventListener(name, fn) { this.handlers[name] = fn; }
        focus() {}
        replaceChildren() { this.children = []; }
        append(child) { this.children.push(child); }
        set innerHTML(_) { assert.fail('User-controlled labels must not become HTML'); }
    }
    const requests = [], timers = new Map(), form = new Node(), dialog = new Node(), fieldset = new Node(), feedback = new Node();
    const summary = new Node(), address = new Node(), result = new Node(), close = new Node();
    form.elements = Object.fromEntries(['date', 'time', 'technician_id', 'duration_minutes'].map(name => [name, new Node()]));
    form.reportValidity = () => true;
    dialog.querySelector = selector => ({ '[data-reschedule-form]': form, '[data-reschedule-fields]': fieldset,
        '[data-reschedule-feedback]': feedback, '[data-reschedule-result]': result,
        '[data-reschedule-summary]': summary, '[data-reschedule-address]': address }[selector]);
    dialog.querySelectorAll = () => [close]; dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.handlers.close(); };
    const root = new Node(); root.querySelector = selector => selector === '[data-quick-reschedule-dialog]' ? dialog
        : selector === 'meta[name="csrf-token"]' ? { content: 'csrf-test' } : null;
    root.createElement = () => new Node(); root.dispatchEvent = event => { root.dispatched = event; };
    const browser = { location: new URL('https://cofrac.test/v2/dossiers/42'),
        setTimeout(fn) { const id = timers.size + 1; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); },
        CustomEvent: class { constructor(name, options) { this.type = name; this.detail = options.detail; } },
        fetch(url, options) { return new Promise((resolve, reject) => { requests.push({ url, options, resolve, reject }); }); },
    };
    initAppointmentReschedule(root, browser);
    const button = new Node(); button.dataset.quickRescheduleUrl = '/v2/dossiers/42/prestations/4/replacement-rapide';
    const click = () => root.handlers.click({ target: { closest: () => button }, preventDefault() {} });
    const submit = () => form.handlers.submit({ preventDefault() {} });
    const load = async (data = context) => { click(); requests.at(-1).resolve(response(data)); await settle(); };
    return { root, browser, dialog, button, form, fieldset, feedback, summary, address, result, close, requests, timers, click, submit, load };
}

test('quick replacement retains concurrency tokens and serializes the exact Paris hour', () => {
    const fields = Object.fromEntries(Object.entries({ date: '2026-11-04', time: '10:40', technician_id: '8', duration_minutes: '45' })
        .map(([key, value]) => [key, { value }]));
    assert.deepEqual(reschedulePayload(context, fields), { version: 3, appointment_id: 12, updated_at: context.updated_at,
        technician_id: 8, duration_minutes: 45, starts_at: '2026-11-04T10:40:00+01:00' });
    fields.date.value = '2027-03-28'; fields.time.value = '02:30';
    assert.throws(() => reschedulePayload(context, fields), /inexistant/);
});

test('opens immediately, lazy-loads only this RDV and prefills fields with escaped labels', async () => {
    const ui = fixture(); ui.click();
    assert.equal(ui.dialog.open, true); assert.equal(ui.form.hidden, true); assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].options.cache, 'no-store');
    ui.requests[0].resolve(response(context)); await settle();
    assert.equal(ui.form.hidden, false); assert.equal(ui.form.elements.date.value, '2026-11-03');
    assert.equal(ui.form.elements.time.value, '14:00'); assert.equal(ui.form.elements.technician_id.value, '7');
    assert.equal(ui.form.elements.technician_id.children[1].textContent, '<svg onload=evil()>');
    assert.equal(ui.summary.textContent, '<img src=x> · BAT EQ 127'); assert.equal(ui.address.textContent, '<script>Paris</script>');
    assert.equal(ui.timers.size, 0);
});

test('a disabled previous technician is never silently assigned to the first active technician', async () => {
    const ui = fixture(); await ui.load({ ...context, technician_id: 99 });
    assert.equal(ui.form.elements.technician_id.value, '');
});

test('canceling an initial lookup ignores a late response and never issues a write', async () => {
    const ui = fixture(); ui.click(); ui.close.handlers.click();
    assert.equal(ui.requests[0].options.signal.aborted, true);
    ui.requests[0].resolve(response(context)); await settle();
    assert.equal(ui.dialog.open, false); assert.equal(ui.form.hidden, true); assert.equal(ui.requests.length, 1);
});

test('saves once without navigation, guards double submission and confirms persisted time', async () => {
    const ui = fixture(); await ui.load();
    ui.form.elements.date.value = '2026-11-04'; ui.form.elements.time.value = '10:40'; ui.form.elements.technician_id.value = '8';
    ui.submit(); ui.submit();
    assert.equal(ui.requests.length, 2); assert.equal(ui.fieldset.disabled, true); assert.equal(ui.close.disabled, true);
    const cancel = { prevented: false, preventDefault() { this.prevented = true; } }; ui.dialog.handlers.cancel(cancel);
    assert.equal(cancel.prevented, true); assert.equal(ui.requests[1].options.method, 'PATCH');
    assert.equal(JSON.parse(ui.requests[1].options.body).starts_at, saved.starts_at);
    ui.requests[1].resolve(response(saved)); await settle();
    assert.equal(ui.form.hidden, true); assert.equal(ui.close.disabled, false); assert.equal(ui.dialog.open, true);
    assert.match(ui.feedback.textContent, /10:40/); assert.equal(ui.result.href, saved.planning_url);
    assert.equal(ui.root.dispatched.type, 'techcalendar:appointment-rescheduled');
    assert.equal(ui.root.dispatched.detail.version, 4); ui.submit(); assert.equal(ui.requests.length, 2);
});

test('stale writes stay visible and do not trigger an automatic retry or a forced request', async () => {
    const ui = fixture(); await ui.load(); ui.submit();
    ui.requests[1].resolve(response({ errors: { version: ['Le dossier a changé. Rouvre la fenêtre.'] } }, 422)); await settle();
    assert.match(ui.feedback.textContent, /dossier a changé/); assert.equal(ui.form.hidden, false);
    assert.equal(ui.fieldset.disabled, false); assert.equal(ui.requests.length, 2);
});

test('expired sessions and HTML errors never expose a stale editable context', async () => {
    const ui = fixture(); ui.click();
    ui.requests[0].resolve({ ok: false, status: 419, json: async () => { throw new Error('HTML'); } }); await settle();
    assert.equal(ui.form.hidden, true); assert.match(ui.feedback.textContent, /session/);
    ui.submit(); assert.equal(ui.requests.length, 1);
});

test('refreshes visible dossier fields and concurrency tokens without reload or scroll', () => {
    const date = { dataset: { appointmentTime: '12' } }, tech = { dataset: { appointmentTechnician: '12' } }, version = {};
    const link = { dataset: { appointmentPlanning: '12' } }, state = { dataset: { appointmentState: '12' } };
    const form = { dataset: { appointmentEditor: '12' }, elements: { technician_id: {}, updated_at: {} } };
    const count = {}, summary = {}, status = {}, planning = {};
    const operation = { querySelector: selector => ({ '[data-operation-state]': status, '[data-operation-appointments-count]': count,
        '[data-rescheduled-summary]': summary, '[data-operation-planning]': planning }[selector]) };
    const page = { contains: node => node === operation, querySelectorAll: selector => ({ '[name="version"]': [version],
        '[data-appointment-time]': [date], '[data-appointment-technician]': [tech], '[data-appointment-planning]': [link],
        '[data-appointment-state]': [state], '[data-appointment-editor]': [form] }[selector]) };
    applyRescheduledAppointment({ ...saved, appointments_count: 1 }, { querySelector: () => page, getElementById: () => operation });
    assert.match(date.textContent, /10:40/); assert.equal(tech.textContent, saved.technician_name);
    assert.equal(version.value, 4); assert.equal(form.elements.updated_at.value, saved.updated_at);
    assert.equal(link.href, saved.planning_url); assert.equal(planning.href, saved.planning_url);
    assert.equal(status.textContent, 'RDV en attente de visite'); assert.equal(count.textContent, '1 RDV lié(s)');
});

test('dossier and tracking expose the same compact modal without a wizard or page reload', () => {
    const view = readFileSync(new URL('../../resources/views/planner/partials/quick-reschedule.blade.php', import.meta.url), 'utf8');
    assert.match(view, /type="date"/); assert.match(view, /type="time"/); assert.match(view, /name="technician_id"/);
    assert.doesNotMatch(view, /mapbox|iframe|calendar-window/);
    const script = readFileSync(new URL('../../resources/js/appointment-reschedule.js', import.meta.url), 'utf8');
    assert.doesNotMatch(script, /innerHTML|location\.(assign|reload)|scrollIntoView/);
});
