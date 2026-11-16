<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Crm\DossierStatusCatalog;

class AppointmentPolicy
{
    public function reportOnSiteProblem(User $user, Appointment $appointment): bool
    {
        if (! config('crm.v2_enabled') || ! $this->view($user, $appointment)
            || (int) $appointment->technician_id !== $user->id
            || in_array($appointment->external_source, ['mymar', 'mar'], true)
            || ! $appointment->dossierOperation?->dossier) {
            return false;
        }
        $state = DossierStatusCatalog::describe($appointment->dossierOperation->legacy_status)['key'];
        if ($appointment->status === Appointment::STATUS_SCHEDULED) {
            return in_array($state, ['pending', 'awaiting_visit', 'problem'], true);
        }

        // Planning may have declared the problem before the technician uploads their evidence.
        return $appointment->status === Appointment::STATUS_PROBLEM && $state === 'problem'
            && empty($appointment->external_payload['techcalendar_on_site'])
            && ! TechnicianIncident::where('appointment_id', $appointment->id)->exists();
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
