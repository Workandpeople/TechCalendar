import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/manager/mail-templates/index.blade.php', import.meta.url), 'utf8');
const previewCode = source.slice(source.indexOf('        const updatePreview ='), source.indexOf('        function schedulePreview('));

for (const [subject, body] of [['', 'Contenu'], ['Objet', '   ']]) {
    test(`incomplete mail preview shows guidance without a failing HTTP request: ${JSON.stringify([subject, body])}`, async () => {
        let aborted = false;
        let guidance = '';
        const status = {}, title = {};
        await runInNewContext(`${previewCode}\nupdatePreview();`, {
            previewAbortController: { abort() { aborted = true; } },
            subjectInput: { value: subject }, markdownInput: { value: body },
            previewStatus: status, previewSubject: title,
            renderPreviewFallback(message) { guidance = message; },
            fetch() { assert.fail('An incomplete preview must not call the server.'); },
        });
        assert.equal(aborted, true);
        assert.equal(status.textContent, 'À compléter');
        assert.equal(title.textContent, 'Aperçu du mail');
        assert.match(guidance, /Aucun mail ne sera envoyé/);
    });
}

test('complete mail preview still renders the server response', async () => {
    let requested = false;
    const status = {}, title = {}, frame = {};
    await runInNewContext(`${previewCode}\nupdatePreview();`, {
        previewAbortController: null, AbortController,
        subjectInput: { value: 'Objet' }, markdownInput: { value: 'Contenu' },
        senderSelect: { value: '1' }, editingTemplateId: null,
        mailTemplatePreviewUrl: '/preview', mailTemplateCsrf: 'test',
        previewStatus: status, previewSubject: title, previewFrame: frame,
        renderPreviewFallback() { assert.fail('The preview should succeed.'); },
        async fetch(url, options) {
            requested = true;
            assert.equal(url, '/preview');
            assert.equal(JSON.parse(options.body).subject, 'Objet');
            return { ok: true, async json() { return { subject: 'Objet', html: '<p>Contenu</p>' }; } };
        },
    });
    assert.equal(requested, true);
    assert.equal(status.textContent, 'À jour');
    assert.equal(frame.srcdoc, '<p>Contenu</p>');
});

test('preview errors are inserted as text rather than raw HTML', () => {
    const fallbackCode = source.slice(source.indexOf('        const renderPreviewFallback ='), source.indexOf('        const updatePreview ='));
    assert.match(fallbackCode, /content\.textContent = message/);
    assert.doesNotMatch(fallbackCode, /\$\{message\}/);
});
