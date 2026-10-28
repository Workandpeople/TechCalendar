import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { appointmentTooltipAddress, createAppointmentTooltip } from '../../resources/js/appointment-tooltip.js';

function fixture() {
    const handlers = {};
    const element = () => ({ children: [], style: {}, attributes: {}, hidden: false,
        append(node) { this.children.push(node); }, replaceChildren() { this.children = []; },
        setAttribute(key, value) { this.attributes[key] = value; }, getAttribute(key) { return this.attributes[key]; },
        removeAttribute(key) { delete this.attributes[key]; }, getBoundingClientRect: () => ({ width: 360, height: 330 }),
    });
    const root = { body: element(), documentElement: { clientWidth: 1000 }, createElement: element,
        addEventListener(name, fn) { handlers[name] = fn; } };
    const browser = { innerHeight: 700, addEventListener(name, fn) { handlers[name] = fn; } };
    const tooltip = createAppointmentTooltip({ root, browser });
    const text = node => [node.textContent || '', ...node.children.map(text)].join(' ');
    return { root, tooltip, handlers, element, popup: root.body.children[0], text: () => text(root.body) };
}

test('formats the same complete address without duplicating a postcode/city already in the street', () => {
    assert.equal(appointmentTooltipAddress({ address: '12 rue Test', postal_code: '75001', city: 'Paris' }), '12 rue Test, 75001 Paris');
    assert.equal(appointmentTooltipAddress({ address: '12 rue Test, 75001 PARIS', postal_code: '75001', city: 'Paris' }), '12 rue Test, 75001 PARIS');
    assert.equal(appointmentTooltipAddress({}), 'Adresse non renseignée');
});

test('shows technician service address beneficiary phone and French dates as safe text only', () => {
    const ui = fixture(), trigger = ui.element();
    trigger.setAttribute('aria-describedby', 'previous-help');
    ui.tooltip.show({ clientX: 990, clientY: 30 }, { start: '2026-10-30T12:30:00+01:00', end: '2026-10-30T13:10:00+01:00',
        extendedProps: { technician_name: 'Camille <img onerror=x>', service_label: 'COFFRAC - EQ 108',
            address: '12 rue Test', customer_name: 'Bénéficiaire <script>', customer_phone: '06 12 34 56 78' } }, trigger);
    assert.match(ui.text(), /Camille <img onerror=x>/);
    for (const content of ['COFFRAC - EQ 108', '12 rue Test', 'Bénéficiaire <script>', '06 12 34 56 78', '30\/10\/2026']) {
        assert.match(ui.text(), new RegExp(content));
    }
    assert.equal(ui.popup.innerHTML, undefined);
    assert.equal(ui.popup.hidden, false);
    assert.equal(ui.popup.style.left, '632px');
    assert.equal(ui.popup.style.top, '44px');
    assert.equal(trigger.attributes['aria-describedby'], 'previous-help appointment-hover-tooltip');
    ui.tooltip.hide();
    assert.equal(trigger.attributes['aria-describedby'], 'previous-help');
    assert.equal(ui.popup.hidden, true);
});

test('reuses a single tooltip and closes on Escape, scroll, resize and page exit', () => {
    const ui = fixture();
    for (const [name, event] of [['keydown', { key: 'Escape' }], ['scroll'], ['resize'], ['pagehide']]) {
        ui.tooltip.show({ clientX: 400, clientY: 500 }, { start: 'invalid', extendedProps: {} });
        assert.equal(ui.popup.hidden, false);
        ui.handlers[name](event);
        assert.equal(ui.popup.hidden, true);
    }
    assert.equal(ui.root.body.children.length, 1);
});

test('booking placed events and tracking use one shared tooltip, while proposals keep their travel preview', () => {
    const booking = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    const tracking = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');
    assert.match(booking, /event\.extendedProps\?\.is_suggestion\s*\? ensureBookingTravelTooltip\(\) : \(bookingPlacedTooltip \|\|= window\.TechCalendarAppointmentTooltip\.create\(\)\)/);
    assert.match(booking, /const tooltip = tooltipForBookingEvent\(info\.event\)/);
    assert.match(tracking, /trackingAppointmentTooltip \|\|= window\.TechCalendarAppointmentTooltip\.create\(\)/);
    assert.match(tracking, /showTrackingAppointmentTooltip\(info\.jsEvent, info\.event, info\.el\)/);
    assert.match(booking, /if \(bookingForcePlacementEnabled\) \{[\s\S]*currentCalendarEvents = currentCalendarEvents\.filter[\s\S]*selectedCalendarEvent\.remove\(\)/);
    assert.match(tracking, /if \(trackingNativeDossiers\) \{\s*trackingCalendarEventById\(appointmentId\)\?\.remove\(\);\s*refetchCalendar\(\)/);
});
