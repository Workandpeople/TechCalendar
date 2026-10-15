import assert from 'node:assert/strict';
import { test } from 'node:test';
import { commentFragment, commentHtml, commentText, initCommentMarkup } from '../../resources/js/comment-markup.js';

const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

class Node {
    constructor(tag = null, value = '') {
        this.localName = tag;
        this.nodeType = tag === '#text' ? 3 : tag ? 1 : 11;
        this.value = value;
        this.childNodes = [];
        this.attributes = {};
    }
    append(node) { this.childNodes.push(...(node.nodeType === 11 ? node.childNodes : [node])); }
    get textContent() { return this.nodeType === 3 ? this.value : this.childNodes.map(child => child.textContent).join(''); }
    get innerHTML() {
        return this.childNodes.map(child => child.nodeType === 3 ? escape(child.value)
            : `<${child.localName}>${child.localName === 'br' ? '' : `${child.innerHTML}</${child.localName}>`}`).join('');
    }
}

const text = value => new Node('#text', value);
function element(tag, ...children) {
    const node = new Node(tag);
    children.forEach(child => node.append(typeof child === 'string' ? text(child) : child));
    return node;
}

// Supply a parsed tree to exercise reconstruction independently of a browser parser.
// Real malformed HTML and inert parsing are additionally checked in the browser.
function documentFor(...nodes) {
    return {
        createDocumentFragment: () => new Node(),
        createTextNode: text,
        createElement: tag => tag === 'template'
            ? { content: { childNodes: nodes }, set innerHTML(_value) {} }
            : new Node(tag),
    };
}

test('rebuilds paragraphs bold lists and breaks without any source attributes', () => {
    const bold = element('b', 'important');
    bold.attributes = { onclick: 'attack()', style: 'position:fixed' };
    const root = documentFor(element('p', 'Note ', bold, element('br'), 'Suite'), element('ul', element('li', 'Rappeler')));
    assert.equal(commentHtml('source', root), '<p>Note <b>important</b><br>Suite</p><ul><li>Rappeler</li></ul>');
});

test('drops active and embedded contents and unwraps links without retaining URLs', () => {
    const root = documentFor(element('script', 'attack()'), element('style', 'body{}'), element('svg', element('b', 'hidden')),
        element('iframe', 'hidden'), element('template', 'hidden'), element('a', 'Contacter'), element('img'));
    assert.equal(commentHtml('source', root), 'Contacter');
});

test('keeps literal decoded text escaped instead of interpreting it a second time', () => {
    assert.equal(commentHtml('source', documentFor(text('<img src=x onerror=attack()> & suite'))),
        '&lt;img src=x onerror=attack()&gt; &amp; suite');
});

test('plain previews preserve word boundaries and decode formatting without active content', () => {
    const root = documentFor(element('p', 'Bonjour\u00a0', element('b', 'Lucas')), element('p', 'Suite', element('br'), 'Fin'), element('script', 'hidden'));
    assert.equal(commentText('source', root), 'Bonjour Lucas\n\nSuite\nFin');
});

test('truncates text within valid formatting and respects Unicode characters', () => {
    const root = documentFor(element('p', element('b', 'AB😀CD'), 'Suite'));
    const result = new Node('div');
    result.append(commentFragment('source', root, 3));
    assert.equal(result.innerHTML, '<p><b>AB😀…</b></p>');
});

test('bounds deeply nested comments', () => {
    let nested = element('b', 'deep');
    for (let depth = 0; depth < 200; depth++) nested = element('div', nested);
    assert.doesNotThrow(() => commentHtml('source', documentFor(nested)));
    assert.doesNotMatch(commentHtml('source', documentFor(nested)), /deep/);
});

test('hydrates each escaped comment once without reinterpreting already-rendered text', () => {
    const root = documentFor(element('p', 'Example'));
    let calls = 0;
    const target = { dataset: {}, textContent: '<p>Example</p>', replaceChildren: () => { calls++; } };
    const scope = { ownerDocument: root, querySelectorAll: () => [target] };
    initCommentMarkup(scope);
    initCommentMarkup(scope);
    assert.equal(calls, 1);
});
