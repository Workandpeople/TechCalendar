import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const code = source.slice(source.indexOf('const safeJsonParse ='), source.indexOf('const fieldLabel ='));
const settle = () => new Promise(resolve => setImmediate(resolve));

function fixture({ fetch = async () => ({ ok: true, json: async () => ({ auth: 'test-auth' }) }) } = {}) {
    let socket;
    const sent = [], states = [], errors = [], payloads = [];
    class Socket {
        static OPEN = 1;
        readyState = 1;
        handlers = new Map();
        constructor() { socket = this; }
        addEventListener(name, callback) { this.handlers.set(name, callback); }
        send(message) { sent.push(JSON.parse(message)); }
        close() { this.readyState = 3; this.handlers.get('close')?.(); }
        message(event, data = {}, channel) { return this.handlers.get('message')({ data: JSON.stringify({ event, data: JSON.stringify(data), channel }) }); }
    }
    const window = {};
    runInNewContext(code, {
        window, WebSocket: Socket, fetch,
        document: { querySelector: () => ({ content: 'csrf-test' }) },
        reverbConfig: { host: 'localhost', port: 8080, scheme: 'http', key: 'public-test-key' },
    });
    const subscription = window.TechCalendarReverb.subscribePrivate('lot-import-preview.test', 'lot-import-preview.progressed',
        payload => payloads.push(payload), { onState: state => states.push(state), onError: error => errors.push(error) });
    return { socket, subscription, sent, states, errors, payloads };
}

test('only marks the private channel subscribed after the server acknowledgement', async () => {
    const ui = fixture();
    ui.socket.handlers.get('open')();
    await ui.socket.message('pusher:connection_established', { socket_id: '123.456' });
    assert.deepEqual(ui.states, ['connecting', 'connected']);
    assert.equal(ui.sent[0].event, 'pusher:subscribe');
    await ui.socket.message('pusher_internal:subscription_succeeded', {}, 'private-lot-import-preview.test');
    assert.equal(ui.states.at(-1), 'subscribed');
    await ui.socket.message('lot-import-preview.progressed', { progress: 50 }, 'private-lot-import-preview.test');
    assert.equal(ui.payloads[0].progress, 50);
    await ui.socket.message('lot-import-preview.progressed', { progress: 80 }, 'private-unrelated');
    assert.equal(ui.payloads.length, 1);
});

test('answers Reverb heartbeat so long imports stay connected', async () => {
    const ui = fixture();
    await ui.socket.message('pusher:ping');
    assert.deepEqual(ui.sent, [{ event: 'pusher:pong', data: {} }]);
});

test('reports authentication failure and does not claim realtime is active', async () => {
    const ui = fixture({ fetch: async () => ({ ok: false }) });
    await ui.socket.message('pusher:connection_established', { socket_id: '123.456' });
    assert.equal(ui.errors.length, 1);
    assert.equal(ui.states.includes('subscribed'), false);
});

test('ignores late authentication and socket errors after closing the completed import', async () => {
    let resolve;
    const ui = fixture({ fetch: () => new Promise(done => { resolve = done; }) });
    void ui.socket.message('pusher:connection_established', { socket_id: '123.456' });
    ui.subscription.unsubscribe();
    resolve({ ok: true, json: async () => ({ auth: 'test' }) });
    await settle();
    ui.socket.handlers.get('error')();
    await ui.socket.message('pusher:error', { message: 'Late error' });
    assert.equal(ui.errors.length, 0);
    assert.equal(ui.sent.length, 0);
    assert.equal(ui.states.at(-1), 'closed');
});
