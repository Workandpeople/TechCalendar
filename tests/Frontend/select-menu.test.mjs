import assert from 'node:assert/strict';
import { test } from 'node:test';
import { filterSelectItems, initSelectMenus, nextSelectOption, searchSelectOption, selectMenuItems, selectMenuPosition } from '../../resources/js/select-menu.js';

const items = [
    { index: 0, label: 'Tous', disabled: false },
    { index: 1, label: 'Échantillonnage', disabled: true },
    { index: 2, label: 'Électricité', disabled: false },
    { index: 3, label: 'Énergie', disabled: false },
    { index: 4, label: 'Hybride', disabled: false },
];

test('retains native option indexes, omits hidden options and respects disabled groups', () => {
    const group = { tagName: 'OPTGROUP', label: 'Archives', disabled: true };
    assert.deepEqual(selectMenuItems({ options: [
        { label: 'Tous', disabled: false }, { label: 'Invisible', hidden: true },
        { label: 'Ancien', disabled: false, parentElement: group },
        { label: 'Secret', parentElement: { ...group, hidden: true } },
    ] }), [
        { index: 0, label: 'Tous', group: '', disabled: false },
        { index: 2, label: 'Ancien', group: 'Archives', disabled: true },
    ]);
});

test('keyboard navigation skips disabled choices and clamps to the list', () => {
    assert.equal(nextSelectOption(items, 0, 1), 2);
    assert.equal(nextSelectOption(items, 2, -1), 0);
    assert.equal(nextSelectOption(items, 2, 'last'), 4);
    assert.equal(nextSelectOption(items, 2, 'first'), 0);
    assert.equal(nextSelectOption(items, 0, 10), 4);
    assert.equal(nextSelectOption(items, 0, -10), 0);
    assert.equal(nextSelectOption(items, 1, 0), 0);
    assert.equal(nextSelectOption([], 0, 1), -1);
});

test('typeahead ignores accents and cycles without selecting a disabled item', () => {
    assert.equal(searchSelectOption(items, 'ele', 0), 2);
    assert.equal(searchSelectOption(items, 'é', 2), 3);
    assert.equal(searchSelectOption(items, 'e', 3), 2);
    assert.equal(searchSelectOption(items, 'introuvable', 3), 3);
});

test('menu fits narrow viewports and opens above a field near the bottom', () => {
    const position = selectMenuPosition({ left: 300, top: 600, bottom: 642, width: 400 }, { width: 390, height: 700 });
    assert.equal(position.width, 374);
    assert.equal(position.left, 8);
    assert.equal(position.top, null);
    assert.equal(position.bottom, 105);
    assert.equal(position.maxHeight, 320);
    const below = selectMenuPosition({ left: 20, top: 100, bottom: 142, width: 200 }, { width: 390, height: 700 });
    assert.equal(below.top, 147);
    assert.equal(below.bottom, null);
});

