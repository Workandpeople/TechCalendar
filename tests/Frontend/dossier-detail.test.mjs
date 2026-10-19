import assert from 'node:assert/strict';
import { test } from 'node:test';
import { revealDossierSection, initDossierDetail } from '../../resources/js/dossier-detail.js';

function fixture(dialog = false) {
    const handlers = {};
    let scrolls = 0;
    const details = { open: false, parentElement: { closest: () => null } };
    const target = {
        id: 'documents-dossier', open: false,
        closest: selector => selector === 'details' ? details : dialog ? target : null,
        matches: selector => dialog && selector.startsWith('dialog'),
        showModal() { this.open = true; },
        scrollIntoView: () => scrolls++,
    };
    const page = { ownerDocument: { getElementById: id => id === target.id ? target : null }, contains: () => true,
        querySelectorAll: () => [], querySelector: () => null,
        addEventListener: (type, handler) => { handlers[type] = handler; } };
    const view = { location: { hash: '#documents-dossier' }, addEventListener: (type, handler) => { handlers[type] = handler; } };
    return { page, view, target, details, handlers, scrolls: () => scrolls };
}

test('opens disclosures for normal anchors and dialogs for upload anchors', () => {
    for (const isDialog of [false, true]) {
        const { page, target, details } = fixture(isDialog);
        assert.equal(revealDossierSection(page, '#documents-dossier'), target);
        assert.equal(isDialog ? target.open : details.open, true);
        assert.equal(revealDossierSection(page, '#documents-dossier'), target);
    }
});

test('ignores malformed anchors and targets outside the dossier page', () => {
    const target = { closest: () => { throw new Error('Outside target accessed'); } };
    const page = { ownerDocument: { getElementById: () => target }, contains: () => false };
    for (const hash of [null, '', 'https://example.test/#x', '#%', '#outside']) assert.equal(revealDossierSection(page, hash), null);
});

test('handles initial deep links without scrolling the page behind a modal', () => {
    for (const dialog of [false, true]) {
        const f = fixture(dialog);
        initDossierDetail({ querySelector: () => f.page }, f.view);
        assert.equal(dialog ? f.target.open : f.details.open, true);
        assert.equal(f.scrolls(), dialog ? 0 : 1);
        f.handlers.hashchange();
        assert.equal(f.scrolls(), dialog ? 0 : 2);
    }
});

test('opens a dialog without navigating away and keeps the opener for focus restoration', () => {
    const f = fixture(true);
    initDossierDetail({ querySelector: () => f.page }, f.view);
    const link = { dataset: { dialogTarget: 'documents-dossier' } };
    let prevented = false;
    f.handlers.click({ target: { closest: () => link }, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(f.target.opener, link);
    f.target.open = false;
    f.handlers.click({ ctrlKey: true, target: { closest: () => link } });
    assert.equal(f.target.open, false);
});

test('reopens validation errors and supports close buttons, Escape close and focus return', () => {
    const f = fixture(true);
    const callbacks = {};
    f.target.addEventListener = (name, cb) => { callbacks[name] = cb; };
    f.target.close = () => { f.target.open = false; callbacks.close(); };
    f.page.querySelectorAll = () => [f.target];
    f.page.querySelector = () => f.target;
    f.view.location.hash = '';
    initDossierDetail({ querySelector: () => f.page }, f.view);
    assert.equal(f.target.open, true);
    let focused = false;
    f.target.opener = { focus: () => { focused = true; } };
    callbacks.click({ target: { closest: () => ({}) } });
    assert.equal(f.target.open, false);
    assert.equal(focused, true);
    assert.equal(f.target.opener, null);
});

test('does not attach handlers to other application pages', () => {
    initDossierDetail({ querySelector: () => null }, { addEventListener: () => { throw new Error('Unneeded handler'); } });
});
