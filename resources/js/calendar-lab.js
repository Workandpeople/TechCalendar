import '../css/calendar-lab.css';
import { NativeCalendar } from './planning/native-calendar.js';
import { calendarDate, calendarFields, dayKey, normalizeCalendarEvent, overlappingAppointments } from './planning/calendar-model.js';
import { calendarFixture, demoRequests, demoTechnicians, fixtureSuggestions } from './planning/calendar-fixtures.js';
import { createBookingTravelTooltip } from './booking-travel-tooltip.js';
import { demoTravelEvent, demoTravelMetric } from './planning/calendar-demo-travel.js';

const byId = id => document.getElementById(id);
const node = (tag, className = '', text = '') => { const element = document.createElement(tag); element.className = className; element.textContent = text; return element; };
const fixture = calendarFixture();
let events = fixture.events;
let selected = demoRequests[0];
let mode = 'booking';
let detailEvent = null;
let returnFocus = null;
let toastTimer;
const selectedTechs = new Set(demoTechnicians.slice(0, 3).map(tech => tech.id));
const dialog = byId('lab-detail');
const technicianById = id => demoTechnicians.find(technician => technician.id === String(id));
const travelTooltip = createBookingTravelTooltip({
    routeBetween: async (origin, destination) => demoTravelMetric(origin, destination),
    getEvents: () => calendar.getEvents(), getTechnician: technicianById,
    disclaimer: 'Démo locale : temps et distances fictifs, sans appel Mapbox.',
});
const showTravel = ({ event, el, jsEvent }) => {
    const box = el.getBoundingClientRect();
    const position = jsEvent || { clientX: box.left + box.width / 2, clientY: box.top };
    travelTooltip.show(position, demoTravelEvent(event, calendar.getEvents(), technicianById(event.extendedProps.technician_id)), el);
};
const clearTravelHandlers = ({ el }) => {
    window.cancelAnimationFrame(el._labTravelFocusFrame);
    el.removeEventListener('mousemove', el._labTravelMove);
    el.removeEventListener('focus', el._labTravelFocus);
    el.removeEventListener('blur', travelTooltip.hide);
    delete el._labTravelMove;
    delete el._labTravelFocus;
    delete el._labTravelFocusFrame;
    travelTooltip.hide();
};

function notify(message) {
    clearTimeout(toastTimer);
    byId('lab-toast').textContent = message;
    byId('lab-toast').hidden = false;
    toastTimer = setTimeout(() => { byId('lab-toast').hidden = true; }, 6000);
}

function clearConflict() {
    byId('lab-conflict').hidden = true;
    byId('lab-force-label').hidden = true;
    byId('lab-force').checked = false;
}

function openDetail(event, trigger = null) {
    travelTooltip.hide();
    detailEvent = event;
    returnFocus = trigger;
    const fields = calendarFields(event);
    byId('lab-detail-title').textContent = fields.customer;
    byId('lab-detail-subtitle').textContent = `${fields.service} · ${fields.address}, ${fields.postal} ${fields.city}`;
    byId('lab-detail-tech').value = fields.technicianId;
    byId('lab-detail-date').value = dayKey(event.start);
    byId('lab-detail-time').value = `${String(event.start.getHours()).padStart(2, '0')}:${String(event.start.getMinutes()).padStart(2, '0')}`;
    byId('lab-detail-duration').value = Math.round((event.end - event.start) / 60000);
    byId('lab-detail-save').textContent = fields.suggestion ? 'Placer ce RDV fictif' : 'Enregistrer la modification';
    byId('lab-cancel-appointment').hidden = fields.suggestion;
    clearConflict();
    dialog.showModal();
    byId('lab-detail-close').focus();
}

