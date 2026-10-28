import { addCalendarDays, calendarRange } from './calendar-model.js';

export const demoTechnicians = [
    { id: '101', name: 'Camille Martin', area: 'Rhône · 69', address: 'Domicile fictif · Lyon', latitude: 45.76, longitude: 4.84 },
    { id: '102', name: 'Alex Moreau', area: 'Isère · 38', address: 'Domicile fictif · Grenoble', latitude: 45.19, longitude: 5.72 },
    { id: '103', name: 'Sarah Petit', area: 'Loire · 42', address: 'Domicile fictif · Saint-Étienne', latitude: 45.44, longitude: 4.39 },
    { id: '104', name: 'Thomas Laurent', area: 'Ain · 01', address: 'Domicile fictif · Bourg-en-Bresse', latitude: 46.2, longitude: 5.23 },
];

export const demoRequests = [
    { id: 'request-1', customer: 'Maison des Tilleuls', service: 'BAR EN 101 · Isolation des combles', duration: 40, address: '12 rue des Tilleuls', postal: '69003', city: 'Lyon', latitude: 45.75, longitude: 4.87 },
    { id: 'request-2', customer: 'Atelier des Lumières', service: 'BAT EQ 127 · Éclairage LED', duration: 60, address: '8 avenue des Arts', postal: '38000', city: 'Grenoble', latitude: 45.18, longitude: 5.73 },
    { id: 'request-3', customer: 'Résidence du Parc', service: 'BAR TH 145 · Rénovation globale', duration: 90, address: '25 rue du Parc', postal: '01000', city: 'Bourg-en-Bresse', latitude: 46.21, longitude: 5.24 },
];

export function fixtureEvent(id, day, hour, technician, request, suggestion = false) {
    const start = new Date(day);
    const [hours, minutes] = hour.split(':').map(Number);
    start.setHours(hours, minutes, 0, 0);
    return { id, title: request.customer, start: start.toISOString(), end: new Date(start.getTime() + request.duration * 60000).toISOString(),
        extendedProps: { technician_id: technician.id, technician_name: technician.name, customer_name: request.customer,
            service_label: request.service, duration_minutes: request.duration, address: request.address,
            postal_code: request.postal, city: request.city, latitude: request.latitude, longitude: request.longitude, is_suggestion: suggestion, status: 'scheduled' } };
}

export function calendarFixture(anchor = new Date()) {
    const monday = calendarRange(anchor).start;
    const events = [];
    const customers = [
        { ...demoRequests[0], customer: 'Julie Bernard', postal: '69100', city: 'Villeurbanne', latitude: 45.77, longitude: 4.89 },
        { ...demoRequests[1], customer: 'École des Marronniers', postal: '69500', city: 'Bron', duration: 45, latitude: 45.74, longitude: 4.92 },
        { ...demoRequests[0], customer: 'Pierre Dubois', postal: '69008', city: 'Lyon', duration: 30, latitude: 45.73, longitude: 4.87 },
        { ...demoRequests[2], customer: 'Copropriété les Acacias', postal: '01000', city: 'Bourg-en-Bresse', duration: 60, latitude: 46.2, longitude: 5.23 },
        { ...demoRequests[1], customer: 'Ferme des Coteaux', postal: '42100', city: 'Saint-Étienne', duration: 50, latitude: 45.43, longitude: 4.39 },
    ];
    demoTechnicians.forEach((technician, index) => {
        for (const offset of [0, 1, 2, 3, 4]) {
            if (index === 3 && offset > 2) continue;
            const customer = customers[(index + offset) % customers.length];
            events.push(fixtureEvent(`placed-${index}-${offset}`, addCalendarDays(monday, offset), index % 2 ? '10:15' : '09:00', technician, customer));
        }
    });
    events.push(fixtureEvent('short-visit', addCalendarDays(monday, 2), '09:45', demoTechnicians[0], customers[2]));
    events.push(fixtureEvent('afternoon-visit', addCalendarDays(monday, 2), '14:00', demoTechnicians[1], customers[3]));
    return { monday, events };
}

export function fixtureSuggestions(monday, request) {
    return demoTechnicians.flatMap((technician, index) => [1, 2, 3, 4].map(offset =>
        fixtureEvent(`suggestion-${technician.id}-${offset}`, addCalendarDays(monday, offset), index % 2 ? '14:30' : '11:00', technician, request, true)));
}
