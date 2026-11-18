import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/manager/lots/index.blade.php', import.meta.url), 'utf8');
const warningHelpers = view.slice(view.indexOf('function lotImportWarnings('), view.indexOf('function lotImportCheckboxes('));
const selection = view.slice(view.indexOf('function lotImportSelectedWarningCheckboxes('), view.indexOf('function lotImportSelectedPayload('));
const count = view.slice(view.indexOf('function updateLotImportSelectionCount('), view.indexOf('function isLotImportRowSelected('));

test('accepted location warnings remain visible while only blocking warnings disable import confirmation', () => {
    const accepted = { checked: true, value: 'row-2', dataset: { rowNumber: '2', hasWarnings: '1', hasBlockingWarnings: '0' } };
    const blocking = { checked: true, value: 'row-3', dataset: { rowNumber: '3', hasWarnings: '1', hasBlockingWarnings: '1' } };
    const button = {}, label = {}, sandbox = { selectedLotImportRows: new Set(), lotImportCheckboxes: () => [accepted, blocking],
        lotImportConfirm: button, lotImportSelectionCount: label, currentLotImport: { confirm_url: '/confirm' },
        lotImportErrorMessage: { textContent: '' }, hideLotImportError() {} };
    const update = runInNewContext(`${selection}\n${count}\nupdateLotImportSelectionCount;`, sandbox);
    update();
    assert.equal(button.disabled, true);
    assert.match(label.textContent, /1 warning/);
    blocking.checked = false;
    update();
    assert.equal(button.disabled, false);
    assert.doesNotMatch(label.textContent, /warning/);
    accepted.checked = false;
    update();
    assert.equal(button.disabled, true);
});

test('an older preview without explicit blocking warnings remains conservative', () => {
    const helpers = runInNewContext(`${warningHelpers}\n({ warnings: lotImportWarnings, blocking: lotImportBlockingWarnings });`);
    assert.equal(helpers.warnings({ warnings: ['Position approximative'], blocking_warnings: [] }).length, 1);
    assert.equal(helpers.blocking({ warnings: ['Position approximative'], blocking_warnings: [] }).length, 0);
    assert.equal(helpers.blocking({ warnings: ['Ancienne erreur'] }).length, 1);
});

test('the actual preview renderer exposes an explicit per-line fallback and escapes the retained address', () => {
    const children = [];
    const render = view.slice(view.indexOf('function renderLotImportPreview('), view.indexOf('function lotImportStatusText('));
    const escape = view.slice(view.indexOf('function escapeHtml('), view.indexOf('function lotImportDisplayName('));
    const sandbox = { currentLotImport: null, lotImportPreviewRows: { appendChild: row => children.push(row), querySelectorAll: () => [] },
        lotImportPreviewSummary: {}, lotImportWarningsOnly: {}, lotImportWarningsSummary: { style: {} },
        normalizedPreviewRows: rows => rows, isLotImportRowSelected: () => true,
        lotImportDisplayName: () => 'Fictif', lotAppointmentBusinessLabel: () => '',
        document: { createElement: () => ({ dataset: {} }) }, lotImportPreview: { classList: { remove() {} } },
        applyLotImportWarningFilter() {}, updateLotImportSelectionCount() {},
    };
    const renderer = runInNewContext(`${warningHelpers}\n${escape}\n${render}\nrenderLotImportPreview;`, sandbox);
    renderer({ normalized_rows: 1, appointments: [{ row_number: 2, update_url: '/rows/2', geocoding_mode: 'unverified',
        address: '"><img src=x onerror=x>', warnings: ['Adresse non localisée'], blocking_warnings: [] }] });
    assert.equal(children.length, 2);
    assert.match(children[0].innerHTML, /data-has-warnings="1" data-has-blocking-warnings="0"/);
    assert.match(children[0].innerHTML, /Adresse conservée sans GPS/);
    assert.match(children[0].innerHTML, /&lt;img/);
    assert.doesNotMatch(children[0].innerHTML, /<img/);
    assert.match(children[1].innerHTML, /data-field="geocoding_mode"/);
    assert.match(children[1].innerHTML, /value="city"/);
    assert.match(children[1].innerHTML, /value="unverified" selected/);
    assert.match(children[1].innerHTML, /L’adresse saisie est conservée/);
    assert.match(children[1].innerHTML, /Enregistrer la ligne/);
    assert.match(children[1].innerHTML, /w-\[calc\(100vw-6rem\)\] max-w-full md:w-auto/);
});

test('opening a correction returns to the start of the horizontally scrolled table on mobile', () => {
    const code = view.slice(view.indexOf('function toggleLotImportEditRow('), view.indexOf('async function saveLotImportPreviewRow('));
    const calls = [];
    const row = { classList: { toggle: (...args) => calls.push(args) },
        closest: () => ({ scrollTo: ({ left }) => calls.push(['horizontal', left]) }),
        scrollIntoView: ({ block }) => calls.push(['vertical', block]) };
    const toggle = runInNewContext(`${code}\ntoggleLotImportEditRow;`, { lotImportEditRow: () => row });
    toggle('row', true);
    assert.deepEqual(calls, [['hidden', false], ['horizontal', 0], ['vertical', 'nearest']]);
});
