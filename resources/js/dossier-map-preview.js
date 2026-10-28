import { commentTooltipPosition } from './dossier-comments.js';

export function validMapPoint(point) {
    return point && Number.isFinite(point.latitude) && Number.isFinite(point.longitude)
        && Math.abs(point.latitude) <= 90 && Math.abs(point.longitude) <= 180
        && (point.latitude !== 0 || point.longitude !== 0);
}

export function dossierOverviewBounds(point) {
    return [[Math.min(-5.5, point.longitude), Math.min(41, point.latitude)],
        [Math.max(9.8, point.longitude), Math.max(51.5, point.latitude)]];
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
    let controller;
    let map;
    let marker;
    let generation = 0;
    const cache = new Map();
    const position = () => {
        if (!active) return;
        const rect = active.getBoundingClientRect();
        const point = commentTooltipPosition({ x: rect.left, y: rect.bottom, top: rect.top }, popup.getBoundingClientRect(), {
            width: root.documentElement.clientWidth, height: browser.innerHeight,
        });
        popup.style.left = `${point.left}px`; popup.style.top = `${point.top}px`;
    };
    const hide = (restoreFocus = false) => {
        controller?.abort(); generation++;
        const trigger = active;
        active?.setAttribute('aria-expanded', 'false');
        active = null; popup.hidden = true;
        if (restoreFocus && trigger) {
            trigger.focus();
        }
    };
    const show = async (trigger) => {
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
                        container: canvas, accessToken: token, style: 'mapbox://styles/mapbox/streets-v12', center: [2.2, 46.4], zoom: 3,
                        interactive: true, dragRotate: false, pitchWithRotate: false,
                        locale: { 'NavigationControl.ZoomIn': 'Zoomer', 'NavigationControl.ZoomOut': 'D\u00e9zoomer' },
                    });
                    map.addControl(new browser.mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
                    marker = new browser.mapboxgl.Marker({ color: '#be123c' }).setLngLat(center).addTo(map);
                    map.on('error', () => {
                        if (active) message.textContent = 'Le fond de carte ne peut pas \u00eatre charg\u00e9. R\u00e9essaie dans un instant.';
                    });
                } else {
                    marker.setLngLat(center);
                }
                map.resize();
                // Keep metropolitan France (including Corsica) visible; overseas sites extend the overview.
                map.fitBounds(dossierOverviewBounds(payload.coordinates), { padding: 16, duration: 0, maxZoom: 5 });
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
        trigger.addEventListener('click', () => {
            if (active === trigger) hide();
            else show(trigger);
        });
    });
    bind(triggers);
    root.addEventListener('techcalendar:dossiers-before-update', () => hide());
    root.addEventListener('techcalendar:dossiers-updated', () => bind(root.querySelectorAll('[data-dossier-map-url]')));
    close.addEventListener('click', () => hide(true));
    root.addEventListener('techcalendar:dossier-comments-open', () => hide());
    root.addEventListener('keydown', event => { if (event.key === 'Escape' && active) hide(popup.contains(root.activeElement)); });
    root.addEventListener('click', event => { if (!popup.contains(event.target) && !active?.contains(event.target)) hide(); });
    root.addEventListener('scroll', event => { if (!popup.contains(event.target)) hide(); }, true);
    browser.addEventListener('resize', () => hide());
}
