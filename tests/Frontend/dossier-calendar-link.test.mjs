import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');
const selection = source.slice(source.indexOf('const trackingQueryParams ='), source.indexOf('let trackingCalendar ='));
const initialOpen = source.slice(source.indexOf('const openInitialTrackingAppointment ='), source.indexOf('const initTrackingCalendar ='));

test('a dossier shortcut opens its loaded appointment once, even after a calendar refresh', () => {
    assert.ok(source.includes('eventsSet: openInitialTrackingAppointment'));
    const opened = [];
    const context = {
        URLSearchParams,
        window: { location: { search: '?date=2026-10-14&technician_id=7&appointment_id=42' } },
        openTrackingAppointmentModal: (event) => opened.push(event),
        events: [{ id: '41' }],
    };
    runInNewContext(`${selection}\n${initialOpen}\nopenInitialTrackingAppointment(events);`, context);
    assert.equal(opened.length, 0);
    context.events = [{ id: '41' }, { id: '42' }];
    runInNewContext('openInitialTrackingAppointment(events);', context);
    assert.deepEqual(opened, [{ id: '42' }]);
    runInNewContext('openInitialTrackingAppointment(events);', context);
    assert.equal(opened.length, 1);
});

for (const id of ['', '0', '-1', '42wrong']) {
    test(`does not open an invalid or missing appointment identifier: ${id || '(empty)'}`, () => {
        runInNewContext(`${selection}\n${initialOpen}\nopenInitialTrackingAppointment([{id: "${id}"}]);`, {
            URLSearchParams,
            window: { location: { search: `?appointment_id=${id}` } },
            openTrackingAppointmentModal: () => assert.fail('No modal should open'),
        });
    });
}

test('switching calendar details clears the previous dossier shortcut', () => {
    const begin = source.indexOf("const dossierLink = document.getElementById('tracking-detail-dossier');");
    const end = source.indexOf("document.getElementById('tracking_detail_appointment_id').value =", begin);
    const block = source.slice(begin, end);
    const link = {
        href: '',
        hidden: true,
        classList: { toggle: (name, hidden) => { assert.equal(name, 'hidden'); link.hidden = hidden; } },
        removeAttribute: (name) => { assert.equal(name, 'href'); link.href = null; },
    };
    const context = { document: { getElementById: (id) => id === 'tracking-detail-dossier' ? link : null }, props: { dossier_url: '/v2/dossiers/1' } };
    runInNewContext(`{ ${block} }`, context);
    assert.equal(link.href, '/v2/dossiers/1');
    assert.equal(link.hidden, false);
    context.props = {};
    runInNewContext(`{ ${block} }`, context);
    assert.equal(link.href, null);
    assert.equal(link.hidden, true);
    context.document.getElementById = () => null;
    runInNewContext(`{ ${block} }`, context);
});
