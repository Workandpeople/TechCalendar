import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initSidebar } from '../../resources/js/sidebar.js';

function fixture({ stored = null, blocked = false, writeBlocked = false, focusedLink = false } = {}) {
    const key = 'techcalendar.sidebar.collapsed';
    const values = new Map(stored === null ? [] : [[key, stored]]);
    const attributes = new Map();
    const classes = new Set();
    const listeners = new Map();
    const events = [];
    const frames = [];
    const link = {};
    const toggle = {
        getAttribute: name => attributes.get(name),
        setAttribute: (name, value) => attributes.set(name, value),
        addEventListener: (name, listener) => listeners.set(`button:${name}`, listener),
        focus: () => { root.activeElement = toggle; },
    };
    const root = {
        activeElement: focusedLink ? link : null,
        documentElement: {
            dataset: { sidebarCollapseStorageKey: key },
            classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) },
        },
        getElementById: id => id === 'sidebar-toggle' ? toggle : { contains: element => element === link },
    };
    const browser = {
        get localStorage() {
            if (blocked) throw new Error('Storage unavailable');
            return {
                getItem: name => values.get(name) ?? null,
                setItem: (name, value) => {
                    if (writeBlocked) throw new Error('Storage full');
                    values.set(name, value);
                },
            };
        },
        addEventListener: (name, listener) => listeners.set(name, listener),
        requestAnimationFrame: callback => frames.push(callback),
        dispatchEvent: event => events.push(event.type),
        CustomEvent: class { constructor(type) { this.type = type; } },
    };
    initSidebar(root, browser);
    return { key, values, attributes, classes, root, toggle, events,
        click: () => listeners.get('button:click')(),
        storageEvent: key => listeners.get('storage')({ key }),
        render: () => frames.splice(0).forEach(callback => callback()),
    };
}

test('hides the entire sidebar and persists both states using the existing storage key', () => {
    const ui = fixture();
    assert.equal(ui.classes.has('sidebar-hidden'), false);
    assert.equal(ui.attributes.get('aria-expanded'), 'true');
    ui.click();
    assert.equal(ui.classes.has('sidebar-hidden'), true);
    assert.equal(ui.attributes.get('aria-expanded'), 'false');
    assert.equal(ui.attributes.get('aria-label'), 'D\u00e9plier le menu');
    assert.equal(ui.attributes.get('title'), 'D\u00e9plier le menu');
    assert.equal(ui.values.get(ui.key), '1');
    const reloaded = fixture({ stored: ui.values.get(ui.key) });
    assert.equal(reloaded.classes.has('sidebar-hidden'), true);
    reloaded.click();
    assert.equal(reloaded.classes.has('sidebar-hidden'), false);
    assert.equal(reloaded.attributes.get('aria-label'), 'Replier le menu');
    assert.equal(reloaded.values.get(ui.key), '0');
    assert.equal(fixture({ stored: '0' }).classes.has('sidebar-hidden'), false);
});

for (const stored of ['true', 'invalid', '', 'null']) {
    test(`defaults to visible for an invalid preference: ${stored}`, () => {
        assert.equal(fixture({ stored }).classes.has('sidebar-hidden'), false);
    });
}

test('keeps toggling when storage cannot be read or written', () => {
    for (const options of [{ blocked: true }, { writeBlocked: true }]) {
        const ui = fixture(options);
        ui.click();
        assert.equal(ui.classes.has('sidebar-hidden'), true);
        ui.click();
        assert.equal(ui.classes.has('sidebar-hidden'), false);
    }
});

test('resizes maps and calendars after the layout changes', () => {
    const ui = fixture();
    assert.deepEqual(ui.events, []);
    ui.render();
    ui.click();
    ui.render();
    assert.deepEqual(ui.events, ['techcalendar:layout-resized', 'techcalendar:layout-resized']);
});

test('synchronizes other tabs without modifying section preferences', () => {
    const ui = fixture();
    ui.values.set('techcalendar.sidebar.sections.v2.42', '{"admin":false}');
    ui.values.set(ui.key, '1');
    ui.storageEvent('unrelated');
    assert.equal(ui.classes.has('sidebar-hidden'), false);
    ui.storageEvent(ui.key);
    assert.equal(ui.classes.has('sidebar-hidden'), true);
    assert.equal(ui.values.get('techcalendar.sidebar.sections.v2.42'), '{"admin":false}');
    ui.values.delete(ui.key);
    ui.storageEvent(null);
    assert.equal(ui.classes.has('sidebar-hidden'), false);
});

test('returns keyboard focus to the header when the focused link is hidden', () => {
    const ui = fixture({ focusedLink: true });
    ui.values.set(ui.key, '1');
    ui.storageEvent(ui.key);
    assert.equal(ui.root.activeElement, ui.toggle);
});

test('leaves pages without a sidebar untouched', () => {
    initSidebar({ getElementById: () => null, documentElement: { dataset: {} } }, {});
});
