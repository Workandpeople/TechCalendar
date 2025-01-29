<div class="card">
    <div class="card-header">
        <h5 class="card-title">Calendrier des rendez-vous</h5>
    </div>
    <div class="card-body">
        <div id="calendar-container">
            <div id="calendar"></div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        let calendarEl = document.getElementById('calendar');
        let calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'fr',
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'timeGridWeek,timeGridDay'
            },
            events: {!! json_encode(array_map(function($appoint) {
                return [
                    'id' => $appoint['id'],
                    'title' => $appoint['client_fname'] . ' ' . $appoint['client_lname'],
                    'start' => $appoint['start_at'],
                    'end' => $appoint['end_at'],
                    'backgroundColor' => '#'.substr(md5($appoint['tech_id']), 0, 6),
                    'extendedProps' => [
                        'techName' => $appoint['tech']['user']['prenom'] . ' ' . $appoint['tech']['user']['nom'],
                        'serviceName' => $appoint['service']['name'],
                        'comment' => $appoint['comment'],
                    ]
                ];
            }, $appointments)) !!},
            eventClick: function (info) {
                alert("Rendez-vous de " + info.event.title);
            }
        });

        calendar.render();
    });
    </script>
