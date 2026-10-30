import assert from 'node:assert/strict';
import { test } from 'node:test';
import { commentEditorError, commentEditorValue, initCommentEditors } from '../../resources/js/comment-editor.js';

test('default initialization uses the actual document without shadowing it', () => {
    const previous = globalThis.document;
    try {
        let queried = false;
        globalThis.document = { querySelectorAll() { queried = true; return []; } };
        initCommentEditors(undefined, {});
        assert.equal(queried, true);
    } finally { globalThis.document = previous; }
});

test('does not turn an empty editor or a whitespace-only paste into a nonempty comment', () => {
    const root = { createElement() { throw new Error('Empty input should not be parsed'); } };
    for (const value of ['', '\n', '\u00a0\n', '\u200b']) {
        assert.equal(commentEditorValue({ getText: () => value }, root), '');
    }
});

test('validates required text, minimum size and the actual serialized payload', () => {
    assert.equal(commentEditorError('\n', '', { required: true }), 'Écris un commentaire avant de l’enregistrer.');
    assert.equal(commentEditorError('\n', '', {}), '');
    assert.equal(commentEditorError('AB', '<p>AB</p>', { min: 3 }), 'Écris au moins 3 caractères.');
    assert.equal(commentEditorError('AB', '<p>AB</p>', { max: 5 }), 'Le commentaire est trop long. Réduis le texte ou sa mise en forme.');
    assert.equal(commentEditorError('Bonjour\n', '<p>Bonjour</p>', { required: true }), '');
});
