import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initSidebarSections } from '../../resources/js/sidebar-sections.js';

function fixture({ stored = null, key = 'techcalendar.sidebar.sections.v2.42', blocked = false } = {}) {
    const values = new Map(stored === null ? [] : [[key, stored]]);
    const panels = new Map();
    const listeners = new Map();
    const buttons = [];
    for (const scope of ['desktop', 'mobile']) {
        for (const name of ['admin', 'technician-planning', 'manager', 'manager-data', 'planner']) {
            const id = `${scope}-${name}`;
            panels.set(id, { hidden: false });
            const attributes = new Map([['aria-expanded', 'true'], ['aria-controls', id]]);
            buttons.push({
                dataset: { sidebarSectionToggle: name },
                getAttribute: (attr) => attributes.get(attr),
                setAttribute: (attr, value) => attributes.set(attr, value),
                addEventListener: (event, callback) => listeners.set(`${id}:${event}`, callback),
            });
        }
    }
    const root = {
        querySelector: () => ({ dataset: { sidebarStorageKey: key } }),
        querySelectorAll: () => buttons,
        getElementById: (id) => panels.get(id),
    };
    const browser = {
        get localStorage() {
            if (blocked) throw new Error('Storage unavailable');
            return { getItem: (id) => values.get(id) ?? null, setItem: (id, value) => values.set(id, value) };
        },
        addEventListener: (event, callback) => listeners.set(event, callback),
    };
    initSidebarSections(root, browser);
    return { values, panels, buttons, click: (id) => listeners.get(`${id}:click`)(), storageEvent: (event) => listeners.get('storage')(event) };
}

test('remembers each section and mirrors desktop and mobile without changing its parent', () => {
    const ui = fixture();
    ui.click('desktop-manager-data');
    assert.equal(ui.panels.get('desktop-manager-data').hidden, true);
    assert.equal(ui.panels.get('mobile-manager-data').hidden, true);
    assert.equal(ui.panels.get('desktop-manager').hidden, false);
    ui.click('desktop-manager');
    assert.equal(ui.panels.get('desktop-manager').hidden, true);
    ui.click('mobile-manager');
    assert.equal(ui.panels.get('desktop-manager').hidden, false);
    assert.equal(ui.panels.get('desktop-manager-data').hidden, true);
    ui.click('desktop-admin');
    ui.click('desktop-planner');
    ui.click('desktop-technician-planning');
    const stored = ui.values.get('techcalendar.sidebar.sections.v2.42');
    const reloaded = fixture({ stored });
    for (const name of ['admin', 'planner', 'technician-planning', 'manager-data']) {
        assert.equal(reloaded.panels.get(`desktop-${name}`).hidden, true);
    }
    assert.equal(reloaded.panels.get('desktop-manager').hidden, false);
    assert.equal(reloaded.buttons.find((button) => button.dataset.sidebarSectionToggle === 'admin').getAttribute('aria-expanded'), 'false');
});

test('uses the account-specific storage key', () => {
    const ui = fixture({ key: 'techcalendar.sidebar.sections.v2.99' });
    ui.click('desktop-admin');
    assert.equal(ui.values.has('techcalendar.sidebar.sections.v2.42'), false);
    assert.equal(ui.values.get('techcalendar.sidebar.sections.v2.99'), '{"admin":false}');
});

for (const stored of ['broken json', 'null', '[]', 'false', '{"admin":"false"}']) {
    test(`falls back to open sections for invalid preferences: ${stored}`, () => {
        const ui = fixture({ stored });
        assert.equal(ui.panels.get('desktop-admin').hidden, false);
        ui.click('desktop-admin');
        assert.equal(ui.panels.get('desktop-admin').hidden, true);
    });
}

test('still toggles when localStorage is blocked', () => {
    const ui = fixture({ blocked: true });
    ui.click('mobile-planner');
    assert.equal(ui.panels.get('mobile-planner').hidden, true);
    ui.click('desktop-planner');
    assert.equal(ui.panels.get('mobile-planner').hidden, false);
});

test('reflects preference changes in another tab and ignores unrelated keys', () => {
    const ui = fixture();
    const key = 'techcalendar.sidebar.sections.v2.42';
    ui.values.set(key, '{"admin":false}');
    ui.storageEvent({ key: 'unrelated' });
    assert.equal(ui.panels.get('desktop-admin').hidden, false);
    ui.storageEvent({ key });
    assert.equal(ui.panels.get('desktop-admin').hidden, true);
    ui.values.clear();
    ui.storageEvent({ key: null });
    assert.equal(ui.panels.get('desktop-admin').hidden, false);
});

test('does nothing on a page without the sidebar', () => {
    initSidebarSections({ querySelector: () => null, querySelectorAll: () => [] }, {});
});
