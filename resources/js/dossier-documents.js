export function reportActions(state, canReview = true) {
    if (state === 'visited') return ['submit_control'];
    return canReview && ['rt_control', 'recontrol'].includes(state) ? ['validate_report'] : [];
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
        const operation = form.querySelector('[data-report-operation]');
        const update = () => {
            const type = form.querySelector('[name="document_type"]:checked')?.value || 'free';
            const report = type === 'report';
            const canReview = form.dataset.canReview === '1';
            const nameField = form.querySelector('[data-document-name]');
            nameField.hidden = type !== 'free';
            nameField.querySelector('input').disabled = type !== 'free';
            for (const [selector, enabled] of [['[data-operation-fields]', type !== 'free'], ['[data-report-fields]', report], ['[data-visit-fields]', type === 'visit']]) {
                const fields = form.querySelector(selector);
                fields.hidden = !enabled;
                fields.disabled = !enabled;
                fields.querySelectorAll('select, input').forEach((input) => { input.disabled = !enabled; });
            }
            Array.from(operation.options).forEach((option) => {
                option.disabled = !!option.value && (report ? reportActions(option.dataset.state, canReview).length === 0 : type === 'visit' && option.dataset.state !== 'awaiting_visit');
            });
            if (operation.selectedOptions[0]?.disabled) operation.value = '';
            const state = operation.selectedOptions[0]?.dataset.state;
            const appointment = form.querySelector('[data-visit-appointment]');
            Array.from(appointment.options).forEach((option) => { option.disabled = !!option.value && option.dataset.operation !== operation.value; });
            if (appointment.selectedOptions[0]?.disabled) appointment.value = '';
            const candidates = Array.from(appointment.options).filter(option => option.value && !option.disabled);
            if (!appointment.value && candidates.length === 1) appointment.value = candidates[0].value;
            form.querySelector('[data-document-effect]').textContent = type === 'free' ? 'Pièce libre : aucun changement d’état.'
                : type === 'visit' ? 'Ce document confirmera la visite et passera la fiche en RDV visité.'
                : ['visited', 'rt_control', 'recontrol'].includes(state) ? (form.querySelector('[name="audience"]:checked')?.value === 'internal'
                    ? 'Rapport privé : la fiche passera en Contrôle RT, quel que soit l’avis.'
                    : 'Rapport partagé : la fiche passera en Rapport Genius, quel que soit l’avis.')
                : 'Choisissez une fiche en RDV visité, contrôle RT ou recontrôle.';
            form.querySelector('[type="file"]').accept = report ? '.pdf,application/pdf' : '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx';
        };
        form.addEventListener('change', (event) => {
            if (['document_type', 'audience'].includes(event.target.name) || event.target === operation) update();
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
