<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentCancellation
{
    public function cancel(User $actor, int $id, ?string $updatedAt): void
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $reference = Appointment::query()->with('dossierOperation')->findOrFail($id);
        if (! $reference->dossierOperation) {
            throw ValidationException::withMessages(['appointment' => 'Rattache ce rendez-vous à sa fiche dossier avant de le remettre en prise de RDV.']);
        }
        DB::transaction(function () use ($actor, $reference, $updatedAt): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($reference->dossierOperation->dossier_id);
            Gate::forUser($actor)->authorize('update', $dossier);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($reference->dossier_operation_id);
            $appointment = $operation->appointments()->lockForUpdate()->findOrFail($reference->id);
            if ($appointment->updated_at === null ? $updatedAt !== null
                : ($updatedAt === null || ! $appointment->updated_at->equalTo(Carbon::parse($updatedAt)))) {
                throw ValidationException::withMessages(['appointment' => 'Ce RDV a changé. Ferme puis rouvre la fiche avant de le supprimer.']);
            }
            if (! $appointment->starts_at || $appointment->starts_at->lte(now())) {
                throw ValidationException::withMessages(['appointment' => 'Un rendez-vous déjà commencé ou passé ne peut pas être supprimé.']);
            }
            PostVisitWorkflow::assertPlanningMutable($operation);
            if ($operation->appointments()->whereKeyNot($appointment->id)->where('status', Appointment::STATUS_SCHEDULED)
                ->where('starts_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['appointment' => 'Cette fiche possède un autre rendez-vous futur. Vérifie ses rendez-vous avant de la remettre en prise de RDV.']);
            }
            $rows = $appointment->lotAppointments()->lockForUpdate()->get();
            foreach ($rows as $row) {
                $lot = Lot::query()->lockForUpdate()->findOrFail($row->lot_id);
                if (Lot::isArchivedStatus($lot->status) || $row->physical_satisfaction !== null || $row->contact_satisfaction !== null
                    || $row->contact_processed_at !== null || $row->excluded_from_lot_stats) {
                    throw ValidationException::withMessages(['appointment' => 'Ce rendez-vous appartient à un lot déjà traité, exclu ou archivé. Sa preuve de traitement est conservée.']);
                }
                $row->update(['appointment_id' => null, 'status' => LotAppointment::STATUS_PENDING]);
            }
            $appointment->forceFill(['status' => Appointment::STATUS_CANCELLED])->save();
            $appointment->delete();
            $changed = app(DossierWorkflow::class)->changeStatus($actor, $dossier, $operation,
                ['status' => 'pending', 'version' => $dossier->version]);
            if (! $changed) {
                $dossier->increment('version');
            }
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'appointment_cancelled',
                'body' => 'RDV #'.$appointment->id.' retiré du planning. Prestation remise en prise de RDV ; historique conservé.',
                'changed_fields' => ['appointment_id' => $appointment->id, 'operation_id' => $operation->id,
                    'technician_id' => $appointment->technician_id, 'starts_at' => $appointment->starts_at->toIso8601String(),
                    'lot_row_ids' => $rows->modelKeys()], 'client_visible' => false, 'delegataire_visible' => false]);
            TechnicianDailyRouteMetric::query()->where('technician_id', $appointment->technician_id)
                ->whereDate('service_date', $appointment->starts_at->toDateString())->delete();
        }, 3);
    }
}
