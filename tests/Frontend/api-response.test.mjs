import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readApiJson } from '../../resources/js/api-response.js';

const response = (status, body, type = 'application/json') => ({
    status, ok: status < 400, headers: new Headers({ 'Content-Type': type }), json: async () => body,
});

test('preserves successful results and field validation payloads', async () => {
    for (const status of [200, 422]) {
        const data = { errors: { address: ['Adresse à confirmer.'] } };
        assert.equal(await readApiJson(response(status, data)), data);
    }
    assert.deepEqual(await readApiJson(response(200, { ok: true }, 'application/problem+json; charset=utf-8')), { ok: true });
});

test('explains session expiry and redirects without trying to parse the login HTML', async () => {
    for (const status of [401, 419]) {
        await assert.rejects(readApiJson(response(status, null, 'text/html')), /session a expiré/);
    }
    await assert.rejects(readApiJson({ ...response(200, null, 'text/html'), redirected: true, url: 'https://app.test/login' }), /session a expiré/);
    await assert.rejects(readApiJson({ ...response(200, null), redirected: true, url: 'https://app.test/profile' }), /redirigée/);
});

test('never exposes HTML or parser errors and distinguishes server failures by HTTP code', async () => {
    for (const status of [200, 500, 502, 503, 504]) {
        await assert.rejects(readApiJson(response(status, '<!DOCTYPE html><script>private</script>', 'text/html')), error => {
            assert.equal(error.status, status);
            assert.match(error.message, new RegExp(`HTTP ${status}`));
            assert.doesNotMatch(error.message, /DOCTYPE|token|private|SyntaxError/);
            return true;
        });
    }
    await assert.rejects(readApiJson({ ...response(200, null), json: async () => { throw new SyntaxError('PRIVATE'); } }), /HTTP 200/);
    for (const data of [null, [], 'private']) await assert.rejects(readApiJson(response(200, data)), /HTTP 200/);
});

test('only displays a validated error reference and preserves request cancellation', async () => {
    const reference = '5ba7863b-a643-41d2-b376-24941ffefd62';
    const error = response(500, { message: 'PRIVATE SQL' });
    error.headers.set('X-Error-Reference', reference);
    await assert.rejects(readApiJson(error), new RegExp(reference));
    error.headers.set('X-Error-Reference', '<script>private</script>');
    await assert.rejects(readApiJson(error), failure => !/private|script|SQL/.test(failure.message));
    const abort = Object.assign(new Error('Aborted'), { name: 'AbortError' });
    await assert.rejects(readApiJson({ ...response(200, null), json: async () => { throw abort; } }), failure => failure === abort);
});
