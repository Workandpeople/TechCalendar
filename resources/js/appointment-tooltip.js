import { cofracLabel } from './cofrac-label.js';

export function appointmentTooltipAddress(props) {
    const address = String(props.address || '').trim();
    const locality = [props.postal_code, props.city].filter(Boolean).join(' ').trim();
    const suffix = locality || (props.location_label !== address ? String(props.location_label || '').trim() : '');
    return !address ? suffix || 'Adresse non renseignée'
        : !suffix || address.toLocaleLowerCase('fr').includes(suffix.toLocaleLowerCase('fr')) ? address : `${address}, ${suffix}`;
}

export function createAppointmentTooltip({ root = document, browser = window } = {}) {
    const popup = root.createElement('div');
    popup.id = 'appointment-hover-tooltip';
    popup.setAttribute('role', 'tooltip');
    popup.style.cssText = 'position:fixed;z-index:90;pointer-events:none;width:min(360px,calc(100vw - 16px));border-radius:16px;padding:12px 14px;background:#31424c;color:#fff;box-shadow:0 18px 45px rgba(15,23,42,.28);font-size:12px;line-height:1.45;overflow-wrap:anywhere';
    popup.hidden = true;
    root.body.append(popup);
    let trigger, description;
    const text = (parent, value, css = '') => {
        const node = root.createElement('p'); node.textContent = value; node.style.cssText = css; parent.append(node);
    };
    const hide = () => {
        if (trigger) {
            if (description) trigger.setAttribute('aria-describedby', description);
            else trigger.removeAttribute('aria-describedby');
        }
        trigger = null; popup.hidden = true;
    };
    const move = pointer => {
        const box = popup.getBoundingClientRect();
        const width = root.documentElement.clientWidth, height = browser.innerHeight;
        popup.style.left = `${Math.max(8, Math.min(pointer.clientX - box.width / 2, width - box.width - 8))}px`;
        const above = pointer.clientY - box.height - 14;
        popup.style.top = `${Math.max(8, above >= 8 ? above : Math.min(pointer.clientY + 14, height - box.height - 8))}px`;
    };
    const show = (pointer, event, element) => {
        hide(); popup.replaceChildren();
        const props = event.extendedProps || {};
        text(popup, props.technician_name || props.technician_full_name || 'Technicien non renseigné', 'font-weight:800;letter-spacing:.02em');
        if (props.status === 'problem') text(popup, 'Problème RDV', 'color:#fed7aa;font-weight:700');
        for (const [label, value] of [['RDV', cofracLabel(props.service_label || props.service_type || 'Prestation non renseignée')],
            ['Adresse', appointmentTooltipAddress(props)], ['Client', [props.customer_name || event.title, props.customer_phone].filter(Boolean).join(' · ')]]) {
            if (!value) continue;
            const row = root.createElement('div'); row.style.cssText = 'margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:10px';
            text(row, label, 'color:rgba(255,255,255,.68);font-size:11px;text-transform:uppercase;letter-spacing:.08em');
            text(row, value, 'margin-top:2px;font-weight:700'); popup.append(row);
        }
        const times = [event.start, event.end].filter(Boolean).map(value => new Date(value))
            .filter(value => Number.isFinite(value.getTime())).map(value => value.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }));
        if (times.length) text(popup, times.join(' → '), 'margin-top:10px;color:rgba(255,255,255,.78)');
        trigger = element; description = trigger?.getAttribute('aria-describedby');
        trigger?.setAttribute('aria-describedby', [description, popup.id].filter(Boolean).join(' '));
        popup.hidden = false; move(pointer);
    };
    root.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    root.addEventListener('scroll', hide, true);
    browser.addEventListener('resize', hide);
    browser.addEventListener('pagehide', hide);
    return { show, move, hide };
}
