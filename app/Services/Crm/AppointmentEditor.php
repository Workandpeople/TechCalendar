<?php

namespace App\Services\Crm;

use App\Exceptions\AppointmentPlacementConflict;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentEditor
{
    public function update(int $id, array $data, User $actor): Appointment
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $forced = (bool) ($data['force_placement'] ?? false);
        if ($forced && ! in_array($data['force_confirmed'] ?? null, [true, 1, '1', 'yes', 'on', 'true'], true)) {
            throw ValidationException::withMessages(['force_confirmed' => 'Confirme explicitement le placement forcé.']);
        }

        return DB::transaction(function () use ($id, $data, $actor, $forced) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($id);
            if (array_key_exists('updated_at', $data) && ($appointment->updated_at === null
                ? $data['updated_at'] !== null
                : ($data['updated_at'] === null || ! $appointment->updated_at->equalTo(Carbon::parse($data['updated_at']))))) {
                throw ValidationException::withMessages(['appointment' => 'Ce RDV a changé. Ferme puis rouvre la fiche avant de modifier.']);
            }
            $technicianId = (int) ($data['technician_id'] ?? $appointment->technician_id);
            $serviceId = (int) ($data['service_id'] ?? $appointment->service_id);
            if ($serviceId !== $appointment->service_id && $appointment->dossierOperation) {
                $operation = $appointment->dossierOperation;
                if ($operation->appointments()->whereKeyNot($appointment->id)->exists()
                    || $operation->capceeReports()->exists()
                    || ($operation->legacy_status && ! in_array(DossierStatusCatalog::describe($operation->legacy_status)['key'], ['pending', 'problem', 'awaiting_visit'], true))) {
                    throw ValidationException::withMessages(['service_id' => 'La prestation possède un historique de visites ou de contrôle. Elle ne peut pas être remplacée depuis ce rendez-vous.']);
                }
            }
            $technician = User::query()->internal()->where('role', 2)->where('admin', false)->lockForUpdate()->findOrFail($technicianId);
            $conflicts = [];
            if (($technicianId !== $appointment->technician_id || $serviceId !== $appointment->service_id)
                && ! $technician->services()->whereKey($serviceId)->exists()) {
                $conflicts[] = ['field' => 'technician_id', 'type' => 'qualification', 'message' => 'Ce technicien ne couvre pas cette prestation.'];
            }
            $service = Service::query()->findOrFail($serviceId);
            if ($serviceId !== $appointment->service_id && $service->retired_at !== null) {
                throw ValidationException::withMessages(['service_id' => 'Sélectionne une prestation du catalogue actuel.']);
            }
            $firstName = trim((string) (array_key_exists('customer_first_name', $data) ? $data['customer_first_name'] : $appointment->customer_first_name));
            $lastName = trim((string) (array_key_exists('customer_last_name', $data) ? $data['customer_last_name'] : $appointment->customer_last_name));
            if ($firstName === '' && $lastName === '') {
                throw ValidationException::withMessages(['customer_last_name' => 'Renseigne le nom ou la raison sociale du bénéficiaire.']);
            }
            if ($data['address'] !== $appointment->address) {
                $data['latitude'] = $data['latitude'] ?? null;
                $data['longitude'] = $data['longitude'] ?? null;
            }
            $start = AppointmentDateTime::parse($data['starts_at']);
            $end = $start->copy()->addMinutes($data['duration_minutes']);
            AppointmentDateTime::assertStorableRange($start, $end);
            if ($appointment->dossierOperation && ($technicianId !== $appointment->technician_id
                || $serviceId !== $appointment->service_id || ! $start->equalTo($appointment->starts_at)
                || ! $end->equalTo($appointment->ends_at) || $data['address'] !== $appointment->address)) {
                PostVisitWorkflow::assertPlanningMutable($appointment->dossierOperation);
            }
            $checks = app(AppointmentSlotAvailability::class)->inspect($technician, $start, $end, [
                'address' => $data['address'], 'latitude' => array_key_exists('latitude', $data) ? $data['latitude'] : $appointment->latitude,
                'longitude' => array_key_exists('longitude', $data) ? $data['longitude'] : $appointment->longitude,
            ], $id);
            $conflicts = [...$conflicts, ...$checks['conflicts']];
            if ($conflicts !== [] && ! $forced) {
                throw AppointmentPlacementConflict::fromConflicts($conflicts, $appointment->dossier_operation_id !== null);
            }
            $oldTechnician = $appointment->technician_id;
            $oldDate = $appointment->starts_at->toDateString();
            foreach (['customer_first_name', 'customer_last_name', 'customer_phone'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = (string) ($data[$field] ?? '');
                }
            }
            $appointment->fill(collect($data)->except(['updated_at', 'force_placement', 'force_confirmed'])->all());
            $commentChanged = $appointment->isDirty('comment');
            $appointment->forceFill(['technician_id' => $technicianId, 'service_id' => $serviceId, 'starts_at' => $start, 'ends_at' => $end]);

            if ($operation = $appointment->dossierOperation) {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($operation->dossier_id);
                Gate::forUser($actor)->authorize('update', $dossier);
                $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
                if ($appointment->isDirty(['technician_id', 'service_id', 'starts_at', 'ends_at', 'address', 'latitude', 'longitude', 'customer_first_name', 'customer_last_name'])) {
                    PostVisitWorkflow::assertPlanningMutable($operation);
                }
                $operation->update(['service_id' => $serviceId]);
                $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'appointment_updated',
                    'body' => 'RDV #'.$id.' modifié : '.$start->format('d/m/Y H:i').', '.$data['duration_minutes'].' min.']);
                $dossier->increment('version');
                if ($commentChanged && filled($appointment->comment)) {
                    $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment', 'body' => $appointment->comment]);
                }
            }
            $appointment->save();
            if ($forced) {
                app(AppointmentSlotAvailability::class)->recordForcedPlacement($actor, $appointment,
                    [...$checks, 'conflicts' => $conflicts, 'previous_technician_id' => $oldTechnician]);
            }
            TechnicianDailyRouteMetric::query()->whereIn('technician_id', [$oldTechnician, $technicianId])
                ->where(fn ($query) => $query->whereDate('service_date', $oldDate)->orWhereDate('service_date', $start->toDateString()))->delete();

            return $appointment;
        }, 3);
    }

    public function updateComment(Appointment $appointment, User $actor, ?string $comment): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor, $comment): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $appointment->comment = $comment;
            if (! $appointment->isDirty('comment')) {
                return $appointment;
            }
            if ($operation = $appointment->dossierOperation) {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($operation->dossier_id);
                Gate::forUser($actor)->authorize('update', $dossier);
                $dossier->activities()->create([
                    'author_id' => $actor->id,
                    'kind' => filled($comment) ? 'comment' : 'appointment_updated',
                    'body' => filled($comment) ? $comment : 'Commentaire courant du RDV #'.$appointment->id.' effacé. Historique conservé.',
                ]);
                $dossier->increment('version');
            }
            $appointment->save();

            return $appointment;
        }, 3);
    }
}
