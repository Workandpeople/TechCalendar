import $ from 'jquery';
import 'bootstrap/dist/js/bootstrap.bundle';
import 'jquery.easing';
import moment from 'moment';
import { Chart, registerables } from 'chart.js';
import { Calendar } from 'fullcalendar';
import mapboxgl from 'mapbox-gl';

window.$ = $;
window.jQuery = $;
window.moment = moment;
window.Chart = Chart;
window.FullCalendar = { Calendar };
window.mapboxgl = mapboxgl;

Chart.register(...registerables);
