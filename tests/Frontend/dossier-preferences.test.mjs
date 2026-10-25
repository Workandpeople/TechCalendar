import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readDossierPreferences, saveDossierPreferences, preferredDossierUrl } from '../../resources/js/dossier-preferences.js';

test('persists the category and page size only, isolated by account', () => {
    const values = new Map();
    const storage = { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) };
    saveDossierPreferences(storage, 'user:1', new URL('https://example.test/v2/dossiers?status=visited&per_page=100&q=PRIVATE&delegataire_id=187'));
    assert.deepEqual(readDossierPreferences(storage, 'user:1'), { status: 'visited', per_page: '100' });
    assert.deepEqual(readDossierPreferences(storage, 'user:2'), { status: '', per_page: '25' });
    assert.equal(values.get('user:1').includes('PRIVATE'), false);
    assert.equal(values.get('user:1').includes('187'), false);
});

test('ignores corrupt unavailable or invalid storage', () => {
    for (const value of ['{broken', 'null', '{"status":"arbitrary","per_page":"999999"}']) {
        assert.deepEqual(readDossierPreferences({ getItem: () => value }, 'key'), { status: '', per_page: '25' });
    }
    const unavailable = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } };
    assert.doesNotThrow(() => saveDossierPreferences(unavailable, 'key', new URL('https://example.test')));
    assert.deepEqual(readDossierPreferences(unavailable, 'key'), { status: '', per_page: '25' });
});

test('restores only a default navigation, never overriding a deep link', () => {
    const preferences = { status: 'visited', per_page: '50' };
    assert.equal(preferredDossierUrl(new URL('https://example.test/v2/dossiers'), preferences).search, '?status=visited&per_page=50');
    for (const search of ['?status=pending', '?q=Paris', '?per_page=25', '?page=2']) {
        assert.equal(preferredDossierUrl(new URL(`https://example.test/v2/dossiers${search}`), preferences).search, search);
    }
});
