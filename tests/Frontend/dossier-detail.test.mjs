import assert from 'node:assert/strict';
import { test } from 'node:test';
import { revealDossierSection, initDossierDetail } from '../../resources/js/dossier-detail.js';

test('opens the existing upload disclosure without creating another form', () => {
    const details = { open: false, parentElement: { closest: () => null } };
    const target = { closest: () => details };
    const page = { ownerDocument: { getElementById: (id) => id === 'documents-dossier' ? target : null }, contains: () => true };
    assert.equal(revealDossierSection(page, '#documents-dossier'), target);
    assert.equal(details.open, true);
    details.open = false;
    assert.equal(revealDossierSection(page, '#documents-dossier'), target);
    assert.equal(details.open, true);
});

test('ignores malformed anchors and targets outside the dossier page', () => {
    const target = { closest: () => { throw new Error('Outside target accessed'); } };
    const page = { ownerDocument: { getElementById: () => target }, contains: () => false };
    for (const hash of [null, '', 'https://example.test/#x', '#%', '#outside']) {
        assert.equal(revealDossierSection(page, hash), null);
    }
});

test('handles initial deep links and repeated clicks on the same anchor', () => {
    const handlers = {};
    let scrolls = 0;
    const details = { open: false, parentElement: { closest: () => null } };
    const target = { closest: () => details, scrollIntoView: () => scrolls++ };
    const page = { ownerDocument: { getElementById: () => target }, contains: () => true,
        addEventListener: (type, handler) => { handlers[type] = handler; } };
    const view = { location: { hash: '#documents-dossier' }, addEventListener: (type, handler) => { handlers[type] = handler; } };
    initDossierDetail({ querySelector: () => page }, view);
    assert.equal(details.open, true);
    assert.equal(scrolls, 1);
    details.open = false;
    handlers.click({ target: { closest: () => ({ getAttribute: () => '#documents-dossier' }) } });
    assert.equal(details.open, true);
    handlers.hashchange();
    assert.equal(scrolls, 2);
});

test('does not attach handlers to other application pages', () => {
    initDossierDetail({ querySelector: () => null }, { addEventListener: () => { throw new Error('Unneeded handler'); } });
});
