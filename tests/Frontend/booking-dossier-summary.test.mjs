import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { initBookingDossierSummary, renderBookingDossierSummary, safeDossierSummaryUrl } from '../../resources/js/booking-dossier-summary.js';

test('only links to a native dossier on the current origin', () => {
    const origin = 'https://app.test';
    assert.equal(safeDossierSummaryUrl('/v2/dossiers/12', origin), `${origin}/v2/dossiers/12`);
    for (const url of [null, '', 'javascript:alert(1)', '//evil.test/v2/dossiers/12', '/admin/users',
        'https://user:password@app.test/v2/dossiers/12', '/v2/dossiers/12?edit=1', '/v2/dossiers/12#edit']) {
        assert.equal(safeDossierSummaryUrl(url, origin), null);
    }
});

function recapHarness() {
    const fields = ['beneficiary', 'email', 'address', 'service', 'client', 'delegataire', 'site', 'installer', 'lot', 'status']
        .map(key => ({ dataset: { bookingSummaryField: key }, textContent: '', hidden: false }));
    const optional = ['client', 'delegataire', 'site', 'installer', 'lot'].map(key => ({ dataset: { bookingSummaryOptional: key } }));
    const phones = { children: [], textContent: '', replaceChildren() { this.children = []; this.textContent = ''; },
        append(child) { this.children.push(child); }, get childElementCount() { return this.children.length; } };
    const link = { removeAttribute(key) { delete this[key]; } };
    const listeners = {};
    const section = { dataset: {}, hidden: true,
        ownerDocument: { createElement: tagName => ({ tagName }) },
        querySelectorAll: selector => selector === '[data-booking-summary-field]' ? fields : optional,
        querySelector: selector => selector === '[data-booking-summary-phones]' ? phones : link,
        addEventListener: (name, callback) => { listeners[name] = callback; },
    };
    const browser = { location: { origin: 'https://app.test' } };
    return { section, fields, optional, phones, link, listeners, browser };
}

test('renders contact information as text, not HTML, with separate client and delegataire audiences', () => {
    const ui = recapHarness();
    renderBookingDossierSummary(ui.section, { beneficiary: '<img src=x onerror=alert(1)>', client: 'Client', delegataire: 'Délégataire',
        status: 'Prise de RDV', dossier_url: '/v2/dossiers/12', phone_numbers: [
            { label: '06 09 28 53 33', href: 'tel:+33609285333' }, { label: '<script>test</script>', href: 'javascript:alert(1)' },
        ] }, ui.browser);
    assert.equal(ui.section.hidden, false);
    assert.equal(ui.fields[0].textContent, '<img src=x onerror=alert(1)>');
    assert.equal(ui.fields[0].innerHTML, undefined);
    assert.equal(ui.phones.children[0].tagName, 'a');
    assert.equal(ui.phones.children[1].tagName, 'span');
    assert.equal(ui.phones.children[1].href, undefined);
    assert.equal(ui.link.href, 'https://app.test/v2/dossiers/12');
    assert.equal(ui.optional[0].hidden, false);
    assert.equal(ui.optional[1].hidden, false);
    assert.equal(ui.optional[2].hidden, true);
});

test('clears all previous details and links when changing to an incomplete manual request or resetting', () => {
    const ui = recapHarness();
    renderBookingDossierSummary(ui.section, { beneficiary: 'Ancien dossier', client: 'Client', dossier_url: '/v2/dossiers/12',
        phone_numbers: [{ label: 'Téléphone', href: 'tel:+33609285333' }] }, ui.browser);
    renderBookingDossierSummary(ui.section, { beneficiary: 'RDV manuel' }, ui.browser);
    assert.equal(ui.fields[0].textContent, 'RDV manuel');
    assert.equal(ui.optional[0].hidden, true);
    assert.equal(ui.link.hidden, true);
    assert.equal(ui.link.href, undefined);
    assert.equal(ui.phones.textContent, 'Non renseigné');
    assert.equal(ui.phones.children.length, 0);
    renderBookingDossierSummary(ui.section, null, ui.browser);
    assert.equal(ui.section.hidden, true);
    assert.equal(ui.fields[0].textContent, 'Non renseigné');
});