const calendar = new NativeCalendar(byId('calendar-lab'), {
    initialDate: new Date(), initialView: window.innerWidth < 720 ? 'listWeek' : 'timeGridWeek',
    nativeResources: () => demoTechnicians.filter(tech => selectedTechs.has(tech.id)),
    eventMouseEnter: info => {
        info.el._labTravelMove = event => travelTooltip.move(event);
        info.el.addEventListener('mousemove', info.el._labTravelMove);
        showTravel(info);
    },
    eventMouseLeave: ({ el }) => {
        el.removeEventListener('mousemove', el._labTravelMove);
        delete el._labTravelMove;
        travelTooltip.hide();
    },
    eventDidMount: info => {
        info.el.removeAttribute('title');
        info.el._labTravelFocus = () => {
            info.el._labTravelFocusFrame = window.requestAnimationFrame(() => {
                if (document.activeElement === info.el) showTravel(info);
            });
        };
        info.el.addEventListener('focus', info.el._labTravelFocus);
        info.el.addEventListener('blur', travelTooltip.hide);
    },
    eventWillUnmount: clearTravelHandlers,
    datesSet: travelTooltip.hide,
    eventClick: ({ event, el }) => openDetail(event, el),
    dateClick: ({ date, resource }) => openDetail(normalizeCalendarEvent({
        id: `draft-${Date.now()}`, title: selected.customer, start: date,
        end: new Date(date.getTime() + selected.duration * 60000),
        extendedProps: { technician_id: resource.id, technician_name: resource.name,
            customer_name: selected.customer, service_label: selected.service, address: selected.address,
            postal_code: selected.postal, city: selected.city, latitude: selected.latitude, longitude: selected.longitude, is_suggestion: true },
    })),
});

function refresh() {
    calendar.options.dateClick = mode === 'booking' ? calendar.options.nativeDateClick : undefined;
    const suggestions = mode === 'booking' && byId('lab-suggestions').checked ? fixtureSuggestions(fixture.monday, selected) : [];
    calendar.removeAllEvents();
    calendar.addEventSource([...events, ...suggestions].filter(event => selectedTechs.has(String(event.extendedProps.technician_id))));
    const banner = byId('lab-selected');
    banner.replaceChildren();
    const text = node('div');
    text.append(node('p', '', mode === 'booking' ? 'DOSSIER SÉLECTIONNÉ' : 'VUE D’ENSEMBLE'), node('strong', '', mode === 'booking' ? selected.customer : 'Les rendez-vous de l’équipe'),
        node('p', '', mode === 'booking' ? `${selected.service} · ${selected.postal} ${selected.city}` : 'Cliquez sur un RDV pour le consulter ou essayer une modification.'));
    banner.append(text, node('span', '', mode === 'booking' ? `${selected.duration} min à placer` : 'Lecture claire, sans survol'));
}
calendar.options.nativeDateClick = calendar.options.dateClick;

function renderRequests() {
    byId('lab-requests').replaceChildren();
    for (const request of demoRequests) {
        const button = node('button', 'cl-request');
        button.type = 'button';
        button.setAttribute('aria-pressed', String(request.id === selected.id));
        button.append(node('strong', '', request.customer), node('span', '', request.service), node('small', '', `${request.postal} ${request.city} · ${request.duration} min`));
        button.addEventListener('click', () => { selected = request; renderRequests(); refresh(); });
        byId('lab-requests').append(button);
    }
}

for (const technician of demoTechnicians) {
    const label = node('label', 'cl-tech');
    const input = node('input'); input.type = 'checkbox'; input.value = technician.id; input.checked = selectedTechs.has(technician.id);
    const text = node('span', '', technician.name); text.append(node('small', '', technician.area)); label.append(input, text);
    input.addEventListener('change', () => { if (input.checked) selectedTechs.add(input.value); else selectedTechs.delete(input.value); refresh(); });
    byId('lab-technicians').append(label);
    const option = node('option', '', technician.name); option.value = technician.id; byId('lab-detail-tech').append(option);
}

