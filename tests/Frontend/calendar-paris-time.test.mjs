import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { test } from 'node:test';

for (const timezone of ['UTC', 'Europe/Paris', 'America/New_York', 'Indian/Reunion', 'Pacific/Honolulu']) {
    test(`choosing 10:40 remains 10:40 in Paris, including the rendered card, with a ${timezone} browser`, () => {
        const script = `
            import { NativeCalendar } from './resources/js/planning/native-calendar.js';
            import { calendarDate, dayKey, calendarMinute, calendarRange, normalizeCalendarEvent } from './resources/js/planning/calendar-model.js';
            import { serializeAppointmentInput, formatAppointmentInput } from './resources/js/appointment-date-time.js';
            const nodes = [];
            class Node {
                constructor(tag) { this.tag = tag; this.children = []; this.handlers = {}; this.dataset = {}; this.style = {setProperty(){}}; nodes.push(this); }
                append(...children) { this.children.push(...children); }
                setAttribute() {} addEventListener(type, listener) { this.handlers[type] = listener; }
                close() { this.handlers.close?.(); } remove() {} showModal() {} focus() {}
                setCustomValidity(message) { this.error = message; } reportValidity() {}
            }
            const calendar = Object.create(NativeCalendar.prototype);
            calendar.document = {createElement: tag => new Node(tag), body: new Node('body')};
            calendar.events = []; calendar.resources = () => [{id:'7', name:'Camille Test'}];
            calendar.toggleExpanded = () => {}; calendar.mountEvent = node => node;
            const results = [];
            for (const day of ['2026-10-29', '2026-07-15', '2026-03-29', '2026-10-25']) {
                let click;
                calendar.date = calendarDate(day); calendar.mode = 'timeGridWeek';
                calendar.options = {dateClick: value => {click = value;}};
                calendar.pickSlot(calendarDate(day), calendar.resources()[0], 600);
                const input = nodes.filter(node => node.tag === 'input').at(-1); input.value = '10:40';
                nodes.filter(node => node.tag === 'form').at(-1).handlers.submit({preventDefault(){}});
                const stored = serializeAppointmentInput(click.date);
                const event = normalizeCalendarEvent({id:'test', start:stored, extendedProps:{technician_name:'Camille'}});
                const card = calendar.card(event);
                const text = node => node.textContent + ' ' + node.children.map(text).join(' ');
                results.push({day:dayKey(click.date), input:formatAppointmentInput(click.date), stored,
                    minute:calendarMinute(event.start), card:text(card), range:dayKey(calendarRange(day).start)});
            }
            console.log(JSON.stringify(results));
        `;
        const result = JSON.parse(execFileSync(process.execPath, ['--input-type=module', '-e', script], {
            cwd: new URL('../..', import.meta.url), env: { ...process.env, TZ: timezone }, encoding: 'utf8',
        }));
        for (const row of result) {
            assert.equal(row.input, `${row.day}T10:40`);
            assert.equal(row.minute, 640);
            assert.match(row.card, /10:40/);
            assert.match(row.stored, /T10:40:00\+0[12]:00$/);
        }
        assert.equal(result[0].stored, '2026-10-29T10:40:00+01:00');
        assert.equal(result[1].stored, '2026-07-15T10:40:00+02:00');
        assert.equal(result[0].range, '2026-10-26');
    });
}
