import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dossierFilterItems, clearDossierFilter, initDossierFilterPanel } from '../../resources/js/dossier-filter-panel.js';

function fixture(values = {}) {
    const fields = Object.fromEntries(['q', 'lot', 'status', 'delegataire_id', 'department', 'section', 'planning', 'billing', 'date_field', 'date_from', 'date_to', 'sort', 'direction', 'per_page', 'all_columns']
        .map(name => [name, { value: values[name] || '', focus() { this.focused = true; } }]));
    for (const [name, entries] of Object.entries({ delegataire_id: [['12', 'Organisation test']], department: [['75', '75 · Paris']],
        section: [['sav', 'SAV']], planning: [['problem', 'Avec un problème RDV']], billing: [['invoiced', 'Facturés']] })) {
        fields[name].options = entries.map(([value, label]) => ({ value, label }));
    }
    const events = [];
    fields['company_ids[]'] = { options: ['Société Alpha', 'Délégataire Bravo'].map((label, index) => ({ label, value: String(index + 12), selected: false })),
        dispatchEvent: event => events.push(event.type) };
    fields['planning_user_ids[]'] = { options: ['Alice Martin', 'Bob Dupont'].map((label, index) => ({ label, value: String(index + 12), selected: false })),
        dispatchEvent: event => events.push(event.type) };
    fields['service_ids[]'] = { options: ['Fiche A', 'Fiche B'].map((label, index) => ({ label, value: String(index + 1), selected: false })),
        dispatchEvent: event => events.push(event.type) };
    const node = tag => ({ tag, children: [], attributes: {}, dataset: {}, hidden: false,
        append(...children) { this.children.push(...children); }, replaceChildren() { this.children = []; },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(type, callback) { this[type] = callback; },
        querySelector(selector) { return selector === 'button' ? this.children[0] : null; },
        contains(child) { return this.children.includes(child); }, focus() { this.focused = true; },
        set innerHTML(value) { throw new Error(`Unsafe HTML rendering: ${value}`); } });
    const chips = node('div');
    const active = node('div');
    const details = { open: false };
    const counter = node('span');
    const nodes = { '[data-dossier-filter-chips]': chips, '[data-dossier-active-filters]': active,
        '[data-dossier-advanced]': details, '[data-dossier-advanced-count]': counter };
    const form = { elements: { namedItem: name => fields[name] }, querySelector: selector => nodes[selector] };
    const categories = { querySelectorAll: () => [{ dataset: { category: 'visited' }, querySelector: () => ({ textContent: 'RDV visité' }) }] };
    const root = { createElement: node };
    const browser = { CustomEvent: class { constructor(type) { this.type = type; } } };
    return { fields, events, chips, active, details, counter, form, categories, root, browser };
}

test('does not confuse pagination sorting columns or an unused date basis with search criteria', () => {
    const ui = fixture({ per_page: '100', all_columns: '1', sort: 'client', direction: 'desc', date_field: 'appointment' });
    assert.deepEqual(dossierFilterItems(ui.form), []);
});

test('summarizes primary and complementary criteria using their visible labels', () => {
    const ui = fixture({ q: '  rue du Test  ', status: 'visited', delegataire_id: '12', department: '75', section: 'sav',
        planning: 'problem', billing: 'invoiced', date_field: 'appointment', date_from: '2026-10-01', date_to: '2026-10-26' });
    ui.fields['service_ids[]'].options.forEach(option => { option.selected = true; });
    const items = dossierFilterItems(ui.form, { visited: 'RDV visité' });
    assert.equal(items.length, 9);
    assert.equal(items.filter(item => item.advanced).length, 5);
    assert.equal(items.find(item => item.key === 'q').label, 'Recherche : rue du Test');
    assert.equal(items.find(item => item.key === 'status').label, 'État : RDV visité');
    assert.equal(items.find(item => item.key === 'department').label, 'Département : 75 · Paris');
    assert.equal(items.find(item => item.key === 'services').label, 'Fiches : 2 sélectionnées');
    assert.equal(items.find(item => item.key === 'services').title, 'Fiches : Fiche A, Fiche B');
    assert.equal(items.find(item => item.key === 'dates').label, 'Date RDV : du 01/10/2026 au 26/10/2026');
});

