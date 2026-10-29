export const APPOINTMENT_TIMEZONE = 'Europe/Paris';

const partsFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: APPOINTMENT_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
});
const pad = value => String(value).padStart(2, '0');
const partsAt = date => Object.fromEntries(partsFormatter.formatToParts(date)
    .filter(part => part.type !== 'literal').map(part => [part.type, Number(part.value)]));
const utcValue = parts => Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour || 0, parts.minute || 0, parts.second || 0);
const offsetAt = date => utcValue(partsAt(date)) - Math.floor(date.getTime() / 1000) * 1000;
const datePattern = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?)?$/;
const displayFormatters = new Map();

export function parseAppointmentDate(value) {
    if (value instanceof Date) return Number.isFinite(value.getTime()) ? new Date(value) : null;
    if (typeof value !== 'string') return null;
    const match = value.match(datePattern);
    if (!match) return null;
    const [, year, month, day, hour = '00', minute = '00', second = '00', fraction = '', offset] = match;
    const parts = { year: Number(year), month: Number(month), day: Number(day), hour: Number(hour), minute: Number(minute), second: Number(second) };
    const wallTime = utcValue(parts);
    const wallDate = new Date(wallTime);
    if (parts.year < 100 || wallDate.getUTCFullYear() !== parts.year || wallDate.getUTCMonth() + 1 !== parts.month
        || wallDate.getUTCDate() !== parts.day || parts.hour > 23 || parts.minute > 59 || parts.second > 59) return null;
    if (offset) {
        if (offset !== 'Z') {
            const digits = offset.slice(1).replace(':', '');
            if (Number(digits.slice(0, 2)) > 14 || Number(digits.slice(2)) > 59
                || (Number(digits.slice(0, 2)) === 14 && Number(digits.slice(2)) !== 0)) return null;
        }
        const date = new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}${fraction}${offset}`);
        return Number.isFinite(date.getTime()) ? date : null;
    }
    // Try date-specific offsets on both sides of DST, never the browser's offset.
    const offsets = new Set([-86400000, 0, 86400000].map(delta => offsetAt(new Date(wallTime + delta))));
    const candidates = [...offsets].map(offsetMs => new Date(wallTime - offsetMs))
        .filter(date => utcValue(partsAt(date)) === wallTime).sort((left, right) => right - left);
    // As with PHP's Paris parser, the repeated autumn hour uses standard time.
    return candidates[0] ? new Date(candidates[0].getTime() + Number(fraction || 0) * 1000) : null;
}

export function formatAppointmentInput(value) {
    const date = parseAppointmentDate(value);
    if (!date) return '';
    const parts = partsAt(date);
    return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}T${pad(parts.hour)}:${pad(parts.minute)}`;
}

export function serializeAppointmentInput(value) {
    const date = parseAppointmentDate(value);
    if (!date) throw new RangeError('Horaire invalide ou inexistant en Europe/Paris (changement d\'heure).');
    const parts = partsAt(date);
    const offsetMinutes = offsetAt(date) / 60000;
    const offset = `${offsetMinutes < 0 ? '-' : '+'}${pad(Math.floor(Math.abs(offsetMinutes) / 60))}:${pad(Math.abs(offsetMinutes) % 60)}`;
    return `${formatAppointmentInput(date)}:${pad(parts.second)}${offset}`;
}

export function formatAppointmentDateTime(value, options = {}) {
    const date = parseAppointmentDate(value);
    if (!date) return '-';
    const key = JSON.stringify(options);
    if (!displayFormatters.has(key)) {
        if (displayFormatters.size >= 32) displayFormatters.clear();
        displayFormatters.set(key, new Intl.DateTimeFormat('fr-FR', { ...options, timeZone: APPOINTMENT_TIMEZONE }));
    }
    return displayFormatters.get(key).format(date);
}
