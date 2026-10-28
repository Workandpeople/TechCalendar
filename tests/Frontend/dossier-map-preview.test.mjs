import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initDossierMapPreview, validMapPoint, dossierOverviewBounds } from '../../resources/js/dossier-map-preview.js';

test('accepts only finite geographical coordinates', () => {
    assert.ok(validMapPoint({ latitude: 48.85, longitude: 2.35 }));
    for (const point of [null, {}, { latitude: '48', longitude: 2 }, { latitude: NaN, longitude: 2 },
        { latitude: 0, longitude: 0 }, { latitude: 91, longitude: 2 }, { latitude: 45, longitude: 181 }]) {
        assert.ok(!validMapPoint(point));
    }
});

function fixture({ token = 'public-test-token', fetch, empty = false } = {}) {
    const events = target => {
        target.handlers = new Map();
        target.addEventListener = (name, callback) => target.handlers.set(name, callback);
        target.emit = (name, event = {}) => target.handlers.get(name)?.(event);
        return target;
    };
    const root = events({ documentElement: { clientWidth: 1000 }, activeElement: null });
    const element = () => events({
        children: [], attributes: {}, style: {}, dataset: {}, hidden: false,
        append(...children) { this.children.push(...children); },
        contains(node) { return this === node || this.children.some(child => child.contains(node)); },
        setAttribute(name, value) { this.attributes[name] = value; },
        getBoundingClientRect() { return { left: 100, top: 50, bottom: 75, width: 340, height: 290 }; },
        focus() { root.activeElement = this; this.emit('focus'); },
    });
    const first = element(); first.dataset = { dossierMapUrl: '/first', dossierMapAddress: 'Adresse 1' };
    const second = element(); second.dataset = { dossierMapUrl: '/second', dossierMapAddress: 'Adresse 2' };
    root.body = element(); root.createElement = element;
    root.triggers = empty ? [] : [first, second];
    root.querySelectorAll = () => root.triggers;
    root.querySelector = () => ({ dataset: { mapboxToken: token } });
    root.dispatchEvent = event => root.emit(event.type, event);
    const requests = []; const maps = []; const controls = []; const bounds = []; const markers = [];
    const browser = events({
        innerHeight: 800,
        clearTimeout() { browser.timer = null; },
        setTimeout(callback, delay) { browser.timer = callback; browser.delay = delay; },
        CustomEvent: class { constructor(type) { this.type = type; } },
        fetch(url, options) { requests.push(url); return fetch ? fetch(url, options) : Promise.resolve({ ok: true, json: async () => ({ coordinates: { latitude: 48.85, longitude: 2.35 }, message: 'Point enregistré' }) }); },
        mapboxgl: {
            Map: class { constructor(options) { maps.push(options); } on() {} fitBounds(points, options) { bounds.push({ points, options }); } resize() {} addControl(control, position) { controls.push({ control, position }); } },
            Marker: class { setLngLat(point) { markers.push(point); return this; } addTo() { return this; } },
            NavigationControl: class { constructor(options) { this.options = options; } },
        },
    });
    initDossierMapPreview(root, browser);
    const popup = root.body.children[0];
    const close = popup.children[0].children[1];
    const message = popup.children[2];
    return { root, browser, first, second, popup, close, message, maps, requests, controls, bounds, markers };
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('binds ajax addresses after an empty search and reuses the existing map across refreshes', async () => {
    const ui = fixture({ empty: true });
    ui.root.triggers = [ui.first];
    ui.root.emit('techcalendar:dossiers-updated');
    ui.first.emit('click'); await settle();
    assert.equal(ui.popup.hidden, false);
    ui.root.emit('techcalendar:dossiers-before-update');
    assert.equal(ui.popup.hidden, true);
    ui.root.triggers = [ui.second];
    ui.root.emit('techcalendar:dossiers-updated');
    ui.second.emit('click'); await settle();
    assert.equal(ui.popup.hidden, false);
    assert.equal(ui.maps.length, 1);
    assert.equal(ui.root.body.children.length, 1);
    assert.equal(ui.controls.length, 1);
});

test('loads on demand and reuses a single map and cached coordinates', async () => {
    const ui = fixture();
    assert.equal(ui.popup.hidden, true);
    assert.deepEqual(ui.requests, []);
    ui.first.emit('click');
    await settle();
    assert.equal(ui.popup.hidden, false);
    assert.equal(ui.first.attributes['aria-expanded'], 'true');
    assert.equal(ui.message.textContent, 'Point enregistré');
    assert.equal(ui.maps.length, 1);
    assert.equal(ui.maps[0].interactive, true);
    assert.equal(ui.controls.length, 1);
    assert.equal(ui.controls[0].position, 'top-right');
    ui.root.emit('keydown', { key: 'Escape' });
    assert.equal(ui.popup.hidden, true);
    ui.first.emit('click');
    await settle();
    assert.deepEqual(ui.requests, ['/first']);
    assert.equal(ui.maps.length, 1);
    assert.equal(ui.controls.length, 1);
    assert.equal(ui.bounds.length, 2);
    assert.deepEqual(ui.bounds[1].points, [[-5.5, 41], [9.8, 51.5]]);
    assert.equal(ui.bounds[1].options.duration, 0);
    assert.deepEqual(ui.markers.at(-1), [2.35, 48.85]);
});

test('never opens on hover or focus and keeps the clicked map open on mouse leave', async () => {
    const ui = fixture();
    ui.first.emit('pointerenter', { pointerType: 'mouse' });
    ui.first.focus(); await settle();
    assert.equal(ui.popup.hidden, true);
    assert.deepEqual(ui.requests, []);
    ui.first.emit('click'); await settle();
    ui.first.emit('pointerleave'); ui.popup.emit('pointerleave');
    assert.equal(ui.popup.hidden, false);
    assert.equal(ui.browser.timer, undefined);
    ui.root.emit('scroll', { target: ui.popup });
    assert.equal(ui.popup.hidden, false);
    ui.first.emit('click');
    assert.equal(ui.popup.hidden, true);
});

test('closes on an outside click or outside scroll, but not when interacting with the map', async () => {
    const ui = fixture();
    ui.first.emit('click'); await settle();
    ui.root.emit('click', { target: ui.popup });
    assert.equal(ui.popup.hidden, false);
    ui.root.emit('click', { target: {} });
    assert.equal(ui.popup.hidden, true);
    ui.first.emit('click'); await settle();
    ui.root.emit('scroll', { target: ui.first });
    assert.equal(ui.popup.hidden, true);
});

test('ignores stale responses after changing row and allows dismissal by keyboard', async () => {
    const pending = {};
    const ui = fixture({ fetch: url => new Promise(resolve => { pending[url] = resolve; }) });
    ui.first.emit('click');
    ui.second.emit('click');
    pending['/second']({ ok: true, json: async () => ({ coordinates: { latitude: 45, longitude: 4 }, message: 'Second' }) });
    await settle();
    pending['/first']({ ok: true, json: async () => ({ coordinates: { latitude: 48, longitude: 2 }, message: 'First' }) });
    await settle();
    assert.equal(ui.message.textContent, 'Second');
    assert.deepEqual(ui.maps[0].center, [2.2, 46.4]);
    assert.deepEqual(ui.markers.at(-1), [4, 45]);
    ui.close.focus();
    ui.root.emit('keydown', { key: 'Escape' });
    assert.equal(ui.popup.hidden, true);
    assert.equal(ui.root.activeElement, ui.second);
    assert.equal(ui.second.attributes['aria-expanded'], 'false');
});

test('opens on touch click and closes when comments are opened', async () => {
    const ui = fixture();
    ui.first.emit('pointerenter', { pointerType: 'touch' });
    assert.equal(ui.popup.hidden, true);
    ui.first.emit('click'); await settle();
    assert.equal(ui.popup.hidden, false);
    ui.first.emit('pointerleave');
    assert.equal(ui.popup.hidden, false);
    ui.root.emit('techcalendar:dossier-comments-open');
    assert.equal(ui.popup.hidden, true);
});

test('handles missing configuration or denied requests without showing a false point', async () => {
    const unconfigured = fixture({ token: '' });
    unconfigured.first.emit('click'); await settle();
    assert.deepEqual(unconfigured.requests, []);
    assert.match(unconfigured.message.textContent, /configuration Mapbox/);
    const denied = fixture({ fetch: async () => ({ ok: false }) });
    denied.first.emit('click'); await settle();
    assert.match(denied.message.textContent, /indisponible/);
    assert.equal(denied.maps.length, 0);
});

test('does nothing on pages without address previews', () => {
    initDossierMapPreview({ querySelectorAll: () => [] }, {});
});

test('includes Corsica and overseas markers without losing the France overview', () => {
    assert.deepEqual(dossierOverviewBounds({ longitude: 8.7, latitude: 41.9 }), [[-5.5, 41], [9.8, 51.5]]);
    assert.deepEqual(dossierOverviewBounds({ longitude: -61.5, latitude: 16.2 }), [[-61.5, 16.2], [9.8, 51.5]]);
    assert.deepEqual(dossierOverviewBounds({ longitude: 55.5, latitude: -21.1 }), [[-5.5, -21.1], [55.5, 51.5]]);
});
