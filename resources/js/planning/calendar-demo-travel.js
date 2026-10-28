import { calendarDate, dayKey } from './calendar-model.js';

const valid = point => Number.isFinite(point?.lat) && Math.abs(point.lat) <= 90 && Number.isFinite(point?.lng) && Math.abs(point.lng) <= 180;
const pointOf = props => ({ lat: props?.latitude, lng: props?.longitude });

// Deliberately fictitious estimates for the isolated UI demo; never a routing provider.
export function demoTravelMetric(origin, destination) {
    if (!valid(origin) || !valid(destination)) return null;
    const radians = value => value * Math.PI / 180;
    const lat = radians(destination.lat - origin.lat), lng = radians(destination.lng - origin.lng);
    const haversine = Math.sin(lat / 2) ** 2 + Math.cos(radians(origin.lat)) * Math.cos(radians(destination.lat)) * Math.sin(lng / 2) ** 2;
    const distance = 12742 * Math.asin(Math.sqrt(Math.min(1, haversine))) * 1.25;
    return { distance_km: Math.round(distance * 10) / 10, duration_minutes: Math.max(5, Math.round(distance / 45 * 60 + 5)), source: 'estimation interne' };
}

export function demoTravelEvent(event, events, technician = {}) {
    if (!event.extendedProps?.is_suggestion) return event;
    const start = calendarDate(event.start);
    if (!start) return event;
    const props = event.extendedProps;
    const neighbors = events.filter(candidate => !candidate.extendedProps?.is_suggestion && calendarDate(candidate.start)
        && String(candidate.extendedProps?.technician_id) === String(props.technician_id)
        && dayKey(calendarDate(candidate.start)) === dayKey(start)).sort((left, right) => new Date(left.start) - new Date(right.start));
    const previous = neighbors.filter(candidate => new Date(candidate.start) < start).at(-1);
    const next = neighbors.find(candidate => new Date(candidate.start) > start);
    const home = pointOf(technician), destination = pointOf(props);
    const homeMetric = demoTravelMetric(home, destination);
    const before = demoTravelMetric(previous ? pointOf(previous.extendedProps) : home, destination);
    const after = demoTravelMetric(destination, next ? pointOf(next.extendedProps) : home);
    return { ...event, extendedProps: { ...props,
        has_previous_appointment: Boolean(previous), has_next_appointment: Boolean(next),
        origin_label: previous ? 'rdv précédent' : 'domicile', origin_name: previous?.extendedProps.customer_name || technician.address,
        origin_latitude: previous?.extendedProps.latitude ?? technician.latitude, origin_longitude: previous?.extendedProps.longitude ?? technician.longitude,
        home_to_distance_km: homeMetric?.distance_km, home_to_minutes: homeMetric?.duration_minutes,
        travel_to_distance_km: before?.distance_km, travel_to_minutes: before?.duration_minutes,
        travel_after_distance_km: after?.distance_km, travel_after_minutes: after?.duration_minutes,
    } };
}
