<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return ! $user->trashed() && ! $user->isExternal() && ! $user->must_change_password
            && ! $appointment->trashed()
            && ($appointment->dossier_operation_id === null || ($appointment->dossierOperation?->dossier && ! $appointment->dossierOperation->dossier->trashed()))
            && in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_PROBLEM], true)
            && ($user->admin || ($user->role === 2 && (int) $appointment->technician_id === $user->id));
    }
}
