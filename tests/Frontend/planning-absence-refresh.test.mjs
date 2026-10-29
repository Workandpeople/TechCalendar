import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const view = name => readFileSync(new URL(`../../resources/views/planner/${name}.blade.php`, import.meta.url), 'utf8');
const booking = view('book'), tracking = view('tracking');
const bookingHandler = booking.slice(booking.indexOf('const refreshBookingAfterAbsence ='), booking.indexOf('const analyzeAppointment ='));
const trackingHandler = tracking.slice(tracking.indexOf('const refreshTrackingAfterAbsence ='), tracking.indexOf('const applyTechnicianFilter ='));

test('both pages include the authorized absence modal once and outside every form', () => {
    for (const source of [booking, tracking]) {
        const include = "@include('planner.partials.absence-block-modal')";
        assert.equal(source.split(include).length - 1, 1);
        const position = source.indexOf(include);
        assert.ok(position > 0 && position < source.indexOf('<form'));
    }
});

function bookingFixture(overrides = {}) {
    const calls = [];
    const currentTechnicians = [{ id: 1, absences: [{ id: 10, technician_id: 1, reason: 'Existante' }] }, { id: 2, absences: [] }];
    const selectedTechnicianIds = new Set(['1']);
    const selectedCalendarEvent = { id: 'draft', start: '2026-11-02T09:00:00+01:00' };
    const lastCalendarDateInfo = { startStr: '2026-11-02', endStr: '2026-11-09' };
    const context = {
        currentTechnicians, selectedTechnicianIds, selectedCalendarEvent, lastCalendarDateInfo,
        currentAppointmentRequest: { id: 'dossier-1' }, currentFilters: {}, currentAnalysisPayload: { crm_appointment_id: 'dossier-1' }, bookingCalendar: {},
        technicianAbsences: technician => technician.absences || [],
        renderTechnicians: (...args) => calls.push(['render', ...args]),
        hideSuggestionTooltip: () => calls.push(['hide']),
        refreshCalendarWindow: async (...args) => calls.push(['refresh', ...args]),
        window: { addEventListener: (name, handler) => { context.listener = { name, handler }; } },
        ...overrides,
    };
    runInNewContext(bookingHandler, context);
    return { calls, context, selectedCalendarEvent, selectedTechnicianIds, lastCalendarDateInfo, run: context.listener.handler };
}

test('booking updates absences for checked and unchecked technicians and refetches only the active window', async () => {
    const ui = bookingFixture();
    const event = { detail: { absences: [{ id: 11, technician_id: 1, reason: 'Formation' }, { id: 12, technician_id: 2, reason: 'Indisponible' }] } };
    assert.equal(ui.context.listener.name, 'planning:absences-created');
    await ui.run(event);
    await ui.run(event);
    assert.deepEqual(Array.from(ui.context.currentTechnicians[0].absences, absence => absence.id), [10, 11]);
    assert.deepEqual(Array.from(ui.context.currentTechnicians[1].absences, absence => absence.id), [12]);
    assert.deepEqual([...ui.selectedTechnicianIds], ['1']);
    assert.equal(ui.context.selectedTechnicianIds, ui.selectedTechnicianIds);
    assert.equal(ui.context.selectedCalendarEvent, ui.selectedCalendarEvent);
    assert.equal(ui.context.lastCalendarDateInfo, ui.lastCalendarDateInfo);
    const refreshes = ui.calls.filter(([name]) => name === 'refresh');
    assert.equal(refreshes.length, 2);
    assert.equal(refreshes[0][1], ui.lastCalendarDateInfo);
    assert.equal(refreshes[0][2].force, true);
    assert.equal(ui.calls[0][0], 'render');
});

test('booking handles an absent analysis or empty selection without starting a new analysis or navigating', async () => {
    const idle = bookingFixture({ currentAppointmentRequest: null, currentFilters: null, currentAnalysisPayload: null, bookingCalendar: null, lastCalendarDateInfo: null });
    await idle.run({ detail: { absences: [null, { technician_id: 1 }, { id: 11, technician_id: 1 }] } });
    assert.equal(idle.calls.length, 0);
    assert.equal(idle.context.currentTechnicians[0].absences.length, 2);
    const empty = bookingFixture({ selectedTechnicianIds: new Set() });
    await empty.run({});
    assert.equal(empty.context.selectedTechnicianIds.size, 0);
    assert.equal(empty.calls.filter(([name]) => name === 'refresh').length, 1);
    assert.doesNotMatch(bookingHandler, /location|gotoDate|analyzeAppointment|selectedTechnicianIds\s*=/);
    assert.match(booking, /if \(Array.isArray\(payload.technicians\)\) \{\s*mergeTechnicianUpdates\(payload.technicians\)/);
});

test('tracking refetches the current calendar and visible list page without touching selection or view', () => {
    for (const visible of [true, false, null]) {
        const calls = [];
        const context = {
            hideTrackingAppointmentTooltip: () => calls.push(['hide']), refetchCalendar: () => calls.push(['calendar']),
            runTrackingAppointmentSearch: page => calls.push(['list', page]), trackingSearchPage: 3,
            document: { getElementById: () => visible === null ? null : ({ classList: { contains: () => !visible } }) },
            window: { addEventListener: (name, handler) => { context.listener = { name, handler }; } },
        };
        runInNewContext(trackingHandler, context);
        assert.equal(context.listener.name, 'planning:absences-created');
        context.listener.handler();
        assert.deepEqual(calls, visible ? [['hide'], ['calendar'], ['list', 3]] : [['hide'], ['calendar']]);
    }
    assert.doesNotMatch(trackingHandler, /location|gotoDate|checked\s*=|applyInitialTechnicianSelection/);
});