test('restores context published before the module loads and listens for later selections', () => {
    const ui = recapHarness();
    ui.section.dataset.bookingSummary = JSON.stringify({ beneficiary: 'Sélection initiale' });
    initBookingDossierSummary({ querySelector: () => ui.section }, ui.browser);
    assert.equal(ui.fields[0].textContent, 'Sélection initiale');
    ui.section.dataset.bookingSummary = JSON.stringify({ beneficiary: 'Deuxième sélection' });
    ui.listeners['techcalendar:booking-summary-updated']();
    assert.equal(ui.fields[0].textContent, 'Deuxième sélection');
    ui.section.dataset.bookingSummary = 'incomplete JSON';
    ui.listeners['techcalendar:booking-summary-updated']();
    assert.equal(ui.section.hidden, true);
});

const view = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const analysisCode = view.slice(view.indexOf('const analyzeAppointment ='), view.indexOf('const analyzeCrmAppointment ='));
function analysisHarness() {
    const pending = [], summaries = [], rendered = [], errors = [];
    const sandbox = { bookingAnalysisGeneration: 0, currentAnalysisPayload: null, currentCrmAppointmentId: null,
        currentAppointmentRequest: null, currentTechnicians: [], currentTechnicianColors: {}, selectedTechnicianIds: new Set(),
        currentFilters: null, currentCalendarEvents: [], currentCalendarSuggestions: [], shouldFetchCalendarWindow: false,
        calendarWindowAbortController: null, bookingConnectorsEnabled: true,
        bookingCrmAppointments: [{ id: 'a' }, { id: 'b' }], bookingAnalyzeUrl: '/analysis', bookingCsrfToken: '',
        clearBookingAnalysisContext() { sandbox.bookingAnalysisGeneration++; },
        updateBookingDossierSummary: value => summaries.push(value?.id),
        analysisSection: { classList: { remove() {} } }, techniciansList: {}, technicianSearchInput: {},
        startBookingAnalysisLoader() {}, scrollToBookingResults() {}, clearFeedback() {}, clearTechnicianSearch() {},
        hideCalendarLoader() {}, renderTechnicians: (_rows, appointment) => rendered.push(appointment.id),
        selectedTechnicians: () => [], renderMap: async () => {}, renderCalendar() {}, finishBookingAnalysisLoader: async () => {},
        showFeedback: message => errors.push(message),
        window: { requestAnimationFrame() {}, setTimeout() {} },
        fetch: () => new Promise((resolve, reject) => pending.push({ resolve, reject })),
    };
    const analyze = runInNewContext(`${analysisCode}\nanalyzeAppointment;`, sandbox);
    const response = id => ({ ok: true, json: async () => ({ crm_appointment: { id }, technicians: [{}], suggestions: [{}], filters: {} }) });
    return { analyze, pending, summaries, rendered, errors, sandbox, response };
}

test('an older analysis response never overwrites the newly selected dossier or its results', async () => {
    const ui = analysisHarness();
    const first = ui.analyze({ crm_appointment_id: 'a' });
    const second = ui.analyze({ crm_appointment_id: 'b' });
    ui.pending[1].resolve(ui.response('b'));
    assert.equal(await second, true);
    ui.pending[0].resolve(ui.response('a'));
    assert.equal(await first, false);
    assert.equal(ui.summaries.at(-1), 'b');
    assert.deepEqual(ui.rendered, ['b']);
    assert.equal(ui.sandbox.currentAppointmentRequest.id, 'b');
});

test('an older failed request does not clear the new dossier or display a stale error', async () => {
    const ui = analysisHarness();
    const first = ui.analyze({ crm_appointment_id: 'a' });
    const second = ui.analyze({ crm_appointment_id: 'b' });
    ui.pending[0].reject(new Error('Erreur ancienne'));
    assert.equal(await first, false);
    assert.deepEqual(ui.errors, []);
    assert.equal(ui.summaries.at(-1), 'b');
    ui.pending[1].resolve(ui.response('b'));
    assert.equal(await second, true);
});

test('changing dossier before the map style loads still clears markers without throwing', () => {
    const code = view.slice(view.indexOf('const clearMap ='), view.indexOf('const straightRoute ='));
    let removed = false;
    const sandbox = { bookingMapMarkers: [{ remove() { removed = true; } }], bookingMap: { getStyle: () => undefined } };
    runInNewContext(`${code}\nclearMap();`, sandbox);
    assert.equal(removed, true);
    assert.equal(sandbox.bookingMapMarkers.length, 0);
});
