import { commentHtml } from './comment-markup.js';

const bound = new WeakSet();
let library;
const loadEditor = () => library ??= import('./comment-editor-quill.js').catch(error => { library = null; throw error; });

export function commentEditorValue(editor, root = document) {
    return editor.getText().replace(/[\s\u200b-\u200d\ufeff]/g, '') === '' ? '' : commentHtml(editor.getSemanticHTML(), root);
}

export function commentEditorError(text, html, { required = false, min = 0, max = 10000 } = {}) {
    const length = text.trim().length;
    if (required && length === 0) return 'Écris un commentaire avant de l’enregistrer.';
    if (length > 0 && length < min) return `Écris au moins ${min} caractères.`;
    if (html.length > max) return 'Le commentaire est trop long. Réduis le texte ou sa mise en forme.';
    return '';
}

export function initCommentEditors(root = document, browser = window, load = loadEditor) {
    const owner = root.ownerDocument || root;
    root.querySelectorAll('[data-comment-editor]').forEach(wrapper => {
        if (bound.has(wrapper)) return;
        bound.add(wrapper);
        const input = wrapper.querySelector('[data-comment-input]');
        const toolbar = wrapper.querySelector('[data-comment-toolbar]');
        const surface = wrapper.querySelector('[data-comment-surface]');
        const error = wrapper.querySelector('[data-comment-error]');
        const constraints = { required: input.required, min: Math.max(0, input.minLength), max: input.maxLength > 0 ? input.maxLength : 10000 };
        let editor, loading = false, syncing = false, lastValue = input.value;
        let observer;
        const showError = message => {
            error.textContent = message;
            error.hidden = !message;
            editor?.root.setAttribute('aria-invalid', String(Boolean(message)));
        };
        const hydrate = value => {
            syncing = true;
            if (/<\/?[a-z][^>]*>/i.test(value)) editor.setContents(editor.clipboard.convert({ html: commentHtml(value, owner) }), 'silent');
            else editor.setText(value, 'silent');
            editor.history.clear();
            syncing = false;
        };
        const activate = async focus => {
            if (editor || loading || input.disabled || input.readOnly) return;
            loading = true;
            try {
                const { createCommentEditor } = await load();
                const hadFocus = focus || owner.activeElement === input;
                surface.hidden = false;
                editor = createCommentEditor(surface, toolbar, input.placeholder);
                lastValue = input.value;
                hydrate(input.value);
                editor.root.setAttribute('role', 'textbox');
                editor.root.setAttribute('aria-multiline', 'true');
                editor.root.setAttribute('aria-required', String(constraints.required));
                editor.root.id = `${input.id}-editor`;
                error.id = `${input.id}-error`;
                editor.root.setAttribute('aria-describedby', error.id);
                const label = input.labels?.[0];
                if (label) {
                    label.id ||= `${input.id}-label`;
                    editor.root.setAttribute('aria-labelledby', label.id);
                    label.addEventListener('click', event => { event.preventDefault(); editor.focus({ preventScroll: true }); });
                } else editor.root.setAttribute('aria-label', 'Commentaire');

                // Existing booking dialogs assign .value directly; mirror those changes without polling.
                const valueProperty = Object.getOwnPropertyDescriptor(browser.HTMLTextAreaElement.prototype, 'value');
                Object.defineProperty(input, 'value', {
                    configurable: true,
                    get() { return valueProperty.get.call(input); },
                    set(value) {
                        valueProperty.set.call(input, value);
                        lastValue = valueProperty.get.call(input);
                        if (!syncing) { hydrate(lastValue); showError(''); }
                    },
                });
                editor.on('text-change', () => {
                    if (syncing) return;
                    syncing = true;
                    lastValue = commentEditorValue(editor, owner);
                    valueProperty.set.call(input, lastValue);
                    syncing = false;
                    showError(commentEditorError(editor.getText(), lastValue, { ...constraints, required: false }));
                    input.dispatchEvent(new browser.Event('input', { bubbles: true }));
                });
                toolbar.querySelector('[data-comment-undo]').addEventListener('click', () => editor.history.undo());
                toolbar.querySelector('[data-comment-redo]').addEventListener('click', () => editor.history.redo());
                input.required = false;
                input.hidden = true;
                toolbar.hidden = false;
                wrapper.dataset.editorReady = 'true';
                input.form?.addEventListener('submit', event => {
                    const message = commentEditorError(editor.getText(), lastValue, constraints);
                    showError(message);
                    if (message) { event.preventDefault(); event.stopImmediatePropagation(); editor.focus({ preventScroll: true }); }
                }, true);
                input.form?.addEventListener('reset', () => browser.setTimeout(() => { input.value = input.defaultValue; }, 0));
                observer?.disconnect();
                if (hadFocus) editor.focus({ preventScroll: true });
            } catch {
                editor = null;
                surface.hidden = true;
                toolbar.hidden = true;
                input.hidden = false;
                input.required = constraints.required;
                showError('Mise en forme indisponible. Tu peux continuer en texte simple.');
            } finally { loading = false; }
        };
        input.addEventListener('focus', () => activate(true));
        if (browser.IntersectionObserver) {
            observer = new browser.IntersectionObserver(entries => {
                if (entries.some(entry => entry.isIntersecting)) activate(false);
            });
            observer.observe(wrapper);
        } else if (wrapper.getClientRects().length) activate(false);
    });
}
