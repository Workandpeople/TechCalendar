import $ from 'jquery';
import 'bootstrap/dist/js/bootstrap.bundle';
import 'jquery.easing';
import moment from 'moment';
import { Chart, registerables } from 'chart.js';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import multiMonthPlugin from '@fullcalendar/multimonth';
import timeGridPlugin from '@fullcalendar/timegrid';
import mapboxgl from 'mapbox-gl';

window.$ = $;
window.jQuery = $;
window.moment = moment;
window.Chart = Chart;
const fullCalendarPlugins = [
    dayGridPlugin,
    interactionPlugin,
    listPlugin,
    multiMonthPlugin,
    timeGridPlugin,
];

class LegacyCalendar extends Calendar {
    constructor(el, options = {}) {
        const hasPlugins = Array.isArray(options.plugins) && options.plugins.length > 0;
        const mergedOptions = hasPlugins
            ? options
            : { ...options, plugins: fullCalendarPlugins };

        super(el, mergedOptions);
    }
}

window.FullCalendar = { Calendar: LegacyCalendar };
window.mapboxgl = mapboxgl;

Chart.register(...registerables);
