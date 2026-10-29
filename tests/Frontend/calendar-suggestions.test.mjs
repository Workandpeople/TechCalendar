import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { NativeCalendar } from '../../resources/js/planning/native-calendar.js';
import { calendarRange } from '../../resources/js/planning/calendar-model.js';

process.env.TZ = 'Europe/Paris';
const booking = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const renderer = booking.slice(booking.indexOf('const bookingCalendarEventContent ='), booking.indexOf('const refreshCalendarWindow ='));
const escapeStart = booking.indexOf('const escapeHtml =');
const escape = booking.slice(escapeStart, booking.indexOf('const formatShortDate =', escapeStart));
const suggestion = {
    id: 'suggestion-1', title: 'Client confidentiel · COFRAC · 12 rue privée',
    start: new Date('2026-10-28T09:15:00+01:00'), end: new Date('2026-10-28T10:00:00+01:00'),
    extendedProps: { is_suggestion: true, technician_id: 7, technician_name: 'Camille', technician_full_name: 'Camille Martin',
        customer_name: 'Client confidentiel', service_label: 'COFRAC', address: '12 rue privée', postal_code: '01300', city: 'Belley',
        travel_to_distance_km: 20, travel_to_minutes: 30, booking_travel_badges: [{ label: '30 min', title: 'Trajet', kind: 'before' }] },
};

test('suggestions use a light dashed outline and do not stretch to confirmed card height', () => {
    const css = readFileSync(new URL('../../resources/css/native-calendar.css', import.meta.url), 'utf8');
    const style = css.match(/\.nc-event\[data-suggestion='true'\], \.nc-month-event\[data-suggestion='true'\] \{([^}]+)\}/)[1];
    assert.match(style, /align-self: start/);
    assert.match(style, /border: 1px dashed/);
    assert.match(style, /box-shadow: none/);
    assert.match(booking, /\.appointment-suggestion \{[^}]*border-width: 1px !important;[^}]*box-shadow: none/);
});

function element(tagName) {
    const node = { tagName, children: [], dataset: {}, attributes: {}, handlers: {}, textContent: '', className: '',
        style: { setProperty() {} },
        append(...children) { this.children.push(...children); },
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; },
        addEventListener(key, handler) { this.handlers[key] = handler; },
    };
    node.classList = { add: name => { node.className += ` ${name}`; } };
    return node;
}

const text = node => [node.textContent, ...node.children.map(text)].filter(Boolean).join(' ');
const descendants = node => [node, ...node.children.flatMap(descendants)];

function nativeFixture() {
    const root = element('div');
    root.ownerDocument = { defaultView: {}, createElement: element, createElementNS: (_namespace, tag) => element(tag) };
    const calls = [];
    const calendar = new NativeCalendar(root, {
        initialDate: suggestion.start, events: [suggestion],
        eventMouseEnter: info => calls.push(['enter', info]), eventMouseLeave: info => calls.push(['leave', info]),
        eventClick: info => calls.push(['click', info]), eventWillUnmount: info => calls.push(['unmount', info]),
    });
    return { calendar, calls };
}

for (const [mode, render] of [['timeGridWeek', 'week'], ['timeGridDay', 'timeline'], ['dayGridMonth', 'month'], ['listWeek', 'list'], ['resourceWeek', 'resourceWeek']]) {
    test(`native ${mode} keeps suggestions minimal and preserves tooltip and selection event identity`, () => {
        const { calendar, calls } = nativeFixture();
        calendar.mode = mode;
        const tree = calendar[render](calendarRange(suggestion.start, mode), calendar.events);
        const cards = descendants(tree).filter(node => node.dataset.eventId === suggestion.id);
        assert.equal(cards.length, 1);
        const card = cards[0];
        assert.equal(card.dataset.suggestion, 'true');
        assert.match(text(card), /Suggestion/);
        assert.match(text(card), /09:15 – 10:00/);
        assert.equal(descendants(card).filter(node => node.tagName === 'svg').length, 1);
        assert.equal(text(card).includes('Camille Martin'), !['timeGridDay', 'resourceWeek'].includes(mode));
        for (const content of [text(card), card.attributes['aria-label']]) {
            assert.doesNotMatch(content, /confidentiel|COFRAC|privée|01300|Belley/);
        }
        card.handlers.mouseenter({ clientX: 100 });
        card.handlers.mouseleave({});
        card.handlers.click({});
        calendar.unmountEvents();
        assert.deepEqual(calls.map(([name]) => name), ['enter', 'leave', 'click', 'unmount']);
        for (const [, info] of calls) {
            assert.equal(info.event, calendar.events[0]);
            assert.equal(info.event.extendedProps.travel_to_distance_km, 20);
            assert.equal(info.el, card);
        }
    });
}

