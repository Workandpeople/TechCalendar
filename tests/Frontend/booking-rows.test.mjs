import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { cofracLabel } from '../../resources/js/cofrac-label.js';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const renderer = source.slice(source.indexOf('const renderBookingCrmCard ='), source.indexOf('const setBookingExternalSourceStatus ='));
const escapeStart = source.indexOf('const escapeHtml =');
const escape = source.slice(escapeStart, source.indexOf('const formatShortDate =', escapeStart));

function render(appointment, connectors = false) {
    return runInNewContext(`${escape}\n${renderer}\nrenderBookingCrmCard(appointment);`, {
        appointment,
        window: { TechCalendarLabels: { cofrac: cofracLabel } },
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
        assert.match(markup, /gc-booking-request-service min-w-0 rounded-lg/);
        assert.doesNotMatch(markup, /gc-booking-request-service[^"\n]*\btruncate\b/);
        assert.match(markup, /crm-appointment-detail-button inline-flex h-8 w-8 shrink-0/);
        assert.doesNotMatch(markup, /flex-wrap\b|md:grid-cols-/);
        assert.equal((markup.match(/data-crm-comments-trigger/g) || []).length, 1);
        assert.match(markup, /gc-booking-request-phone text-sm font-bold tabular-nums/);
        assert.match(markup, /gc-booking-request-postal shrink-0 whitespace-nowrap/);
    }
});

test('postal badges display the whole code including leading zeroes and overseas codes', () => {
    for (const postal_code of ['01300', '20100', '31500', '97100']) {
        const html = render({ id: 'dossier-1', postal_code, department_code: postal_code.slice(0, 2) });
        assert.match(html, new RegExp(`>CP ${postal_code}</span>`));
        assert.doesNotMatch(html, />Dép\./);
    }
    assert.match(render({ id: 'dossier-1', department_code: '2A' }), />Dép\. 2A<\/span>/);
    assert.match(render({ id: 'dossier-1' }), />CP non renseigné<\/span>/);
});

test('phone and postal values remain escaped in refreshed rows', () => {
    const html = render({ id: 'dossier-1', phone: '06 12 34 56 78 <img onerror=alert(1)>', postal_code: '<script>alert(1)</script>' });
    assert.match(html, /gc-booking-request-phone[^>]*title="06 12 34 56 78 &lt;img/);
    assert.match(html, />CP &lt;script&gt;/);
    assert.doesNotMatch(html, /<img|<script/);
});

test('type and long fiche names stay visible on separate wrapping lines and remain escaped', () => {
    const name = 'BAT EN 101 Isolation en rampant de toiture "test" <img src=x onerror=alert(1)>';
    const html = render({ id: 'dossier-1', service: { type: 'COFFRAC', name } });
    assert.match(html, /title="COFRAC - BAT EN 101 Isolation en rampant de toiture &quot;test&quot; &lt;img/);
    assert.match(html, /gc-booking-request-service-type">COFRAC<\/span>/);
    assert.match(html, /gc-booking-request-service-name">BAT EN 101 Isolation en rampant de toiture &quot;test&quot; &lt;img/);
    assert.match(source, /\.gc-booking-request-service \{[^}]*white-space: normal;[^}]*overflow-wrap: anywhere;/);
    assert.match(source, /\.gc-booking-request-service-type \{[^}]*display: block;/);
    assert.match(source, /\.gc-booking-request-service-name \{[^}]*display: block;/);
    assert.match(source, /minmax\(17rem, 1\.5fr\)/);
    assert.doesNotMatch(html, /<img/);
});

test('missing services and legacy GPS warnings keep compact accessible labels', () => {
    const html = render({ id: 'coffrac-1' }, true);
    assert.match(html, /title="Service non renseigné"/);
    assert.match(html, /gc-booking-request-gps[^>]*title="GPS à recalculer"/);
    assert.doesNotMatch(render({ id: 'dossier-1' }), /GPS à recalculer/);
});

test('scheduled pending requests use a replacement link in the same row instead of a hidden second list', () => {
    const html = render({ id: 'dossier-1', dossier_id: 1, replace_appointment_id: 42, existing_appointment_at: '2026-10-27T10:00:00Z', dossier_summary: { dossier_url: '/v2/dossiers/1' } });
    assert.match(html, /href="\/planner\/appointments\/modify\?replace_appointment_id=42"/);
    assert.match(html, /Replacer le RDV/);
    assert.match(html, />Replacer \/ horaire<\/a>/);
    assert.match(html, /aria-label="Replacer le RDV ou modifier l’horaire"/);
    assert.match(html, /title="Replacer le RDV ou modifier l’horaire · RDV actuel : 14\/10\/2026 12:00"/);
    assert.match(html, /title="Voir le dossier"/);
    assert.doesNotMatch(source, /booking-replacements/);
    assert.match(source, /event\.target\.closest\('a, button'\)/);
    assert.match(source, /if \(event\.target !== card\) return/);
});

test('new and replacement dossier eyes open the dossier directly without selecting the planning row', () => {
    for (const replace_appointment_id of [null, 42]) {
        const html = render({ id: 'dossier-1', replace_appointment_id, dossier_summary: { dossier_url: '/v2/dossiers/1' } });
        assert.match(html, /<a href="\/v2\/dossiers\/1" class="crm-appointment-dossier-link/);
        assert.doesNotMatch(html, /target=|nouvel onglet/);
        assert.doesNotMatch(html, /data-crm-detail-id=/);
    }
    assert.match(source, /href="\{\{ \$appointment\['dossier_summary'\]\['dossier_url'\] \}\}" title="Voir le dossier"/);
    const summary = readFileSync(new URL('../../resources/views/planner/booking-dossier-summary.blade.php', import.meta.url), 'utf8');
    assert.doesNotMatch(summary, /target=|nouvel onglet/);
    assert.doesNotMatch(source, /window\.location\.assign\(appointment\.dossier_summary\.dossier_url\)/);
    assert.match(render({ id: 'legacy-1' }), /<button type="button" data-crm-detail-id="legacy-1"/);
});
