import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/planner/book.blade.php', import.meta.url), 'utf8');
const implementation = source.slice(source.indexOf('const storedBookingSourceMode ='), source.indexOf('const clampProgress ='));
const listeners = source.slice(source.indexOf('bookingSourceTabs.forEach((tab, index) =>'), source.indexOf('bookingCrmRefreshButton?.addEventListener'));

function element(mode) {
    const classes = new Set();
    return {
        dataset: { bookingSource: mode, crmDescription: 'Demandes Cofrac' },
        attrs: {}, handlers: {}, hidden: false, textContent: '', focused: false,
        classList: { toggle: (key, on) => on ? classes.add(key) : classes.delete(key), add: (key) => classes.add(key), contains: (key) => classes.has(key) },
        setAttribute(name, value) { this.attrs[name] = value; },
        style: { setProperty() {} },
        addEventListener(name, handler) { this.handlers[name] = handler; },
        focus() { this.focused = true; },
    };
}

function fixture({ v2 = true, storage = 'crm', throws = false } = {}) {
    let persisted = storage;
    const context = {
        bookingSourceTabs: (v2 ? ['crm', 'lot', 'mymar'] : ['crm', 'lot']).map(element),
        bookingCrmSource: element(), bookingLotSource: element(), bookingMymarSource: v2 ? element() : null,
        bookingCrmSearchWrap: element(), bookingPendingSummary: element(), bookingCrmRefreshStatus: element(),
        bookingCrmEmpty: element(), bookingCrmPagination: element(), bookingSourceDescription: element(),
        bookingExternalRefreshButtons: [element()], bookingCrmCards: [element()],
        bookingSourceModeStorageKey: 'source', bookingSourceMode: 'crm', bookingCrmPage: 3, filtered: 0,
        filterBookingCrmCards() { context.filtered += 1; },
        window: { localStorage: {
            getItem() { if (throws) throw new Error('storage unavailable'); return persisted; },
            setItem(_key, value) { if (throws) throw new Error('storage unavailable'); persisted = value; },
        } },
    };
    runInNewContext(`${implementation}\n${listeners}\nglobalThis.select = setBookingSourceMode; globalThis.stored = storedBookingSourceMode;`, context);
    return { context, persisted: () => persisted };
}

test('source tabs expose exactly one panel and do not confuse MAR with native or lot requests', () => {
    const { context: ui, persisted } = fixture();
    for (const mode of ['mymar', 'lot', 'crm']) {
        ui.select(mode);
        assert.equal(ui.bookingSourceMode, mode);
        assert.equal(persisted(), mode);
        for (const [name, panel] of [['crm', ui.bookingCrmSource], ['lot', ui.bookingLotSource], ['mymar', ui.bookingMymarSource]]) {
            assert.equal(panel.hidden, name !== mode);
            assert.equal(panel.classList.contains('hidden'), name !== mode);
        }
        assert.equal(ui.bookingPendingSummary.classList.contains('hidden'), mode !== 'crm');
        assert.equal(ui.bookingCrmSearchWrap.classList.contains('hidden'), mode !== 'crm');
        assert.equal(ui.bookingSourceTabs.filter((tab) => tab.attrs['aria-selected'] === 'true').length, 1);
        assert.equal(ui.bookingSourceTabs.filter((tab) => tab.tabIndex === 0).length, 1);
    }
    assert.equal(ui.filtered, 1);
    assert.equal(ui.bookingCrmPage, 1);
    assert.equal(ui.bookingSourceDescription.textContent, 'Demandes Cofrac');
});

test('restores source choice, rejects invalid modes and preserves the legacy two-source page', () => {
    assert.equal(fixture({ storage: 'mymar' }).context.stored(), 'mymar');
    assert.equal(fixture({ storage: 'lot' }).context.stored(), 'lot');
    assert.equal(fixture({ storage: '<script>' }).context.stored(), 'crm');
    const legacy = fixture({ v2: false, storage: 'mymar' }).context;
    assert.equal(legacy.stored(), 'crm');
    legacy.select('mymar');
    assert.equal(legacy.bookingSourceMode, 'crm');
    const privateMode = fixture({ throws: true }).context;
    assert.equal(privateMode.stored(), 'crm');
    privateMode.select('lot');
    assert.equal(privateMode.bookingSourceMode, 'lot');
});

test('supports arrows, Home and End without hijacking unrelated keys', () => {
    const { context: ui } = fixture();
    let prevented = 0;
    const key = (index, value) => ui.bookingSourceTabs[index].handlers.keydown({ key: value, preventDefault: () => { prevented += 1; } });
    key(0, 'ArrowLeft');
    assert.equal(ui.bookingSourceMode, 'mymar');
    assert.equal(ui.bookingSourceTabs[2].focused, true);
    key(2, 'ArrowRight');
    assert.equal(ui.bookingSourceMode, 'crm');
    key(0, 'End');
    assert.equal(ui.bookingSourceMode, 'mymar');
    key(2, 'Home');
    assert.equal(ui.bookingSourceMode, 'crm');
    key(0, 'Tab');
    assert.equal(prevented, 4);
    ui.bookingSourceTabs[1].handlers.click();
    assert.equal(ui.bookingSourceMode, 'lot');
});

test('MAR tab exposes only a deferred read list, not a placement or problem action', () => {
    const panel = source.slice(source.indexOf('<div id="booking-mymar-source"'), source.indexOf('</section>', source.indexOf('<div id="booking-mymar-source"')));
    assert.match(panel, /ne sont pas encore activés/);
    assert.match(panel, /data-mymar-pending/);
    assert.match(panel, /data-mymar-refresh/);
    assert.doesNotMatch(panel, /<form|data-crm-id|data-place|fetch\(/);
    assert.doesNotMatch(implementation + listeners, /fetch\(|XMLHttpRequest|location\.(?:reload|href)/);
});
