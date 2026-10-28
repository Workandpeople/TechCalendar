import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { demoTravelEvent, demoTravelMetric } from '../../resources/js/planning/calendar-demo-travel.js';
import { calendarFixture, demoTechnicians, fixtureSuggestions } from '../../resources/js/planning/calendar-fixtures.js';
import { normalizeCalendarEvent } from '../../resources/js/planning/calendar-model.js';
import { bookingTravelMetric, bookingTravelRows } from '../../resources/js/booking-travel-tooltip.js';
import { NativeCalendar } from '../../resources/js/planning/native-calendar.js';

test('demo distances are deterministic local estimates, never missing GPS converted to zero', () => {
    const metric = demoTravelMetric({ lat: 45.76, lng: 4.84 }, { lat: 45.75, lng: 4.87 });
    assert.ok(metric.distance_km > 0);
    assert.ok(metric.duration_minutes >= 5);
    assert.equal(metric.source, 'estimation interne');
    for (const invalid of [{ lat: null, lng: null }, { lat: 100, lng: 4 }, { lat: '45', lng: 4 }, {}]) {
        assert.equal(demoTravelMetric(invalid, { lat: 45, lng: 4 }), null);
    }
    const source = readFileSync(new URL('../../resources/js/planning/calendar-demo-travel.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /fetch\(|XMLHttpRequest|https?:\/\//);
});

test('suggestion tooltips include the previous visit and the return home without mutating fixture data', () => {
    const fixture = calendarFixture(new Date(2026, 9, 28));
    const request = { customer: 'Client fictif', duration: 40, service: 'Visite', address: 'Test', postal: '69003', city: 'Lyon', latitude: 45.75, longitude: 4.87 };
    const suggestion = normalizeCalendarEvent(fixtureSuggestions(fixture.monday, request)[1]);
    const original = structuredClone(suggestion);
    const events = fixture.events.map(raw => normalizeCalendarEvent(raw));
    const prepared = demoTravelEvent(suggestion, events, demoTechnicians[0]);
    const rows = bookingTravelRows(prepared, events, demoTechnicians[0]);
    assert.deepEqual(rows.map(row => row.title), ['Domicile → proposition', 'RDV précédent → proposition', 'Proposition → retour domicile']);
    assert.equal(rows[1].detail, 'Pierre Dubois');
    for (const row of rows) assert.notEqual(bookingTravelMetric(row.metric), 'Indisponible');
    assert.deepEqual(suggestion, original);
});

test('placed appointments retain their real shape and routing callbacks work in every native calendar view', () => {
    const event = normalizeCalendarEvent(calendarFixture(new Date(2026, 9, 28)).events[0]);
    assert.equal(demoTravelEvent(event, [], demoTechnicians[0]), event);
    const handlers = {}, received = [];
    const removed = [];
    const element = { dataset: {}, removeAttribute: name => removed.push(name), addEventListener: (name, handler) => { handlers[name] = handler; } };
    const engine = { mounted: [], view: {}, options: { eventMouseEnter: info => received.push(info), eventMouseLeave: info => received.push(info) } };
    assert.equal(NativeCalendar.prototype.mountEvent.call(engine, element, event), element);
    handlers.mouseenter({ clientX: 100, clientY: 200 });
    handlers.mouseleave({ clientX: 300, clientY: 200 });
    assert.equal(received.length, 2);
    assert.equal(received[0].event, event);
    assert.equal(received[0].el, element);
    assert.equal(engine.mounted.length, 1);
    assert.deepEqual(removed, ['title']);
    const source = readFileSync(new URL('../../resources/js/planning/native-calendar.js', import.meta.url), 'utf8');
    assert.match(source, /this\.mountEvent\(button, event\)/);
});
