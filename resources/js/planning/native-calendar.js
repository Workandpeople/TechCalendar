import { addCalendarDays, calendarDate, calendarFields, calendarRange, CALENDAR_MODES, dayKey, eventsDuring, normalizeCalendarEvent, timelineLayout } from './calendar-model.js';

const time = date => date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
const palette = ['#1764d9', '#008574', '#bd5719', '#ac3e58', '#416b97', '#697512'];
const tone = id => palette[[...String(id)].reduce((sum, char) => sum + char.charCodeAt(0), 0) % palette.length];

// Local calendar engine. Server-side authorization and placement validation remain unchanged.
export class NativeCalendar {
    constructor(element, options = {}) {
        this.element = element;
        this.document = element.ownerDocument;
        this.browser = this.document.defaultView;
        this.options = options;
        this.date = calendarDate(options.initialDate) || new Date();
        this.mode = CALENDAR_MODES.includes(options.initialView) ? options.initialView : 'timeGridWeek';
        this.events = [];
        this.mounted = [];
        this.expandedCells = new Set();
        this.requestId = 0;
        this.rendered = false;
        this.destroyed = false;
        this.sequence = 0;
        this.zoom = 120;
        this.controller = new AbortController();
        this.setEvents(Array.isArray(options.events) ? options.events : []);
    }

    node(tag, className = '', text = '') {
        const node = this.document.createElement(tag);
        node.className = className;
        node.textContent = String(text ?? '');
        return node;
    }

    button(label, handler, className = '', title = label) {
        const button = this.node('button', `nc-button ${className}`, label);
        button.type = 'button';
        button.title = title;
        button.setAttribute('aria-label', title);
        button.addEventListener('click', handler);
        return button;
    }

    render() {
        if (this.rendered || this.destroyed) return;
        this.rendered = true;
        this.element.classList.add('nc-calendar');
        this.element.setAttribute('aria-label', 'Calendrier des rendez-vous');
        this.toolbar = this.node('div', 'nc-toolbar');
        const navigation = this.node('div', 'nc-navigation');
        navigation.append(this.button('‹', () => this.navigate(-1), '', 'Période précédente'), this.button('›', () => this.navigate(1), '', 'Période suivante'),
            this.button('Aujourd’hui', () => this.gotoDate(new Date())));
        this.heading = this.node('h2', 'nc-title');
        this.heading.setAttribute('aria-live', 'polite');
        this.views = this.node('div', 'nc-views');
        this.views.setAttribute('role', 'group');
        this.views.setAttribute('aria-label', 'Affichage du calendrier');
        for (const [mode, label] of [['timeGridWeek', 'Semaine'], ['timeGridDay', 'Jour'], ['dayGridMonth', 'Mois'], ['listWeek', 'Liste'], ['resourceWeek', 'Par tech.']]) {
            const button = this.button(label, () => this.changeView(mode));
            button.dataset.view = mode;
            this.views.append(button);
        }
        this.expandButton = this.button('Agrandir', () => this.toggleExpanded(), 'nc-expand');
        this.toolbar.append(navigation, this.heading, this.views, this.expandButton);
        if (typeof this.options.events === 'function') this.toolbar.append(this.button('Actualiser', () => this.refetchEvents()));
        this.subbar = this.node('div', 'nc-subbar');
        this.summary = this.node('p', 'nc-summary');
        this.zoomControls = this.node('div', 'nc-zoom');
        this.zoomControls.append(this.node('span', '', 'Échelle horaire'), this.button('−', () => this.setZoom(this.zoom - 30), '', 'Réduire l’échelle horaire'),
            this.button('+', () => this.setZoom(this.zoom + 30), '', 'Agrandir l’échelle horaire'));
        this.subbar.append(this.summary, this.zoomControls);
        this.status = this.node('p', 'nc-fetch-status');
        this.status.setAttribute('role', 'status');
        this.status.hidden = true;
        this.viewport = this.node('div', 'nc-viewport');
        this.viewport.tabIndex = 0;
        this.viewport.setAttribute('aria-label', 'Planning défilant, utilisez les flèches ou le défilement horizontal');
        this.footer = this.node('p', 'nc-footer', 'Les cartes sont classées par heure de début. Leur hauteur s’adapte au contenu ; les horaires indiquent la durée réelle.');
        this.element.replaceChildren(this.toolbar, this.subbar, this.status, this.viewport, this.footer);
        this.browser.addEventListener('keydown', event => {
            if (event.key === 'Escape' && this.expanded) this.toggleExpanded(false);
        }, { signal: this.controller.signal });
        this.draw();
        this.notifyDates();
        if (typeof this.options.events === 'function') this.refetchEvents();
        else this.options.eventsSet?.(this.events);
    }