function fixture() {
    const handlers = new Map(), emitted = [], observers = [];
    const root = { activeElement: null, documentElement: { clientWidth: 1024 } };
    class Node {
        constructor(tag = 'DIV') {
            this.tagName = tag; this.children = []; this.dataset = {}; this.attrs = new Map();
            this.handlers = {};
            this.style = { setProperty() {} }; this.size = 0; this.selectedIndex = 0;
        }
        get isConnected() { return this === root.body || Boolean(this.parentElement?.isConnected); }
        get labels() { return [{ textContent: 'Type de lot' }]; }
        get options() { return this.children; }
        get value() { return this.tagName === 'INPUT' ? (this._value || '') : this.options[this.selectedIndex]?.value || ''; }
        set value(value) { this._value = value; }
        setAttribute(key, value) { this.attrs.set(key, String(value)); }
        getAttribute(key) { return this.attrs.get(key) ?? null; }
        hasAttribute(key) { return this.attrs.has(key); }
        removeAttribute(key) { this.attrs.delete(key); }
        append(node) { this.children.push(node); node.parentElement = this; }
        replaceChildren() { this.children.forEach(node => { node.parentElement = null; }); this.children = []; }
        remove() { this.parentElement.children = this.parentElement.children.filter(node => node !== this); this.parentElement = null; }
        contains(node) { return node === this || this.children.some(child => child.contains(node)); }
        matches(selector) {
            if (selector === 'dialog[open]') return this.tagName === 'DIALOG' && this.open;
            if (selector === ':disabled') return Boolean(this.disabled || this.parentElement?.disabled);
            if (selector === '[role="option"]') return this.getAttribute('role') === 'option';
            return this.tagName === 'SELECT' && !this.multiple && !this.native;
        }
        closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector); }
        querySelectorAll(selector) { return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]); }
        getBoundingClientRect() { return { left: 200, top: 100, bottom: 142, width: this.hidden ? 0 : 280, height: this.hidden ? 0 : 42 }; }
        getClientRects() { return this.hidden ? [] : [this.getBoundingClientRect()]; }
        scrollIntoView() {}
        addEventListener(name, callback) { this.handlers[name] = callback; }
        focus() { root.activeElement = this; }
        dispatchEvent(event) { emitted.push(event.type); fire(event.type, this); }
    }
    root.body = new Node('BODY');
    root.createElement = tag => new Node(tag.toUpperCase());
    root.addEventListener = (name, callback) => { if (!handlers.has(name)) handlers.set(name, []); handlers.get(name).push(callback); };
    const browser = {
        innerHeight: 720, addEventListener() {},
        getComputedStyle: () => ({ getPropertyValue: () => '' }),
        Event: class { constructor(type) { this.type = type; } },
        MutationObserver: class {
            constructor(callback) { this.callback = callback; observers.push(this); }
            observe() { this.connected = true; }
            disconnect() { this.connected = false; }
        },
    };
    const select = new Node('SELECT');
    root.body.append(select);
    for (const item of items) {
        const option = new Node('OPTION'); option.label = item.label; option.disabled = item.disabled;
        select.append(option);
    }
    const fire = (name, target = select, extra = {}) => {
        const event = { target, button: 0, pointerType: 'mouse', detail: 1, ...extra,
            preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
        for (const handler of handlers.get(name) || []) { handler(event); if (event.stopped) break; }
        return event;
    };
    initSelectMenus(root, browser);
    return { root, select, emitted, browser, Node, fire,
        menu: () => root.body.children.find(node => node.getAttribute('role') === 'listbox'),
        mutate: target => observers.filter(observer => observer.connected).forEach(observer => observer.callback([{ target }])),
    };
}

test('renders a select popup inside the active native dialog and removes it on modal close', () => {
    const ui = fixture();
    const dialog = new ui.Node('DIALOG');
    dialog.open = true;
    ui.select.remove();
    ui.root.body.append(dialog);
    dialog.append(ui.select);
    ui.fire('pointerdown');
    const menu = dialog.children.find(node => node.getAttribute('role') === 'listbox');
    assert.ok(menu);
    assert.equal(ui.menu(), undefined);
    ui.fire('click', menu.querySelectorAll('[role="option"]')[2]);
    assert.equal(ui.select.selectedIndex, 2);
    assert.deepEqual(ui.emitted, ['input', 'change']);
    ui.fire('pointerdown');
    ui.fire('close', dialog);
    assert.equal(dialog.children.some(node => node.getAttribute('role') === 'listbox'), false);
});

test('search filters labels and groups without changing native indexes or selecting disabled options', () => {
    assert.deepEqual(filterSelectItems(items, 'LECTRIC'), [items[2]]);
    assert.deepEqual(filterSelectItems([{ index: 7, label: 'Paris', group: 'Île de France' }], 'ile de'), [{ index: 7, label: 'Paris', group: 'Île de France' }]);
    assert.deepEqual(filterSelectItems(items, 'missing'), []);
});

test('searchable menus keep focus in a text input and only commit on explicit choice', () => {
    const ui = fixture();
    ui.select.setAttribute('data-searchable', '');
    ui.fire('pointerdown');
    const popup = ui.root.body.children.find(node => node.getAttribute('role') === 'dialog');
    const input = popup.children[0];
    assert.equal(ui.root.activeElement, input);
    assert.deepEqual(ui.emitted, []);
    input.value = 'electric';
    input.handlers.input();
    assert.equal(popup.querySelectorAll('[role="option"]').length, 1);
    ui.fire('keydown', input, { key: 'Enter' });
    assert.equal(ui.select.selectedIndex, 2);
    assert.deepEqual(ui.emitted, ['input', 'change']);
    assert.equal(ui.root.activeElement, ui.select);
});

