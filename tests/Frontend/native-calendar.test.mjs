import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { addCalendarDays, calendarDate, calendarFields, calendarRange, dayKey, eventsDuring, normalizeCalendarEvent, overlappingAppointments, timelineLayout } from '../../resources/js/planning/calendar-model.js';
import { NativeCalendar } from '../../resources/js/planning/native-calendar.js';

process.env.TZ = 'Europe/Paris';
const event = (id, start, end, props = {}) => normalizeCalendarEvent({ id, start, end, extendedProps: { technician_id: 1, ...props } });

test('calendar cards adapt their height to their content in week and day views', () => {
    const css = readFileSync(new URL('../../resources/css/native-calendar.css', import.meta.url), 'utf8');
    const card = css.match(/\.nc-event \{[^}]+\}/)[0];
    assert.match(card, /min-height: 0;/);
    assert.doesNotMatch(card, /(?:^|[; ])height: \d+px/);
    assert.match(css, /\.nc-timeline-row \.nc-event \{[^}]*height: auto;/);
    assert.match(css, /\.nc-customer \{[^}]*font-size: 14px/);
});

test('native dialogs explicitly stay centered and scroll inside the available viewport', () => {
    const css = readFileSync(new URL('../../resources/css/native-calendar.css', import.meta.url), 'utf8');
    const dialog = css.match(/\.nc-slot-dialog \{[^}]+\}/)[0];
    for (const declaration of ['position: fixed;', 'inset: 0;', 'margin: auto;', 'height: fit-content;',
        'box-sizing: border-box;', 'max-height: calc(100dvh - 32px);', 'overflow-y: auto;']) {
        assert.ok(dialog.includes(declaration), declaration);
    }
});

test('dates and week ranges use local days, Monday first, and an exclusive end', () => {
    assert.equal(calendarDate('invalid'), null);
    assert.equal(calendarDate('2026-02-30'), null);
    assert.equal(calendarDate(null), null);
    assert.equal(dayKey(calendarDate('2026-10-28')), '2026-10-28');
    const range = calendarRange('2026-10-28');
    assert.equal(dayKey(range.start), '2026-10-26');
    assert.equal(dayKey(range.end), '2026-11-02');
    assert.equal(range.days.length, 6);
    assert.equal(range.days[0].getDay(), 1);
});

test('date navigation survives month changes, leap years and daylight-saving changes', () => {
    assert.equal(dayKey(addCalendarDays('2028-02-28', 1)), '2028-02-29');
    assert.equal(dayKey(addCalendarDays('2026-12-31', 1)), '2027-01-01');
    for (const day of ['2026-03-28', '2026-10-24']) {
        const next = addCalendarDays(day, 1);
        assert.equal(next.getHours(), 0);
        assert.equal(calendarRange(day, 'timeGridDay').days.length, 1);
    }
    const spring = calendarRange('2026-03-25');
    assert.equal((spring.end - spring.start) / 3600000, 167);
    const autumn = calendarRange('2026-10-21');
    assert.equal((autumn.end - autumn.start) / 3600000, 169);
    assert.equal(calendarRange('2026-10-28', 'dayGridMonth', []).days.length, 42);
});

test('events spanning midnight appear on both days but touching boundaries do not overlap', () => {
    const overnight = event('night', '2026-10-28T23:00:00+01:00', '2026-10-29T01:00:00+01:00');
    assert.equal(eventsDuring([overnight], calendarDate('2026-10-28'), calendarDate('2026-10-29')).length, 1);
    assert.equal(eventsDuring([overnight], calendarDate('2026-10-29'), calendarDate('2026-10-30')).length, 1);
    assert.equal(eventsDuring([overnight], calendarDate('2026-10-30'), calendarDate('2026-10-31')).length, 0);
    assert.equal(normalizeCalendarEvent({ start: 'broken' }), null);
    const missingEnd = normalizeCalendarEvent({ start: '2026-10-28T09:00:00+01:00', extendedProps: { duration_minutes: 40 } });
    assert.equal((missingEnd.end - missingEnd.start) / 60000, 40);
});

test('timeline cards stay legible without covering each other or losing the actual duration', () => {
    const events = [event('a', '2026-10-28T09:00:00', '2026-10-28T09:30:00'), event('b', '2026-10-28T09:45:00', '2026-10-28T10:15:00'),
        event('c', '2026-10-28T12:00:00', '2026-10-28T13:00:00')];
    const layout = timelineLayout(events, calendarDate('2026-10-28'));
    assert.equal(layout.placements[0].durationWidth, 60);
    assert.equal(layout.placements[0].width, 216);
    assert.equal(layout.placements[1].lane, 1);
    assert.equal(layout.placements[2].lane, 0);
    for (const left of layout.placements) for (const right of layout.placements) {
        if (left === right || left.lane !== right.lane) continue;
        assert.ok(left.left + left.width <= right.left || right.left + right.width <= left.left);
    }
});

