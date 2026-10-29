export function dossierArchivingConfirmation(action, count, reference = '') {
    if (!Number.isInteger(count) || count < 1) throw new Error('Invalid selection count');
    const target = reference ? `le dossier ${reference} (1 dossier)` : `${count} dossier(s) sélectionné(s) sur cette page`;
    return action === 'delete'
        ? `Supprimer ${target} ?\nLes dossiers seront retirés des listes actives et des accès externes. Documents, rendez-vous, historique et facturation conservés. Suppression réversible par un gérant/admin.${reference ? '' : '\nTout dossier contenant un état autre que problème RDV ou annulé sera refusé : utiliser la suppression individuelle après vérification.'}`
        : `Annuler les prestations en problème RDV de ${target} ?\nSeules ces prestations passeront à « Dossier annulé ». Les autres états, les rendez-vous, les documents, l’historique et la facturation seront conservés.`;
}

export function selectedDossiers(listing) {
    return [...listing.querySelectorAll('[data-dossier-select]:checked')]
        .map(input => ({ id: Number(input.dataset.id), version: Number(input.dataset.version) }));
}

export async function submitDossierArchiving(browser, url, csrfToken, method, data) {
    const controller = new AbortController();
    const timeout = browser.setTimeout(() => controller.abort(), 20000);
    try {
        const response = await browser.fetch(url, {
            method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ ...data, confirmed: true }),
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            const message = [401, 419].includes(response.status) ? 'Ta session a expiré. Recharge la page.'
                : response.status === 403 ? 'Action réservée aux gérants et administrateurs.'
                    : [404, 409].includes(response.status) ? 'Un dossier a changé ou a déjà été supprimé. Actualise et sélectionne à nouveau les dossiers.'
                        : response.status === 422 ? Object.values(payload?.errors || {}).flat()[0] || 'Sélection invalide. Actualise le tableau.'
                            : response.status === 429 ? 'Trop de demandes rapprochées. Patiente puis actualise le tableau.'
                                : 'Résultat non confirmé. Actualise le tableau pour vérifier avant toute nouvelle action.';
            throw Object.assign(new Error(message), { status: response.status });
        }
        const expected = method === 'DELETE' ? 1 : data.dossiers.length;
        if (payload?.count !== expected || typeof payload.message !== 'string') throw new Error('Invalid archiving response');
        return payload;
    } catch (error) {
        if (!error.status) throw new Error('Résultat non confirmé. Actualise le tableau pour vérifier avant toute nouvelle action.');
        throw error;
    } finally {
        browser.clearTimeout(timeout);
    }
}

export function initDossierArchiving(listing, root, browser, refresh) {
    const feedback = listing.querySelector('[data-dossier-archiving-feedback]');
    if (!feedback) return;
    const status = feedback.querySelector('[data-dossier-archiving-status]');
    const errorBox = feedback.querySelector('[data-dossier-archiving-error]');
    const message = feedback.querySelector('[data-dossier-archiving-message]');
    let pending = false;
    let needsRefresh = false;
    const paint = () => {
        const inputs = [...listing.querySelectorAll('[data-dossier-select]')];
        const count = inputs.filter(input => input.checked).length;
        const counter = listing.querySelector('[data-dossier-selection-count]');
        if (counter) counter.textContent = String(count);
        const selectPage = listing.querySelector('[data-dossier-select-page]');
        if (selectPage) {
            selectPage.checked = inputs.length > 0 && count === inputs.length;
            selectPage.indeterminate = count > 0 && count < inputs.length;
            selectPage.disabled = pending || needsRefresh || inputs.length === 0;
        }
        inputs.forEach(input => { input.disabled = pending || needsRefresh; });
        listing.querySelectorAll('[data-dossier-batch-action]').forEach(button => { button.disabled = pending || needsRefresh || count === 0; });
        listing.querySelectorAll('[data-dossier-delete-url]').forEach(button => { button.disabled = pending || needsRefresh; });
        const reason = listing.querySelector('[data-dossier-batch-reason]');
        if (reason) reason.disabled = pending || needsRefresh;
        feedback.setAttribute('aria-busy', String(pending));
    };
    root.addEventListener('techcalendar:dossiers-updated', () => {
        // Any replaced page requires a new explicit selection, even after a quiet refresh.
        listing.querySelectorAll('[data-dossier-select]').forEach(input => { input.checked = false; });
        needsRefresh = pending;
        if (!pending) errorBox.hidden = true;
        paint();
    });
    listing.addEventListener('change', event => {
        if (event.target.matches('[data-dossier-select-page]')) {
            listing.querySelectorAll('[data-dossier-select]').forEach(input => { if (!input.disabled) input.checked = event.target.checked; });
        } else if (!event.target.matches('[data-dossier-select]')) return;
        paint();
    });
    listing.addEventListener('click', async event => {
        const button = event.target.closest('[data-dossier-delete-url], [data-dossier-batch-action]');
        if (!button || pending || needsRefresh || button.disabled) return;
        const single = Boolean(button.dataset.dossierDeleteUrl);
        const action = single ? 'delete' : button.dataset.dossierBatchAction;
        const selection = single ? null : selectedDossiers(listing);
        const count = single ? 1 : selection.length;
        const batch = listing.querySelector('[data-dossier-batch-url]');
        const reason = listing.querySelector('[data-dossier-batch-reason]')?.value.trim();
        if (!count || (!single && count > Number(batch.dataset.batchLimit))) return;
        if (!browser.confirm(dossierArchivingConfirmation(action, count, single ? button.dataset.reference : ''))) return;
        pending = true;
        errorBox.hidden = true;
        status.textContent = `Traitement de ${count} dossier(s) en cours…`;
        paint();
        try {
            const payload = await submitDossierArchiving(browser, single ? button.dataset.dossierDeleteUrl : batch.dataset.dossierBatchUrl,
                root.querySelector('meta[name="csrf-token"]')?.content || '', single ? 'DELETE' : 'POST',
                single ? { version: Number(button.dataset.version) } : { action, dossiers: selection, ...(reason ? { reason } : {}) });
            status.textContent = payload.message;
            // Block stale buttons until the post-write read succeeds; never automatically replay a write.
            needsRefresh = true;
            pending = false;
            refresh();
        } catch (error) {
            needsRefresh = true;
            status.textContent = '';
            message.textContent = error.message;
            errorBox.hidden = false;
        } finally {
            pending = false;
            paint();
        }
    });
    feedback.querySelector('[data-dossier-archiving-refresh]').addEventListener('click', () => {
        if (pending) return;
        errorBox.hidden = true;
        refresh();
    });
    paint();
}