test('selection uses the original field and emits native input/change exactly once', () => {
    const ui = fixture();
    assert.equal(ui.fire('pointerdown').prevented, true);
    assert.equal(ui.select.getAttribute('aria-expanded'), 'true');
    const option = ui.menu().querySelectorAll('[role="option"]')[2];
    ui.fire('click', option);
    assert.equal(ui.select.selectedIndex, 2);
    assert.deepEqual(ui.emitted, ['input', 'change']);
    assert.equal(ui.menu(), undefined);
    assert.equal(ui.select.getAttribute('aria-controls'), null);
    assert.equal(ui.select.isConnected, true);
    ui.fire('pointerdown');
    ui.fire('keydown', ui.select, { key: 'Enter' });
    assert.deepEqual(ui.emitted, ['input', 'change']);
});

test('Escape cancels navigation without firing AJAX or closing the enclosing modal', () => {
    const ui = fixture();
    ui.fire('keydown', ui.select, { key: 'ArrowDown' });
    assert.equal(ui.select.selectedIndex, 0);
    const event = ui.fire('keydown', ui.select, { key: 'Escape' });
    assert.equal(event.stopped, true);
    assert.equal(ui.menu(), undefined);
    assert.equal(ui.select.selectedIndex, 0);
    assert.deepEqual(ui.emitted, []);
});

test('Tab commits the highlighted value and preserves normal focus traversal', () => {
    const ui = fixture();
    ui.fire('keydown', ui.select, { key: 'ArrowDown' });
    const event = ui.fire('keydown', ui.select, { key: 'Tab' });
    assert.equal(event.prevented, undefined);
    assert.equal(ui.select.selectedIndex, 2);
    assert.deepEqual(ui.emitted, ['input', 'change']);
});

test('Space confirms the highlighted option after the typeahead delay', t => {
    t.mock.method(Date, 'now', () => 1000);
    const ui = fixture();
    ui.fire('keydown', ui.select, { key: 'e' });
    assert.equal(ui.select.selectedIndex, 0);
    Date.now.mock.mockImplementation(() => 2000);
    ui.fire('keydown', ui.select, { key: ' ' });
    assert.equal(ui.select.selectedIndex, 2);
    assert.equal(ui.menu(), undefined);
    assert.deepEqual(ui.emitted, ['input', 'change']);
});

test('disabled options, disabled fields, multiple/listbox and explicit native opt-outs are not changed', () => {
    const ui = fixture();
    ui.fire('pointerdown');
    ui.fire('click', ui.menu().querySelectorAll('[role="option"]')[1]);
    assert.equal(ui.select.selectedIndex, 0);
    assert.deepEqual(ui.emitted, []);
    ui.fire('reset', ui.select);
    for (const [property, value] of [['disabled', true], ['multiple', true], ['size', 3], ['native', true]]) {
        const field = new ui.Node('SELECT'); field[property] = value; ui.root.body.append(field);
        assert.equal(ui.fire('pointerdown', field).prevented, undefined);
        assert.equal(ui.menu(), undefined);
    }
});

test('supports newly inserted selects, programmatic values, and AJAX option refresh', () => {
    const ui = fixture();
    const field = new ui.Node('SELECT'); ui.root.body.append(field);
    for (const label of ['Initial', '<script>alert(1)</script>']) {
        const option = new ui.Node('OPTION'); option.label = label; field.append(option);
    }
    field.selectedIndex = 1;
    ui.fire('pointerdown', field);
    assert.equal(ui.menu().children[1].getAttribute('aria-selected'), 'true');
    assert.equal(ui.menu().children[1].textContent, '<script>alert(1)</script>');
    field.options[1].label = 'Nouvelle option';
    ui.mutate(field);
    assert.equal(ui.menu().children[1].textContent, 'Nouvelle option');
    field.disabled = true;
    ui.mutate(field);
    assert.equal(ui.menu(), undefined);
});

test('removes the popup when its source is removed or a form resets', () => {
    for (const action of ['remove', 'reset']) {
        const ui = fixture(); ui.fire('pointerdown');
        if (action === 'remove') { ui.select.remove(); ui.mutate(ui.root.body); }
        else ui.fire('reset');
        assert.equal(ui.menu(), undefined);
        assert.deepEqual(ui.emitted, []);
    }
});

test('initialisation is idempotent and only one popup is open at once', () => {
    const ui = fixture();
    initSelectMenus(ui.root, ui.browser);
    ui.fire('pointerdown');
    assert.ok(ui.menu());
    ui.fire('pointerdown');
    assert.equal(ui.menu(), undefined);
});
