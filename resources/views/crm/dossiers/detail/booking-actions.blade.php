<div class="gc-dossier-booking-actions" role="group" aria-label="Planification du rendez-vous">
    <button type="button" class="gc-btn-warning" data-quick-reschedule-url="{{ route('v2.dossiers.appointments.reschedule', [$dossier, $bookingOperation], absolute: false) }}"><x-nav-icon name="planning" />Replacer rapidement</button>
    <a class="gc-btn-secondary" href="{{ route('planner.book', ['crm_appointment_id' => 'rebook-'.$bookingOperation->id]) }}"><x-nav-icon name="planning" />Replanifier avec le planning</a>
</div>
