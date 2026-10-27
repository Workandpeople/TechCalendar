export function confirmAppointmentAction({ title, messages, label = 'Confirmer', requireCheck = false }, root = document) {
    const dialog = root.createElement('dialog');
    dialog.className = 'gc-dossier-dialog gc-placement-dialog';
    dialog.setAttribute('aria-label', title);
    dialog.innerHTML = '<form method="dialog"><header><h2></h2></header><div class="gc-placement-reasons"><ul></ul><label class="gc-placement-accept"><input type="checkbox"> Je confirme ce placement malgré les contraintes signalées.</label></div><footer class="gc-dialog-actions"><button class="gc-btn-secondary" value="cancel">Annuler</button><button class="gc-btn-warning" value="confirm"></button></footer></form>';
    dialog.querySelector('h2').textContent = title;
    const list = dialog.querySelector('ul');
    for (const message of messages) {
        const item = root.createElement('li'); item.textContent = message; list.append(item);
    }
    const accepted = dialog.querySelector('input');
    const confirm = dialog.querySelector('[value="confirm"]');
    confirm.textContent = label;
    dialog.querySelector('label').hidden = !requireCheck;
    confirm.disabled = requireCheck;
    accepted.addEventListener('change', () => { confirm.disabled = requireCheck && !accepted.checked; });
    const previous = root.activeElement;
    root.body.append(dialog);
    if (typeof dialog.showModal !== 'function') { dialog.remove(); return Promise.resolve(false); }
    return new Promise(resolve => {
        dialog.addEventListener('close', () => {
            const result = dialog.returnValue === 'confirm' && (!requireCheck || accepted.checked);
            dialog.remove(); previous?.focus?.(); resolve(result);
        }, { once: true });
        dialog.showModal(); dialog.querySelector('[value="cancel"]').focus();
    });
}

export async function requestAppointmentPlacement(url, data, { browser = window, root = document, confirm = details => confirmAppointmentAction(details, root) } = {}) {
    const initial = { ...data };
    delete initial.force_placement; delete initial.force_confirmed;
    const send = async payload => {
        const controller = new AbortController();
        const timeout = browser.setTimeout(() => controller.abort(), 30000);
        try {
            const response = await browser.fetch(url, {
                method: 'PATCH', credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': root.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify(payload),
            });
            return { response, body: await response.json().catch(() => null) };
        } finally { browser.clearTimeout(timeout); }
    };
    let result;
    try {
        result = await send(initial);
        if (result.response.status === 422 && result.body?.can_force === true && result.body.conflicts?.length) {
            const approved = await confirm({ title: 'Placement bloqué',
                messages: result.body.conflicts.map(reason => reason.message), label: 'Forcer le placement', requireCheck: true });
            if (!approved) return null;
            result = await send({ ...initial, force_placement: true, force_confirmed: true });
        }
    } catch {
        throw new Error('Enregistrement non confirmé. Actualise la page avant de réessayer.');
    }
    if (!result.response.ok) {
        throw new Error(Object.values(result.body?.errors || {}).flat()[0] || result.body?.message || 'Modification impossible. Actualise la page pour vérifier le rendez-vous.');
    }
    if (!result.body || typeof result.body !== 'object') throw new Error('Enregistrement non confirmé. Actualise la page pour vérifier le rendez-vous.');
    return result.body;
}

export function initAppointmentAssignments(root = document, browser = window) {
    const pending = new Set();
    const save = async form => {
        const id = form.dataset.appointmentEditor;
        if (pending.has(id) || !form.reportValidity()) return;
        const select = form.elements.technician_id;
        const previous = form.dataset.technicianId || select.defaultValue;
        const value = select.value;
        const feedback = form.querySelector('[data-technician-status]');
        pending.add(id);
        select.disabled = true;
        form.querySelectorAll('button').forEach(button => { button.disabled = true; });
        if (feedback) feedback.textContent = 'Vérification du placement…';
        try {
            const result = await requestAppointmentPlacement(form.action, {
                technician_id: Number(value), version: Number(form.elements.version.value),
                updated_at: form.elements.updated_at.value || null,
            }, { root, browser });
            if (!result) {
                select.value = previous;
                if (feedback) feedback.textContent = 'Changement annulé.';
                return;
            }
            root.querySelectorAll('[data-appointment-editor]').forEach(other => {
                if (other.dataset.dossierId === form.dataset.dossierId) other.elements.version.value = result.version;
                if (other.dataset.appointmentEditor === id) {
                    other.dataset.technicianId = String(result.technician_id);
                    other.elements.technician_id.value = result.technician_id;
                    other.elements.updated_at.value = result.updated_at || '';
                }
            });
            const detail = form.closest('.gc-dossier-detail');
            if (detail?.dataset.dossierId === form.dataset.dossierId) {
                detail.querySelectorAll('[name="version"]').forEach(input => { input.value = result.version; });
                detail.querySelectorAll('[data-appointment-technician]').forEach(label => {
                    if (label.dataset.appointmentTechnician === id) label.textContent = result.technician_name;
                });
                detail.querySelectorAll('[data-appointment-planning]').forEach(link => {
                    if (link.dataset.appointmentPlanning !== id) return;
                    const target = new URL(link.href);
                    target.searchParams.set('technician_id', result.technician_id);
                    link.href = target.href;
                });
            }
            if (feedback) feedback.textContent = result.message || 'Technicien modifié.';
            root.dispatchEvent(new browser.CustomEvent('techcalendar:appointment-assigned', { detail: result }));
        } catch (error) {
            select.value = previous;
            if (feedback) feedback.textContent = error.message;
        } finally {
            pending.delete(id); select.disabled = false;
            form.querySelectorAll('button').forEach(button => { button.disabled = false; });
        }
    };
    root.addEventListener('submit', event => {
        const form = event.target.closest('[data-appointment-editor]');
        if (!form) return;
        event.preventDefault(); save(form);
    });
    root.addEventListener('change', event => {
        const form = event.target.closest('[data-technician-auto]');
        if (form && event.target.name === 'technician_id' && event.target.value !== form.dataset.technicianId) save(form);
    });
}
