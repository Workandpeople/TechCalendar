<?php

namespace App\Observers;

use App\Models\DossierActivity;
use App\Services\Crm\AppointmentChangeReviews;

class AppointmentStatusChangeObserver
{
    public function created(DossierActivity $activity): void
    {
        app(AppointmentChangeReviews::class)->captureStatusActivity($activity);
    }
}
