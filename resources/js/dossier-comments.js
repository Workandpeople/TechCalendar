import { initCommentMarkup } from './comment-markup.js';

export function commentTooltipPosition(anchor, size, viewport) {
    const margin = 12;
    const left = Math.max(margin, Math.min(anchor.x, viewport.width - size.width - margin));
    const below = anchor.y + margin;
    const top = below + size.height <= viewport.height - margin
        ? below : Math.max(margin, (anchor.top ?? anchor.y) - size.height - margin);
    return { left, top };
}

export function initDossierComments(root = document, browser = window) {
    const rows = [...root.querySelectorAll('[data-dossier-comments]')];
    if ((rows.length === 0) && !root.querySelector?.('[data-dossier-list]')) return;

    const tooltip = root.createElement('div');
    tooltip.id = 'dossier-comments-tooltip';
    tooltip.className = 'gc-dossier-comments-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    root.body.append(tooltip);
    let activeTrigger = null;
    let hideTimer;

    const cancelHide = () => browser.clearTimeout(hideTimer);
    const hide = () => {
        cancelHide();
        activeTrigger?.removeAttribute('aria-describedby');
        activeTrigger = null;
        tooltip.hidden = true;
    };
    const scheduleHide = () => {
        cancelHide();
        hideTimer = browser.setTimeout(() => {
            if (root.activeElement !== activeTrigger) hide();
        }, 150);
    };
    const place = (anchor) => {
        const position = commentTooltipPosition(anchor, tooltip.getBoundingClientRect(), {
            width: root.documentElement.clientWidth, height: browser.innerHeight,
        });
        tooltip.style.left = `${position.left}px`;
        tooltip.style.top = `${position.top}px`;
    };
    const show = (row, anchor, fromKeyboard = false) => {
        // Keep the map reachable while crossing the row during its dismissal delay.
        if (!fromKeyboard && root.querySelector?.('#dossier-map-preview')?.hidden === false) return;
        hide();
        const template = row.querySelector('[data-dossier-comments-content]');
        const trigger = row.querySelector('[data-dossier-comments-trigger]');
        if (!template || !trigger) return;
        root.dispatchEvent?.(new browser.CustomEvent('techcalendar:dossier-comments-open'));
        activeTrigger = trigger;
        // Server text is escaped; apply the same formatting allowlist as appointment details.
        tooltip.replaceChildren(template.content.cloneNode(true));
        initCommentMarkup(tooltip);
        tooltip.hidden = false;
        place(anchor);
        trigger.setAttribute('aria-describedby', tooltip.id);
    };
    const bound = new WeakSet();
    const bind = (rows) => rows.forEach((row) => {
        if (bound.has(row)) return;
        const trigger = row.querySelector('[data-dossier-comments-trigger]');
        if (!trigger) return;
        bound.add(row);
        trigger.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'touch') return;
            show(row, { x: event.clientX + 12, y: event.clientY });
        });
        trigger.addEventListener('pointerleave', scheduleHide);
        trigger.addEventListener('focus', () => {
            const rect = trigger.getBoundingClientRect();
            show(row, { x: rect.left, y: rect.bottom, top: rect.top }, true);
        });
        trigger.addEventListener('blur', hide);
    });
    bind(rows);
    root.addEventListener('techcalendar:dossiers-before-update', () => hide());
    root.addEventListener('techcalendar:dossiers-updated', () => bind(root.querySelectorAll('[data-dossier-comments]')));
    tooltip.addEventListener('pointerenter', cancelHide);
    tooltip.addEventListener('pointerleave', scheduleHide);
    root.addEventListener('keydown', (event) => { if (event.key === 'Escape') hide(); });
    root.addEventListener('scroll', (event) => {
        if (tooltip.contains(event.target)) return;
        if (activeTrigger && root.activeElement === activeTrigger) {
            const rect = activeTrigger.getBoundingClientRect();
            if (rect.bottom >= 0 && rect.top < browser.innerHeight) {
                place({ x: rect.left, y: rect.bottom, top: rect.top });
                return;
            }
        }
        hide();
    }, true);
    browser.addEventListener('resize', hide);
    root.addEventListener('techcalendar:dossier-map-open', hide);
}
