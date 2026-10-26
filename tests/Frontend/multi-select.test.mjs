import assert from 'node:assert/strict';
import { test } from 'node:test';
import { multiSelectSummary } from '../../resources/js/multi-select.js';

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
