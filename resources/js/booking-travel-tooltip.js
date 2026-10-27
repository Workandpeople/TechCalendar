const coordinate = value => value === null || value === undefined || value === '' ? NaN : Number(value);
const point = (lat, lng) => ({ lat: coordinate(lat), lng: coordinate(lng) });
const valid = value => Number.isFinite(value?.lat) && Math.abs(value.lat) <= 90 && Number.isFinite(value?.lng) && Math.abs(value.lng) <= 180;
const day = date => `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
const dateOf = value => { const date = new Date(value); return value && Number.isFinite(date.getTime()) ? date : null; };

export function bookingTravelRows(event, events = [], technician = {}) {
    const props = event.extendedProps || {};
    const suggested = Boolean(props.is_suggestion);
    const target = suggested ? 'proposition' : 'RDV';
    const home = point(props.technician_latitude ?? technician.latitude, props.technician_longitude ?? technician.longitude);
    const destination = point(props.latitude, props.longitude);
    let previous = Boolean(props.has_previous_appointment) || String(props.origin_label || '').toLowerCase().includes('rdv');
    let next = Boolean(props.has_next_appointment || props.next_appointment_id);
    let before = { point: point(props.origin_latitude, props.origin_longitude), name: props.origin_name };
    let after = { point: null, name: null };
    if (!suggested) {
        const start = dateOf(event.start);
        const neighbors = start ? events.filter(candidate => {
            const candidateStart = dateOf(candidate.start);
            return String(candidate.id) !== String(event.id) && !candidate.extendedProps?.is_suggestion && candidateStart
                && String(candidate.extendedProps?.technician_id) === String(props.technician_id) && day(candidateStart) === day(start);
        }).sort((left, right) => new Date(left.start) - new Date(right.start)) : [];
        const previousEvent = neighbors.filter(candidate => new Date(candidate.start) < start).at(-1);
        const nextEvent = neighbors.find(candidate => new Date(candidate.start) > start);
        previous = Boolean(previousEvent);
        next = Boolean(nextEvent);
        const fromEvent = candidate => ({ point: point(candidate?.extendedProps?.latitude, candidate?.extendedProps?.longitude),
            name: candidate?.extendedProps?.customer_name || candidate?.extendedProps?.address });
        before = fromEvent(previousEvent);
        after = fromEvent(nextEvent);
    }
    const homeAddress = props.technician_address || technician.address || 'Domicile du technicien non renseigné';
    const metric = (distance, duration) => ({ distance_km: distance, duration_minutes: duration });
    const rows = [{ title: `Domicile → ${target}`, detail: homeAddress, from: home, to: destination,
        metric: suggested ? metric(props.home_to_distance_km ?? props.travel_to_distance_km, props.home_to_minutes ?? props.travel_to_minutes) : null }];
    if (previous) rows.push({ title: `RDV précédent → ${target}`, detail: before.name || 'RDV précédent', from: before.point, to: destination,
        metric: suggested ? metric(props.travel_to_distance_km, props.travel_to_minutes) : null });
    rows.push({ title: next ? `${suggested ? 'Proposition' : 'RDV'} → prochain RDV` : `${suggested ? 'Proposition' : 'RDV'} → retour domicile`,
        detail: next ? (after.name || 'Suite du planning') : homeAddress, from: destination, to: next ? after.point : home,
        metric: suggested ? metric(next ? props.travel_after_distance_km : (props.return_home_distance_km ?? props.travel_after_distance_km),
            next ? props.travel_after_minutes : (props.return_home_minutes ?? props.travel_after_minutes)) : null });
    return rows;
}

export function bookingTravelMetric(metric) {
    const distance = coordinate(metric?.distance_km), minutes = coordinate(metric?.duration_minutes);
    if (!Number.isFinite(distance) || distance < 0 || !Number.isFinite(minutes) || minutes < 0) return 'Indisponible';
    const rounded = Math.round(minutes), hours = Math.floor(rounded / 60), remainder = rounded % 60;
    const duration = hours ? `${hours}h${remainder ? String(remainder).padStart(2, '0') : ''}` : `${rounded} min`;
    return `${metric.source === 'estimation interne' ? 'Estimation · ' : ''}${distance.toFixed(1)} km · ${duration}`;
}

export function createBookingTravelTooltip({ root = document, browser = window, routeBetween, getEvents, getTechnician }) {
    const tooltip = root.createElement('div');
    tooltip.id = 'booking-travel-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.style.cssText = 'position:fixed;z-index:90;pointer-events:none;width:min(360px,calc(100vw - 16px));border-radius:16px;padding:12px 14px;background:#31424c;color:#fff;box-shadow:0 18px 45px rgba(15,23,42,.28);font-size:12px;line-height:1.45';
    tooltip.hidden = true;
    root.body.append(tooltip);
    const cache = new Map();
    let current = null, pointer = { clientX: 0, clientY: 0 };
    const move = position => {
        pointer = { clientX: position.clientX, clientY: position.clientY };
        const box = tooltip.getBoundingClientRect();
        const viewportWidth = root.documentElement.clientWidth, viewportHeight = browser.innerHeight;
        tooltip.style.left = `${Math.max(8, Math.min(pointer.clientX - box.width / 2, viewportWidth - box.width - 8))}px`;
        const above = pointer.clientY - box.height - 14;
        tooltip.style.top = `${Math.max(8, above >= 8 ? above : Math.min(pointer.clientY + 14, viewportHeight - box.height - 8))}px`;
    };
    const hide = () => {
        if (current) {
            browser.clearTimeout(current.debounce);
            browser.clearTimeout(current.timeout);
            current.controller.abort();
            if (current.element) {
                if (current.description === null) current.element.removeAttribute('aria-describedby');
                else current.element.setAttribute('aria-describedby', current.description);
            }
        }
        current = null;
        tooltip.hidden = true;
    };
    const render = (event, rows, technicianName, loading = false) => {
        tooltip.replaceChildren();
        const text = (parent, value, style) => { const node = root.createElement('p'); node.textContent = value; node.style.cssText = style; parent.append(node); };
        text(tooltip, event.extendedProps?.is_suggestion ? 'Distances de la proposition' : 'Trajets du rendez-vous', 'font-weight:800');
        text(tooltip, `Technicien : ${technicianName}`, 'margin-top:3px;color:rgba(255,255,255,.85)');
        text(tooltip, `Fiche : ${event.extendedProps?.service_label || 'Non renseignée'}`, 'margin-top:3px;color:rgba(255,255,255,.85)');
        for (const row of rows) {
            const section = root.createElement('div');
            section.style.cssText = 'margin-top:10px;border-top:1px solid rgba(255,255,255,.18);padding-top:8px';
            text(section, row.title, 'font-weight:700');
            text(section, row.detail, 'margin-top:3px;color:rgba(255,255,255,.72)');
            text(section, loading && valid(row.from) && valid(row.to) ? 'Calcul du trajet…' : bookingTravelMetric(row.metric), 'margin-top:5px');
            tooltip.append(section);
        }
        tooltip.hidden = false;
        move(pointer);
    };
    const show = (position, event, element = null) => {
        hide();
        pointer = position;
        const controller = new AbortController();
        const request = { controller, element, description: element?.getAttribute('aria-describedby') ?? null };
        current = request;
        element?.setAttribute('aria-describedby', [request.description, tooltip.id].filter(Boolean).join(' '));
        const props = event.extendedProps || {};
        const technician = getTechnician?.(props.technician_id) || {};
        const name = props.technician_full_name || props.technician_name || technician.name || 'Non renseigné';
        const rows = bookingTravelRows(event, getEvents?.() || [], technician);
        if (props.is_suggestion) { render(event, rows, name); return; }
        render(event, rows, name, true);
        request.debounce = browser.setTimeout(async () => {
            const metrics = rows.map(async row => {
                if (!valid(row.from) || !valid(row.to)) return null;
                const key = `${row.from.lng},${row.from.lat};${row.to.lng},${row.to.lat}`;
                const cached = cache.get(key);
                if (cached && Date.now() - cached.at < 15 * 60 * 1000) return cached.metric;
                try {
                    const metric = await routeBetween(row.from, row.to, controller.signal);
                    if (!controller.signal.aborted) {
                        if (cache.size >= 128) cache.delete(cache.keys().next().value);
                        cache.set(key, { metric, at: Date.now() });
                    }
                    return metric;
                } catch { return null; }
            });
            const result = await Promise.race([Promise.all(metrics), new Promise(resolve => {
                request.timeout = browser.setTimeout(() => { controller.abort(); resolve(rows.map(() => null)); }, 10000);
            })]);
            browser.clearTimeout(request.timeout);
            if (current === request) render(event, rows.map((row, index) => ({ ...row, metric: result[index] })), name);
        }, 150);
    };
    root.addEventListener('scroll', hide, true);
    root.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    browser.addEventListener('blur', hide);
    browser.addEventListener('pagehide', hide);
    browser.addEventListener('resize', hide);
    return { show, move, hide };
}