    get view() {
        const range = calendarRange(this.date, this.mode, this.options.hiddenDays || [0]);
        return { type: this.mode, currentStart: range.start, currentEnd: range.end, activeStart: range.start, activeEnd: range.end, calendar: this };
    }

    dateInfo() {
        const { currentStart: start, currentEnd: end } = this.view;
        return { start, end, startStr: start.toISOString(), endStr: end.toISOString(), timeZone: 'local', view: this.view };
    }

    notifyDates() { this.options.datesSet?.(this.dateInfo()); }

    navigate(direction) {
        const date = new Date(this.date);
        if (this.mode === 'dayGridMonth') date.setMonth(date.getMonth() + direction, 1);
        else date.setDate(date.getDate() + direction * (this.mode === 'timeGridDay' ? 1 : 7));
        this.gotoDate(date);
    }

    gotoDate(value) {
        const date = calendarDate(value);
        if (!date || this.destroyed) return;
        const previous = this.view.currentStart.getTime();
        this.date = date;
        if (!this.rendered) return;
        this.draw();
        if (previous !== this.view.currentStart.getTime()) {
            this.notifyDates();
            if (typeof this.options.events === 'function') this.refetchEvents();
        }
    }

    getDate() { return new Date(this.date); }

    changeView(mode, date = this.date) {
        if (!CALENDAR_MODES.includes(mode) || this.destroyed) return;
        this.mode = mode;
        this.date = calendarDate(date) || this.date;
        this.draw();
        this.notifyDates();
        if (typeof this.options.events === 'function') this.refetchEvents();
    }

    setZoom(value) { this.zoom = Math.min(240, Math.max(90, value)); this.draw(); }

    setEvents(rawEvents) {
        this.events = rawEvents.map(raw => this.event(raw)).filter(Boolean);
    }

    event(raw) {
        const event = normalizeCalendarEvent(raw, `native-${++this.sequence}`);
        if (!event) return null;
        event.setProp = (key, value) => { if (['title', 'backgroundColor', 'borderColor', 'textColor', 'classNames'].includes(key)) event[key] = value; this.scheduleDraw(); };
        event.setExtendedProp = (key, value) => { event.extendedProps[key] = value; this.scheduleDraw(); };
        event.remove = () => { this.events = this.events.filter(item => item !== event); this.scheduleDraw(); };
        return event;
    }

    getEvents() { return [...this.events]; }
    getEventById(id) { return this.events.find(event => event.id === String(id)) || null; }
    removeAllEvents() { this.events = []; this.scheduleDraw(); }
    addEventSource(events) {
        if (!Array.isArray(events)) return;
        const additions = events.map(raw => this.event(raw)).filter(Boolean);
        const ids = new Set(additions.map(event => event.id));
        this.events = [...this.events.filter(event => !ids.has(event.id)), ...additions];
        this.scheduleDraw();
        this.options.eventsSet?.(this.events);
    }

    scheduleDraw() {
        if (!this.rendered || this.destroyed || this.frame) return;
        this.frame = this.browser.requestAnimationFrame(() => { this.frame = null; this.draw(); });
    }

