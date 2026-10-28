import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initMultiSelects, multiSelectSummary } from '../../resources/js/multi-select.js';

test('summarizes all single and multiple selections using the native options only', () => {
    const select = { dataset: { emptyLabel: 'Toutes les fiches' }, options: [
        { label: 'BAR TH 171', selected: false }, { label: 'AGRI TH 117', selected: false },
    ] };
    assert.equal(multiSelectSummary(select).label, 'Toutes les fiches');
    select.options[0].selected = true;
    assert.deepEqual(multiSelectSummary(select), { count: 1, title: 'BAR TH 171', label: 'BAR TH 171' });
    select.options[1].selected = true;
    assert.deepEqual(multiSelectSummary(select), { count: 2, title: 'BAR TH 171, AGRI TH 117', label: '2 fiches sélectionnées' });
    select.options.forEach(option => { option.selected = false; });
    assert.equal(multiSelectSummary(select).count, 0);
});

test('uses responsible-user wording without changing the service selector defaults', () => {
    const select = { dataset: { emptyLabel: 'Tous les responsables', selectedLabel: 'responsables sélectionnés' }, options: [
        { label: 'Alice Martin', selected: false }, { label: 'Bob Dupont', selected: false },
    ] };
    assert.equal(multiSelectSummary(select).label, 'Tous les responsables');
    select.options.forEach(option => { option.selected = true; });
    assert.equal(multiSelectSummary(select).label, '2 responsables sélectionnés');
    select.options[1].selected = false;
    assert.equal(multiSelectSummary(select).label, 'Alice Martin');
});

// A small DOM double keeps required-field validation testable without a browser dependency.
function requiredPicker() {
    const root = { documentElement: { clientWidth: 1024 }, activeElement: null, addEventListener() {} };
    class Element {
        constructor(tag) { this.tagName = tag; this.children = []; this.dataset = {}; this.attrs = {}; this.handlers = {}; this.style = {}; }
        append(...children) { for (const child of children) { this.children.push(child); child.parentElement = this; } }
        before(child) { this.parentElement.append(child); }
        remove() { this.parentElement.children = this.parentElement.children.filter(item => item !== this); }
        setAttribute(key, value) { this.attrs[key] = value; }
        getAttribute(key) { return this.attrs[key] ?? null; }
        removeAttribute(key) { delete this.attrs[key]; }
        addEventListener(type, callback) { this.handlers[type] = callback; }
        focus() { root.activeElement = this; }
        closest() { return null; }
        replaceChildren() { this.children = []; }
        querySelectorAll() { return this.children.flatMap(child => [child, ...child.querySelectorAll()]).filter(child => child.tagName === 'input'); }
        getBoundingClientRect() { return { left: 10, top: 100, bottom: 144, width: 280, height: 44 }; }
        get isConnected() { return true; }
        dispatchEvent(event) { this.handlers[event.type]?.(event); }
    }
    root.createElement = tag => new Element(tag);
    root.body = new Element('body');
    const select = new Element('select');
    select.id = 'prestations'; select.required = true; select.form = new Element('form');
    select.options = [{ label: 'BAR EN 101', selected: false }, { label: 'AGRI TH 117', selected: false }];
    select.labels = [{ textContent: 'Prestations' }];
    root.body.append(select);
    root.querySelectorAll = () => [select];
    const browser = { innerHeight: 800, addEventListener() {}, setTimeout: fn => fn(), Event: class { constructor(type) { this.type = type; } } };
    initMultiSelects(root, browser);
    const wrapper = root.body.children.find(child => child.className === 'gc-multi-select');
    return { root, select, button: wrapper.children[0], wrapper,
        submit() { const event = { preventDefault() { this.prevented = true; } }; select.form.handlers.submit(event); return event; } };
}

test('validates the visible required picker and resets errors without a hidden invalid field', () => {
    const ui = requiredPicker();
    assert.equal(ui.select.required, false);
    assert.equal(ui.select.hidden, true);
    assert.equal(ui.submit().prevented, true);
    assert.equal(ui.button.getAttribute('aria-invalid'), 'true');
    assert.equal(ui.button.getAttribute('aria-expanded'), 'true');
    ui.select.options[0].selected = true;
    ui.select.dispatchEvent({ type: 'change' });
    assert.equal(ui.button.getAttribute('aria-invalid'), null);
    assert.equal(ui.submit().prevented, undefined);
    ui.select.options[0].selected = false;
    ui.submit();
    ui.select.form.handlers.reset();
    assert.equal(ui.button.getAttribute('aria-invalid'), null);
    assert.equal(ui.button.getAttribute('aria-expanded'), 'false');
    ui.select.disabled = true;
    assert.equal(ui.submit().prevented, undefined);
});
