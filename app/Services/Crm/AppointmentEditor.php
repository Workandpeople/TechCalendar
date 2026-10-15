<?php

namespace App\Services\Crm;

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
        return DB::transaction(function () use ($id, $data, $actor) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($id);
            if (isset($data['updated_at']) && ! $appointment->updated_at->equalTo(Carbon::parse($data['updated_at']))) {
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
            $technician = User::query()->where('role', 2)->where('admin', false)->lockForUpdate()->findOrFail($technicianId);
            if (($technicianId !== $appointment->technician_id || $serviceId !== $appointment->service_id)
                && ! $technician->services()->whereKey($serviceId)->exists()) {
                throw ValidationException::withMessages(['technician_id' => 'Ce technicien ne couvre pas cette prestation.']);
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
            $start = Carbon::parse($data['starts_at']);
            $end = $start->copy()->addMinutes($data['duration_minutes']);
            if (Appointment::query()->whereKeyNot($id)->where('technician_id', $technicianId)
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Ce technicien a déjà un RDV sur ce créneau.']);
            }
            if ($technician->absences()->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Le technicien est absent sur ce créneau.']);
            }
            $oldTechnician = $appointment->technician_id;
            $oldDate = $appointment->starts_at->toDateString();
            foreach (['customer_first_name', 'customer_last_name', 'customer_phone'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = (string) ($data[$field] ?? '');
                }
            }
            $appointment->fill(collect($data)->except('updated_at')->all());
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
