import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { companyLabel, companyOptions, filterBookingCompanies, renderCompanyOptions } from '../../resources/js/booking-company-filter.js';

const rows = [
    { id: '1', company_name: 'Zénith', last_name: 'Durand', first_name: 'Paul', service: { name: 'BAT EQ 127' } },
    { id: '2', company_name: 'Énergie Test', last_name: 'Martin', first_name: 'Léa', service: { name: 'BAR TH 171' } },
    { id: '3', company_name: '  ENERGIE   TEST ', last_name: 'Albert', first_name: 'Marc' },
    { id: '4', company_name: null, last_name: 'Sans société' },
];

test('groups company spelling variants and counts prestations, not just unique beneficiary names', () => {
    assert.deepEqual(companyOptions(rows), [
        { key: 'name:energie test', label: 'Énergie Test', count: 2 },
        { key: 'name:zenith', label: 'Zénith', count: 1 },
        { key: '__without_company__', label: 'Société non renseignée', count: 1 },
    ]);
    assert.equal(companyLabel({ client_company_name: 'Client', delegataire_company_name: 'Delegate' }), 'Client');
    assert.equal(companyLabel({ delegataire_company_name: 'Delegate' }), 'Delegate');
});

test('combines exact company filtering with accent insensitive beneficiary and service search', () => {
    assert.deepEqual(filterBookingCompanies(rows, { company: 'name:energie test', query: 'lea bar' }).map(row => row.id), ['2']);
    assert.deepEqual(filterBookingCompanies(rows, { company: 'name:energie' }), []);
    assert.deepEqual(filterBookingCompanies(rows, { company: '__without_company__' }).map(row => row.id), ['4']);
    assert.deepEqual(filterBookingCompanies(rows, { query: '<script>' }), []);
});

test('does not confuse a real company name with the missing-company filter', () => {
    const items = [{ id: 1, company_name: '__without_company__' }, { id: 2 }];
    assert.deepEqual(filterBookingCompanies(items, { company: '__without_company__' }).map(row => row.id), [2]);
    assert.deepEqual(filterBookingCompanies(items, { company: 'name:__without_company__' }).map(row => row.id), [1]);
});

test('sorts the whole queue before pagination and keeps unknown companies last in both directions', () => {
    assert.deepEqual(filterBookingCompanies(rows, { sort: 'company_asc' }).map(row => row.id), ['3', '2', '1', '4']);
    assert.deepEqual(filterBookingCompanies(rows, { sort: 'company_desc' }).map(row => row.id), ['1', '3', '2', '4']);
    assert.deepEqual(filterBookingCompanies(rows).map(row => row.id), ['1', '2', '3', '4']);
    assert.deepEqual(rows.map(row => row.id), ['1', '2', '3', '4']);
});

test('renders labels as text and retains a selected company with zero results after refresh', () => {
    const select = { value: 'old company', selectedOptions: [{ dataset: { companyLabel: 'Old company' } }],
        ownerDocument: { createElement: () => ({ dataset: {}, set innerHTML(_) { assert.fail('Unsafe company HTML'); } }) },
        replaceChildren(...options) { this.options = options; } };
    renderCompanyOptions(select, [{ company_name: '<img onerror=x>' }]);
    assert.equal(select.value, 'old company');
    assert.equal(select.options[1].textContent, '<img onerror=x> (1)');
    assert.equal(select.options[2].textContent, 'Old company (0)');
});

test('initializes inline booking controls only after the deferred application module has loaded', () => {
    const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
    const script = source.split('const initializeBookingSource = () => {')[1].split('if (bookingInitialCrmAppointmentId) {')[0];
    const initialize = new Function('window', 'document', 'bookingCrmCompany', 'bookingCrmAppointments', 'setBookingSourceMode',
        'initialMarRdv', 'bookingInitialCrmAppointmentId', 'storedBookingSourceMode', 'analyzeAppointment',
        `const initializeBookingSource = () => {${script}`);
    for (const readyState of ['loading', 'interactive', 'complete']) {
        let ready, calls = [];
        const window = {}, document = { readyState, addEventListener(event, callback, options) {
            assert.equal(event, 'DOMContentLoaded'); assert.equal(options.once, true); ready = callback;
        } };
        const exposeModule = () => { window.TechCalendarBookingCompanies = { renderOptions: () => calls.push('options') }; };
        if (readyState !== 'loading') exposeModule();
        initialize(window, document, {}, rows, mode => calls.push(mode), null, null, () => 'crm', () => assert.fail('No MAR request'));
        if (readyState === 'loading') {
            assert.deepEqual(calls, []);
            exposeModule(); ready();
        }
        assert.deepEqual(calls, ['options', 'crm']);
    }
});
