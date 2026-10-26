import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initCommentExcerpts } from '../../resources/js/comment-excerpts.js';

function fixture(heights, { observer = true } = {}) {
    const frames = [], listeners = {};
    const entries = heights.map(height => {
        const body = { clientWidth: 300, scrollHeight: height };
        const button = { hidden: true, attrs: {}, handlers: {}, textContent: 'Afficher plus',
            setAttribute(name, value) { this.attrs[name] = value; },
            addEventListener(name, callback) { this.handlers[name] = callback; },
        };
        const excerpt = { dataset: {}, querySelector(selector) { return selector === '[data-excerpt-body]' ? body : button; } };
        return { excerpt, body, button };
    });
    const root = { querySelectorAll: () => entries.map(entry => entry.excerpt) };
    const browser = {
        getComputedStyle: () => ({ lineHeight: '20px' }),
        requestAnimationFrame(callback) { frames.push(callback); },
        addEventListener(name, callback) { listeners[name] = callback; },
    };
    let resize;
    const observed = [];
    if (observer) browser.ResizeObserver = class {
        constructor(callback) { resize = callback; }
        observe(body) { observed.push(body); }
    };
    return { root, browser, entries, observed, listeners, frames,
        flush() { while (frames.length) frames.shift()(); },
        resize(body, width) { resize([{ target: body, contentRect: { width } }]); },
    };
}

test('leaves one and two lines visible without a toggle, including rounding tolerance', () => {
    const ui = fixture([20, 40, 41, 60]);
    initCommentExcerpts(ui.root, ui.browser);
    assert.deepEqual(ui.entries.map(entry => entry.button.hidden), [true, true, true, false]);
    for (const { excerpt, button } of ui.entries) {
        assert.equal(excerpt.dataset.excerptReady, 'true');
        assert.equal(excerpt.dataset.excerptExpanded, 'false');
        assert.equal(button.attrs['aria-expanded'], 'false');
    }
});

test('expands and reduces the same content without modifying or duplicating the comment', () => {
    const ui = fixture([100]);
    initCommentExcerpts(ui.root, ui.browser);
    const { excerpt, button, body } = ui.entries[0];
    button.handlers.click();
    assert.equal(excerpt.dataset.excerptExpanded, 'true');
    assert.equal(button.textContent, 'Afficher moins');
    assert.equal(button.attrs['aria-expanded'], 'true');
    button.handlers.click();
    assert.equal(excerpt.dataset.excerptExpanded, 'false');
    assert.equal(button.textContent, 'Afficher plus');
    assert.equal(body.scrollHeight, 100);
});

test('recalculates the actual wrapping when the container becomes narrower or wider', () => {
    const ui = fixture([40]);
    initCommentExcerpts(ui.root, ui.browser);
    const { body, button, excerpt } = ui.entries[0];
    body.clientWidth = 150; body.scrollHeight = 80;
    ui.resize(body, 150); ui.flush();
    assert.equal(button.hidden, false);
    button.handlers.click();
    body.clientWidth = 600; body.scrollHeight = 20;
    ui.resize(body, 600); ui.flush();
    assert.equal(button.hidden, true);
    assert.equal(excerpt.dataset.excerptExpanded, 'false');
    assert.equal(button.attrs['aria-expanded'], 'false');
});

test('keeps an expanded long comment open after a resize', () => {
    const ui = fixture([100]);
    initCommentExcerpts(ui.root, ui.browser);
    ui.entries[0].button.handlers.click();
    ui.listeners.resize(); ui.flush();
    assert.equal(ui.entries[0].excerpt.dataset.excerptExpanded, 'true');
});

test('does not schedule an observer loop when only the height changes', () => {
    const ui = fixture([100]);
    initCommentExcerpts(ui.root, ui.browser);
    ui.resize(ui.entries[0].body, 300);
    assert.equal(ui.frames.length, 0);
});

test('supports browsers without ResizeObserver and batches repeated resize events', () => {
    const ui = fixture([20], { observer: false });
    initCommentExcerpts(ui.root, ui.browser);
    ui.entries[0].body.scrollHeight = 100;
    ui.listeners.resize(); ui.listeners.resize();
    assert.equal(ui.frames.length, 1);
    ui.flush();
    assert.equal(ui.entries[0].button.hidden, false);
});

test('measures comments once they become visible and never binds twice', () => {
    const ui = fixture([100]);
    ui.entries[0].body.clientWidth = 0;
    initCommentExcerpts(ui.root, ui.browser);
    assert.equal(ui.entries[0].excerpt.dataset.excerptReady, undefined);
    ui.entries[0].body.clientWidth = 300;
    ui.resize(ui.entries[0].body, 300); ui.flush();
    assert.equal(ui.entries[0].button.hidden, false);
    initCommentExcerpts(ui.root, ui.browser);
    assert.equal(ui.observed.length, 1);
});

test('refreshes wrapping after web fonts are ready', async () => {
    const ui = fixture([40]);
    let ready;
    ui.root.fonts = { ready: new Promise(resolve => { ready = resolve; }) };
    initCommentExcerpts(ui.root, ui.browser);
    ui.entries[0].body.scrollHeight = 60;
    ready(); await Promise.resolve(); ui.flush();
    assert.equal(ui.entries[0].button.hidden, false);
});
