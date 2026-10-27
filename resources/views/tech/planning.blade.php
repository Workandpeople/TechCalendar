<x-layouts.app>
    <div class="tech-workspace tech-planning-page">
        <header class="tech-hero">
            <div><span class="tech-eyebrow">Mon espace terrain</span><h1>Bonjour {{ $technician->first_name }}</h1><p>Vos interventions, vos trajets, vos documents.</p></div>
            <span class="tech-hero-icon"><x-nav-icon name="planning" /></span>
        </header>
        <section class="tech-agenda" aria-labelledby="tech-day-heading">
            <div class="tech-day-heading"><div><p>{{ $selectedDay->isToday() ? 'Aujourd’hui' : 'Mes interventions' }}</p><h2 id="tech-day-heading">{{ $selectedDay->locale('fr')->isoFormat('dddd D MMMM') }}</h2></div><a class="tech-today-link" href="{{ route('tech.planning') }}">Aujourd’hui</a></div>
            <nav class="tech-week" aria-label="Choisir le jour des interventions">
                <a class="tech-week-step" href="{{ route('tech.planning', ['date' => $selectedDay->copy()->subWeek()->toDateString()]) }}" aria-label="Semaine précédente">‹</a>
                @foreach ($weekDays as $day)
                    <a href="{{ route('tech.planning', ['date' => $day->toDateString()]) }}" class="tech-day" @if ($day->isSameDay($selectedDay)) aria-current="date" @endif aria-label="{{ $day->locale('fr')->isoFormat('dddd D MMMM') }} : {{ $weekCounts[$day->toDateString()] ?? 0 }} rendez-vous"><span>{{ $day->locale('fr')->isoFormat('dd') }}</span><strong>{{ $day->format('d') }}</strong><i @if (! ($weekCounts[$day->toDateString()] ?? 0)) class="is-empty" @endif aria-hidden="true"></i></a>
                @endforeach
                <a class="tech-week-step" href="{{ route('tech.planning', ['date' => $selectedDay->copy()->addWeek()->toDateString()]) }}" aria-label="Semaine suivante">›</a>
            </nav>
            <form method="GET" action="{{ route('tech.planning') }}" class="tech-date-picker"><label for="tech-date">Une autre date</label><input type="date" id="tech-date" name="date" value="{{ $selectedDay->toDateString() }}" required class="gc-input"><button class="tech-small-button" type="submit">Voir</button></form>
            <div class="tech-day-summary"><span>{{ $dayCards->count() }} intervention{{ $dayCards->count() > 1 ? 's' : '' }}</span><span><x-nav-icon name="document" /> Documents dans chaque dossier</span></div>
            <ol class="tech-appointments" aria-label="Interventions du jour">
                @forelse ($dayCards as $card)
                    @include('tech.partials.appointment-card', ['card' => $card])
                @empty
                    <li class="tech-empty"><span class="tech-empty-icon"><x-nav-icon name="planning" /></span><h3>Aucune intervention ce jour</h3><p>Choisissez un autre jour pour retrouver vos dossiers.</p>@if ($nextCard)<a class="tech-small-button" href="{{ route('tech.planning', ['date' => $nextCard['appointment']->starts_at->toDateString()]) }}">Prochain RDV : {{ $nextCard['appointment']->starts_at->format('d/m à H:i') }}</a>@endif</li>
                @endforelse
            </ol>
        </section>
        @include('tech.partials.calendar')
        <details class="tech-panel tech-week-stats"><summary><span><strong>Ma semaine en chiffres</strong><small>{{ $weekDays->first()->format('d/m') }} au {{ $weekDays->last()->format('d/m') }}</small></span><x-nav-icon name="dashboard" /></summary><dl>@foreach ($stats as $stat)<div><dt>{{ $stat['label'] }}</dt><dd>{{ $stat['value'] }}</dd></div>@endforeach</dl><p>Les trajets et heures supplémentaires proviennent des derniers calculs enregistrés.</p></details>
    </div>
</x-layouts.app>
