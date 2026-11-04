import { createBookingPlacementForm } from './booking-placement-form.js';

export function initMymarReschedule(root = document, browser = window) {
    const feedback = root.querySelector('[data-mymar-reschedule-feedback]');
    if (!feedback) return;
    let current = null, form = null, loading = false;
    root.addEventListener('click', async event => {
        const trigger = event.target.closest('[data-mymar-reschedule-id]');
        if (!trigger || event.defaultPrevented || loading || !browser.CofracMymarActions) return;
        event.preventDefault();
        const id = Number(trigger.dataset.mymarRescheduleId);
        if (!Number.isSafeInteger(id) || id < 1) return;
        loading = true; trigger.disabled = true; feedback.hidden = false; feedback.textContent = 'Chargement du rendez-vous MAR…';
        try {
            const source = await browser.CofracMymarActions.scheduled(id);
            if (source.rdv_id !== id || !Array.isArray(source.technicians) || !source.technicians.length) {
                throw new Error('Aucun technicien MAR actif et habilité n’est disponible pour ce replacement.');
            }
            current = source;
            form ||= createBookingPlacementForm({ root, title: 'Replacer le rendez-vous MAR', titleId: 'mymar-reschedule-title',
                submitLabel: 'Confirmer dans MYMAR',
                getContext: () => ({ request: { is_mymar: true, customer_name: current.customer, address: current.address,
                    service: { name: current.service } }, technicians: current.technicians, duration: current.duration_minutes, forceAllowed: false,
                    source: { ...current } }),
                onPlace: async ({ values, context, close }) => {
                    const result = await browser.CofracMymarActions.reschedule({ rdv_id: context.source.rdv_id,
                        dossier_id: context.source.dossier_id, expected_revision: context.source.expected_revision,
                        technician_id: values.technician_id, starts_at: values.starts_at, duration_minutes: context.source.duration_minutes });
                    close();
                    feedback.hidden = false;
                    feedback.textContent = result?.message || 'Confirmation MYMAR en attente : vérifiez son résultat avant de renouveler la demande.';
                },
            });
            trigger.closest('dialog')?.close();
            feedback.hidden = true;
            form.open({ date: current.starts_at, resource: { id: current.technician_id }, trigger });
        } catch (error) {
            feedback.hidden = false; feedback.textContent = error.message || 'Le replacement MAR n’est pas disponible.';
            const dialog = trigger.closest('dialog');
            if (dialog) {
                const status = dialog.querySelector?.('[data-mymar-load-error]') || root.createElement('p');
                status.dataset.mymarLoadError = '1'; status.setAttribute('role', 'alert');
                status.textContent = feedback.textContent; dialog.append(status);
            }
        }
        finally { loading = false; trigger.disabled = false; }
    });
}
