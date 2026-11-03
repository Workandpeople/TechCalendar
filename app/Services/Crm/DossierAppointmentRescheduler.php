<?php

namespace App\Services\Crm;

use App\Exceptions\AppointmentPlacementConflict;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Support\CommentContent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DossierAppointmentRescheduler
{
    public function context(User $actor, Dossier $dossier, DossierOperation $operation, bool $includeTechnicians = true): array
    {
        $this->authorize($actor, $dossier, $operation);
        $appointment = $this->current($operation);
        $previous = $appointment ?? $operation->appointments()->withTrashed()->latest('starts_at')->latest('id')->first();

        return [
            'version' => $dossier->version,
            'appointment_id' => $appointment?->id,
            'updated_at' => $appointment?->updated_at?->toIso8601String(),
            'customer' => $dossier->beneficiaryLabel(),
            'service' => $operation->service->name,
            'address' => $appointment?->address ?: DossierLocation::address($dossier),
            'starts_at' => AppointmentDateTime::formatStored($previous?->starts_at),
            'duration_minutes' => $previous?->duration_minutes ?: $operation->service->average_duration_minutes,
            'technician_id' => $previous?->technician_id,
            'preserves_previous_visit' => $appointment === null && $previous !== null,
            'technicians' => $includeTechnicians ? User::query()->internal()->where('role', 2)->where('admin', false)
                ->withExists(['services as qualified' => fn ($query) => $query->where('services.id', $operation->service_id)])
                ->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($tech) => ['id' => $tech->id, 'name' => $tech->full_name, 'qualified' => (bool) $tech->qualified])->all() : [],
        ];
    }

    public function reschedule(User $actor, Dossier $dossier, DossierOperation $operation, array $data): Appointment
    {
        $this->authorize($actor, $dossier, $operation);
        $forced = (bool) ($data['force_placement'] ?? false);
        if ($forced && ! in_array($data['force_confirmed'] ?? null, [true, 1, '1', 'yes', 'on', 'true'], true)) {
            throw ValidationException::withMessages(['force_confirmed' => 'Confirme explicitement le placement forcé.']);
        }
        $start = AppointmentDateTime::parse($data['starts_at']);
        $end = $start->copy()->addMinutes($data['duration_minutes']);
        AppointmentDateTime::assertStorableRange($start, $end);
        if ($start->lte(now(AppointmentDateTime::TIMEZONE))) {
            throw ValidationException::withMessages(['starts_at' => 'Choisis un créneau à venir.']);
        }

        // Only a new reservation may need geocoding; never hold SQL locks during this lookup.
        $location = empty($data['appointment_id'])
            ? app(DossierMapPreview::class)->coordinates($actor, $dossier, forPlanning: true) : null;

        return DB::transaction(function () use ($actor, $dossier, $operation, $data, $forced, $start, $end, $location): Appointment {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            $operation = $dossier->operations()->lockForUpdate()->findOrFail($operation->id);
            $this->authorize($actor, $dossier, $operation);
            $appointment = $this->current($operation, lock: true);
            if ($dossier->version !== (int) $data['version'] || $appointment?->id !== (isset($data['appointment_id']) ? (int) $data['appointment_id'] : null)
                || ($appointment?->updated_at === null ? $data['updated_at'] !== null
                    : ($data['updated_at'] === null || ! $appointment->updated_at->equalTo(Carbon::parse($data['updated_at']))))) {
                throw ValidationException::withMessages(['version' => 'Le dossier ou le RDV a changé. Ferme puis rouvre le replacement rapide.']);
            }
            $technician = User::query()->internal()->where('role', 2)->where('admin', false)
                ->lockForUpdate()->findOrFail($data['technician_id']);
            $address = $appointment?->address ?: DossierLocation::address($dossier);
            $point = $appointment ? DossierLocation::point($appointment->latitude, $appointment->longitude) : ($location['coordinates'] ?? null);
            if (blank($address) || (! $appointment && $point === null)) {
                throw ValidationException::withMessages(['address' => 'Vérifie et localise l’adresse du dossier avant de placer ce RDV.']);
            }
            $precision = $appointment?->external_payload['location_precision'] ?? $location['precision'] ?? 'address';
            $checks = app(AppointmentSlotAvailability::class)->assertAvailable($technician, $start, $end,
                ['address' => $address, ...($point ?? []), 'location_precision' => $precision], $appointment?->id, $forced);
            if (! $technician->services()->whereKey($operation->service_id)->exists()) {
                $checks['conflicts'][] = ['field' => 'technician_id', 'type' => 'qualification', 'message' => 'Ce technicien ne couvre pas cette prestation.'];
                if (! $forced) {
                    throw AppointmentPlacementConflict::fromConflicts($checks['conflicts']);
                }
            }
            $rows = $this->lotRows($operation, $appointment);
            if ($appointment) {
                $appointment = app(AppointmentEditor::class)->update($appointment->id, [
                    'technician_id' => $technician->id, 'updated_at' => $data['updated_at'],
                    'starts_at' => $data['starts_at'], 'duration_minutes' => $data['duration_minutes'],
                    'force_placement' => $forced, 'force_confirmed' => $data['force_confirmed'] ?? false,
                    'address' => $address, 'service_id' => $operation->service_id,
                    'status' => Appointment::STATUS_SCHEDULED, 'problem_reported_at' => null,
                ], $actor, $checks);
            } else {
                $appointment = Appointment::forceCreate([
                    'dossier_operation_id' => $operation->id, 'service_id' => $operation->service_id,
                    'technician_id' => $technician->id, 'created_by' => $actor->id,
                    'customer_first_name' => $dossier->company_name ? '' : ($dossier->first_name ?? ''),
                    'customer_last_name' => $dossier->company_name ?: ($dossier->last_name ?: $dossier->beneficiaryLabel()),
                    'customer_phone' => $dossier->phone ?? '', 'address' => $address,
                    'latitude' => $point['latitude'], 'longitude' => $point['longitude'],
                    'starts_at' => $start, 'ends_at' => $end, 'duration_minutes' => $data['duration_minutes'],
                    'external_source' => 'native', 'external_payload' => ['location_precision' => $precision,
                        'quick_reschedule_control' => PostVisitWorkflow::planningEvidence($operation)],
                    'status' => Appointment::STATUS_SCHEDULED,
                ]);
                if ($forced) {
                    app(AppointmentSlotAvailability::class)->recordForcedPlacement($actor, $appointment, $checks);
                }
                TechnicianDailyRouteMetric::query()->where('technician_id', $technician->id)
                    ->whereDate('service_date', $start->toDateString())->delete();
                $dossier->increment('version');
            }
            foreach ($rows as $row) {
                $row->update(['appointment_id' => $appointment->id, 'status' => LotAppointment::STATUS_PLACED]);
            }
            if (filled($data['comment'] ?? null)) {
                $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment',
                    'body' => CommentContent::forStorage($data['comment'], 'comment'),
                    'client_visible' => false, 'delegataire_visible' => false]);
            }
            $dossier->refresh();
            app(DossierWorkflow::class)->changeStatus($actor, $dossier, $operation,
                ['version' => $dossier->version, 'status' => 'awaiting_visit']);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'appointment_rescheduled',
                'body' => 'RDV #'.$appointment->id.' replacé le '.$start->format('d/m/Y H:i').' (replacement rapide).',
                'client_visible' => false, 'delegataire_visible' => false,
                'changed_fields' => ['appointment_id' => $appointment->id, 'operation_id' => $operation->id,
                    'starts_at' => AppointmentDateTime::formatStored($start), 'technician_id' => $technician->id]]);

            return $appointment;
        }, 3);
    }

    private function authorize(User $actor, Dossier $dossier, DossierOperation $operation): void
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless($operation->dossier_id === $dossier->id, 404);
        if (! $operation->service) {
            throw ValidationException::withMessages(['appointment' => 'Renseigne la prestation du dossier avant de replacer le RDV.']);
        }
    }

    private function current(DossierOperation $operation, bool $lock = false): ?Appointment
    {
        $appointments = $operation->appointments()->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->with('lotAppointments.lot:id,status')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->reject(fn (Appointment $appointment) => (PostVisitWorkflow::planningEvidence($operation) !== []
                && ! PostVisitWorkflow::isFollowUp($operation, $appointment))
                || $appointment->lotAppointments->contains(fn ($row) => $this->isLotProof($row)));
        if ($appointments->count() > 1) {
            throw ValidationException::withMessages(['appointment' => 'Plusieurs RDV sont liés à cette fiche. Utilise le planning pour choisir le RDV à déplacer.']);
        }

        return $appointments->first();
    }

    private function lotRows(DossierOperation $operation, ?Appointment $appointment)
    {
        $rows = LotAppointment::query()->where(function ($query) use ($operation, $appointment): void {
            $query->whereRaw('1 = 0');
            if ($appointment) {
                $query->orWhere('appointment_id', $appointment->id);
            }
            if ($operation->source === 'lot' && ctype_digit((string) $operation->source_reference)) {
                $query->orWhere('id', (int) $operation->source_reference);
            }
        })->with(['appointment' => fn ($query) => $query->withTrashed()])->lockForUpdate()->get();
        $mutable = collect();
        foreach ($rows as $row) {
            $lot = Lot::query()->lockForUpdate()->findOrFail($row->lot_id);
            $row->setRelation('lot', $lot);
            if ($this->isLotProof($row)) {
                if ($appointment && $row->appointment_id === $appointment->id) {
                    throw ValidationException::withMessages(['version' => 'Le lot a été traité depuis l’ouverture du formulaire. Ferme puis rouvre le replacement rapide.']);
                }

                // Preserve processed lot results and their original appointment; the new visit is independent.
                continue;
            }
            if ($row->appointment_id !== $appointment?->id && $row->appointment?->dossier_operation_id === $operation->id
                && PostVisitWorkflow::planningEvidence($operation) !== []
                && ! PostVisitWorkflow::isFollowUp($operation, $row->appointment)) {
                continue;
            }
            if ($appointment === null && $row->appointment?->dossier_operation_id === $operation->id
                && ($row->appointment->trashed() || $row->appointment->status === Appointment::STATUS_CANCELLED)) {
                $mutable->push($row);

                continue;
            }
            if ($row->appointment_id !== null && $row->appointment_id !== $appointment?->id) {
                throw ValidationException::withMessages(['appointment' => 'Le lot est déjà traité ou rattaché à un autre RDV. Le replacement rapide ne peut pas modifier cette preuve.']);
            }
            $mutable->push($row);
        }

        return $mutable;
    }

    private function isLotProof(LotAppointment $row): bool
    {
        return ! $row->lot || Lot::isArchivedStatus($row->lot->status)
            || $row->physical_satisfaction !== null || $row->contact_satisfaction !== null
            || $row->contact_processed_at !== null || $row->excluded_from_lot_stats;
    }
}
