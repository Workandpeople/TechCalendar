import { commentTooltipPosition } from './dossier-comments.js';

export function validMapPoint(point) {
    return point && Number.isFinite(point.latitude) && Number.isFinite(point.longitude)
        && Math.abs(point.latitude) <= 90 && Math.abs(point.longitude) <= 180
        && (point.latitude !== 0 || point.longitude !== 0);
}

export function initDossierMapPreview(root = document, browser = window) {
    const triggers = [...root.querySelectorAll('[data-dossier-map-url]')];
    if ((!triggers.length) && !root.querySelector?.('[data-dossier-list]')) return;
    const token = root.querySelector('[data-dossier-map-config]')?.dataset.mapboxToken;
    const popup = root.createElement('div');
    popup.className = 'gc-dossier-map-tooltip';
    popup.id = 'dossier-map-preview';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'Carte du lieu d\u2019inspection');
    popup.hidden = true;
    const heading = root.createElement('div'); heading.className = 'gc-dossier-map-heading';
    const title = root.createElement('strong');
    const close = root.createElement('button'); close.type = 'button'; close.textContent = 'Fermer';
    close.setAttribute('aria-label', 'Fermer la carte');
    heading.append(title, close);
    const canvas = root.createElement('div'); canvas.className = 'gc-dossier-map-canvas';
    const message = root.createElement('p'); message.className = 'gc-dossier-map-message'; message.setAttribute('role', 'status');
    popup.append(heading, canvas, message);
    root.body.append(popup);
    let active = null;
    let pinned = false;
    let hovered = false;
    let timer;
    let controller;
    let map;
    let marker;
    let generation = 0;
    let restoringFocus = false;
    const cache = new Map();
    const cancelHide = () => browser.clearTimeout(timer);
    const position = () => {
        if (!active) return;
        const rect = active.getBoundingClientRect();
        const point = commentTooltipPosition({ x: rect.left, y: rect.bottom, top: rect.top }, popup.getBoundingClientRect(), {
            width: root.documentElement.clientWidth, height: browser.innerHeight,
        });
        popup.style.left = `${point.left}px`; popup.style.top = `${point.top}px`;
    };
    const hide = (restoreFocus = false) => {
        cancelHide(); controller?.abort(); generation++;
        const trigger = active;
        active?.setAttribute('aria-expanded', 'false');
        active = null; pinned = false; hovered = false; popup.hidden = true;
        if (restoreFocus && trigger) {
            restoringFocus = true;
            trigger.focus();
            restoringFocus = false;
        }
    };
    const scheduleHide = () => {
        cancelHide();
        timer = browser.setTimeout(() => {
            if (!hovered && !pinned && root.activeElement !== active && !popup.contains(root.activeElement)) hide();
        }, 1000);
    };
    const show = async (trigger) => {
        cancelHide();
        if (active === trigger) return;
        hide(); active = trigger;
        const current = ++generation;
        root.dispatchEvent(new browser.CustomEvent('techcalendar:dossier-map-open'));
        title.textContent = trigger.dataset.dossierMapAddress || 'Lieu d\u2019inspection';
        message.textContent = 'Chargement de la carte\u2026';
        canvas.hidden = true; popup.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        position();
        if (!token || !browser.mapboxgl) {
            message.textContent = 'Carte indisponible : v\u00e9rifier la configuration Mapbox.';
            position(); return;
        }
        const url = trigger.dataset.dossierMapUrl;
        controller = new AbortController();
        try {
            let payload = cache.get(url);
            if (!payload) {
                const response = await browser.fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error('preview unavailable');
                payload = await response.json();
                if (validMapPoint(payload.coordinates)) cache.set(url, payload);
            }
            if (current !== generation) return;
            message.textContent = payload.message || 'Position indisponible.';
            if (validMapPoint(payload.coordinates)) {
                canvas.hidden = false;
                const center = [payload.coordinates.longitude, payload.coordinates.latitude];
                if (!map) {
                    map = new browser.mapboxgl.Map({
                        container: canvas, accessToken: token, style: 'mapbox://styles/mapbox/streets-v12', center, zoom: 14,
                        interactive: true, dragRotate: false, pitchWithRotate: false,
                        locale: { 'NavigationControl.ZoomIn': 'Zoomer', 'NavigationControl.ZoomOut': 'D\u00e9zoomer' },
                    });
                    map.addControl(new browser.mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
                    marker = new browser.mapboxgl.Marker({ color: '#be123c' }).setLngLat(center).addTo(map);
                    map.on('error', () => {
                        if (active) message.textContent = 'Le fond de carte ne peut pas \u00eatre charg\u00e9. R\u00e9essaie dans un instant.';
                    });
                } else {
                    marker.setLngLat(center); map.jumpTo({ center, zoom: 14 });
                }
                map.resize();
            }
        } catch (error) {
            if (current !== generation || error.name === 'AbortError') return;
            message.textContent = 'Aper\u00e7u indisponible. R\u00e9essaie dans un instant.';
        }
        position();
    };
    const bound = new WeakSet();
    const bind = (triggers) => triggers.forEach((trigger) => {
        if (bound.has(trigger)) return;
        bound.add(trigger);
        trigger.setAttribute('aria-controls', popup.id);
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.addEventListener('pointerenter', event => { if (event.pointerType !== 'touch') show(trigger); });
        trigger.addEventListener('pointerleave', scheduleHide);
        trigger.addEventListener('focus', () => { if (!restoringFocus) show(trigger); });
        trigger.addEventListener('blur', scheduleHide);
        trigger.addEventListener('click', () => {
            if (active === trigger && pinned) hide();
            else { show(trigger); pinned = true; }
        });
    });
    bind(triggers);
    root.addEventListener('techcalendar:dossiers-before-update', () => hide());
    root.addEventListener('techcalendar:dossiers-updated', () => bind(root.querySelectorAll('[data-dossier-map-url]')));
    close.addEventListener('click', () => hide(true));
    popup.addEventListener('pointerenter', () => { hovered = true; cancelHide(); });
    popup.addEventListener('pointerleave', () => { hovered = false; scheduleHide(); });
    popup.addEventListener('focusout', scheduleHide);
    root.addEventListener('techcalendar:dossier-comments-open', () => hide());
    root.addEventListener('keydown', event => { if (event.key === 'Escape' && active) hide(popup.contains(root.activeElement)); });
    root.addEventListener('click', event => { if (!popup.contains(event.target) && !active?.contains(event.target)) hide(); });
    root.addEventListener('scroll', event => { if (!popup.contains(event.target)) hide(); }, true);
    browser.addEventListener('resize', () => hide());
}
