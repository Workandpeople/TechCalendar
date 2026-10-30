<div id="{{ $mymarBannerId }}" class="mymar-observation-status" role="status" aria-live="polite" hidden></div>
@once
<style>
    .mymar-observation-status { padding: 10px 14px; margin-bottom: 12px; border: 1px solid #e6c37b; border-radius: 12px; color: #71450a; background: #fff9e8; font-size: 12px; line-height: 1.5; }
    .mymar-observation-status[hidden] { display: none; }
    .mymar-observation-event { background: #fff7db !important; color: #71450a !important; border: 1px dashed #c58615 !important; box-shadow: none !important; }
    .mymar-event-content { display: flex; flex-direction: column; gap: 3px; padding: 5px; min-width: 0; font-size: 11px; line-height: 1.3; color: #71450a; }
    .mymar-event-content strong { font-size: 12px; }
    .mymar-event-content small { font-size: 10px; }
    .mymar-observation-dialog { width: min(440px, calc(100vw - 24px)); max-height: calc(100dvh - 32px); padding: 22px; border: 1px solid var(--gc-border, #cbd5e1); border-radius: 20px; color: var(--gc-text, #243b48); box-shadow: 0 24px 80px #0f172a33; }
    .mymar-observation-dialog::backdrop { background: #0f172a66; }
    .mymar-observation-dialog h2 { margin: 0 0 14px; font-size: 18px; font-weight: 700; }
    .mymar-observation-dialog p { margin: 10px 0; font-size: 14px; line-height: 1.6; }
    .mymar-observation-dialog button { display: block; margin: 18px 0 0 auto; padding: 8px 14px; border: 1px solid var(--gc-border, #cbd5e1); border-radius: 10px; font-weight: 600; }
</style>
<script>
(() => {
    if (window.CofracMymarCalendar) return;
    const isMymar = event => event?.extendedProps?.is_mymar === true;
    const date = (value, options) => {
        const parsed = new Date(value);
        return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('fr-FR', { ...options, timeZone: 'Europe/Paris' }).format(parsed) : 'Date indisponible';
    };
    const node = (tag, value, className = '') => {
        const element = document.createElement(tag);
        element.textContent = value;
        if (className) element.className = className;
        return element;
    };
    const summary = event => {
        const props = event.extendedProps || {};
        return [props.service_label || 'Rendez-vous MAR', props.technician_name || 'Technicien',
            date(event.start, { dateStyle: 'short', timeStyle: 'short' }) + ' – ' + date(event.end, { timeStyle: 'short' }),
            'Observation en lecture seule. Ne bloque pas les créneaux.',
            props.cache_state === 'stale' || props.cache_state === 'degraded' ? 'Données à actualiser.' : 'Horaires à confirmer.'].join(' · ');
    };
    const content = event => {
        const props = event.extendedProps || {};
        const box = node('span', '', 'mymar-event-content');
        box.append(node('small', 'MYMAR · Observation · Lecture seule'), node('strong', props.service_label || 'Rendez-vous MAR'),
            node('span', date(event.start, { timeStyle: 'short' }) + ' – ' + date(event.end, { timeStyle: 'short' })),
            node('span', props.technician_name || 'Technicien'));
        if (['stale', 'degraded'].includes(props.cache_state)) box.append(node('small', 'Données à actualiser'));
        return { domNodes: [box] };
    };
    const mount = info => {
        info.el.classList.add('mymar-observation-event');
        info.el.title = summary(info.event);
        info.el.setAttribute('aria-label', summary(info.event));
        info.el.tabIndex = 0;
        if (info.el.classList.contains('nc-event')) info.el.replaceChildren(...content(info.event).domNodes);
        if (info.el.classList.contains('nc-month-event')) info.el.replaceChildren(node('span',
            date(info.event.start, { timeStyle: 'short' }) + ' · MYMAR · Observation'));
        if (info.el.tagName !== 'BUTTON' && !info.el.dataset.mymarKeyboard) {
            info.el.dataset.mymarKeyboard = '1';
            info.el.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(info.event, info.el); }
            });
        }
    };
    const open = (event, trigger) => {
        if (!isMymar(event)) return;
        const props = event.extendedProps || {};
        const dialog = document.createElement('dialog');
        dialog.className = 'mymar-observation-dialog';
        const title = node('h2', props.service_label || 'Rendez-vous MAR');
        title.id = 'mymar-observation-dialog-title';
        dialog.setAttribute('aria-labelledby', title.id);
        const close = node('button', 'Fermer');
        close.type = 'button';
        close.addEventListener('click', () => dialog.close());
        dialog.append(title, node('p', props.technician_name || 'Technicien'),
            node('p', date(event.start, { dateStyle: 'full', timeStyle: 'short' }) + ' – ' + date(event.end, { timeStyle: 'short' })),
            node('p', 'Observation uniquement : ce rendez-vous MYMAR ne bloque pas encore les créneaux et ne peut pas être modifié ici.'),
            node('p', 'Les durées et horaires restent à confirmer. Le bénéficiaire, les documents et les trajets MYMAR ne sont pas repris.'),
            node('p', 'Dernière synchronisation : ' + date(props.synced_at, { dateStyle: 'short', timeStyle: 'short' })), close);
        dialog.addEventListener('close', () => { dialog.remove(); trigger?.focus({ preventScroll: true }); }, { once: true });
        document.body.append(dialog);
        dialog.showModal();
    };
    const setStatus = (id, meta) => {
        const element = document.getElementById(id);
        if (!element) return;
        element.hidden = !meta || ['disabled', 'outside_pilot'].includes(meta.state);
        if (element.hidden) { element.textContent = ''; return; }
        const states = { observing: 'Cache à jour.', partial: 'La période affichée dépasse la période synchronisée.',
            stale: 'Cache périmé : les derniers horaires connus sont affichés.', degraded: 'La dernière actualisation a échoué : données précédentes conservées.',
            mapping_changed: 'Correspondance de technicien à revalider.', unavailable: 'Cache indisponible : aucune conclusion sur les disponibilités MAR.',
            invalid: 'Cache non exploitable : aucune conclusion sur les disponibilités MAR.' };
        element.textContent = ['MYMAR · Pilote en observation, sans blocage des créneaux.', states[meta.state] || states.unavailable,
            meta.pilot_technicians ? `${meta.covered_technicians || 0}/${meta.pilot_technicians} technicien(s) du pilote sélectionné(s).` : '',
            meta.generated_at ? 'Synchronisé le ' + date(meta.generated_at, { dateStyle: 'short', timeStyle: 'short' }) + '.' : '',
            meta.window_start && meta.window_end ? 'Couverture du ' + date(meta.window_start, { dateStyle: 'short' }) + ' au ' + date(meta.window_end, { dateStyle: 'short' }) + '.' : '',
            'Horaires et durées à confirmer.'].filter(Boolean).join(' ');
    };
    const combineStatuses = values => {
        const metas = values.filter(Boolean);
        const active = metas.filter(meta => !['disabled', 'outside_pilot'].includes(meta.state));
        if (!active.length) return metas[0] || null;
        const severity = ['observing', 'partial', 'degraded', 'stale', 'unavailable', 'invalid', 'mapping_changed'];
        const chosen = active.reduce((left, right) => severity.indexOf(right.state) > severity.indexOf(left.state) ? right : left);
        return { ...chosen, selected_technicians: metas.reduce((sum, meta) => sum + (meta.selected_technicians || 0), 0),
            covered_technicians: active.reduce((sum, meta) => sum + (meta.covered_technicians || 0), 0) };
    };
    window.CofracMymarCalendar = { isMymar, content, mount, open, setStatus, combineStatuses };
})();
</script>
@endonce
