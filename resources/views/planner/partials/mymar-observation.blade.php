<div id="{{ $mymarBannerId }}" class="mymar-observation-status" role="status" aria-live="polite" hidden></div>
@once
<div data-mymar-actions data-place-url="{{ route('planner.book.mar.place', [], false) }}" data-problem-url="{{ route('planner.book.mar.problem', [], false) }}"
    data-status-url="{{ route('planner.book.mar.status', ['id' => '__REQUEST__'], false) }}"
    data-reschedule-url="{{ route('planner.book.mar.reschedule', [], false) }}" data-scheduled-url="{{ route('planner.book.mar.scheduled', ['rdv' => '__RDV__'], false) }}"
    data-user-id="{{ auth()->id() }}" data-csrf="{{ csrf_token() }}">
    <button type="button" class="gc-btn-secondary mb-3" data-mymar-resume style="display:none">Confirmation MAR à vérifier</button>
</div>
<p data-mymar-reschedule-feedback class="mb-3 text-sm" role="status" aria-live="polite" hidden></p>
<style>
    .gc-mymar-action-dialog { margin: auto; width: min(36rem, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); overflow-y: auto; padding: 1.25rem; border-radius: 1rem; border: 1px solid var(--gc-border, #cbd5e1); background: var(--gc-surface, white); color: var(--gc-text, #243b48); }
    .gc-mymar-action-dialog::backdrop { background: #0f172a99; }
    .mymar-observation-status { padding: 10px 14px; margin-bottom: 12px; border: 1px solid #e6c37b; border-radius: 12px; color: #71450a; background: #fff9e8; font-size: 12px; line-height: 1.5; }
    .mymar-observation-status[hidden] { display: none; }
    .mymar-observation-event { background: #fff7db !important; color: #71450a !important; border: 1px dashed #c58615 !important; box-shadow: none !important; }
    .mymar-event-content { display: flex; flex-direction: column; gap: 3px; padding: 5px; min-width: 0; font-size: 11px; line-height: 1.3; color: #71450a; }
    .mymar-event-content strong { font-size: 12px; }
    .mymar-event-content small { font-size: 10px; }
    .mymar-event-address { overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow-wrap: anywhere; white-space: normal; }
    .nc-calendar[data-density='compact'] .mymar-event-content { padding: 0; gap: 2px; }
    .nc-calendar[data-density='compact'] .mymar-event-content strong { font-size: 11px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .nc-calendar[data-density='compact'] :is(.mymar-event-service, .mymar-event-address, .mymar-event-technician) { display: none; }
    .mymar-observation-dialog { width: min(440px, calc(100vw - 24px)); max-height: calc(100dvh - 32px); padding: 22px; border: 1px solid var(--gc-border, #cbd5e1); border-radius: 20px; color: var(--gc-text, #243b48); box-shadow: 0 24px 80px #0f172a33; }
    .mymar-observation-dialog::backdrop { background: #0f172a66; }
    .mymar-observation-dialog h2 { margin: 0 0 14px; font-size: 18px; font-weight: 700; }
    .mymar-observation-dialog p { margin: 10px 0; font-size: 14px; line-height: 1.6; }
    .mymar-observation-dialog button { display: block; margin: 18px 0 0 auto; padding: 8px 14px; border: 1px solid var(--gc-border, #cbd5e1); border-radius: 10px; font-weight: 600; }
    .mymar-observation-dialog > a, .mymar-observation-dialog > button[data-mymar-reschedule-id] { display: flex; align-items: center; justify-content: center; width: 100%; margin-top: 10px; }
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
        return [props.service_label || 'Rendez-vous MAR', props.customer_name || 'Bénéficiaire non disponible',
            props.address || 'Adresse non disponible', props.technician_name || 'Technicien',
            date(event.start, { dateStyle: 'short', timeStyle: 'short' }) + ' – ' + date(event.end, { timeStyle: 'short' }),
            props.can_reschedule ? 'Replacement disponible depuis le détail. Disponibilité MAR contrôlée au placement.' : props.availability_guard_enabled ? 'Lecture seule. Disponibilité MAR contrôlée au placement.' : 'Observation en lecture seule. Ne bloque pas les créneaux.',
            props.cache_state === 'stale' || props.cache_state === 'degraded' ? 'Données à actualiser.' : 'Horaires à confirmer.'].join(' · ');
    };
    const content = event => {
        const props = event.extendedProps || {};
        const box = node('span', '', 'mymar-event-content');
        box.append(node('small', props.can_reschedule ? 'MYMAR · Après travaux' : props.availability_guard_enabled ? 'MYMAR · Lecture seule' : 'MYMAR · Observation · Lecture seule'),
            node('strong', props.customer_name || 'Bénéficiaire non disponible'), node('span', props.service_label || 'Rendez-vous MAR', 'mymar-event-service'),
            node('span', date(event.start, { timeStyle: 'short' }) + ' – ' + date(event.end, { timeStyle: 'short' })),
            node('span', props.address || 'Adresse non disponible', 'mymar-event-address'),
            node('small', props.technician_name || 'Technicien', 'mymar-event-technician'));
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
            date(info.event.start, { timeStyle: 'short' }) + (info.event.extendedProps?.availability_guard_enabled ? ' · MYMAR' : ' · MYMAR · Observation')
                + (info.event.extendedProps?.customer_name ? ' · ' + info.event.extendedProps.customer_name : '')));
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
        dialog.append(title, node('p', props.customer_name || 'Bénéficiaire non disponible'), node('p', props.address || 'Adresse non disponible'),
            node('p', props.technician_name || 'Technicien'),
            node('p', date(event.start, { dateStyle: 'full', timeStyle: 'short' }) + ' – ' + date(event.end, { timeStyle: 'short' })),
            node('p', props.can_reschedule ? 'Le replacement sera enregistré dans MYMAR ; l’ancien créneau et son historique seront conservés.' : props.availability_guard_enabled ? 'Ce rendez-vous est pris en compte dans les disponibilités. Il reste en lecture seule et se modifie dans MYMAR.' : 'Observation uniquement : ce rendez-vous MYMAR ne bloque pas encore les créneaux et ne peut pas être modifié ici.'),
            node('p', (props.availability_guard_enabled ? '' : 'Les horaires restent à confirmer. ') + 'Les trajets autour des rendez-vous MYMAR ne sont pas encore calculés.'),
            node('p', 'Dernière synchronisation : ' + date(props.synced_at, { dateStyle: 'short', timeStyle: 'short' })), close);
        if (props.can_reschedule === true && props.visit_phase === 'after_works' && Number.isSafeInteger(props.source_rdv_id) && props.source_rdv_id > 0) {
            const replan = node('button', 'Replacer rapidement');
            replan.type = 'button'; replan.className = 'gc-btn-warning'; replan.dataset.mymarRescheduleId = String(props.source_rdv_id);
            dialog.append(replan);
            const full = node('a', 'Replanifier avec le planning', 'gc-btn-secondary');
            full.href = @json(route('planner.book', [], false)) + '?mymar_rdv_id=' + encodeURIComponent(props.source_rdv_id);
            dialog.append(full);
        }
        if (props.visit_phase === 'after_works' && typeof props.documents_url === 'string' && props.documents_url.startsWith('/mar/appointments/')) {
            const documents = node('a', 'Factures et devis de travaux', 'gc-btn-secondary');
            documents.href = props.documents_url;
            dialog.append(documents);
        }
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
        element.textContent = [meta.blocking_enabled ? 'MYMAR · Disponibilités contrôlées au placement pour les techniciens du pilote.' : 'MYMAR · Pilote en observation, sans blocage des créneaux.', states[meta.state] || states.unavailable,
            meta.pilot_technicians ? `${meta.covered_technicians || 0}/${meta.pilot_technicians} technicien(s) du pilote sélectionné(s).` : '',
            meta.generated_at ? 'Synchronisé le ' + date(meta.generated_at, { dateStyle: 'short', timeStyle: 'short' }) + '.' : '',
            meta.window_start && meta.window_end ? 'Couverture du ' + date(meta.window_start, { dateStyle: 'short' }) + ' au ' + date(meta.window_end, { dateStyle: 'short' }) + '.' : '',
            meta.blocking_enabled ? 'Une disponibilité inconnue ne permet pas de placer un rendez-vous.' : 'Horaires et durées à confirmer.'].filter(Boolean).join(' ');
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