test('native confirmed appointments retain customer fiche location and technician details', () => {
    const { calendar } = nativeFixture();
    const event = calendar.event({ ...suggestion, extendedProps: { ...suggestion.extendedProps, is_suggestion: false } });
    const card = calendar.card(event);
    assert.equal(card.dataset.suggestion, 'false');
    assert.match(text(card), /Client confidentiel COFRAC 01300 Belley Camille Martin/);
    assert.doesNotMatch(text(card), /Suggestion/);
});

test('native technician values are plain text and absent names do not add a placeholder', () => {
    const { calendar } = nativeFixture();
    const malicious = calendar.event({ ...suggestion, extendedProps: { ...suggestion.extendedProps, technician_full_name: '<img src=x onerror=alert(1)>' } });
    const card = calendar.card(malicious);
    assert.match(text(card), /<img src=x onerror=alert\(1\)>/);
    assert.equal(descendants(card).some(node => node.tagName === 'img'), false);
    const unnamed = calendar.event({ ...suggestion, extendedProps: { is_suggestion: true } });
    assert.doesNotMatch(text(calendar.card(unnamed)), /non renseigné/);
});

function fullCalendarFixture() {
    return runInNewContext(`${escape}\n${renderer}\n({ render: bookingCalendarEventContent, colorize: colorizedEvents });`, {
        calendarTimeLabel: date => date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }),
        visibleCalendarItems: events => events,
        currentTechnicianColors: { 7: '#1764d9' }, travelBadgesForSuggestion: event => event.extendedProps.booking_travel_badges,
    });
}

test('FullCalendar suggestions never repeat customer fiche or address, including month fallback time labels', () => {
    const { render } = fullCalendarFixture();
    for (const timeText of ['09:15 - 10:00', '']) {
        const { html } = render({ event: suggestion, timeText });
        assert.match(html, /aria-hidden="true"/);
        assert.match(html, />Suggestion<\/span>/);
        assert.match(html, /09:15/);
        assert.match(html, /10:00/);
        assert.match(html, /Camille Martin/);
        assert.doesNotMatch(html, /confidentiel|COFRAC|privée|01300|Belley|travel-badge/);
    }
    const { html } = render({ event: { ...suggestion, extendedProps: { is_suggestion: true, technician_name: '<img onerror=x>' } }, timeText: '<script>' });
    assert.match(html, /&lt;img onerror=x&gt;/);
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /<img|<script/);
});

test('FullCalendar confirmed content and suggestion payloads remain intact for existing tooltips and modals', () => {
    const { render, colorize } = fullCalendarFixture();
    assert.match(render({ event: { ...suggestion, extendedProps: {} }, timeText: '09:15' }).html, /Client confidentiel/);
    const original = structuredClone(suggestion);
    const [prepared] = colorize([], [suggestion]);
    assert.equal(prepared.backgroundColor, '#1764d90a');
    assert.ok(prepared.classNames.includes('appointment-suggestion'));
    assert.equal(prepared.extendedProps.travel_to_minutes, 30);
    assert.equal(prepared.extendedProps.address, suggestion.extendedProps.address);
    assert.deepEqual(suggestion, original);
    assert.match(booking, /hideSuggestionTooltip\(\);\s*openBookingAppointmentModal\(info.event\)/);
});

test('tracking keeps its existing edit action with an explicit schedule label', () => {
    const tracking = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');
    assert.match(tracking, /id="tracking-open-edit"[^>]*>Modifier le RDV \/ horaire<\/button>/);
    assert.match(tracking, /editButton.disabled = props.can_edit === false \|\| props.post_visit_locked === true/);
});
