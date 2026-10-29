import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import * as dateTime from '../../resources/js/appointment-date-time.js';

test('formats and serializes the chosen Paris time with a date-specific DST offset', () => {
    for (const [input, expected] of [
        ['2026-01-15T16:30', '2026-01-15T16:30:00+01:00'],
        ['2026-07-15T16:30', '2026-07-15T16:30:00+02:00'],
        ['2026-03-29T16:30', '2026-03-29T16:30:00+02:00'],
        ['2026-10-25T16:30', '2026-10-25T16:30:00+01:00'],
        ['2026-01-15T15:30:00.000Z', '2026-01-15T16:30:00+01:00'],
        ['2026-07-15T14:30:00Z', '2026-07-15T16:30:00+02:00'],
        ['2026-07-15T10:30:00-04:00', '2026-07-15T16:30:00+02:00'],
        ['2026-07-15T23:30:00-04:00', '2026-07-16T05:30:00+02:00'],
        ['2026-10-25T02:30', '2026-10-25T02:30:00+01:00'],
    ]) {
        assert.equal(dateTime.serializeAppointmentInput(input), expected);
        assert.equal(dateTime.formatAppointmentInput(input), expected.slice(0, 16));
        assert.equal(dateTime.parseAppointmentDate(expected).getTime(), dateTime.parseAppointmentDate(input).getTime());
    }
});

test('rejects impossible hours and invalid dates without automatic rollover', () => {
    for (const value of ['', null, undefined, new Date(NaN), '2026-02-30T16:30', '2026-03-29T02:30',
        '2026-07-15T24:00', '2026-07-15T16:60', '2026-07-15T16:30:60',
        '2026-07-15T16:30+15:00', '2026-07-15T16:30+14:01', '2026-07-15T16:30+02:60', 'next Thursday']) {
        assert.equal(dateTime.parseAppointmentDate(value), null);
        assert.equal(dateTime.formatAppointmentInput(value), '');
        assert.throws(() => dateTime.serializeAppointmentInput(value), RangeError);
    }
});

test('does not depend on the browser timezone for input, payload or confirmation', () => {
    const moduleUrl = new URL('../../resources/js/appointment-date-time.js', import.meta.url).href;
    for (const timezone of ['UTC', 'Europe/Paris', 'Europe/Athens', 'America/New_York', 'Asia/Tokyo']) {
        const output = execFileSync(process.execPath, ['--input-type=module', '-e', `
            import * as dt from ${JSON.stringify(moduleUrl)};
            console.log(JSON.stringify([
                dt.formatAppointmentInput('2026-01-15T16:30:00+01:00'),
                dt.serializeAppointmentInput('2026-07-15T16:30'),
                dt.formatAppointmentDateTime('2026-01-15T15:30:00Z', { hour: '2-digit', minute: '2-digit' }),
                ['2026-01-15T16:30:00+01:00', '2026-07-15T16:30:00+02:00']
                    .map(value => dt.serializeAppointmentInput(dt.formatAppointmentInput(new Date(value)))),
            ]));
        `], { env: { ...process.env, TZ: timezone }, encoding: 'utf8' });
        assert.deepEqual(JSON.parse(output), ['2026-01-15T16:30', '2026-07-15T16:30:00+02:00', '16:30',
            ['2026-01-15T16:30:00+01:00', '2026-07-15T16:30:00+02:00']], timezone);
    }
});