function setMode(value) {
    mode = value;
    document.querySelector('.calendar-local-preview h1').textContent = mode === 'booking' ? 'Prise de RDV' : 'Les RDV';
    document.querySelectorAll('[data-lab-mode]').forEach(tab => tab.setAttribute('aria-pressed', String(tab.dataset.labMode === mode)));
    byId('lab-booking-panel').hidden = mode !== 'booking';
    byId('lab-workflow').hidden = mode !== 'booking';
    byId('lab-suggestions').closest('label').hidden = mode !== 'booking';
    document.querySelector('.gc-workspace-location span').textContent = mode === 'booking' ? 'Prendre un RDV' : 'Les RDV';
    for (const link of document.querySelectorAll('.app-sidebar a')) {
        const path = new URL(link.href).pathname;
        link.classList.toggle('cl-nav-active', mode === 'booking' ? path === '/planner/book' : path === '/planner/tracking');
    }
    refresh();
}
document.querySelectorAll('[data-lab-mode]').forEach(button => button.addEventListener('click', () => setMode(button.dataset.labMode)));
document.querySelectorAll('.app-sidebar a, #crm-mobile-navigation a').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    const path = new URL(link.href).pathname;
    if (path === '/planner/book') setMode('booking');
    else if (path === '/planner/tracking') setMode('tracking');
    else notify('Cette rubrique n’est pas active dans l’essai fictif. Aucun accès à la base réelle.');
}));
byId('lab-suggestions').addEventListener('change', refresh);
byId('lab-techs-all').addEventListener('click', () => {
    for (const input of byId('lab-technicians').querySelectorAll('input')) { input.checked = true; selectedTechs.add(input.value); }
    refresh();
});
byId('lab-reset').addEventListener('click', () => { events = calendarFixture().events; refresh(); notify('Démo réinitialisée. Aucune donnée réelle modifiée.'); });
byId('lab-detail-close').addEventListener('click', () => dialog.close());
dialog.addEventListener('close', () => { if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true }); });
byId('lab-detail-form').addEventListener('input', event => { if (event.target.id !== 'lab-force') clearConflict(); });
byId('lab-detail-form').addEventListener('submit', event => {
    event.preventDefault();
    if (!detailEvent) return;
    const start = calendarDate(byId('lab-detail-date').value);
    const [hour, minute] = byId('lab-detail-time').value.split(':').map(Number);
    start.setHours(hour, minute, 0, 0);
    const technician = demoTechnicians.find(tech => tech.id === byId('lab-detail-tech').value);
    const candidate = normalizeCalendarEvent({ ...detailEvent, start, end: new Date(start.getTime() + Number(byId('lab-detail-duration').value) * 60000),
        extendedProps: { ...detailEvent.extendedProps, technician_id: technician.id, technician_name: technician.name, is_suggestion: false } });
    const conflicts = overlappingAppointments(events.map(raw => normalizeCalendarEvent(raw)), candidate);
    if (conflicts.length && !byId('lab-force').checked) {
        byId('lab-conflict').textContent = `Chevauchement avec ${conflicts.map(item => calendarFields(item).customer).join(', ')}. Démo : seul le chevauchement horaire est simulé ici. Les trajets, absences et habilitations restent validés par le serveur dans les vraies pages.`;
        byId('lab-conflict').hidden = false; byId('lab-force-label').hidden = false; byId('lab-force').focus(); return;
    }
    events = [...events.filter(item => String(item.id) !== candidate.id), candidate];
    refresh(); dialog.close(); notify('Rendez-vous enregistré dans cette démo uniquement. Aucun envoi ni changement en base.');
});
byId('lab-cancel-appointment').addEventListener('click', () => {
    if (!detailEvent) return;
    events = events.filter(item => String(item.id) !== detailEvent.id);
    refresh(); dialog.close(); notify('RDV fictif remis à placer. La production est inchangée.');
});

calendar.render();
renderRequests();
refresh();
setMode('booking');
window.addEventListener('pagehide', event => { travelTooltip.hide(); if (!event.persisted) calendar.destroy(); clearTimeout(toastTimer); });
