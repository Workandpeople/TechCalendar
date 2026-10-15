import assert from 'node:assert/strict';
import { test } from 'node:test';
import { commentTooltipPosition, initDossierComments } from '../../resources/js/dossier-comments.js';

test('keeps comment tooltips within the screen and flips them above the bottom edge', () => {
    const viewport = { width: 1000, height: 800 };
    assert.deepEqual(commentTooltipPosition({ x: 200, y: 100 }, { width: 384, height: 300 }, viewport), { left: 200, top: 112 });
    assert.deepEqual(commentTooltipPosition({ x: 950, y: 740 }, { width: 384, height: 300 }, viewport), { left: 604, top: 428 });
    assert.deepEqual(commentTooltipPosition({ x: 950, y: 740, top: 720 }, { width: 384, height: 300 }, viewport), { left: 604, top: 408 });
    assert.deepEqual(commentTooltipPosition({ x: -5, y: 40 }, { width: 366, height: 600 }, { width: 390, height: 640 }), { left: 12, top: 12 });
});

function fixture({ empty = false } = {}) {
    const handlers = new Map();
    const attributes = new Map();
    const listen = (name) => (event, handler) => handlers.set(`${name}:${event}`, handler);
    const trigger = {
        addEventListener: listen('trigger'),
        getBoundingClientRect: () => ({ left: 100, top: 40, bottom: 60 }),
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: (key) => attributes.delete(key),
    };
    const content = { safe: true };
    const row = {
        addEventListener: listen('row'),
        querySelector: (selector) => selector.includes('content') ? { content: { cloneNode: () => content } } : trigger,
    };
    const tooltip = {
        style: {}, setAttribute() {}, addEventListener: listen('tooltip'),
        querySelectorAll: () => [],
        replaceChildren: (value) => { tooltip.child = value; },
        getBoundingClientRect: () => ({ width: 384, height: 200 }),
        contains: (target) => target === tooltip,
    };
    let timer;
    const root = {
        rows: empty ? [] : [row], querySelectorAll: () => root.rows,
        querySelector: selector => selector === '[data-dossier-list]' ? {} : null,
        createElement: () => tooltip, body: { append() {} },
        documentElement: { clientWidth: 1000 }, activeElement: null, addEventListener: listen('root'),
    };
    const browser = {
        innerHeight: 800, addEventListener: listen('browser'),
        clearTimeout: () => { timer = null; }, setTimeout: (callback) => { timer = callback; },
    };
    initDossierComments(root, browser);
    return { root, row, trigger, tooltip, attributes, content, handlers, emit: (name, event = {}) => handlers.get(name)(event), tick: () => timer?.() };
}

test('shows escaped server content immediately on client hover and remains hoverable', () => {
    const ui = fixture();
    ui.emit('trigger:pointerenter', { pointerType: 'mouse', clientX: 100, clientY: 200 });
    assert.equal(ui.tooltip.hidden, false);
    assert.equal(ui.tooltip.child, ui.content);
    assert.equal(ui.attributes.get('aria-describedby'), 'dossier-comments-tooltip');
    ui.emit('trigger:pointerleave');
    ui.emit('tooltip:pointerenter');
    ui.tick();
    assert.equal(ui.tooltip.hidden, false);
    ui.emit('tooltip:pointerleave');
    ui.tick();
    assert.equal(ui.tooltip.hidden, true);
    assert.equal(ui.attributes.has('aria-describedby'), false);
});

test('supports keyboard focus and dismissal without interfering with touch navigation', () => {
    const ui = fixture();
    ui.emit('trigger:pointerenter', { pointerType: 'touch' });
    assert.equal(ui.tooltip.hidden, true);
    ui.root.activeElement = ui.trigger;
    ui.emit('trigger:focus');
    assert.equal(ui.tooltip.hidden, false);
    ui.emit('root:scroll', { target: ui.root });
    assert.equal(ui.tooltip.hidden, false);
    assert.equal(ui.tooltip.style.top, '72px');
    ui.emit('trigger:pointerleave');
    ui.tick();
    assert.equal(ui.tooltip.hidden, false);
    ui.emit('root:keydown', { key: 'Escape' });
    assert.equal(ui.tooltip.hidden, true);
    ui.emit('trigger:focus');
    ui.emit('trigger:blur');
    assert.equal(ui.tooltip.hidden, true);
});

test('closes on page scrolling or resizing but allows scrolling long comments', () => {
    const ui = fixture();
    ui.emit('trigger:focus');
    ui.emit('root:scroll', { target: ui.tooltip });
    assert.equal(ui.tooltip.hidden, false);
    ui.emit('root:scroll', { target: ui.root });
    assert.equal(ui.tooltip.hidden, true);
    ui.emit('trigger:focus');
    ui.emit('browser:resize');
    assert.equal(ui.tooltip.hidden, true);
});

test('does not create a tooltip on unrelated pages', () => {
    initDossierComments({ querySelectorAll: () => [] }, {});
});

test('does not replace an open map on client hover but allows explicit keyboard focus', () => {
    const ui = fixture();
    ui.root.querySelector = () => ({ hidden: false });
    ui.emit('trigger:pointerenter', { pointerType: 'mouse', clientX: 100, clientY: 200 });
    assert.equal(ui.tooltip.hidden, true);
    ui.emit('trigger:focus');
    assert.equal(ui.tooltip.hidden, false);
});

test('binds ajax rows even after an initially empty search and dismisses stale tooltips', () => {
    const ui = fixture({ empty: true });
    ui.root.rows = [ui.row];
    ui.emit('root:techcalendar:dossiers-updated');
    ui.emit('trigger:focus');
    assert.equal(ui.tooltip.hidden, false);
    ui.emit('root:techcalendar:dossiers-before-update');
    assert.equal(ui.tooltip.hidden, true);
    assert.equal(ui.attributes.has('aria-describedby'), false);
    ui.emit('root:techcalendar:dossiers-updated');
    ui.emit('trigger:focus');
    assert.equal(ui.tooltip.hidden, false);
});

test('does not bind comment tooltips to the whole dossier row', () => {
    const ui = fixture();
    assert.equal([...ui.handlers.keys()].some(name => name.startsWith('row:')), false);
    assert.equal(ui.handlers.has('trigger:pointerenter'), true);
    assert.equal(ui.handlers.has('trigger:pointerleave'), true);
});