test('handles a single service and open ended dates without timezone conversion', () => {
    const ui = fixture({ date_from: '2026-03-29' });
    ui.fields['service_ids[]'].options[0].selected = true;
    assert.equal(dossierFilterItems(ui.form)[0].label, 'Fiche : Fiche A');
    assert.equal(dossierFilterItems(ui.form)[1].label, 'Date RDV : à partir du 29/03/2026');
    ui.fields.date_from.value = '';
    ui.fields.date_to.value = '2026-10-25';
    assert.equal(dossierFilterItems(ui.form)[1].label, 'Date RDV : jusqu’au 25/10/2026');
});

test('always describes appointment dates even when a stale creation selector remains in an old page', () => {
    const ui = fixture({ date_field: 'created', date_from: '2026-11-02' });
    assert.equal(dossierFilterItems(ui.form)[0].label, 'Date RDV : à partir du 02/11/2026');
});

test('clearing one criterion preserves other criteria sorting and display settings', () => {
    const ui = fixture({ q: 'Lyon', status: 'visited', department: '75', per_page: '50', all_columns: '1', sort: 'client', direction: 'desc' });
    assert.equal(clearDossierFilter(ui.form, 'department', ui.browser), true);
    assert.equal(ui.fields.department.value, '');
    for (const [name, expected] of Object.entries({ q: 'Lyon', status: 'visited', per_page: '50', all_columns: '1', sort: 'client', direction: 'desc' })) {
        assert.equal(ui.fields[name].value, expected);
    }
    assert.equal(clearDossierFilter(ui.form, 'all_columns', ui.browser), false);
    assert.equal(clearDossierFilter(ui.form, '__proto__', ui.browser), false);
});

test('clearing services synchronizes the widget without emitting duplicate change requests', () => {
    const ui = fixture({ q: 'Lyon' });
    ui.fields['service_ids[]'].options.forEach(option => { option.selected = true; });
    ui.fields.service_id = { value: '12' };
    clearDossierFilter(ui.form, 'services', ui.browser);
    assert.equal(ui.fields.service_id.value, '');
    assert.equal(ui.fields['service_ids[]'].options.some(option => option.selected), false);
    assert.deepEqual(ui.events, ['techcalendar:selection-sync']);
    assert.equal(ui.fields.q.value, 'Lyon');
});

test('summarizes and clears multiple companies without clearing services or other filters', () => {
    const ui = fixture({ q: 'Paris', delegataire_id: '12', per_page: '50' });
    ui.fields['company_ids[]'].options.forEach(option => { option.selected = true; });
    ui.fields['service_ids[]'].options[0].selected = true;
    const items = dossierFilterItems(ui.form);
    const chip = items.find(item => item.key === 'companies');
    assert.equal(chip.label, 'Sociétés : 2 sélectionnées');
    assert.equal(chip.title, 'Sociétés : Société Alpha, Délégataire Bravo');
    assert.equal(items.some(item => item.key === 'delegataire_id'), false);
    clearDossierFilter(ui.form, 'companies', ui.browser);
    assert.equal(ui.fields.delegataire_id.value, '');
    assert.equal(ui.fields['company_ids[]'].options.some(option => option.selected), false);
    assert.equal(ui.fields['service_ids[]'].options[0].selected, true);
    assert.equal(ui.fields.q.value, 'Paris');
    assert.equal(ui.fields.per_page.value, '50');
    assert.deepEqual(ui.events, ['techcalendar:selection-sync']);
});

test('summarizes planning users only in visited and removes their chip without clearing services', () => {
    const ui = fixture({ status: 'visited', per_page: '50' });
    ui.fields['planning_user_ids[]'].options.forEach(option => { option.selected = true; });
    ui.fields['service_ids[]'].options[0].selected = true;
    const item = dossierFilterItems(ui.form).find(item => item.key === 'planning_users');
    assert.equal(item.label, 'Suivi planning : 2 responsables');
    assert.equal(item.title, 'Suivi planning : Alice Martin, Bob Dupont');
    assert.equal(item.advanced, false);
    clearDossierFilter(ui.form, 'planning_users', ui.browser);
    assert.equal(ui.fields['planning_user_ids[]'].options.some(option => option.selected), false);
    assert.equal(ui.fields['service_ids[]'].options[0].selected, true);
    assert.equal(ui.fields.per_page.value, '50');
    ui.fields['planning_user_ids[]'].options[0].selected = true;
    assert.equal(dossierFilterItems(ui.form).find(item => item.key === 'planning_users').label, 'Suivi planning : Alice Martin');
    clearDossierFilter(ui.form, 'status', ui.browser);
    assert.equal(ui.fields['planning_user_ids[]'].disabled, true);
    assert.equal(ui.fields['planning_user_ids[]'].options.some(option => option.selected), false);
    assert.equal(dossierFilterItems(ui.form).some(item => item.key === 'planning_users'), false);
});

