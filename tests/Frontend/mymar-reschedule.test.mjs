import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initMymarReschedule } from '../../resources/js/mymar-reschedule.js';

class Element {
    constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.handlers = {}; this.dataset = {}; this.value = ''; }
    append(...children) { children.forEach(child => { child.parent = this; this.children.push(child); }); }
    replaceChildren(...children) { this.children = []; this.append(...children); }
    setAttribute(name, value) { this[name] = value; }
    addEventListener(name, callback) { this.handlers[name] = callback; }
    closest(tag) { return this.tagName === tag.toUpperCase() ? this : this.parent?.closest(tag); }
    focus() { this.focused = true; }
    reportValidity() { return true; }
    showModal() { this.open = true; }
    close() { this.open = false; this.handlers.close?.(); }
}
const walk = el => [el, ...el.children.flatMap(walk)];
function fixture({ source = {}, fail = false } = {}) {
    const feedback = new Element('p'), old = new Element('dialog'), trigger = new Element('button');
    trigger.dataset.mymarRescheduleId = '555'; old.append(trigger); old.open = true;
    const root = { body: new Element('body'), handlers: {}, createElement: tag => new Element(tag),
        querySelector: () => feedback, addEventListener(name, callback) { this.handlers[name] = callback; } };
    const writes = [], reads = [];
    const browser = { CofracMymarActions: {
        async scheduled(id) { reads.push(id); if (fail) throw new Error('RDV modifié dans MYMAR.'); return { rdv_id: id, dossier_id: 123,
            expected_revision: 'a'.repeat(64), technician_id: 9, technicians: [{ id: 9, name: 'Camille' }],
            starts_at: '2026-11-05T10:40:00+01:00', duration_minutes: 120, customer: '<img src=x>', address: '10 rue Test', service: 'MAR après travaux', ...source }; },
        async reschedule(payload) { writes.push(payload); return { message: 'Confirmé dans MYMAR.' }; },
    } };
    initMymarReschedule(root, browser);
    const click = () => root.handlers.click({ target: { closest: () => trigger }, preventDefault() {} });
    return { root, old, feedback, writes, reads, click };
}

test('quick MAR replacement is prefilled with the real duration and technician, without writing on open', async () => {
    const ui = fixture(); await ui.click();
    assert.deepEqual(ui.reads, [555]); assert.equal(ui.writes.length, 0); assert.equal(ui.old.open, false);
    const nodes = walk(ui.root.body), fields = Object.fromEntries(nodes.filter(n => n.name).map(n => [n.name, n]));
    assert.equal(nodes.find(n => n.tagName === 'DIALOG').open, true);
    assert.equal(fields.date.value, '2026-11-05'); assert.equal(fields.time.value, '10:40');
    assert.equal(fields.technician_id.value, '9'); assert.equal(fields.duration_minutes.value, 120);
    assert.equal(fields.duration_minutes.readOnly, true); assert.equal(fields.comment.closest('label').hidden, true);
    assert.equal(nodes.find(n => n.textContent === 'Forcer le placement').hidden, true);
    assert.ok(nodes.some(n => n.textContent === '<img src=x>'));
    fields.time.value = '11:40';
    nodes.find(n => n.tagName === 'FORM').handlers.submit({ preventDefault() {} });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(ui.writes, [{ rdv_id: 555, dossier_id: 123, expected_revision: 'a'.repeat(64), technician_id: 9,
        starts_at: '2026-11-05T11:40:00+01:00', duration_minutes: 120 }]);
    assert.equal(nodes.find(n => n.tagName === 'DIALOG').open, false);
});

test('source errors and empty technician lists never open a placement form or make a write', async () => {
    for (const options of [{ fail: true }, { source: { technicians: [] } }]) {
        const ui = fixture(options); await ui.click();
        assert.equal(ui.writes.length, 0); assert.equal(ui.root.body.children.length, 0);
        assert.equal(ui.feedback.hidden, false); assert.ok(ui.feedback.textContent.length > 0);
    }
});
