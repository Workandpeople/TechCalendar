<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Services\Crm\DossierStatusCatalog;

class AppointmentPolicy
{
    public function reportOnSiteProblem(User $user, Appointment $appointment): bool
    {
        return config('crm.v2_enabled') && $this->view($user, $appointment)
            && (int) $appointment->technician_id === $user->id
            && $appointment->status === Appointment::STATUS_SCHEDULED
            && ! in_array($appointment->external_source, ['mymar', 'mar'], true)
            && $appointment->dossierOperation?->dossier
            && in_array(DossierStatusCatalog::describe($appointment->dossierOperation->legacy_status)['key'], ['pending', 'awaiting_visit'], true);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return ! $user->trashed() && ! $user->isExternal() && ! $user->must_change_password
            && ! $appointment->trashed()
            && ($appointment->dossier_operation_id === null || ($appointment->dossierOperation?->dossier && ! $appointment->dossierOperation->dossier->trashed()))
            && in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_PROBLEM], true)
            && ($user->admin || ($user->role === 2 && (int) $appointment->technician_id === $user->id));
    }
}
