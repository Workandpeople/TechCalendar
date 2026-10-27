import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const renderer = source.slice(source.indexOf('const renderBookingCrmCard ='), source.indexOf('const setBookingExternalSourceStatus ='));
const escapeStart = source.indexOf('const escapeHtml =');
const escape = source.slice(escapeStart, source.indexOf('const formatShortDate =', escapeStart));

function render(appointment, connectors = false) {
    return runInNewContext(`${escape}\n${renderer}\nrenderBookingCrmCard(appointment);`, {
        appointment,
        bookingConnectorsEnabled: connectors,
        bookingReplacementPageUrl: '/planner/appointments/modify',
        formatShortDateTime: () => '14/10/2026 12:00',
    });
}

test('initial and refreshed booking rows share the same responsive layout', () => {
    const initial = source.slice(source.indexOf('@foreach ($crmAppointments'), source.indexOf('id="booking-crm-pagination"'));
    for (const markup of [initial, render({ id: 'dossier-1' })]) {
        assert.match(markup, /crm-appointment-card gc-booking-request-row/);
        assert.match(markup, /gc-booking-request-actions flex min-w-0 flex-nowrap/);
        assert.match(markup, /gc-booking-request-service min-w-0 flex-1 truncate/);
        assert.match(markup, /crm-appointment-detail-button inline-flex h-8 w-8 shrink-0/);
        assert.doesNotMatch(markup, /flex-wrap\b|md:grid-cols-/);
    }
});

test('long service names stay complete in an escaped title without introducing markup', () => {
    const name = 'BAT EN 101 Isolation en rampant de toiture "test" <img src=x onerror=alert(1)>';
    const html = render({ id: 'dossier-1', service: { type: 'COFFRAC', name } });
    assert.match(html, /title="COFFRAC - BAT EN 101 Isolation en rampant de toiture &quot;test&quot; &lt;img/);
    assert.doesNotMatch(html, /<img/);
});

test('missing services and legacy GPS warnings keep compact accessible labels', () => {
    const html = render({ id: 'coffrac-1' }, true);
    assert.match(html, /title="Service non renseigné"/);
    assert.match(html, /truncate[^>]*title="GPS à recalculer"/);
    assert.doesNotMatch(render({ id: 'dossier-1' }), /GPS à recalculer/);
});

test('scheduled pending requests use a replacement link in the same row instead of a hidden second list', () => {
    const html = render({ id: 'dossier-1', dossier_id: 1, replace_appointment_id: 42, existing_appointment_at: '2026-10-27T10:00:00Z' });
    assert.match(html, /href="\/planner\/appointments\/modify\?replace_appointment_id=42"/);
    assert.match(html, /Replacer le RDV/);
    assert.match(html, /title="Voir le dossier"/);
    assert.doesNotMatch(source, /booking-replacements/);
    assert.match(source, /event\.target\.closest\('a, button'\)/);
    assert.match(source, /if \(event\.target !== card\) return/);
});
