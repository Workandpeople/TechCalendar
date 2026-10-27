<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DossierTechnicianAssignment
{
    public function update(User $actor, Dossier $dossier, int $appointmentId, array $data): Appointment
    {
        Gate::forUser($actor)->authorize('update', $dossier);

        return DB::transaction(function () use ($actor, $dossier, $appointmentId, $data): Appointment {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de modifier le technicien.']);
            }
            $appointment = $dossier->appointments()->lockForUpdate()->findOrFail($appointmentId);

            return app(AppointmentEditor::class)->update($appointment->id, [
                'technician_id' => $data['technician_id'], 'updated_at' => $data['updated_at'],
                'address' => $appointment->address, 'starts_at' => $appointment->starts_at->toDateTimeString(),
                'duration_minutes' => $appointment->duration_minutes,
                'force_placement' => $data['force_placement'] ?? false,
                'force_confirmed' => $data['force_confirmed'] ?? false,
            ], $actor);
        }, 3);
    }
}
