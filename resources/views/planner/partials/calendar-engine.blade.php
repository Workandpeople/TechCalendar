@if (config('calendar.engine') === 'native' || (app()->environment('local') && request()->query('calendar') === 'native'))
    @vite('resources/js/native-calendar-entry.js')
    @if(app()->environment('local') && request()->query('calendar') === 'native')
        <p class="mb-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">Essai local du calendrier sur mesure. Les règles et validations serveur habituelles restent actives. <a class="underline" href="{{ request()->fullUrlWithoutQuery('calendar') }}">Revenir au calendrier actuel</a></p>
    @endif
@else
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    @if ($calendarLocales ?? false)<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales-all.global.min.js"></script>@endif
@endif
