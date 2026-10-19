export function reportActions(state, canReview = true) {
    return ['store', ...(state === 'visited' ? ['submit_control'] : []),
        ...(canReview && state === 'rt_control' ? ['revise_control', 'validate_report'] : []),
        ...(canReview && state === 'recontrol' ? ['validate_report'] : [])];
}

export function initDossierDocuments(root = document, browser = window) {
    root.querySelectorAll('[data-contact-form]').forEach((section) => {
        section.querySelector('[data-contact-select]')?.addEventListener('change', (event) => {
            const option = event.target.selectedOptions[0];
            // Switching to manual entry keeps the entered coordinates, but removes account access.
            if (!option?.value) return;
            let contact;
            try { contact = JSON.parse(option.dataset.contact); } catch { return; }
            section.querySelectorAll('[data-contact-field]').forEach((input) => {
                input.value = typeof contact[input.dataset.contactField] === 'string' ? contact[input.dataset.contactField] : '';
            });
        });
    });

    root.querySelectorAll('[data-document-upload]').forEach((form) => {
        const fields = form.querySelector('[data-report-fields]');
        const operation = form.querySelector('[data-report-operation]');
        const action = form.querySelector('[data-report-action]');
        const update = () => {
            const report = form.querySelector('[name="document_type"]:checked')?.value === 'report';
            fields.hidden = !report;
            fields.disabled = !report;
            const state = operation.selectedOptions[0]?.dataset.state;
            const allowed = reportActions(state);
            Array.from(action.options).forEach((option) => { option.disabled = !allowed.includes(option.value); });
            if (action.selectedOptions[0]?.disabled) action.value = 'store';
            // Custom select widgets must also reflect a disabled parent fieldset.
            fields.querySelectorAll('select, input').forEach((input) => { input.disabled = !report; });
            form.querySelector('[type="file"]').accept = report ? '.pdf,application/pdf' : '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx';
        };
        form.addEventListener('change', (event) => {
            if (event.target.name === 'document_type' || event.target === operation) update();
        });
        update();
    });

    initDocumentPreview(root, browser);
}

export function documentPreviewKind(response) {
    if (!response.ok || response.redirected || !/^inline(?:;|$)/i.test(response.headers.get('Content-Disposition')?.trim() || '')) return null;
    const mime = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
    if (mime === 'application/pdf') return 'iframe';
    return ['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(mime) ? 'img' : null;
}

export function initDocumentPreview(root = document, browser = window) {
    const dialog = root.querySelector('[data-document-preview]');
    if (!dialog || typeof dialog.showModal !== 'function' || typeof browser.fetch !== 'function') return;
    const owner = dialog.ownerDocument;
    const content = dialog.querySelector('[data-preview-content]');
    const status = dialog.querySelector('[data-preview-status]');
    const fallback = 'Aperçu indisponible. Ouvrez le document dans un nouvel onglet ou téléchargez-le.';
    let current;
    let opener;
    const reset = () => {
        const previous = current;
        current = null;
        previous?.controller.abort();
        browser.clearTimeout(previous?.timer);
        previous?.media?.removeAttribute('src');
        content.replaceChildren();
        dialog.setAttribute('aria-busy', 'false');
    };
    const fail = (request, message = fallback) => {
        if (current !== request) return;
        reset();
        status.textContent = message;
        status.hidden = false;
    };
    const armTimeout = (request) => {
        browser.clearTimeout(request.timer);
        request.timer = browser.setTimeout(() => fail(request, `Le chargement prend trop de temps. ${fallback}`), 20000);
    };
    const localUrl = (value) => {
        try {
            const url = new URL(value, browser.location.href);
            return ['https:', 'http:'].includes(url.protocol) && url.origin === browser.location.origin && !url.username && !url.password ? url : null;
        } catch { return null; }
    };
    root.querySelectorAll('[data-document-open]').forEach((link) => link.addEventListener('click', async (event) => {
        if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = localUrl(link.href);
        const download = link.dataset.documentDownload && localUrl(link.dataset.documentDownload);
        if (!url || !download) return;
        event.preventDefault();
        reset();
        opener = link;
        const title = link.dataset.documentTitle || 'Aperçu du document';
        dialog.querySelector('[data-preview-title]').textContent = title;
        dialog.querySelector('[data-preview-download]').href = download.href;
        dialog.querySelector('[data-preview-external]').href = url.href;
        status.textContent = 'Chargement de l’aperçu…';
        status.hidden = false;
        dialog.setAttribute('aria-busy', 'true');
        if (!dialog.open) dialog.showModal();
        const request = { controller: new AbortController(), url: url.href };
        current = request;
        armTimeout(request);
        try {
            // Validate access and sniffed MIME without buffering the file or automatically downloading attachments.
            const response = await browser.fetch(url.href, {
                method: 'HEAD', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: request.controller.signal,
            });
            if (current !== request) return;
            const kind = documentPreviewKind(response);
            if (!kind || (kind === 'iframe' && browser.navigator?.pdfViewerEnabled === false)) {
                fail(request);
                return;
            }
            const media = owner.createElement(kind);
            request.media = media;
            media.referrerPolicy = 'no-referrer';
            if (kind === 'img') media.alt = title;
            else media.title = title;
            media.addEventListener('load', () => {
                if (current !== request) return;
                browser.clearTimeout(request.timer);
                status.hidden = true;
                dialog.setAttribute('aria-busy', 'false');
            });
            media.addEventListener('error', () => fail(request));
            armTimeout(request);
            media.src = url.href;
            content.replaceChildren(media);
        } catch {
            fail(request);
        }
    }));
    dialog.querySelector('[data-preview-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        reset();
        opener?.focus();
    });
    owner.addEventListener('securitypolicyviolation', (event) => {
        if (current && event.disposition === 'enforce' && ['frame-src', 'img-src', 'object-src'].includes(event.effectiveDirective)
            && [current.url, browser.location.origin].includes(event.blockedURI)) fail(current);
    });
    browser.addEventListener('pagehide', reset);
}
