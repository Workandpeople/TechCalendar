import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { bookingTravelRows, bookingTravelMetric, createBookingTravelTooltip } from '../../resources/js/booking-travel-tooltip.js';

function appointment(id, hour, extra = {}) {
    return { id: String(id), start: new Date(2026, 9, 26, hour), extendedProps: {
        technician_id: 7, technician_name: 'Camille TECH (75)', technician_full_name: 'Camille TECH',
        technician_latitude: 48.8, technician_longitude: 2.3, technician_address: 'Domicile test',
        latitude: 48.9 + id / 100, longitude: 2.4, customer_name: `Client ${id}`, ...extra,
    } };
}

test('placed events use neighboring real appointments for the same technician and local day only', () => {
    const current = appointment(2, 12), previous = appointment(1, 10), next = appointment(3, 15);
    const otherDay = { ...appointment(4, 11), start: new Date(2026, 9, 27, 11) };
    const rows = bookingTravelRows(current, [next, appointment(8, 13, { is_suggestion: true }),
        appointment(9, 11, { technician_id: 99 }), otherDay, current, previous]);
    assert.deepEqual(rows.map(row => row.title), ['Domicile → RDV', 'RDV précédent → RDV', 'RDV → prochain RDV']);
    assert.equal(rows[1].detail, 'Client 1');
    assert.equal(rows[2].detail, 'Client 3');
    assert.deepEqual(rows[1].from, { lat: 48.91, lng: 2.4 });
    assert.deepEqual(rows[2].to, { lat: 48.93, lng: 2.4 });
});

test('first and final appointments show the appropriate home and return legs', () => {
    const first = appointment(1, 10), last = appointment(2, 12);
    assert.deepEqual(bookingTravelRows(first, [last, first]).map(row => row.title), ['Domicile → RDV', 'RDV → prochain RDV']);
    const rows = bookingTravelRows(last, [first, last]);
    assert.equal(rows.at(-1).title, 'RDV → retour domicile');
    assert.deepEqual(rows.at(-1).to, { lat: 48.8, lng: 2.3 });
});

test('suggestions retain already calculated distances without requesting them again', () => {
    const event = appointment(1, 10, { is_suggestion: true, has_previous_appointment: true, has_next_appointment: true,
        home_to_distance_km: 30, home_to_minutes: 45, travel_to_distance_km: 5, travel_to_minutes: 12,
        travel_after_distance_km: 9, travel_after_minutes: 17, origin_name: 'Client précédent' });
    const rows = bookingTravelRows(event);
    assert.deepEqual(rows.map(row => bookingTravelMetric(row.metric)), ['30.0 km · 45 min', '5.0 km · 12 min', '9.0 km · 17 min']);
    assert.equal(rows[1].detail, 'Client précédent');
});

test('never presents missing distances as zero and identifies internal estimates', () => {
    for (const metric of [null, {}, { distance_km: null, duration_minutes: 0 }, { distance_km: -1, duration_minutes: 20 },
        { distance_km: 10, duration_minutes: NaN }]) assert.equal(bookingTravelMetric(metric), 'Indisponible');
    assert.equal(bookingTravelMetric({ distance_km: 0, duration_minutes: 0 }), '0.0 km · 0 min');
    assert.equal(bookingTravelMetric({ distance_km: 90.52, duration_minutes: 125 }), '90.5 km · 2h05');
    assert.equal(bookingTravelMetric({ distance_km: 20, duration_minutes: 60, source: 'estimation interne' }), 'Estimation · 20.0 km · 1h');
});

function element() {
    return { children: [], style: {}, attributes: {}, hidden: false,
        append(child) { this.children.push(child); }, replaceChildren() { this.children = []; },
        setAttribute(key, value) { this.attributes[key] = value; }, getAttribute(key) { return this.attributes[key] ?? null; },
        removeAttribute(key) { delete this.attributes[key]; }, getBoundingClientRect: () => ({ width: 360, height: 300 }),
    };
}

function harness(routeBetween = async () => ({ distance_km: 12, duration_minutes: 25 }), disclaimer = '') {
    const handlers = {}, timers = new Map(), calls = [];
    let timer = 0;
    const body = element();
    const root = { body, documentElement: { clientWidth: 1200 }, createElement: element,
        addEventListener(name, fn) { handlers[`root:${name}`] = fn; } };
    const browser = { innerHeight: 720, addEventListener(name, fn) { handlers[name] = fn; },
        setTimeout(fn, delay) { const id = ++timer; timers.set(id, { fn, delay }); return id; }, clearTimeout: id => timers.delete(id) };
    let events = [];
    const tooltip = createBookingTravelTooltip({ root, browser, routeBetween: (...args) => { calls.push(args); return routeBetween(...args); },
        getEvents: () => events, getTechnician: () => ({ name: 'Fallback TECH' }), disclaimer });
    const run = delay => {
        const item = [...timers].find(([, entry]) => entry.delay === delay);
        if (!item) throw new Error(`No ${delay}ms timer`);
        timers.delete(item[0]);
        return item[1].fn();
    };
    const text = node => [node.textContent || '', ...node.children.map(text)].join(' ');
    return { tooltip, body, handlers, timers, calls, run, text: () => text(body), setEvents: value => { events = value; } };
}

const position = { clientX: 600, clientY: 200 };

