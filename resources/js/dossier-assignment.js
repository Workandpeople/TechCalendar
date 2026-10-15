export async function saveDossierAssignment(browser, url, csrfToken, userId, version) {
    const controller = new AbortController();
    const timeout = browser.setTimeout(() => controller.abort(), 20000);
    try {
        const response = await browser.fetch(url, {
            method: 'PATCH', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ planning_user_id: userId === '' ? null : Number(userId), version: Number(version) }),
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            const message = response.status === 409
                ? 'Le dossier a changé. Actualise avant de réessayer.'
                : [401, 419].includes(response.status)
                    ? 'Ta session a expiré. Recharge la page pour te reconnecter.'
                    : response.status === 403
                        ? 'Tu ne disposes plus des droits nécessaires.'
                        : response.status === 422
                            ? Object.values(payload?.errors || {}).flat()[0] || 'Vérifie l’utilisateur choisi et actualise le tableau.'
                            : response.status === 429
                                ? 'Trop de modifications rapprochées. Patiente avant de réessayer.'
                                : 'Enregistrement non confirmé. Actualise pour vérifier l’affectation avant de réessayer.';
            throw Object.assign(new Error(message), { status: response.status });
        }
        if (!Number.isInteger(payload?.version) || payload.version < 1
            || (payload.planning_user_id !== null && !Number.isInteger(payload.planning_user_id))) {
            throw new Error('Invalid assignment response');
        }
        return payload;
    } catch (error) {
        if (!error.status) {
            throw new Error('Enregistrement non confirmé. Actualise pour vérifier l’affectation avant de réessayer.');
        }
        throw error;
    } finally {
        browser.clearTimeout(timeout);
    }
}

export function initDossierAssignment(listing, root, browser, refresh) {
    // Keep in-flight writes independent of search/pagination replacing the table.
    const states = new Map();
    const paint = (cell) => {
        const state = states.get(cell.dataset.assignmentId);
        if (!state) return;
        const select = cell.querySelector('[data-planning-assignee]');
        const feedback = cell.querySelector('[data-assignment-feedback]');
        cell.setAttribute('aria-busy', String(state.pending));
        if (state.pending) {
            select.value = state.value;
            select.disabled = true;
        } else if (state.error) {
            select.value = cell.dataset.assignedId;
            select.disabled = true;
        } else {
            // The server-rendered select remains authoritative after a listing refresh.
            select.disabled = ![...select.options].some(option => !option.disabled && (option.value || cell.dataset.assignedId));
        }
        feedback.textContent = state.message;
        feedback.dataset.error = String(Boolean(state.error));
        cell.querySelector('[data-assignment-refresh]').hidden = !state.error;
    };
    const paintAll = () => listing.querySelectorAll('[data-planning-assignment]').forEach(paint);
    root.addEventListener('techcalendar:dossiers-updated', paintAll);
    listing.addEventListener('change', async event => {
        const select = event.target.closest('[data-planning-assignee]');
        if (!select) return;
        const cell = select.closest('[data-planning-assignment]');
        const id = cell.dataset.assignmentId;
        if (states.get(id)?.pending || select.value === cell.dataset.assignedId) return;
        states.set(id, { pending: true, value: select.value, message: 'Enregistrement…' });
        paintAll();
        try {
            const payload = await saveDossierAssignment(browser, cell.dataset.assignmentUrl,
                root.querySelector('meta[name="csrf-token"]')?.content || '', select.value, cell.dataset.version);
            states.set(id, { pending: false, message: payload.planning_user_id === null ? 'Affectation retirée.' : 'Affectation enregistrée.' });
            // Update any visible copy immediately, including one rendered during the write.
            listing.querySelectorAll('[data-planning-assignment]').forEach(current => {
                if (current.dataset.assignmentId !== id) return;
                current.dataset.assignedId = String(payload.planning_user_id ?? '');
                current.dataset.version = String(payload.version);
                current.querySelector('[data-planning-assignee]').value = current.dataset.assignedId;
            });
            refresh();
        } catch (error) {
            states.set(id, { pending: false, error: true, message: error.message });
        } finally {
            paintAll();
        }
    });
    listing.addEventListener('click', event => {
        const button = event.target.closest('[data-assignment-refresh]');
        if (!button) return;
        const cell = button.closest('[data-planning-assignment]');
        states.delete(cell.dataset.assignmentId);
        // Do not replay an uncertain write: first read the current assignment/version.
        button.hidden = true;
        cell.querySelector('[data-assignment-feedback]').textContent = 'Actualisation…';
        refresh();
    });
}
