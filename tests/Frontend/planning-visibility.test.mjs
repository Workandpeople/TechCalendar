import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

test('native booking problems remove the placed event and refresh the current window once', () => {
    const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    const start = source.indexOf('if (bookingForcePlacementEnabled) {', source.indexOf("selectedCalendarEvent.setProp('textColor', '#9a3412')"));
    const block = source.slice(start, source.indexOf('\n                }', start));
    for (const native of [false, true]) {
        let removed = 0, refreshes = 0;
        const result = runInNewContext(`${block}\ncurrentCalendarEvents;`, {
            bookingForcePlacementEnabled: native,
            selectedCalendarEvent: { id: 12, remove: () => removed++ },
            currentCalendarEvents: [{ id: 12 }, { id: 13 }],
            lastCalendarDateInfo: { start: '2026-10-30' },
            refreshCalendarWindow: () => refreshes++,
        });
        assert.equal(result.length, native ? 1 : 2);
        assert.equal(removed, native ? 1 : 0);
        assert.equal(refreshes, native ? 1 : 0);
    }
});

test('native tracking problems remove the calendar event without losing the historical detail', () => {
    const source = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');
    const start = source.indexOf('if (trackingNativeDossiers) {', source.indexOf('trackingInitialComment = payload.comment || problemPayload.comment;'));
    const block = source.slice(start, source.indexOf('\n                resetTrackingProblemSection', start));
    for (const native of [false, true]) {
        let removed = 0, refreshes = 0;
        const detail = { comment: 'Historique conserve' };
        runInNewContext(block, {
            trackingNativeDossiers: native, appointmentId: 12,
            trackingCalendarEventById: () => ({ remove: () => removed++ }),
            refetchCalendar: () => refreshes++,
        });
        assert.equal(removed, native ? 1 : 0);
        assert.equal(refreshes, native ? 1 : 0);
        assert.equal(detail.comment, 'Historique conserve');
    }
});