    async refetchEvents() {
        if (typeof this.options.events !== 'function' || this.destroyed) return;
        const id = ++this.requestId;
        this.status.textContent = 'Actualisation du planning…';
        this.status.hidden = false;
        this.viewport.setAttribute('aria-busy', 'true');
        let settled = false;
        const complete = (events, error = null) => {
            if (settled || id !== this.requestId || this.destroyed) return;
            settled = true;
            this.viewport.setAttribute('aria-busy', 'false');
            this.status.hidden = !error;
            if (error) this.status.textContent = 'Le planning n’a pas pu être actualisé. La dernière vue est conservée. Réessayez avec Actualiser.';
            else { this.setEvents(Array.isArray(events) ? events : []); this.draw(); this.options.eventsSet?.(this.events); }
        };
        try {
            const result = this.options.events(this.dateInfo(), events => complete(events), error => complete(null, error || true));
            if (result?.then) {
                const events = await result;
                if (Array.isArray(events)) complete(events);
            }
            else if (Array.isArray(result)) complete(result);
        } catch (error) { complete(null, error); }
    }

    resources(events) {
        const provided = typeof this.options.nativeResources === 'function' ? this.options.nativeResources() : this.options.nativeResources;
        const resources = new Map((provided || []).map(resource => [String(resource.id), { id: String(resource.id), name: resource.name || 'Technicien' }]));
        for (const event of events) {
            const fields = calendarFields(event);
            if (!resources.has(fields.technicianId)) resources.set(fields.technicianId, { id: fields.technicianId, name: fields.technician });
        }
        return [...resources.values()];
    }

    unmountEvents() {
        for (const info of this.mounted) this.options.eventWillUnmount?.(info);
        this.mounted = [];
    }

    draw() {
        if (!this.rendered || this.destroyed) return;
        const scroll = { top: this.viewport.scrollTop, left: this.viewport.scrollLeft };
        const focused = this.document.activeElement?.dataset?.eventId;
        this.unmountEvents();
        const range = calendarRange(this.date, this.mode, this.options.hiddenDays || [0]);
        const events = eventsDuring(this.events, range.start, range.end);
        const placed = events.filter(event => !calendarFields(event).suggestion).length;
        this.heading.textContent = this.mode === 'dayGridMonth' ? this.date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
            : this.mode === 'timeGridDay' ? this.date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
                : `${range.days[0].toLocaleDateString('fr-FR', { day: 'numeric' })} – ${range.days.at(-1).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}`;
        this.summary.textContent = `${placed} RDV placé${placed > 1 ? 's' : ''} · ${events.length - placed} proposition${events.length - placed > 1 ? 's' : ''} · ${this.resources(events).length} technicien(s)`;
        for (const button of this.views.children) button.setAttribute('aria-pressed', String(button.dataset.view === this.mode));
        this.zoomControls.hidden = this.mode !== 'timeGridDay';
        this.element.dataset.mode = this.mode;
        const content = this.mode === 'listWeek' ? this.list(range, events) : this.mode === 'dayGridMonth' ? this.month(range, events)
            : this.mode === 'timeGridDay' ? this.timeline(range, events) : this.mode === 'resourceWeek' ? this.resourceWeek(range, events) : this.week(range, events);
        this.viewport.replaceChildren(content);
        this.viewport.scrollTop = scroll.top;
        this.viewport.scrollLeft = scroll.left;
        if (focused) [...this.viewport.querySelectorAll('[data-event-id]')].find(el => el.dataset.eventId === focused)?.focus({ preventScroll: true });
        for (const info of this.mounted) this.options.eventDidMount?.(info);
    }

    resourceLabel(resource, events) {
        const heading = this.node('div', 'nc-resource');
        heading.style.setProperty('--nc-tone', tone(resource.id));
        const initials = resource.name.trim().split(/\s+/).slice(0, 2).map(word => word[0]).join('');
        heading.append(this.node('span', 'nc-avatar', initials), this.node('strong', '', resource.name),
            this.node('small', '', `${events.filter(event => !calendarFields(event).suggestion).length} RDV sur la période`));
        return heading;
    }