test('the booking handler sends edited input rather than the original suggestion time', async () => {
    const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    const handler = source.slice(source.indexOf('let bookingPlacementBusy ='), source.indexOf("document.getElementById('booking-confirm-suggestion-btn').addEventListener"));
    const nodes = Object.fromEntries(Object.entries({
        booking_detail_crm_id: 'dossier-1', booking_detail_technician_id: '7', booking_detail_starts_at: '2026-07-15T16:30',
        booking_detail_duration: '45', booking_detail_comment: 'Edited',
    }).map(([id, value]) => [id, { value }]));
    const hidden = { classList: { contains: () => true } };
    let sent, confirmation, error;
    const selectedCalendarEvent = { start: new Date('2026-07-15T08:00:00+02:00'), extendedProps: { is_suggestion: true, technician_name: 'Tech' } };
    const context = {
        document: { getElementById: id => nodes[id] || hidden },
        window: { TechCalendarDateTime: dateTime },
        selectedCalendarEvent, currentAnalysisPayload: { replace_appointment_id: 12 },
        bookingStoreUrl: '/book', bookingCsrfToken: 'csrf', bookingConnectorsEnabled: false,
        bookingIsReplacementMode: true, bookingConfirmSuggestionLabel: () => 'Confirmer',
        fetch: async (url, options) => { sent = JSON.parse(options.body); return { ok: true, json: async () => ({ appointment_id: 12 }) }; },
        closeBookingAppointmentModal() {}, showPlacementConfirmation: (data, payload) => { confirmation = payload; },
        showDetailStatus: message => { error = message; },
    };
    await runInNewContext(`${handler}\nplaceSelectedAppointment(false);`, context);
    assert.equal(error, undefined);
    assert.equal(sent.starts_at, '2026-07-15T16:30:00+02:00');
    assert.equal(sent.duration_minutes, '45');
    assert.equal(sent.replace_appointment_id, 12);
    assert.equal(confirmation.starts_at, sent.starts_at);
    nodes.booking_detail_starts_at.value = '2026-07-15T16:45';
    await runInNewContext('placeSelectedAppointment(false);', context);
    assert.equal(sent.starts_at, '2026-07-15T16:45:00+02:00');
    sent = null;
    nodes.booking_detail_starts_at.value = '2026-03-29T02:30';
    await runInNewContext('placeSelectedAppointment(false);', context);
    assert.equal(sent, null);
    assert.match(error, /date/);
});

test('placement confirmation uses the server-persisted hour before the draft value', () => {
    const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    const expression = source.match(/const startsAt = data\.appointment\?\.start[^;]+;/)?.[0];
    assert.ok(expression);
    const chosen = runInNewContext(`${expression}\nstartsAt;`, {
        data: { appointment: { start: '2026-07-15T16:30:00+02:00' } },
        payload: { starts_at: '2026-07-15T14:30:00Z' }, event: { start: '2026-07-15T08:00:00+02:00' },
    });
    assert.equal(chosen, '2026-07-15T16:30:00+02:00');
});

test('the tracking handler sends edited Paris time and updates the native event without setStart', async () => {
    const source = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');
    const handlerStart = source.indexOf("trackingDetailsForm?.addEventListener('submit'");
    const handler = source.slice(handlerStart, source.indexOf("\n        });", handlerStart) + "\n        });".length);
    const setters = source.slice(source.indexOf('const trackingSetEventStart ='), source.indexOf('const trackingSetEventTitle ='));
    const nodes = Object.fromEntries(Object.entries({
        tracking_detail_appointment_id: '12', tracking_detail_starts_at: '2026-01-15T16:30',
        tracking_detail_duration_minutes: '45', tracking_detail_address_input: '10 rue du Test',
        tracking_detail_latitude: '48.85', tracking_detail_longitude: '2.35',
    }).map(([id, value]) => [id, { value }]));
    const nativeEvent = { start: new Date('2026-01-15T10:00:00+01:00'), extendedProps: {} };
    let callback, sent, status;
    const context = {
        Date,
        trackingDetailsForm: { addEventListener: (type, listener) => { callback = listener; } },
        trackingDetailsUrlTemplate: '/appointments/__APPOINTMENT__',
        document: { getElementById: id => nodes[id] || (id.startsWith('tracking_edit_') ? null : {}) },
        window: { TechCalendarDateTime: dateTime, setTimeout() {}, TechCalendarAppointments: { request: async (url, data) => {
            sent = data;
            return { appointment: { start: data.starts_at, end: '2026-01-15T17:15:00+01:00' } };
        } } },
        trackingDetailEventById: () => nativeEvent,
        trackingSetEventExtendedProp: (event, key, value) => { event.extendedProps[key] = value; },
        setTrackingDetailsStatus: value => { status = value; },
        formatDateTime: value => dateTime.formatAppointmentDateTime(value),
        setText() {}, refetchCalendar() {},
    };
    runInNewContext(`${setters}\n${handler}`, context);
    await callback({ preventDefault() {} });
    assert.equal(sent.starts_at, '2026-01-15T16:30:00+01:00');
    assert.equal(nativeEvent.start.toISOString(), '2026-01-15T15:30:00.000Z');
    assert.equal(status, 'Rendez-vous mis à jour.');
    sent = null;
    nodes.tracking_detail_starts_at.value = '2026-03-29T02:30';
    await callback({ preventDefault() {} });
    assert.equal(sent, null);
    assert.match(status, /Europe\/Paris/);
});
