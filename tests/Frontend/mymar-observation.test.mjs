import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const source = readFileSync(new URL('../../resources/views/planner/partials/mymar-observation.blade.php', import.meta.url), 'utf8');
const script = source.split('<script>')[1].split('</script>')[0].replace("@json(route('planner.book', [], false))", "'/planner/book'");
function element(tag = 'div') {
    return { tagName: tag.toUpperCase(), children: [], dataset: {}, attributes: {}, hidden: false, handlers: {},
        classList: { add() {}, contains: name => name === 'nc-event' },
        append(...items) { this.children.push(...items); }, replaceChildren(...items) { this.children = items; },
        setAttribute(key, value) { this.attributes[key] = value; }, addEventListener(key, callback) { this.handlers[key] = callback; },
        focus() { this.focused = true; }, showModal() { this.open = true; }, close() { this.open = false; this.handlers.close?.(); }, remove() { this.removed = true; },
    };
}
function harness() {
    const banner = element(), body = element('body');
    const context = { window: {}, Date, Intl, document: { createElement: element, body, getElementById: () => banner } };
    runInNewContext(script, context);
    return { ui: context.window.CofracMymarCalendar, banner, body };
}
const event = () => ({ start: new Date('2026-11-02T08:30:00Z'), end: new Date('2026-11-02T09:30:00Z'), extendedProps: {
    is_mymar: true, technician_name: '<img src=x onerror=alert(1)>', service_label: 'MAR après travaux',
    synced_at: '2026-11-02T08:00:00Z', cache_state: 'stale',
} });
function text(node) { return [node.textContent || '', ...node.children.map(text)].join(' '); }

test('external cards use text-only nodes, Paris hours and an explicit nonblocking read-only label', () => {
    const { ui } = harness();
    assert.equal(ui.isMymar(event()), true);
    const content = ui.content(event()).domNodes[0];
    assert.match(text(content), /09:30.*10:30/);
    assert.match(text(content), /Lecture seule/);
    assert.match(text(content), /Données à actualiser/);
    assert.match(text(content), /<img src=x/);
    assert.ok(!script.includes('innerHTML'));
    const card = element('button');
    ui.mount({ el: card, event: event() });
    assert.match(card.title, /Ne bloque pas les créneaux/);
    assert.equal(card.children.length, 1);
    const month = element('button');
    month.classList.contains = name => name === 'nc-month-event';
    ui.mount({ el: month, event: event() });
    assert.match(text(month), /09:30 · MYMAR · Observation/);
});

test('click opens only a read-only dialog, with focus returned to the source card', () => {
    const { ui, body } = harness();
    const trigger = element('button');
    ui.open(event(), trigger);
    const dialog = body.children[0];
    assert.equal(dialog.open, true);
    assert.match(text(dialog), /ne peut pas être modifié/);
    assert.match(text(dialog), /Horaires|horaires/);
    const buttons = dialog.children.filter(child => child.tagName === 'BUTTON');
    assert.equal(buttons.length, 1);
    assert.equal(buttons[0].textContent, 'Fermer');
    buttons[0].handlers.click();
    assert.equal(dialog.removed, true);
    assert.equal(trigger.focused, true);
    assert.ok(!script.includes('fetch('));
});

test('all failure states remain explicit and an outside-pilot batch cannot erase an earlier warning', () => {
    const { ui, banner } = harness();
    for (const state of ['stale', 'degraded', 'unavailable', 'invalid', 'mapping_changed', 'partial']) {
        ui.setStatus('banner', { state });
        assert.equal(banner.hidden, false);
        assert.match(banner.textContent, /sans blocage/);
    }
    const merged = ui.combineStatuses([{ state: 'stale', covered_technicians: 4, pilot_technicians: 4, selected_technicians: 80 },
        { state: 'outside_pilot', covered_technicians: 0, pilot_technicians: 4, selected_technicians: 20 }]);
    assert.equal(merged.state, 'stale');
    assert.equal(merged.covered_technicians, 4);
    assert.equal(merged.selected_technicians, 100);
    ui.setStatus('banner', { state: 'disabled' });
    assert.equal(banner.hidden, true);
    assert.equal(banner.textContent, '');
});