    card(event) {
        const fields = calendarFields(event);
        const card = this.button('', jsEvent => {
            this.toggleExpanded(false);
            this.options.eventClick?.({ event, el: card, jsEvent, view: this.view });
        }, 'nc-event');
        card.dataset.eventId = event.id;
        card.dataset.suggestion = String(fields.suggestion);
        card.style.setProperty('--nc-tone', tone(fields.technicianId));
        const duration = Math.round((event.end - event.start) / 60000);
        const head = this.node('span', 'nc-event-head');
        head.append(this.node('strong', '', `${time(event.start)} – ${time(event.end)}`), this.node('span', 'nc-duration', `${duration} min`));
        const location = this.node('span', 'nc-location');
        location.append(this.node('b', '', fields.postal), this.node('span', '', fields.city || fields.address));
        card.append(head, this.node('strong', 'nc-customer', fields.customer), this.node('span', 'nc-service', fields.service), location,
            this.node('span', 'nc-technician', fields.technician));
        if (fields.suggestion || fields.historical) card.append(this.node('span', 'nc-event-state', fields.suggestion ? 'Créneau proposé' : 'Historique · lecture seule'));
        card.title = [fields.customer, fields.service, fields.technician, fields.address, fields.postal, fields.city].filter(Boolean).join(' · ');
        card.setAttribute('aria-label', `${time(event.start)} à ${time(event.end)}, ${card.title}${fields.suggestion ? ', créneau proposé' : ''}`);
        return this.mountEvent(card, event);
    }

    mountEvent(card, event) {
        if (this.options.eventMouseEnter || this.options.eventDidMount) card.removeAttribute('title');
        card.dataset.eventId = event.id;
        const info = { event, el: card, view: this.view };
        card.addEventListener('mouseenter', jsEvent => this.options.eventMouseEnter?.({ ...info, jsEvent }));
        card.addEventListener('mouseleave', jsEvent => this.options.eventMouseLeave?.({ ...info, jsEvent }));
        this.mounted.push(info);
        return card;
    }

    dayEvents(events, day) { return eventsDuring(events, day, addCalendarDays(day, 1)); }

    appendDayEvents(cell, events, key) {
        const placed = events.filter(event => !calendarFields(event).suggestion);
        const suggestions = events.filter(event => calendarFields(event).suggestion);
        const visibleSuggestions = this.expandedCells.has(key) ? suggestions : suggestions.slice(0, 2);
        for (const event of [...placed, ...visibleSuggestions].sort((a, b) => a.start - b.start)) cell.append(this.card(event));
        if (suggestions.length > visibleSuggestions.length) cell.append(this.button(`+ ${suggestions.length - visibleSuggestions.length} créneaux`, () => {
            this.expandedCells.add(key); this.draw();
        }, 'nc-more'));
    }

