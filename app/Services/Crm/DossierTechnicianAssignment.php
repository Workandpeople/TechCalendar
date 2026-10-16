<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DossierTechnicianAssignment
{
    public function update(User $actor, Dossier $dossier, int $appointmentId, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        DB::transaction(function () use ($actor, $dossier, $appointmentId, $data): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if ($dossier->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Le dossier a changé. Recharge la fiche avant de modifier le technicien.']);
            }
            $appointment = $dossier->appointments()->lockForUpdate()->findOrFail($appointmentId);
            app(AppointmentEditor::class)->update($appointment->id, [
                'technician_id' => $data['technician_id'], 'updated_at' => $data['updated_at'],
                'address' => $appointment->address, 'starts_at' => $appointment->starts_at->toDateTimeString(),
                'duration_minutes' => $appointment->duration_minutes,
            ], $actor);
        }, 3);
    }
}
