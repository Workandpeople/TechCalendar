import { requestAppointmentPlacement } from './appointment-placement.js';
import { formatAppointmentInput, formatAppointmentDateTime, serializeAppointmentInput } from './appointment-date-time.js';

export function reschedulePayload(context, fields) {
    return {
        version: context.version, appointment_id: context.appointment_id, updated_at: context.updated_at,
        technician_id: Number(fields.technician_id.value), duration_minutes: Number(fields.duration_minutes.value),
        starts_at: serializeAppointmentInput(`${fields.date.value}T${fields.time.value}`),
    };
}

export function applyRescheduledAppointment(result, root = document) {
    const page = root.querySelector(`.gc-dossier-detail[data-dossier-id="${result.dossier_id}"]`);
    if (!page) return;
    const id = String(result.appointment_id);
    const text = `${formatAppointmentDateTime(result.starts_at, { dateStyle: 'short', timeStyle: 'short' })} à ${formatAppointmentDateTime(result.ends_at, { timeStyle: 'short' })}`;
    page.querySelectorAll('[name="version"]').forEach(input => { input.value = result.version; });
    page.querySelectorAll('[data-appointment-time]').forEach(label => {
        if (label.dataset.appointmentTime === id) label.textContent = text;
    });
    page.querySelectorAll('[data-appointment-technician]').forEach(label => {
        if (label.dataset.appointmentTechnician === id) label.textContent = result.technician_name;
    });
    page.querySelectorAll('[data-appointment-planning]').forEach(link => {
        if (link.dataset.appointmentPlanning === id) link.href = result.planning_url;
    });
    page.querySelectorAll('[data-appointment-state]').forEach(label => {
        if (label.dataset.appointmentState === id) { label.textContent = 'Planifié'; label.className = 'mt-2 inline-block rounded-full bg-sky-50 px-3 py-1 text-xs text-sky-800'; }
    });
    page.querySelectorAll('[data-appointment-editor]').forEach(form => {
        if (form.dataset.appointmentEditor !== id) return;
        form.dataset.technicianId = String(result.technician_id);
        form.elements.technician_id.value = result.technician_id;
        form.elements.updated_at.value = result.updated_at || '';
    });
    const operation = root.getElementById(`fiche-${result.operation_id}`);
    if (!operation || !page.contains(operation)) return;
    const status = operation.querySelector('[data-operation-state]');
    if (status) { status.textContent = 'RDV en attente de visite'; status.className = 'rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-800'; }
    const stateSelect = operation.querySelector('select[name="status"]');
    if (stateSelect) stateSelect.value = 'awaiting_visit';
    const count = operation.querySelector('[data-operation-appointments-count]');
    if (count) count.textContent = `${result.appointments_count} RDV lié(s)`;
    const summary = operation.querySelector('[data-rescheduled-summary]');
    if (summary) { summary.hidden = false; summary.textContent = `${text} · ${result.technician_name}`; }
    const planning = operation.querySelector('[data-operation-planning]');
    if (planning) { planning.hidden = false; planning.href = result.planning_url; }
    const booking = operation.querySelector('[data-operation-booking]');
    if (booking) booking.hidden = true;
    const problemAppointment = operation.querySelector('[data-problem-appointment]');
    if (problemAppointment) problemAppointment.value = id;
    const problemDate = operation.querySelector('[data-problem-appointment-date]');
    if (problemDate) { problemDate.hidden = false; problemDate.textContent = `RDV du ${formatAppointmentDateTime(result.starts_at, { dateStyle: 'short', timeStyle: 'short' })}`; }
    if (result.created) {
        const list = page.querySelector('[data-dossier-appointments]');
        if (list && !list.querySelector(`[data-appointment-row="${id}"]`)) {
            list.querySelector('[data-no-appointments]')?.remove();
            const row = root.createElement('li'); row.className = 'space-y-3 py-4'; row.dataset.appointmentRow = id;
            for (const [content, field] of [[text, 'appointmentTime'], [result.service], [result.technician_name, 'appointmentTechnician'], [result.address]]) {
                const label = root.createElement('p'); label.className = 'text-sm'; label.textContent = content;
                if (field) label.dataset[field] = id;
                row.append(label);
            }
            const link = root.createElement('a'); link.className = 'gc-btn-secondary'; link.textContent = 'Voir le RDV';
            link.dataset.appointmentPlanning = id; link.href = result.planning_url; row.append(link); list.prepend(row);
        }
        const total = page.querySelector('[data-dossier-appointments-count]');
        if (total) total.textContent = result.dossier_appointments_count;
    }
}

