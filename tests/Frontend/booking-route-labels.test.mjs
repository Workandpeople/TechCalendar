import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const helpers = source.slice(source.indexOf('const isFallbackRoute ='), source.indexOf('const ensureBookingTravelTooltip ='));
const { distance, duration } = runInNewContext(`${helpers}\n({ distance: formatRouteDistance, duration: formatRouteDuration });`);
const escapeStart = source.indexOf('const escapeHtml =');
const escape = source.slice(escapeStart, source.indexOf('const formatShortDate =', escapeStart));

test('fallback distances are explicitly straight-line and their duration is independently labeled as estimated', () => {
    for (const routeSource of ['fallback', 'estimation interne']) {
        assert.equal(distance(12.34, routeSource), '12.3 km à vol d’oiseau');
        assert.equal(duration(75, routeSource), 'Durée estimée : 1h15');
        assert.doesNotMatch(distance(12, routeSource), /voiture/);
    }
    assert.equal(distance(12.34, 'mapbox'), '12.3 km voiture');
    assert.equal(duration(75, 'mapbox'), '1h15');
    assert.equal(distance(12.34), '12.3 km');
});

test('missing or invalid route values never become zero while real zero values remain valid', () => {
    for (const value of [null, undefined, '', 'invalid', -1, Infinity]) {
        assert.equal(distance(value, 'fallback'), 'Distance indisponible');
        assert.equal(duration(value, 'fallback'), 'Durée indisponible');
    }
    assert.equal(distance(0, 'fallback'), '0.0 km à vol d’oiseau');
    assert.equal(duration(0, 'fallback'), 'Durée estimée : 0 min');
    assert.equal(duration('60'), '1h');
});

test('technician badges and route summaries pass their actual route source to the labels', () => {
    assert.match(source, /formatRouteDistance\(technician.driving_distance_km, technician.route_source\)/);
    assert.match(source, /formatRouteDuration\(technician.driving_duration_minutes, technician.route_source\)/);
    assert.match(source, /formatRouteDistance\(route.distance_km, route.source\)/);
    assert.match(source, /formatRouteDuration\(segment.route\?\.duration_minutes, segment.route\?\.source\)/);
    assert.doesNotMatch(source, /escapeHtml\(technician.driving_distance_km\)\} km voiture/);
});

test('analysis badges and technician search both distinguish fallback estimates from driving routes', () => {
    const analysis = source.slice(source.indexOf('const renderTechnicians ='), source.indexOf('const bookingCalendarEventContent ='));
    const search = source.slice(source.indexOf('const renderTechnicianSearchResults ='), source.indexOf('const searchTechnicians ='));
    const list = () => ({ innerHTML: '', querySelectorAll: () => [], classList: { remove() {} } });
    const techniciansList = list(), technicianSearchResults = list();
    const renderers = runInNewContext(`${escape}\n${helpers}\n${analysis}\n${search}\n({ analysis: renderTechnicians, search: renderTechnicianSearchResults });`, {
        techniciansList, technicianSearchResults, document: { getElementById: () => ({}) },
        currentTechnicians: [], selectedTechnicianIds: new Set(), refreshTechnicianColors() {}, updateTechnicianSelectionCount() {},
        selectedTechnicians: () => [], technicianColor: () => '#1764d9', technicianOrdinal: () => 1, absenceBadgesForTechnician: () => '',
    });
    for (const route_source of ['fallback', 'mapbox']) {
        const technicians = [{ id: 7, name: '<img onerror=x>', driving_distance_km: 12.34, driving_duration_minutes: 75, route_source }];
        renderers.analysis(technicians, {}, {});
        renderers.search(technicians);
        for (const { innerHTML } of [techniciansList, technicianSearchResults]) {
            assert.match(innerHTML, /&lt;img onerror=x&gt;/);
            if (route_source === 'fallback') {
                assert.match(innerHTML, /12.3 km à vol d’oiseau/);
                assert.match(innerHTML, /Durée estimée : 1h15/);
                assert.doesNotMatch(innerHTML, /km voiture/);
            } else {
                assert.match(innerHTML, /12.3 km voiture/);
                assert.doesNotMatch(innerHTML, /vol d’oiseau|Durée estimée/);
            }
        }
    }
});
