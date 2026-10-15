export function revealDossierSection(page, hash) {
    if (!hash?.startsWith('#')) return null;
    let id;
    try { id = decodeURIComponent(hash.slice(1)); } catch { return null; }
    const target = page.ownerDocument.getElementById(id);
    if (!target || !page.contains(target)) return null;

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

    // Open the upload form before the browser follows an anchor from the control workflow.
    page.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (link) revealDossierSection(page, link.getAttribute('href'));
    });
    const revealHash = () => revealDossierSection(page, view.location.hash)?.scrollIntoView({ block: 'start' });
    view.addEventListener('hashchange', revealHash);
    if (view.location.hash) revealHash();
}