test('early and late events expand the timeline rather than disappearing', () => {
    const layout = timelineLayout([event('a', '2026-10-28T06:15:00', '2026-10-28T06:45:00'),
        event('b', '2026-10-28T22:30:00', '2026-10-29T00:00:00')], calendarDate('2026-10-28'));
    assert.equal(layout.from, 360);
    assert.equal(layout.to, 1440);
    assert.equal(layout.placements.length, 2);
});

test('customer fiche technician and the whole postal code are independent card fields', () => {
    const fields = calendarFields(event('a', '2026-10-28T09:00', '2026-10-28T10:00', {
        customer_name: 'Maison <img onerror=alert(1)>', service_label: 'COFFRAC - EQ 108', technician_name: 'Camille Martin', postal_code: '01000', city: 'Bourg-en-Bresse',
    }));
    assert.equal(fields.postal, '01000');
    assert.equal(fields.service, 'COFRAC - EQ 108');
    assert.equal(fields.technician, 'Camille Martin');
    assert.match(fields.customer, /<img/);
    const source = readFileSync(new URL('../../resources/js/planning/native-calendar.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /innerHTML|insertAdjacentHTML/);
    assert.match(source, /node\.textContent = String/);
});

test('booking feed location remains visible when supplied only as a complete address', () => {
    const fields = calendarFields(event('a', '2026-10-28T09:00', '2026-10-28T10:00', { address: '12 rue du Test, 01300 Belley, France' }));
    assert.equal(fields.postal, '01300');
    assert.equal(fields.city, 'Belley');
});

test('demo time-overlap checks ignore suggestions, different technicians and the event being edited', () => {
    const current = event('a', '2026-10-28T09:00', '2026-10-28T10:00');
    const events = [current, event('b', '2026-10-28T09:30', '2026-10-28T10:30'),
        event('c', '2026-10-28T09:00', '2026-10-28T10:00', { is_suggestion: true }),
        event('d', '2026-10-28T09:00', '2026-10-28T10:00', { technician_id: 2 }),
        event('e', '2026-10-28T10:00', '2026-10-28T11:00')];
    assert.deepEqual(overlappingAppointments(events, current).map(item => item.id), ['b']);
});

function harness() {
    const calendar = Object.create(NativeCalendar.prototype);
    const calls = [];
    Object.assign(calendar, { date: calendarDate('2026-10-28'), mode: 'timeGridWeek', events: [], sequence: 0, requestId: 0, destroyed: false,
        status: {}, viewport: { setAttribute() {} }, draw() {}, options: { events: (info, success, failure) => calls.push({ info, success, failure }) } });
    return { calendar, calls };
}

test('late responses cannot overwrite a more recent calendar window', async () => {
    const { calendar, calls } = harness();
    await calendar.refetchEvents();
    await calendar.refetchEvents();
    calls[1].success([event('recent', '2026-10-28T09:00', '2026-10-28T10:00')]);
    calls[0].success([event('stale', '2026-10-28T09:00', '2026-10-28T10:00')]);
    assert.equal(calendar.events[0].id, 'recent');
    calls[1].failure(new Error('late failure'));
    assert.equal(calendar.status.hidden, true);
});

test('failed fetches preserve the previous dataset and do not claim a successful refresh', async () => {
    const { calendar, calls } = harness();
    calendar.setEvents([event('existing', '2026-10-28T09:00', '2026-10-28T10:00')]);
    await calendar.refetchEvents();
    calls[0].failure(new Error('offline'));
    assert.equal(calendar.events[0].id, 'existing');
    assert.equal(calendar.status.hidden, false);
    assert.match(calendar.status.textContent, /n’a pas pu/);
});

test('event source updates remain available to the existing booking and tracking modals', () => {
    const { calendar } = harness();
    calendar.scheduleDraw = () => {};
    calendar.setEvents([event('existing', '2026-10-28T09:00', '2026-10-28T10:00')]);
    const item = calendar.getEventById('existing');
    item.setExtendedProp('comment', 'Mise à jour');
    assert.equal(calendar.getEvents()[0].extendedProps.comment, 'Mise à jour');
    item.remove();
    assert.equal(calendar.getEvents().length, 0);
    calendar.addEventSource([event('new', '2026-10-28T09:00', '2026-10-28T10:00')]);
    calendar.addEventSource([event('new', '2026-10-28T09:00', '2026-10-28T10:00')]);
    assert.equal(calendar.getEvents().length, 1);
});

test('calendar actions remain in the toolbar when expanded and receive their own trigger', () => {
    class Node {
        constructor() { this.children = []; this.dataset = {}; this.handlers = {}; this.classList = { add() {}, toggle() {} }; }
        append(...children) { this.children.push(...children); }
        replaceChildren(...children) { this.children = children; }
        setAttribute() {} addEventListener(type, handler) { this.handlers[type] = handler; }
    }
    for (const authorized of [true, false]) {
        const calendar = Object.create(NativeCalendar.prototype);
        let click;
        calendar.document = { createElement: () => new Node() };
        calendar.browser = { addEventListener() {} }; calendar.element = new Node(); calendar.controller = new AbortController();
        calendar.options = { events: [], customButtons: authorized ? { absence: { text: 'Bloquer une demi-journée', click: (...args) => { click = args; } } } : {} };
        calendar.draw = () => {}; calendar.notifyDates = () => {};
        calendar.render(); calendar.toggleExpanded(true);
        const action = calendar.toolbar.children.find(node => node.dataset.calendarAction === 'absence');
        assert.equal(Boolean(action), authorized);
        if (action) { const event = {}; action.handlers.click(event); assert.equal(click[0], event); assert.equal(click[1], action); }
        assert.equal(calendar.element.children[0], calendar.toolbar);
    }
});

test('MYMAR observations are never counted as confirmed Cofrac appointments or suggestions', () => {
    const placed = event('placed', '2026-10-28T09:00', '2026-10-28T10:00');
    const suggestion = event('suggestion', '2026-10-28T11:00', '2026-10-28T12:00');
    suggestion.extendedProps.is_suggestion = true;
    const observation = event('mymar:1', '2026-10-28T13:00', '2026-10-28T14:00');
    observation.extendedProps.is_mymar = true;
    assert.deepEqual(NativeCalendar.prototype.eventCounts([placed, suggestion, observation]), { placed: 1, suggestions: 1, observations: 1 });
});

test('every calendar view allows clicking a blank area without intercepting appointments or controls', () => {
    class Node {
        constructor() { this.children = []; this.dataset = {}; this.handlers = {}; this.style = { setProperty() {} }; this.classList = { add() {} }; }
        append(...children) { this.children.push(...children); }
        setAttribute() {} removeAttribute() {}
        addEventListener(name, callback) { this.handlers[name] = callback; }
        getBoundingClientRect() { return { left: 0 }; }
    }
    const descendants = node => [node, ...node.children.flatMap(descendants)];
    for (const mode of ['timeGridWeek', 'timeGridDay', 'resourceWeek', 'dayGridMonth', 'listWeek']) {
        let selection, picked;
        const calendar = Object.create(NativeCalendar.prototype);
        Object.assign(calendar, { date: calendarDate('2026-11-04'), mode, zoom: 120, events: [], mounted: [], expandedCells: new Set(),
            document: { createElement: () => new Node() }, options: { nativeDirectSlotSelection: true,
                nativeResources: () => [{ id: 12, name: 'Olivia' }], dateClick: info => { selection = info; } } });
        const range = calendarRange(calendar.date, mode);
        const method = { timeGridWeek: 'week', timeGridDay: 'timeline', resourceWeek: 'resourceWeek', dayGridMonth: 'month', listWeek: 'list' }[mode];
        const tree = calendar[method](range, []);
        const cell = descendants(tree).find(node => node.dataset.placeable === 'true' || node.className === 'nc-timeline-row');
        assert.ok(cell, mode);
        cell.handlers.click({ target: cell, clientX: 320, ...(mode === 'timeGridDay' ? {} : { target: { closest: () => null } }) });
        assert.ok(selection?.date instanceof Date, mode); assert.equal(String(selection.resource.id), '12');
        picked = selection; selection = null;
        cell.handlers.click({ target: { closest: () => ({ dataset: { eventId: 'placed' } }) } });
        assert.equal(selection, null, mode);
        assert.ok(picked.dateStr.endsWith('Z'));
    }
});

test('direct slot selection uses Paris wall time even outside France and does not open a second picker', () => {
    let selection;
    const calendar = Object.create(NativeCalendar.prototype);
    calendar.date = calendarDate('2026-11-04'); calendar.mode = 'timeGridWeek';
    calendar.options = { nativeDirectSlotSelection: true, dateClick: info => { selection = info; } };
    const old = process.env.TZ; process.env.TZ = 'America/New_York';
    try {
        calendar.pickSlot(calendarDate('2026-11-04'), { id: 12 }, 640);
        assert.equal(selection.date.toISOString(), '2026-11-04T09:40:00.000Z');
        calendar.pickSlot(calendarDate('2026-07-01'), { id: 12 }, 640);
        assert.equal(selection.date.toISOString(), '2026-07-01T08:40:00.000Z');
    } finally { process.env.TZ = old; }
});

test('density changes stay local, are remembered, and tolerate denied browser storage', () => {
    const calendar = Object.create(NativeCalendar.prototype);
    let draws = 0, saved;
    calendar.draw = () => { draws++; };
    calendar.browser = { localStorage: { setItem: (...args) => { saved = args; } } };
    calendar.setDensity('compact'); assert.equal(calendar.density, 'compact');
    assert.deepEqual(saved, ['cofrac.calendar.density', 'compact']);
    calendar.setDensity('detailed'); assert.equal(draws, 2);
    calendar.setDensity('invalid'); assert.equal(draws, 2);
    calendar.browser.localStorage.setItem = () => { throw new Error('denied'); };
    assert.doesNotThrow(() => calendar.setDensity('compact'));
});

function calendarWithStorage(localStorage, options = {}) {
    return new NativeCalendar({ ownerDocument: { defaultView: { localStorage } } }, options);
}

test('every supported view is restored before the first render without changing the requested date', () => {
    for (const mode of ['timeGridWeek', 'timeGridDay', 'dayGridMonth', 'listWeek', 'resourceWeek']) {
        const calendar = calendarWithStorage({ getItem: key => key === 'cofrac.calendar.view' ? mode : null }, {
            initialView: 'timeGridWeek', initialDate: '2026-11-04',
        });
        assert.equal(calendar.mode, mode);
        assert.equal(calendar.view.type, mode);
        assert.equal(dayKey(calendar.getDate()), '2026-11-04');
        assert.equal(calendar.dateInfo().startStr, calendar.view.currentStart.toISOString());
    }
});

test('changing views persists the choice across calendar recreation and navigation between pages', () => {
    const saved = new Map();
    const storage = { getItem: key => saved.get(key) ?? null, setItem: (key, value) => saved.set(key, value) };
    const booking = calendarWithStorage(storage);
    let draws = 0, notifications = 0;
    booking.draw = () => { draws++; };
    booking.notifyDates = () => { notifications++; };
    for (const mode of ['resourceWeek', 'timeGridDay', 'dayGridMonth', 'listWeek', 'timeGridWeek']) {
        booking.changeView(mode);
        assert.equal(saved.get('cofrac.calendar.view'), mode);
        assert.equal(calendarWithStorage(storage, { initialView: 'timeGridWeek' }).mode, mode);
    }
    assert.equal(draws, 5);
    assert.equal(notifications, 5);
    assert.deepEqual([...saved.keys()], ['cofrac.calendar.view']);
});

test('invalid saved views keep the configured default, and inaccessible storage never breaks navigation', () => {
    for (const saved of [null, '', 'unknown', 'resourceDay', '{}', 'undefined']) {
        assert.equal(calendarWithStorage({ getItem: () => saved }, { initialView: 'listWeek' }).mode, 'listWeek');
    }
    assert.equal(calendarWithStorage(undefined, { initialView: 'invalid' }).mode, 'timeGridWeek');
    const denied = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('full'); } };
    const calendar = calendarWithStorage(denied, { initialView: 'timeGridDay' });
    assert.equal(calendar.mode, 'timeGridDay');
    let fetched = 0;
    calendar.draw = () => {};
    calendar.notifyDates = () => {};
    calendar.options.events = () => {};
    calendar.refetchEvents = () => { fetched++; };
    assert.doesNotThrow(() => calendar.changeView('resourceWeek'));
    assert.equal(calendar.mode, 'resourceWeek');
    assert.equal(fetched, 1);

    const browser = {};
    Object.defineProperty(browser, 'localStorage', { get() { throw new Error('blocked'); } });
    assert.doesNotThrow(() => new NativeCalendar({ ownerDocument: { defaultView: browser } }));
    calendar.browser = browser;
    assert.doesNotThrow(() => calendar.changeView('listWeek'));
    assert.equal(calendar.mode, 'listWeek');
});

test('invalid views and destroyed calendars do not overwrite the remembered choice', () => {
    const writes = [];
    const calendar = calendarWithStorage({ getItem: () => null, setItem: (...args) => writes.push(args) });
    calendar.draw = () => assert.fail('unexpected render');
    calendar.notifyDates = () => assert.fail('unexpected notification');
    calendar.changeView('invalid');
    calendar.destroyed = true;
    calendar.changeView('resourceWeek');
    assert.deepEqual(writes, []);
    assert.equal(calendar.mode, 'timeGridWeek');
});
