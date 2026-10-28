import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const bindCode = source.slice(source.indexOf('const bindBookingCrmCards ='), source.indexOf('\n        bindBookingCrmCards();'));

function element() {
    const events = {}, attributes = {};
    return {
        dataset: {}, events, attributes,
        addEventListener: (key, handler) => { (events[key] ||= []).push(handler); },
        setAttribute: (key, value) => { attributes[key] = value; },
        removeAttribute: (key) => { delete attributes[key]; },
        getBoundingClientRect: () => ({ left: 100, top: 200, width: 160 }),
        closest: () => null,
        emit(key, extra = {}) { for (const handler of events[key] || []) handler({ type: key, target: this, ...extra }); },
        click() { this.emit('click'); },
    };
}

function harness(withComments = true) {
    const name = element(), phone = element(), card = element();
    const shown = [], moved = [], selected = [], tooltip = { style: { display: 'none' } };
    card.dataset.crmId = 'dossier-1';
    card.querySelectorAll = (selector) => {
        assert.equal(selector, '[data-crm-comments-trigger]');
        return [name];
    };
    const context = {
        bookingCrmCards: [card], bookingCrmGrid: null, bookingConnectorsEnabled: false,
        bookingCrmAppointmentTooltip: tooltip, bookingCrmCommentTooltipTarget: null,
        crmAppointmentById: (id) => ({ id }),
        showCrmAppointmentTooltip: (event, appointment) => {
            shown.push({ event, appointment });
            tooltip.style.display = withComments ? 'block' : 'none';
        },
        moveCrmAppointmentTooltip: (event) => moved.push(event),
        hideCrmAppointmentTooltip: () => {
            context.bookingCrmCommentTooltipTarget?.removeAttribute('aria-describedby');
            context.bookingCrmCommentTooltipTarget = null;
            tooltip.style.display = 'none';
        },
        analyzeCrmAppointment: (id) => selected.push(id),
    };
    const bind = runInNewContext(`${bindCode}\nbindBookingCrmCards;`, context);
    bind();
    return { bind, name, phone, card, shown, moved, selected, tooltip };
}

test('comment tooltip hover is bound only to the name, never the phone or full row', () => {
    const ui = harness();
    assert.equal(ui.card.events.mouseenter, undefined);
    assert.equal(ui.card.events.mousemove, undefined);
    ui.name.emit('mouseenter', { clientX: 130, clientY: 240 });
    assert.equal(ui.shown.length, 1);
    assert.equal(ui.shown[0].appointment.id, 'dossier-1');
    assert.equal(ui.name.attributes['aria-describedby'], 'booking-crm-comments-tooltip');
    ui.name.emit('mousemove');
    assert.equal(ui.moved.length, 1);
    ui.name.emit('mouseleave');
    assert.equal(ui.tooltip.style.display, 'none');
    assert.equal(ui.name.attributes['aria-describedby'], undefined);
    ui.phone.emit('mouseenter');
    assert.equal(ui.shown.length, 1);
    assert.equal(ui.phone.events.mouseenter, undefined);
    ui.card.emit('mouseleave');
    assert.equal(ui.tooltip.style.display, 'none');
});

test('keyboard focus exposes comments, escape hides them and Enter still selects the dossier', () => {
    const ui = harness();
    ui.name.emit('focus');
    assert.equal(ui.shown[0].event.clientX, 180);
    assert.equal(ui.shown[0].event.clientY, 200);
    ui.name.emit('keydown', { key: 'Escape' });
    assert.equal(ui.tooltip.style.display, 'none');
    let prevented = false;
    ui.name.emit('keydown', { key: 'Enter', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(ui.selected, ['dossier-1']);
    ui.name.emit('blur');
    assert.equal(ui.name.attributes['aria-describedby'], undefined);
});

test('SSR and AJAX markup expose only dossier names as comment triggers', () => {
    const triggers = source.match(/<p data-crm-comments-trigger[^>]+>/g);
    assert.equal(triggers.length, 2);
    assert.ok(triggers.every(tag => !tag.includes('gc-booking-request-phone')));
});

test('refresh binding is idempotent and empty comments never create a description', () => {
    const ui = harness(false);
    ui.bind();
    ui.name.emit('focus');
    assert.equal(ui.shown.length, 1);
    assert.equal(ui.name.attributes['aria-describedby'], undefined);
    assert.equal(ui.name.events.mouseenter.length, 1);
    assert.equal(ui.card.events.click.length, 1);
});
