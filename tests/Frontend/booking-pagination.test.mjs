import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const render = source.slice(source.indexOf('const renderBookingCrmPagination ='), source.indexOf('const filterBookingCrmCards ='));
const labels = {
    previous: 'Précédent', next: 'Suivant',
    results: 'Résultats :first à :last sur :total', page: 'Page :current sur :last',
};

function fixture(page, count, mode = 'crm') {
    const callbacks = {};
    let hidden = true;
    let filtered = 0;
    const element = {
        innerHTML: '',
        classList: { add: () => { hidden = true; }, remove: () => { hidden = false; } },
        querySelectorAll: () => ['prev', 'next'].map((action) => ({
            dataset: { crmPageAction: action },
            addEventListener: (_event, callback) => { callbacks[action] = callback; },
        })),
    };
    const context = {
        bookingCrmPagination: element, bookingCrmPage: page,
        bookingCrmPageSize: 10, bookingSourceMode: mode,
        bookingPaginationLabels: labels,
        escapeHtml: (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
        filterBookingCrmCards: () => { filtered += 1; },
    };
    runInNewContext(`${render}\nrenderBookingCrmPagination(${count});`, context);
    return { element, context, callbacks, hidden: () => hidden, filtered: () => filtered };
}

test('shows French counts and consistent controls including a partial last page', () => {
    const first = fixture(1, 23);
    assert.equal(first.hidden(), false);
    assert.match(first.element.innerHTML, /Résultats 1 à 10 sur 23/);
    assert.match(first.element.innerHTML, /Page 1 sur 3/);
    assert.match(first.element.innerHTML, /gc-pagination-button gc-pagination-step/);
    assert.match(first.element.innerHTML, /data-crm-page-action="prev" disabled/);
    assert.doesNotMatch(first.element.innerHTML, /data-crm-page-action="next" disabled/);
    const last = fixture(3, 23);
    assert.match(last.element.innerHTML, /Résultats 21 à 23 sur 23/);
    assert.match(last.element.innerHTML, /data-crm-page-action="next" disabled/);
});

test('switches pages without exceeding bounds', () => {
    const ui = fixture(1, 23);
    ui.callbacks.prev();
    assert.equal(ui.context.bookingCrmPage, 1);
    ui.callbacks.next();
    assert.equal(ui.context.bookingCrmPage, 2);
    ui.callbacks.next();
    ui.callbacks.next();
    assert.equal(ui.context.bookingCrmPage, 3);
    assert.equal(ui.filtered(), 4);
});

test('hides controls for an empty or single page and in lot mode', () => {
    for (const [count, mode] of [[0, 'crm'], [10, 'crm'], [23, 'lot']]) {
        const ui = fixture(1, count, mode);
        assert.equal(ui.hidden(), true);
        assert.equal(ui.element.innerHTML, '');
    }
});
