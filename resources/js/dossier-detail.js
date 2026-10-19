export function revealDossierSection(page, hash) {
    if (!hash?.startsWith('#')) return null;
    let id;
    try { id = decodeURIComponent(hash.slice(1)); } catch { return null; }
    const target = page.ownerDocument.getElementById(id);
    if (!target || !page.contains(target)) return null;

    const dialog = target.closest('dialog[data-dossier-dialog]');
    if (dialog && typeof dialog.showModal === 'function') {
        if (!dialog.open) dialog.showModal();
        return dialog;
    }
    let details = target.closest('details');
    while (details && page.contains(details)) {
        details.open = true;
        details = details.parentElement?.closest('details');
    }
    return target;
}

export function initDossierDetail(root = document, view = window) {
    const page = root.querySelector('.gc-dossier-detail');
    if (!page) return;

    page.querySelectorAll('[data-dossier-dialog]').forEach(dialog => {
        dialog.addEventListener('click', event => {
            if (event.target.closest('[data-dialog-close]')) dialog.close();
        });
        dialog.addEventListener('close', () => {
            dialog.opener?.focus({ preventScroll: true });
            dialog.opener = null;
        });
    });
    page.addEventListener('click', event => {
        if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return;
        const link = event.target.closest('a[href^="#"], [data-dialog-target]');
        if (!link) return;
        const hash = link.dataset.dialogTarget ? `#${link.dataset.dialogTarget}` : link.getAttribute('href');
        const target = revealDossierSection(page, hash);
        if (target?.matches('dialog[data-dossier-dialog]')) {
            event.preventDefault();
            target.opener = link;
        }
    });
    const revealHash = () => {
        const target = revealDossierSection(page, view.location.hash);
        if (target && !target.matches('dialog')) target.scrollIntoView({ block: 'start' });
    };
    view.addEventListener('hashchange', revealHash);
    if (view.location.hash) revealHash();
    const invalid = page.querySelector('[data-dialog-reopen]');
    if (invalid) revealDossierSection(page, `#${invalid.id}`);
}
