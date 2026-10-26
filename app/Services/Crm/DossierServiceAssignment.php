<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DossierServiceAssignment
{
    public function change(User $actor, Dossier $dossier, DossierOperation $operation, int $serviceId, int $version): array
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless($operation->dossier_id === $dossier->id, 404);

        return DB::transaction(function () use ($actor, $dossier, $operation, $serviceId, $version): array {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('update', $dossier);
            abort_if($dossier->version !== $version, 409, 'Le dossier a changé. Rechargez la fiche avant de réessayer.');
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            $service = Service::query()->catalog()->findOrFail($serviceId);
            if ($operation->service_id !== $service->id) {
                if ($operation->report_document_id || $dossier->billing_review_required || $dossier->billings()->exists()
                    || DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'report') {
                    throw ValidationException::withMessages(['service_id' => 'Une fiche déjà rapportée ou facturée ne peut plus changer de prestation.']);
                }
                $appointments = $operation->appointments()->where('status', Appointment::STATUS_SCHEDULED)
                    ->with('technician.services')->lockForUpdate()->get();
                if ($appointments->contains(fn ($appointment) => ! $appointment->technician
                    || ! $appointment->technician->services->contains('id', $service->id))) {
                    throw ValidationException::withMessages(['service_id' => 'Le technicien affecté doit être habilité pour cette nouvelle fiche. Faites vérifier ses habilitations avant de changer la prestation.']);
                }
                $lots = Lot::whereIn('id', LotAppointment::whereIn('appointment_id', $appointments->modelKeys())->select('lot_id'))
                    ->orderBy('id')->lockForUpdate()->get();
                if ($lots->contains(fn ($lot) => Lot::isArchivedStatus($lot->status))) {
                    throw ValidationException::withMessages(['service_id' => 'Un lot lié est archivé. Sa prestation ne peut plus être modifiée.']);
                }
                $previous = $operation->service_id;
                $operation->update(['service_id' => $service->id]);
                $operation->appointments()->where('status', Appointment::STATUS_SCHEDULED)->update(['service_id' => $service->id]);
                if ($appointments->isNotEmpty()) {
                    LotAppointment::whereIn('appointment_id', $appointments->modelKeys())->update(['service_id' => $service->id]);
                }
                $dossier->increment('version');
                $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'service_changed',
                    'body' => 'Prestation remplacée par '.$service->name.'. Horaires du RDV conservés.',
                    'changed_fields' => ['operation_id' => $operation->id, 'previous_service_id' => $previous, 'service_id' => $service->id]]);
            }

            return ['operation_id' => $operation->id, 'service_id' => $service->id,
                'name' => $service->name, 'type' => $service->type, 'version' => $dossier->version,
                'appointments' => $operation->appointments()->where('status', Appointment::STATUS_SCHEDULED)->get(['id', 'updated_at'])
                    ->map(fn ($appointment) => ['id' => $appointment->id, 'updated_at' => $appointment->updated_at?->toIso8601String()]),
                'technicians' => User::query()->where('role', 2)->where('admin', false)
                    ->whereHas('services', fn ($q) => $q->where('services.id', $service->id))->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
                    ->map(fn ($technician) => ['id' => $technician->id, 'label' => $technician->full_name]),
            ];
        }, 3);
    }
}