test('clearing the period removes both boundaries as a single criterion', () => {
    const ui = fixture({ date_field: 'appointment', date_from: '2026-10-01', date_to: '2026-10-26', billing: 'invoiced' });
    clearDossierFilter(ui.form, 'dates', ui.browser);
    assert.equal(ui.fields.date_from.value, '');
    assert.equal(ui.fields.date_to.value, '');
    assert.equal(ui.fields.billing.value, 'invoiced');
});

test('reveals saved advanced criteria but respects a deliberate collapse until history is restored', () => {
    const ui = fixture({ department: '75' });
    const panel = initDossierFilterPanel(ui.form, ui.categories, ui.root, ui.browser, () => {});
    assert.equal(ui.details.open, true);
    assert.equal(ui.counter.textContent, '1 actif');
    ui.details.open = false;
    panel.sync();
    assert.equal(ui.details.open, false);
    panel.sync({ revealAdvanced: true });
    assert.equal(ui.details.open, true);
    ui.fields.department.value = '';
    panel.sync();
    assert.equal(ui.counter.hidden, true);
    assert.equal(ui.active.hidden, true);
});

test('renders labels only as text and removes one chip with a single update and predictable focus', () => {
    const ui = fixture({ q: '<img src=x onerror=alert(1)>', billing: 'invoiced' });
    let updates = 0;
    initDossierFilterPanel(ui.form, ui.categories, ui.root, ui.browser, () => updates++);
    const button = ui.chips.children[0];
    assert.equal(button.type, 'button');
    assert.equal(button.children[0].textContent, 'Recherche : <img src=x onerror=alert(1)>');
    assert.equal(button.children[1].attributes['aria-hidden'], 'true');
    assert.equal(button.attributes['aria-label'], 'Retirer le filtre Recherche : <img src=x onerror=alert(1)>');
    ui.chips.click({ target: { closest: () => button } });
    assert.equal(ui.fields.q.value, '');
    assert.equal(ui.fields.billing.value, 'invoiced');
    assert.equal(ui.chips.children.length, 1);
    assert.equal(ui.chips.children[0].focused, true);
    assert.equal(updates, 1);
});

test('falls back to the search field after removing the last chip and tolerates older layouts', () => {
    const ui = fixture({ q: 'Lyon' });
    initDossierFilterPanel(ui.form, ui.categories, ui.root, ui.browser, () => {});
    const button = ui.chips.children[0];
    ui.chips.click({ target: { closest: () => button } });
    assert.equal(ui.active.hidden, true);
    assert.equal(ui.fields.q.focused, true);
    assert.doesNotThrow(() => initDossierFilterPanel({ querySelector: () => null }, null, null, null).sync());
});

test('keeps existing chip nodes and focus through repeated pending and success synchronizations', () => {
    const ui = fixture({ q: 'Lyon', billing: 'invoiced' });
    const panel = initDossierFilterPanel(ui.form, ui.categories, ui.root, ui.browser, () => panel.sync());
    const removed = ui.chips.children[0];
    ui.chips.click({ target: { closest: () => removed } });
    const focused = ui.chips.children[0];
    panel.sync();
    panel.sync();
    assert.equal(ui.chips.children[0], focused);
    assert.equal(focused.focused, true);
    assert.equal(focused.children[0].textContent, 'Facturation : Facturés');
});

test('lot filtering has a safe removable chip and is counted as a complementary filter', () => {
    const ui = fixture({ lot: 'LOT <test> %_' });
    const item = dossierFilterItems(ui.form).find(item => item.key === 'lot');
    assert.equal(item.label, 'Lot : LOT <test> %_');
    assert.equal(item.advanced, true);
    assert.equal(clearDossierFilter(ui.form, 'lot', ui.browser), true);
    assert.equal(ui.fields.lot.value, '');
});
