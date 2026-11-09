import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';
import * as dateTime from '../../resources/js/appointment-date-time.js';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const handler = source.slice(source.indexOf('let bookingPlacementBusy ='), source.indexOf("document.getElementById('booking-confirm-suggestion-btn').addEventListener"));

test('the complete MAR planning form uses the reschedule command and the source receipt without a native POST', async () => {
    const hidden = { classList: { contains: () => true } };
    const fields = Object.fromEntries(Object.entries({ booking_detail_crm_id: 'mymar-123', booking_detail_technician_id: '7',
        booking_detail_starts_at: '2026-11-18T16:30', booking_detail_duration: '120', booking_detail_comment: '',
    }).map(([name, value]) => [name, { value }]));
    const selected = { extendedProps: { is_suggestion: true, technician_name: 'Camille Test' }, title: 'MAR Test' };
    let posted, added, removed, error, confirmed = false;
    const context = {
        Date, Number,
        document: { getElementById: id => fields[id] || hidden },
        window: { TechCalendarDateTime: dateTime, CofracMymarActions: {
            place() { assert.fail('A replacement must never create another pending MAR booking'); },
            async reschedule(payload) {
                posted = payload;
                return { confirmed, message: confirmed ? 'Confirmé dans MYMAR' : 'Confirmation à vérifier',
                    source_result: confirmed ? { rdv_id: 556, starts_at: '2026-11-18T16:30:00+01:00', duration_minutes: 120 } : null };
            },
        } },
        currentAppointmentRequest: { is_mymar: true, mymar_dossier_id: 123, mymar_rdv_id: 555, expected_revision: 'revision-source' },
        selectedCalendarEvent: selected, currentAnalysisPayload: { mymar_rdv_id: 555 },
        bookingCalendar: { getEventById(id) { return { remove() { removed = id; } }; }, addEvent(event) { added = event; } },
        addMinutes: (date, minutes) => new Date(date.getTime() + minutes * 60000),
        bookingConfirmSuggestionLabel: () => 'Confirmer dans MYMAR',
        closeBookingAppointmentModal() {}, showFeedback() {}, showDetailStatus(message) { error = message; },
        fetch() { assert.fail('A MAR replacement must not use the native booking endpoint'); },
    };
    await runInNewContext(`${handler}\nplaceSelectedAppointment(false);`, context);
    assert.equal(error, undefined);
    assert.equal(posted.rdv_id, 555); assert.equal(posted.dossier_id, 123);
    assert.equal(posted.technician_id, 7); assert.equal(posted.duration_minutes, 120);
    assert.equal(posted.starts_at, '2026-11-18T16:30:00+01:00');
    assert.equal(posted.expected_revision, 'revision-source');
    assert.equal(added, undefined); assert.equal(removed, undefined);
    confirmed = true;
    await runInNewContext('placeSelectedAppointment(false);', context);
    assert.equal(error, undefined);
    assert.equal(removed, 'mymar:555'); assert.equal(added.id, 'mymar:556');
    assert.equal(added.extendedProps.source_rdv_id, 556);
    assert.equal(added.extendedProps.can_reschedule, true);
    assert.equal(added.start, '2026-11-18T16:30:00+01:00');
    assert.equal(added.end.toISOString(), '2026-11-18T17:30:00.000Z');
});