export function initAppointmentReschedule(root = document, browser = window) {
    const dialog = root.querySelector('[data-quick-reschedule-dialog]');
    if (!dialog) return;
    const form = dialog.querySelector('[data-reschedule-form]');
    const fieldset = dialog.querySelector('[data-reschedule-fields]');
    const feedback = dialog.querySelector('[data-reschedule-feedback]');
    const resultLink = dialog.querySelector('[data-reschedule-result]');
    let context = null, target = null, opener = null, pending = false, sequence = 0, loading = null;
    const close = () => { if (!pending) dialog.close(); };
    dialog.querySelectorAll('[data-reschedule-close]').forEach(button => button.addEventListener('click', close));
    dialog.addEventListener('cancel', event => { if (pending) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        sequence++; loading?.abort(); context = null;
        opener?.focus?.({ preventScroll: true }); opener = null;
    });
    root.addEventListener('click', async event => {
        const button = event.target.closest('[data-quick-reschedule-url]');
        if (!button || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (pending || dialog.open || typeof dialog.showModal !== 'function') return;
        const url = new URL(button.dataset.quickRescheduleUrl, browser.location.href);
        if (url.origin !== browser.location.origin) return;
        target = url.href; opener = button; context = null; form.hidden = true; resultLink.hidden = true;
        fieldset.disabled = false; feedback.textContent = 'Chargement du RDV…'; feedback.classList.remove('text-rose-700');
        dialog.querySelector('[data-reschedule-summary]').textContent = '';
        dialog.querySelector('[data-reschedule-address]').textContent = '';
        dialog.showModal();
        const current = ++sequence;
        loading = new AbortController();
        const timeout = browser.setTimeout(() => loading?.abort(), 20000);
        try {
            const response = await browser.fetch(target, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: loading.signal });
            const data = await response.json().catch(() => null);
            if (current !== sequence || !dialog.open) return;
            if (!response.ok || !Array.isArray(data?.technicians)) {
                throw new Error(Object.values(data?.errors || {}).flat()[0] || 'Impossible de charger le RDV. Actualise la page et vérifie ta session.');
            }
            context = data;
            dialog.querySelector('[data-reschedule-summary]').textContent = `${data.customer} · ${data.service}`;
            dialog.querySelector('[data-reschedule-address]').textContent = data.address;
            const input = formatAppointmentInput(data.starts_at);
            form.elements.date.value = input.slice(0, 10); form.elements.time.value = input.slice(11, 16);
            form.elements.duration_minutes.value = data.duration_minutes || 60;
            const select = form.elements.technician_id;
            select.replaceChildren();
            const placeholder = root.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Choisir un technicien'; select.append(placeholder);
            for (const technician of data.technicians) {
                const option = root.createElement('option'); option.value = String(technician.id);
                option.textContent = technician.name + (technician.qualified ? '' : ' · Non habilité'); select.append(option);
            }
            select.value = data.technicians.some(tech => tech.id === data.technician_id) ? String(data.technician_id) : '';
            feedback.textContent = data.preserves_previous_visit ? 'Une nouvelle visite sera planifiée ; l’ancien rendez-vous et ses pièces seront conservés.' : '';
            form.hidden = false; form.elements.date.focus();
        } catch (error) {
            if (current === sequence && dialog.open) { feedback.textContent = error.name === 'AbortError' ? 'Chargement interrompu. Ferme puis rouvre le replacement.' : error.message; feedback.classList.add('text-rose-700'); }
        } finally { browser.clearTimeout(timeout); }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending || !context || !form.reportValidity()) return;
        let payload;
        try { payload = reschedulePayload(context, form.elements); } catch (error) { feedback.textContent = error.message; return; }
        pending = true; fieldset.disabled = true; feedback.textContent = 'Vérification du créneau…'; feedback.classList.remove('text-rose-700');
        dialog.querySelectorAll('[data-reschedule-close]').forEach(button => { button.disabled = true; });
        try {
            const result = await requestAppointmentPlacement(target, payload, { root, browser });
            if (!result) { feedback.textContent = 'Replacement annulé. Aucun changement enregistré.'; return; }
            context = null; form.hidden = true;
            feedback.textContent = `${result.message} ${formatAppointmentDateTime(result.starts_at, { dateStyle: 'short', timeStyle: 'short' })} · ${result.technician_name}.`;
            resultLink.href = result.planning_url; resultLink.hidden = false;
            applyRescheduledAppointment(result, root);
            root.dispatchEvent(new browser.CustomEvent('techcalendar:appointment-rescheduled', { detail: result }));
        } catch (error) { feedback.textContent = error.message; feedback.classList.add('text-rose-700'); }
        finally {
            pending = false; fieldset.disabled = false;
            dialog.querySelectorAll('[data-reschedule-close]').forEach(button => { button.disabled = false; });
        }
    });
}
