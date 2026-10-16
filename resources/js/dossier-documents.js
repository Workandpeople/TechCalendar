export function reportActions(state, canReview = true) {
    return ['store', ...(state === 'visited' ? ['submit_control'] : []),
        ...(canReview && state === 'rt_control' ? ['revise_control', 'validate_report'] : []),
        ...(canReview && state === 'recontrol' ? ['validate_report'] : [])];
}

export function initDossierDocuments(root = document) {
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

    const dialog = root.querySelector('[data-document-preview]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    let opener;
    root.querySelectorAll('[data-document-open]').forEach((link) => link.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href, root.location?.href || window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        opener = link;
        dialog.querySelector('[data-preview-title]').textContent = link.dataset.documentTitle;
        dialog.querySelector('[data-preview-frame]').src = url.href;
        dialog.querySelector('[data-preview-download]').href = link.dataset.documentDownload;
        dialog.showModal();
    }));
    dialog.querySelector('[data-preview-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        dialog.querySelector('[data-preview-frame]').removeAttribute('src');
        opener?.focus();
    });
}
