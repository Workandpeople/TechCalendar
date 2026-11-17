<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Services\Crm\AppointmentChangeReviews;

class AppointmentChangeObserver
{
    public function updating(Appointment $appointment): void
    {
        // Review metadata is written in the same SQL UPDATE as the changed appointment.
        app(AppointmentChangeReviews::class)->capture($appointment, auth()->user());
    }
}
