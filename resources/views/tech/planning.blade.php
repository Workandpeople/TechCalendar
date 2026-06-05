<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm" style="color:var(--gc-text-soft);">Tech</p>
                <h1 class="mt-1 text-2xl font-semibold" style="color:var(--gc-text);">Mon planning</h1>
                <p class="mt-2 text-sm" style="color:var(--gc-text-soft);">Vue personnelle des interventions a venir.</p>
            </div>
            <div class="rounded-xl px-4 py-3 text-sm" style="background:var(--gc-accent-soft);color:var(--gc-text);">
                {{ $technician->full_name }}
            </div>
        </div>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                @php
                    $tones = [
                        'blue' => ['#dbeafe', '#1d4ed8'],
                        'green' => ['#dcfce7', '#15803d'],
                        'gold' => ['#fef3c7', '#b45309'],
                        'pink' => ['#ffe4e6', '#be123c'],
                    ];
                    [$toneBg, $toneText] = $tones[$stat['tone']] ?? ['var(--gc-accent-soft)', 'var(--gc-text)'];
                @endphp
                <article class="gc-card p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm" style="color:var(--gc-text-soft);">{{ $stat['label'] }}</p>
                            <p class="mt-3 text-3xl font-semibold" style="color:var(--gc-text);">{{ $stat['value'] }}</p>
                            <p class="mt-2 text-xs" style="color:var(--gc-text-soft);">{{ $stat['detail'] }}</p>
                        </div>
                        <span class="rounded-2xl px-3 py-2 text-xs font-semibold" style="background:{{ $toneBg }};color:{{ $toneText }};">Live</span>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1.2fr]">
            <div class="space-y-6">
                <article class="gc-card p-5">
                    <p class="text-sm" style="color:var(--gc-text-soft);">Prochain rendez-vous</p>
                    @if ($nextAppointment)
                        <h2 class="mt-2 text-xl font-semibold" style="color:var(--gc-text);">
                            {{ $nextAppointment->customer_first_name }} {{ $nextAppointment->customer_last_name }}
                        </h2>
                        <div class="mt-4 space-y-2 text-sm" style="color:var(--gc-text-soft);">
                            <p>{{ $nextAppointment->starts_at->locale('fr')->isoFormat('dddd D MMMM YYYY') }} à {{ $nextAppointment->starts_at->format('H:i') }}</p>
                            <p>{{ $nextAppointment->service ? $nextAppointment->service->type.' - '.$nextAppointment->service->name : 'Prestation' }}</p>
                            <p>{{ $nextAppointment->address }}</p>
                            <p>{{ $nextAppointment->customer_phone }}</p>
                        </div>
                    @else
                        <p class="mt-3 text-sm" style="color:var(--gc-text-soft);">Aucun rendez-vous a venir.</p>
                    @endif
                </article>

                <article class="gc-card p-5">
                    <div class="mb-4">
                        <p class="text-sm" style="color:var(--gc-text-soft);">A venir</p>
                        <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Prochains RDV</h2>
                    </div>

                    <div class="space-y-3">
                        @forelse ($upcomingAppointments as $appointment)
                            <div class="rounded-xl border p-4" style="border-color:var(--gc-border);">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-semibold" style="color:var(--gc-text);">
                                            {{ $appointment->starts_at->format('d/m') }} · {{ $appointment->starts_at->format('H:i') }}
                                        </p>
                                        <p class="mt-1 text-sm" style="color:var(--gc-text-soft);">
                                            {{ $appointment->customer_first_name }} {{ $appointment->customer_last_name }}
                                        </p>
                                        <p class="mt-1 text-xs" style="color:var(--gc-text-soft);">{{ $appointment->address }}</p>
                                    </div>
                                    <span class="rounded-full px-2 py-1 text-xs" style="background:#e0f2fe;color:#1d4ed8;">
                                        {{ $appointment->service?->type ?? 'RDV' }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm" style="color:var(--gc-text-soft);">Aucun rendez-vous a venir.</p>
                        @endforelse
                    </div>
                </article>
            </div>

            <section class="gc-card p-5">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold" style="color:var(--gc-text);">Calendrier</h2>
                    <p class="text-sm" style="color:var(--gc-text-soft);">Tes interventions planifiees.</p>
                </div>
                <div id="tech-calendar"></div>
            </section>
        </section>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script>
        const calendarEl = document.getElementById('tech-calendar');

        if (calendarEl && window.FullCalendar) {
            const techCalendar = new window.FullCalendar.Calendar(calendarEl, {
                initialView: 'timeGridWeek',
                locale: 'fr',
                firstDay: 1,
                weekends: false,
                allDaySlot: false,
                height: 760,
                nowIndicator: true,
                buttonText: {
                    today: "Aujourd'hui",
                    month: 'Mois',
                    week: 'Semaine',
                    day: 'Jour',
                    list: 'Liste',
                },
                slotMinTime: '07:00:00',
                slotMaxTime: '20:00:00',
                slotLabelFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                },
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                },
                events: async (fetchInfo, successCallback, failureCallback) => {
                    try {
                        const response = await fetch('{{ route('tech.planning.events') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                start: fetchInfo.startStr,
                                end: fetchInfo.endStr,
                            }),
                        });
                        const payload = await response.json();

                        if (!response.ok) {
                            throw new Error(payload?.message || 'Erreur calendrier.');
                        }

                        successCallback((payload.events || []).map((event) => ({
                            ...event,
                            backgroundColor: '#31424c',
                            borderColor: '#d8c27a',
                            textColor: '#ffffff',
                        })));
                    } catch (error) {
                        failureCallback(error);
                    }
                },
                eventDidMount: (info) => {
                    const props = info.event.extendedProps;
                    info.el.title = [
                        props.service_label,
                        props.customer_name,
                        props.customer_phone,
                        props.address,
                        props.comment,
                    ].filter(Boolean).join('\n');
                },
            });

            techCalendar.render();
        }
    </script>
</x-layouts.app>
