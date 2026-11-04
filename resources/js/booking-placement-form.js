import { formatAppointmentInput, formatAppointmentDateTime, parseAppointmentDate, serializeAppointmentInput } from './appointment-date-time.js';

export function placementSelection(fields) {
    const startsAt = serializeAppointmentInput(`${fields.date.value}T${fields.time.value}`);
    const technicianId = Number(fields.technician_id.value);
    const duration = Number(fields.duration_minutes.value);
    if (!startsAt) throw new Error('Choisissez une date et une heure valides (heure de Paris).');
    if (!Number.isSafeInteger(technicianId) || technicianId < 1 || !Number.isInteger(duration) || duration < 30 || duration > 480) {
        throw new Error('Choisissez un technicien et une durée de 30 à 480 minutes.');
    }
    return { technician_id: technicianId, starts_at: startsAt, duration_minutes: duration, comment: fields.comment.value.trim() };
}

export function createBookingPlacementForm({ getContext, onPlace, root = document, title: titleText = 'Placer le rendez-vous',
    titleId = 'booking-placement-title', submitLabel = 'Placer le RDV' }) {
    const node = (tag, className = '', text = '') => {
        const element = root.createElement(tag); element.className = className; element.textContent = text; return element;
    };
    const button = (text, style = 'gc-btn-secondary') => {
        const element = node('button', style, text); element.type = 'button'; return element;
    };
    const dialog = node('dialog', 'gc-dossier-dialog gc-placement-dialog');
    dialog.setAttribute('aria-labelledby', titleId);
    const header = node('header');
    const heading = node('div');
    const title = node('h2', '', titleText); title.id = titleId;
    heading.append(node('p', 'gc-placement-eyebrow', 'Placement manuel · Heure de Paris'), title);
    const close = button('Fermer'); header.append(heading, close);
    const body = node('div', 'gc-dossier-dialog-body');
    const summary = node('section', 'gc-placement-summary');
    const customer = node('strong'), service = node('span'), address = node('p');
    summary.append(customer, service, address);
    const form = node('form');
    const fieldset = node('fieldset', 'gc-placement-fields');
    const fields = {};
    for (const [name, text, type] of [['date', 'Date', 'date'], ['time', 'Heure de début (Paris)', 'time'],
        ['technician_id', 'Technicien', 'select'], ['duration_minutes', 'Durée (minutes)', 'number'], ['comment', 'Commentaire interne (facultatif)', 'textarea']]) {
        const label = node('label', `gc-label gc-placement-field-${name}`, text);
        const input = node(type === 'select' || type === 'textarea' ? type : 'input', 'gc-input');
        if (input.tagName === 'INPUT') input.type = type;
        input.name = name; input.required = name !== 'comment'; fields[name] = input;
        label.append(input); fieldset.append(label);
    }
    fields.time.step = '60'; fields.duration_minutes.min = '30'; fields.duration_minutes.max = '480';
    fields.comment.rows = 2; fields.comment.maxLength = 2000;
    const end = node('p', 'gc-placement-end'); end.setAttribute('aria-live', 'polite');
    const feedback = node('p', 'gc-placement-feedback'); feedback.setAttribute('role', 'status');
    const actions = node('footer', 'gc-dialog-actions');
    const cancel = button('Annuler'), force = button('Forcer le placement', 'gc-btn-warning'), submit = button(submitLabel, 'gc-btn-primary');
    submit.type = 'submit'; force.hidden = true; actions.append(cancel, force, submit);
    fieldset.append(end, feedback, actions); form.append(fieldset); body.append(summary, form); dialog.append(header, body);
    root.body.append(dialog);
    let context = null, busy = false, opener = null;
    const status = (message, type = '') => { feedback.textContent = message; feedback.dataset.error = String(type === 'error'); };
    const dismiss = () => { if (!busy) dialog.close(); };
    close.addEventListener('click', dismiss); cancel.addEventListener('click', dismiss);
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { context = null; opener?.focus?.({ preventScroll: true }); });
    const updateEnd = () => {
        force.hidden = true;
        const start = parseAppointmentDate(`${fields.date.value}T${fields.time.value}`);
        const duration = Number(fields.duration_minutes.value);
        end.textContent = start && duration > 0 && duration <= 480
            ? `Fin prévue à ${formatAppointmentDateTime(new Date(start.getTime() + duration * 60000), { hour: '2-digit', minute: '2-digit' })} · Le créneau sera vérifié avant enregistrement.` : '';
    };
    form.addEventListener('input', updateEnd); form.addEventListener('change', updateEnd);
    const place = async forced => {
        if (busy || !context || !form.reportValidity()) return;
        let values;
        try { values = placementSelection(fields); } catch (error) { status(error.message, 'error'); return; }
        if (!context.technicians.some(tech => Number(tech.id) === values.technician_id)) { status('Ce technicien n’est plus sélectionné.', 'error'); return; }
        busy = true; fieldset.disabled = true; close.disabled = true; status('Vérification du créneau…');
        try {
            await onPlace({ values, context, forced, button: submit, status,
                conflict: data => { force.hidden = !context?.forceAllowed || data?.can_force !== true; },
                close: () => dialog.close() });
        } catch (error) { status(error.message || 'Placement impossible. Aucun enregistrement confirmé.', 'error'); }
        finally { busy = false; fieldset.disabled = false; close.disabled = false; submit.textContent = submitLabel; }
    };
    form.addEventListener('submit', event => { event.preventDefault(); place(false); });
    force.addEventListener('click', () => place(true));

    return {
        open({ date = new Date(), resource = null, trigger = null } = {}) {
            if (busy || dialog.open) return;
            const next = getContext();
            if (!next?.request || !next.technicians?.length) throw new Error('Choisissez un dossier et cochez au moins un technicien avant de placer un RDV.');
            if (!next.request.service) throw new Error('Renseignez la prestation du dossier avant de placer le RDV.');
            context = next; opener = trigger || root.activeElement;
            customer.textContent = next.request.customer_name || next.request.beneficiary_name || next.request.company_name || [next.request.last_name, next.request.first_name].filter(Boolean).join(' ');
            service.textContent = next.request.service.name; address.textContent = next.request.address || '';
            const input = formatAppointmentInput(date);
            fields.date.value = input.slice(0, 10); fields.time.value = input.slice(11, 16) || '09:00';
            fields.technician_id.replaceChildren();
            for (const tech of next.technicians) {
                const option = node('option', '', tech.name); option.value = tech.id; fields.technician_id.append(option);
            }
            fields.technician_id.value = String(next.technicians.find(tech => String(tech.id) === String(resource?.id))?.id || next.technicians[0].id);
            fields.duration_minutes.value = next.duration; fields.duration_minutes.readOnly = Boolean(next.request.is_mymar);
            fields.comment.value = ''; fields.comment.closest('label').hidden = Boolean(next.request.is_mymar);
            status(''); updateEnd(); dialog.showModal(); body.scrollTop = 0; fields.date.focus({ preventScroll: true });
        },
        destroy() { if (!busy) dialog.remove(); },
    };
}
