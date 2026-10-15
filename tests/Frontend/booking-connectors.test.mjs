import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const syncCode = source.slice(source.indexOf('const stopExternalSyncPolling ='), source.indexOf('const renderBookingCrmPagination ='));

test('v2 neither polls nor subscribes to obsolete connector progress', () => {
    // No window, DOM or network stubs: accessing any of them must fail this test.
    runInNewContext(`${syncCode}
        subscribeToExternalApiSync();
        startExternalSyncPolling();
        handleExternalApiSyncProgress({ source: 'coffrac', state: 'available' });
    `, { bookingConnectorsEnabled: false });
});

test('legacy booking still subscribes and polls for connector progress', () => {
    const subscriptions = [];
    const timers = [];
    runInNewContext(`
        let externalSyncSubscription = null;
        let externalSyncPollingTimer = null;
        let externalSyncPollStartedAt = null;
        ${syncCode}
        subscribeToExternalApiSync();
        startExternalSyncPolling();
    `, {
        bookingConnectorsEnabled: true,
        bookingExternalRefreshButtons: [],
        window: {
            TechCalendarReverb: { subscribePrivate: (...args) => subscriptions.push(args) },
            clearInterval: () => {},
            setInterval: (_callback, delay) => timers.push(delay),
        },
    });
    assert.equal(subscriptions.length, 1);
    assert.equal(subscriptions[0][0], 'external-api-sync.coffrac');
    assert.equal(subscriptions[0][1], 'external-api-sync.progressed');
    assert.deepEqual(timers, [2500]);
});