test('the active guard is described accurately without enabling calendar editing', () => {
    const { ui, banner, body } = harness();
    const appointment = event();
    appointment.extendedProps.availability_guard_enabled = true;
    const card = element('button');
    ui.mount({ el: card, event: appointment });
    assert.match(card.title, /Disponibilité MAR contrôlée/);
    assert.doesNotMatch(card.title, /Ne bloque pas/);
    ui.open(appointment, card);
    assert.match(text(body.children[0]), /se modifie dans MYMAR/);
    ui.setStatus('banner', { state: 'observing', blocking_enabled: true });
    assert.match(banner.textContent, /Disponibilités contrôlées au placement/);
    assert.doesNotMatch(banner.textContent, /sans blocage/);
});

test('MAR beneficiary and full address are visible on cards, hover text and the read-only detail dialog', () => {
    const { ui, body } = harness();
    const appointment = event();
    appointment.extendedProps.customer_name = '<img src=x> Philippe Martin';
    appointment.extendedProps.address = '10 rue des Tilleuls, 01560 Lescheroux';
    const content = ui.content(appointment).domNodes[0];
    assert.match(text(content), /Philippe Martin/); assert.match(text(content), /01560 Lescheroux/);
    const card = element('button'); ui.mount({ el: card, event: appointment });
    assert.match(card.title, /Philippe Martin/); assert.match(card.title, /10 rue des Tilleuls/);
    ui.open(appointment, card);
    assert.match(text(body.children[0]), /<img src=x> Philippe Martin/);
    assert.match(text(body.children[0]), /01560 Lescheroux/);
    assert.equal(body.children[0].children.filter(child => child.tagName === 'BUTTON').length, 1);
    assert.doesNotMatch(text(body.children[0]), /Le bénéficiaire.*ne sont pas repris/);
});

test('old caches keep MAR occupations visible with explicit missing-label placeholders', () => {
    const { ui } = harness();
    const content = ui.content(event()).domNodes[0];
    assert.match(text(content), /Bénéficiaire non disponible/); assert.match(text(content), /Adresse non disponible/);
    assert.match(text(content), /MAR après travaux/);
});

test('only a current after-works appointment explicitly enabled by the server offers quick replacement', () => {
    for (const phase of ['initial', 'after_works']) {
        const { ui, body } = harness();
        const appointment = event();
        Object.assign(appointment.extendedProps, { can_reschedule: true, source_rdv_id: 555, visit_phase: phase });
        ui.open(appointment, element('button'));
        const rebook = body.children[0].children.find(child => child.dataset.mymarRescheduleId);
        assert.equal(Boolean(rebook), phase === 'after_works');
        const complete = body.children[0].children.find(child => child.tagName === 'A');
        assert.equal(Boolean(complete), phase === 'after_works');
        if (rebook) {
            assert.equal(rebook.dataset.mymarRescheduleId, '555');
            assert.equal(complete.href, '/planner/book?mymar_rdv_id=555');
        }
    }
});

test('MAR document links only use the authenticated local document entry point', () => {
    for (const url of ['/mar/appointments/555/documents?technician_id=9', 'https://other.test/private.pdf', 'javascript:alert(1)']) {
        const { ui, body } = harness(); const item = event();
        Object.assign(item.extendedProps, { visit_phase: 'after_works', documents_url: url });
        ui.open(item, element('button'));
        const link = body.children[0].children.find(child => child.tagName === 'A');
        assert.equal(Boolean(link), url.startsWith('/mar/appointments/'));
        if (link) assert.equal(link.href, url);
    }
});
