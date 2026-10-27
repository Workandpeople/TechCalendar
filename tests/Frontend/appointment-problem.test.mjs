import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const book = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const tracking = readFileSync(new URL('../../resources/views/planner/tracking.blade.php', import.meta.url), 'utf8');

function harness(surface, legacy = false) {
    const audience = { value: 'internal' }, comment = { value: '  Client indisponible  ', closest: () => section };
    const section = { open: true, querySelector: () => audience };
    const type = { value: 'Problème document', selectedOptions: [{ dataset: { requiresRecall: '0' } }] };
    const date = { value: '2026-11-12' }, time = { value: '10:30' };
    const wrapper = { classList: { toggle: (_name, hidden) => { wrapper.hidden = hidden; } } };
    const code = surface === 'book'
        ? book.slice(book.indexOf('const problemTypeRequiresRecall ='), book.indexOf('const updateBookingDetailCommentButtonVisibility ='))
        : tracking.slice(tracking.indexOf('const trackingProblemTypeRequiresRecall ='), tracking.indexOf('const trackingEscapeHtml ='));
    const context = {
        bookingConnectorsEnabled: legacy, trackingNativeDossiers: !legacy,
        trackingProblemSection: section, trackingProblemComment: comment, trackingProblemType: type,
        trackingProblemRecallDate: date, trackingProblemRecallTime: time, trackingProblemRecallFields: wrapper,
    };
    const api = runInNewContext(`${code}\n({
        payload: () => ${surface === 'book' ? 'problemPayloadFromFields(comment, type, date, time)' : 'trackingProblemPayloadFromFields()'},
        reset: () => ${surface === 'book' ? 'resetProblemSection(section, comment, type, wrapper, date, time)' : 'resetTrackingProblemSection()'},
        sync: () => ${surface === 'book' ? 'syncProblemRecallFields(type, wrapper, date, time)' : 'syncTrackingProblemRecallFields()'},
    });`, { ...context, section, comment, type, wrapper, date, time });
    return { ...api, audience, comment, section, type, date, time, wrapper };
}

for (const surface of ['book', 'tracking']) {
    test(`${surface}: posts an independent audience and optional reminder without discarding it on reason changes`, () => {
        const ui = harness(surface);
        ui.sync();
        assert.equal(ui.wrapper.hidden, false);
        assert.equal(ui.date.value, '2026-11-12');
        for (const audience of ['internal', 'client', 'shared']) {
            ui.audience.value = audience;
            const payload = ui.payload();
            assert.equal(payload.audience, audience);
            assert.equal(payload.comment, 'Client indisponible');
            assert.equal(payload.recall_date, '2026-11-12');
            assert.equal(payload.recall_time, '10:30');
        }
    });

    test(`${surface}: resets privacy and callback fields when opening another appointment`, () => {
        const ui = harness(surface);
        ui.reset();
        assert.equal(ui.audience.value, 'client');
        assert.equal(ui.comment.value, '');
        assert.equal(ui.date.value, '');
        assert.equal(ui.time.value, '');
        assert.equal(ui.section.open, false);
    });

    test(`${surface}: accepts date-only optional callbacks but requires full callback details for the callback reason`, () => {
        const ui = harness(surface);
        ui.time.value = '';
        assert.equal(ui.payload().recall_time, null);
        ui.type.selectedOptions[0].dataset.requiresRecall = '1';
        assert.throws(() => ui.payload(), /date et l’heure/);
        ui.type.selectedOptions[0].dataset.requiresRecall = '0';
        ui.date.value = '';
        ui.time.value = '10:30';
        assert.throws(() => ui.payload(), /date du rappel/);
    });

    test(`${surface}: keeps the legacy connector contract unchanged`, () => {
        const ui = harness(surface, true);
        ui.sync();
        assert.equal(ui.wrapper.hidden, true);
        assert.equal(ui.date.value, '');
        assert.equal(ui.payload().recall_date, null);
        assert.equal(Object.hasOwn(ui.payload(), 'audience'), false);
    });
}