    resourceWeek(range, events) {
        const board = this.node('div', 'nc-week');
        board.style.setProperty('--nc-days', range.days.length);
        board.append(this.node('div', 'nc-corner', 'L’équipe / les jours'));
        for (const day of range.days) {
            const heading = this.button(day.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' }), () => this.changeView('timeGridDay', day), 'nc-day-heading');
            if (dayKey(day) === dayKey(new Date())) heading.dataset.today = 'true';
            board.append(heading);
        }
        for (const resource of this.resources(events)) {
            const items = events.filter(event => calendarFields(event).technicianId === resource.id);
            board.append(this.resourceLabel(resource, items));
            for (const day of range.days) {
                const cell = this.node('div', 'nc-day-cell');
                this.appendDayEvents(cell, this.dayEvents(items, day), `${resource.id}|${dayKey(day)}`);
                if (this.options.dateClick) cell.append(this.button('+ Choisir un horaire', () => this.pickSlot(day, resource), 'nc-add-slot'));
                else if (!cell.children.length) cell.append(this.node('span', 'nc-empty-slot', 'Aucun rendez-vous'));
                board.append(cell);
            }
        }
        return board;
    }

    week(range, events) {
        const board = this.node('div', 'nc-hour-week');
        board.style.setProperty('--nc-days', range.days.length);
        board.append(this.node('div', 'nc-corner', 'Heure'));
        for (const day of range.days) {
            const heading = this.button(day.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' }), () => this.changeView('timeGridDay', day), 'nc-day-heading');
            if (dayKey(day) === dayKey(new Date())) heading.dataset.today = 'true';
            board.append(heading);
        }
        const resources = this.resources(events);
        const dayItems = range.days.map(day => this.dayEvents(events, day));
        const firstHour = Math.min(7, ...events.map(event => event.start.getHours()));
        const lastHour = Math.max(20, ...events.map(event => event.start.getHours()));
        for (let hour = firstHour; hour <= lastHour; hour++) {
            board.append(this.node('div', 'nc-hour-label', `${String(hour).padStart(2, '0')}:00`));
            range.days.forEach((day, index) => {
                const cell = this.node('div', 'nc-hour-cell');
                const items = dayItems[index].filter(event => event.start < day ? hour === firstHour : event.start.getHours() === hour);
                this.appendDayEvents(cell, items, `hour|${dayKey(day)}|${hour}`);
                if (items.length) cell.dataset.occupied = 'true';
                if (this.options.dateClick && resources.length) cell.append(this.button('+', () => this.pickSlot(day, resources[0], hour * 60), 'nc-hour-add', `Choisir un horaire le ${day.toLocaleDateString('fr-FR')} vers ${hour} heures`));
                board.append(cell);
            });
        }
        return board;
    }

    timeline(range, events) {
        const board = this.node('div', 'nc-timeline');
        const bounds = timelineLayout(events, range.start, { pixelsPerHour: this.zoom });
        board.style.setProperty('--nc-timeline-width', `${bounds.width}px`);
        board.append(this.node('div', 'nc-corner', 'L’équipe / les heures'));
        const axis = this.node('div', 'nc-time-axis');
        for (let minute = bounds.from; minute <= bounds.to; minute += 60) {
            const label = this.node('span', '', `${String(Math.floor(minute / 60)).padStart(2, '0')}:00`);
            label.style.left = `${(minute - bounds.from) / 60 * this.zoom}px`;
            axis.append(label);
        }
        board.append(axis);
        for (const resource of this.resources(events)) {
            const items = events.filter(event => calendarFields(event).technicianId === resource.id);
            const layout = timelineLayout(items, range.start, { minMinute: bounds.from, maxMinute: bounds.to, pixelsPerHour: this.zoom });
            board.append(this.resourceLabel(resource, items));
            const row = this.node('div', 'nc-timeline-row');
            const laneHeight = 174;
            row.style.height = `${layout.lanes * laneHeight + 26}px`;
            row.style.backgroundSize = `${this.zoom}px 100%`;
            for (const item of layout.placements) {
                const card = this.card(item.event);
                card.style.left = `${item.left + 6}px`;
                card.style.top = `${item.lane * laneHeight + 12}px`;
                card.style.width = `${item.width - 10}px`;
                const duration = this.node('span', 'nc-duration-line');
                duration.style.width = `${Math.min(item.width - 10, item.durationWidth)}px`;
                duration.title = 'Durée réelle sur l’échelle horaire';
                card.append(duration);
                row.append(card);
            }
            if (this.options.dateClick) {
                row.addEventListener('dblclick', event => {
                    if (event.target !== row) return;
                    const minutes = bounds.from + Math.round((event.clientX - row.getBoundingClientRect().left) / this.zoom * 60 / 15) * 15;
                    this.pickSlot(range.start, resource, Math.max(0, Math.min(1425, minutes)));
                });
                row.append(this.button('+ Horaire', () => this.pickSlot(range.start, resource), 'nc-row-add'));
            }
            board.append(row);
        }
        return board;
    }

    list(range, events) {
        const list = this.node('div', 'nc-agenda');
        for (const day of range.days) {
            const section = this.node('section', 'nc-agenda-day');
            section.append(this.node('h3', '', day.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })));
            const items = this.dayEvents(events, day);
            for (const event of items) section.append(this.card(event));
            if (!items.length) section.append(this.node('p', 'nc-empty-slot', 'Aucun rendez-vous ce jour.'));
            list.append(section);
        }
        return list;
    }

