// Importer jQuery et l'attacher au contexte global
import $ from 'jquery';
window.$ = window.jQuery = $;

// Importer Axios
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Importer Bootstrap JS
import 'bootstrap';

// Importer jQuery Easing
import 'jquery-easing';

// Importer Chart.js
import Chart from 'chart.js/auto';
window.Chart = Chart;

// Charger layout.min.js (après jQuery et Bootstrap)
import './layout.min.js';