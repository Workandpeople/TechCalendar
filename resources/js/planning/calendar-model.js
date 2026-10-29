import { cofracLabel } from '../cofrac-label.js';
import { parseAppointmentDate, formatAppointmentInput } from '../appointment-date-time.js';

export const CALENDAR_MODES = ['timeGridWeek', 'timeGridDay', 'dayGridMonth', 'listWeek', 'resourceWeek'];

export function calendarDate(value) {
    if (value == null || value === '') return null;
    return parseAppointmentDate(value);
}

export function dayKey(date) {
    return formatAppointmentInput(date).slice(0, 10);
}

export function calendarMinute(date) {
    const input = formatAppointmentInput(date);
    return Number(input.slice(11, 13)) * 60 + Number(input.slice(14, 16));
}

export function calendarDay(date) {
    return calendarDate(dayKey(date));
}

export function addCalendarDays(value, count) {
    const date = new Date(`${dayKey(calendarDate(value))}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + count);
    return calendarDate(date.toISOString().slice(0, 10));
}

export function calendarRange(value, mode = 'timeGridWeek', hiddenDays = [0]) {
    const anchor = calendarDate(value);
    if (!anchor) throw new TypeError('Date de calendrier invalide.');
    let start = calendarDay(anchor);
    let length = 1;
    if (mode === 'dayGridMonth') {
        start = calendarDate(`${dayKey(start).slice(0, 7)}-01`);
        start = addCalendarDays(start, -((new Date(`${dayKey(start)}T12:00:00Z`).getUTCDay() + 6) % 7));
        length = 42;
    } else if (mode !== 'timeGridDay') {
        start = addCalendarDays(start, -((new Date(`${dayKey(start)}T12:00:00Z`).getUTCDay() + 6) % 7));
        length = 7;
    }
    const days = Array.from({ length }, (_, index) => addCalendarDays(start, index))
        .filter(day => mode === 'timeGridDay' || !hiddenDays.includes(new Date(`${dayKey(day)}T12:00:00Z`).getUTCDay()));
    return { start, end: addCalendarDays(start, length), days };
}

export function calendarFields(event) {
    const props = event.extendedProps || {};
    // Booking's existing feed sometimes carries location only in the full address.
    const location = String(props.address || '').replace(/,?\s*France\s*$/i, '').match(/(?:^|[\s,])(\d{5})\s+([^,]+)$/);
    return {
        technicianId: String(props.technician_id ?? 'unassigned'),
        technician: props.technician_full_name || props.technician_name || 'Technicien non renseigné',
        customer: props.customer_name || [props.customer_first_name, props.customer_last_name].filter(Boolean).join(' ') || event.title || 'Client non renseigné',
        service: cofracLabel(props.service_label || props.service_name || props.service_type || 'Fiche non renseignée'),
        postal: String(props.postal_code || location?.[1] || '').trim(),
        city: props.city || location?.[2]?.trim() || props.location_label || '',
        address: props.address || 'Adresse non renseignée',
        suggestion: Boolean(props.is_suggestion),
        historical: Boolean(props.is_historical),
    };
}

export function normalizeCalendarEvent(raw, fallbackId = '') {
    const start = calendarDate(raw.start);
    if (!start) return null;
    const candidateEnd = calendarDate(raw.end);
    const duration = Number(raw.extendedProps?.duration_minutes);
    const end = candidateEnd && candidateEnd > start ? candidateEnd
        : new Date(start.getTime() + (Number.isFinite(duration) && duration > 0 ? duration : 60) * 60000);
    return { ...raw, id: String(raw.id ?? fallbackId), title: String(raw.title || ''), start, end, extendedProps: { ...(raw.extendedProps || {}) } };
}

export function eventsDuring(events, start, end) {
    return events.filter(event => event.start < end && event.end > start)
        .sort((left, right) => left.start - right.start || left.end - right.end || left.id.localeCompare(right.id));
}

export function timelineLayout(events, day, { minMinute = 420, maxMinute = 1260, pixelsPerHour = 120, minCardWidth = 216, gap = 10 } = {}) {
    const midnight = calendarDay(day);
    const tomorrow = addCalendarDays(midnight, 1);
    const items = eventsDuring(events, midnight, tomorrow);
    const minute = calendarMinute;
    const spans = items.map(event => ({ event, start: event.start < midnight ? 0 : minute(event.start),
        end: event.end >= tomorrow ? 1440 : minute(event.end) }));
    const from = Math.max(0, Math.min(minMinute, ...spans.map(item => Math.floor(item.start / 60) * 60)));
    const to = Math.min(1440, Math.max(maxMinute, ...spans.map(item => Math.ceil(item.end / 60) * 60)));
    const lanes = [];
    const placements = spans.map(item => {
        const left = (item.start - from) / 60 * pixelsPerHour;
        const durationWidth = Math.max(2, (item.end - item.start) / 60 * pixelsPerHour);
        const width = Math.max(minCardWidth, durationWidth);
        let lane = lanes.findIndex(end => end <= left);
        if (lane < 0) lane = lanes.length;
        lanes[lane] = left + width + gap;
        return { ...item, left, width, durationWidth, lane };
    });
    return { placements, lanes: Math.max(1, lanes.length), from, to, width: (to - from) / 60 * pixelsPerHour + minCardWidth };
}

export function overlappingAppointments(events, candidate) {
    const id = String(candidate.extendedProps?.technician_id ?? '');
    return events.filter(event => event.id !== candidate.id && !event.extendedProps?.is_suggestion
        && String(event.extendedProps?.technician_id ?? '') === id && event.start < candidate.end && event.end > candidate.start);
}