    month(range, events) {
        const board = this.node('div', 'nc-month');
        board.style.setProperty('--nc-days', this.options.hiddenDays?.length === 0 ? 7 : range.days.length / 6);
        for (const day of range.days) {
            const section = this.node('section', 'nc-month-day');
            if (day.getMonth() !== this.date.getMonth()) section.classList.add('nc-other-month');
            section.append(this.button(day.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }), () => this.changeView('timeGridDay', day), 'nc-day-heading'));
            const items = this.dayEvents(events, day);
            for (const event of items.slice(0, 3)) {
                const fields = calendarFields(event);
                const button = this.button(`${time(event.start)} · ${fields.customer}`, jsEvent => {
                    this.toggleExpanded(false); this.options.eventClick?.({ event, el: button, jsEvent, view: this.view });
                }, 'nc-month-event');
                section.append(this.mountEvent(button, event));
            }
            if (items.length > 3) section.append(this.button(`+ ${items.length - 3} RDV`, () => this.changeView('timeGridDay', day), 'nc-more'));
            board.append(section);
        }
        return board;
    }

    pickSlot(day, resource, minute = 540) {
        const dialog = this.node('dialog', 'nc-slot-dialog');
        dialog.append(this.node('h2', '', 'Choisir un horaire'), this.node('p', '', `${resource.name} · ${day.toLocaleDateString('fr-FR', { dateStyle: 'long' })}`));
        const form = this.node('form');
        const label = this.node('label', '', 'Heure de début');
        const resourceLabel = this.node('label', '', 'Technicien');
        const resourceSelect = this.node('select');
        for (const candidate of this.resources(this.events)) {
            const option = this.node('option', '', candidate.name); option.value = candidate.id;
            resourceSelect.append(option);
        }
        resourceSelect.value = resource.id;
        resourceLabel.append(resourceSelect);
        const input = this.node('input');
        input.type = 'time'; input.required = true; input.step = '900';
        input.value = `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
        label.append(input);
        const actions = this.node('div', 'nc-dialog-actions');
        const cancel = this.button('Annuler', () => dialog.close());
        const submit = this.button('Valider', () => {}, 'nc-button-primary');
        submit.type = 'submit'; actions.append(cancel, submit); form.append(resourceLabel, label, actions); dialog.append(form);
        dialog.addEventListener('close', () => dialog.remove(), { once: true });
        form.addEventListener('submit', event => {
            event.preventDefault();
            const [hours, minutes] = input.value.split(':').map(Number);
            const date = new Date(day); date.setHours(hours, minutes, 0, 0);
            dialog.close(); this.toggleExpanded(false);
            const selectedResource = this.resources(this.events).find(candidate => candidate.id === resourceSelect.value) || resource;
            this.options.dateClick?.({ date, dateStr: date.toISOString(), allDay: false, resource: selectedResource, view: this.view, jsEvent: event });
        });
        this.document.body.append(dialog); dialog.showModal(); input.focus();
    }

    toggleExpanded(value = !this.expanded) {
        if (Boolean(this.expanded) === value) return;
        this.expanded = value;
        this.element.classList.toggle('nc-expanded', value);
        this.expandButton.textContent = value ? 'Réduire' : 'Agrandir';
        this.expandButton.setAttribute('aria-pressed', String(value));
    }

    updateSize() { this.scheduleDraw(); }

    destroy() {
        this.destroyed = true;
        this.requestId++;
        this.controller.abort();
        if (this.frame) this.browser.cancelAnimationFrame(this.frame);
        this.unmountEvents();
        this.element.replaceChildren();
        this.element.classList.remove('nc-calendar', 'nc-expanded');
    }
}