test('isolated demos clearly label fictitious distances as safe text without changing normal tooltips', () => {
    const ui = harness(undefined, 'Démo locale : distances fictives <b>sans Mapbox</b>.');
    ui.tooltip.show(position, appointment(1, 10, { is_suggestion: true }));
    assert.match(ui.text(), /Démo locale : distances fictives <b>sans Mapbox<\/b>/);
    assert.equal(ui.body.children[0].innerHTML, undefined);
    const normal = harness();
    normal.tooltip.show(position, appointment(1, 10, { is_suggestion: true }));
    assert.doesNotMatch(normal.text(), /Démo locale/);
});

test('one tooltip shows the full technician name as safe text for suggestions and placed events', async () => {
    const ui = harness();
    const event = appointment(1, 10, { technician_full_name: '<img src=x onerror=alert(1)>', service_label: 'COFFRAC - BAR EN 101 <script>' });
    const source = element(); source.setAttribute('aria-describedby', 'previous-help');
    ui.tooltip.show(position, event, source);
    assert.match(ui.text(), /Technicien : <img src=x onerror=alert\(1\)>/);
    assert.match(ui.text(), /Fiche : COFFRAC - BAR EN 101 <script>/);
    assert.match(ui.text(), /Calcul du trajet/);
    await ui.run(150);
    assert.match(ui.text(), /12.0 km · 25 min/);
    assert.equal(ui.body.children[0].innerHTML, undefined);
    assert.equal(source.attributes['aria-describedby'], 'previous-help booking-travel-tooltip');
    ui.tooltip.hide();
    assert.equal(source.attributes['aria-describedby'], 'previous-help');
    ui.tooltip.show(position, appointment(2, 12, { is_suggestion: true, home_to_distance_km: 4, home_to_minutes: 8 }));
    assert.match(ui.text(), /Distances de la proposition/);
    assert.match(ui.text(), /Technicien : Camille TECH/);
    assert.match(ui.text(), /Fiche : Non renseignée/);
    assert.equal(ui.timers.size, 0);
    assert.equal(ui.calls.length, 2);
});

test('calculates only after sustained hover and reuses directional coordinate-based cached routes', async () => {
    const ui = harness(), current = appointment(1, 10);
    ui.tooltip.show(position, current);
    ui.tooltip.hide();
    assert.equal(ui.calls.length, 0);
    assert.equal(ui.timers.size, 0);
    ui.tooltip.show(position, current); await ui.run(150);
    assert.equal(ui.calls.length, 2);
    ui.tooltip.show(position, current); await ui.run(150);
    assert.equal(ui.calls.length, 2);
    ui.tooltip.show(position, appointment(1, 10, { longitude: 3 })); await ui.run(150);
    assert.equal(ui.calls.length, 4);
});

test('does not call directions with absent or invalid GPS and never substitutes null island', async () => {
    const ui = harness();
    ui.tooltip.show(position, appointment(1, 10, { technician_latitude: '', technician_longitude: null, latitude: 120 }));
    await ui.run(150);
    assert.equal(ui.calls.length, 0);
    assert.match(ui.text(), /Indisponible/);
    assert.doesNotMatch(ui.text(), /0.0 km/);
});

test('aborts requests on exit and ignores stale results after another appointment is hovered', async () => {
    const pending = [];
    const ui = harness(() => new Promise(resolve => pending.push(resolve)));
    ui.tooltip.show(position, appointment(1, 10));
    const previous = ui.run(150);
    const signal = ui.calls[0][2];
    ui.tooltip.show(position, appointment(2, 12, { technician_full_name: 'New TECH', is_suggestion: true }));
    assert.equal(signal.aborted, true);
    pending.forEach(resolve => resolve({ distance_km: 99, duration_minutes: 99 }));
    await previous;
    assert.match(ui.text(), /New TECH/);
    assert.doesNotMatch(ui.text(), /99.0 km/);
});

test('times out unavailable directions and cleans up after scroll escape blur and resize', async () => {
    const ui = harness(() => new Promise(() => {}));
    ui.tooltip.show(position, appointment(1, 10));
    const pending = ui.run(150);
    ui.run(10000);
    await pending;
    assert.equal(ui.calls[0][2].aborted, true);
    assert.match(ui.text(), /Indisponible/);
    for (const [name, event] of [['root:scroll'], ['root:keydown', { key: 'Escape' }], ['blur'], ['resize'], ['pagehide']]) {
        ui.tooltip.show(position, appointment(1, 10));
        ui.handlers[name](event);
        assert.equal(ui.body.children[0].hidden, true);
        assert.equal(ui.timers.size, 0);
    }
});

test('calendar mounts show the same tooltip for every event and accept abort signals on routing', () => {
    const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    const handlers = source.slice(source.indexOf('eventMouseEnter: (info)'), source.indexOf('dateClick: openCalendarSlotModal'));
    assert.doesNotMatch(handlers, /if \(!info.event.extendedProps\?\.is_suggestion\) return/);
    assert.match(handlers, /tooltip.show\(info.jsEvent, info.event, info.el\)/);
    assert.match(handlers, /eventDidMount:/);
    assert.match(handlers, /eventWillUnmount:/);
    assert.match(source, /const fetchRouteBetween = async \(origin, destination, signal = undefined\)/);
    assert.match(source, /headers: \{ Accept: 'application\/json' \}, signal/);
});
