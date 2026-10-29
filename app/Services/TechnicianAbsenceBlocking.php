<?php

namespace App\Services;

use App\Exceptions\TechnicianAbsenceConflict;
use App\Models\Appointment;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\AppointmentDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TechnicianAbsenceBlocking
{
    public const MAX_TECHNICIANS = 200;

    public static function rules(): array
    {
        return [
            'technician_ids' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_TECHNICIANS],
            'technician_ids.*' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
            'date' => ['required', 'date_format:Y-m-d'],
            'period' => ['required', Rule::in(['day', 'morning', 'afternoon'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function eligibleTechnicians(): Builder
    {
        return User::query()->internal()->where('role', 2)->where('admin', false);
    }

    /** @return Collection<int, TechnicianAbsence> */
    public function block(User $actor, array $data): Collection
    {
        Gate::forUser($actor)->authorize('create', TechnicianAbsence::class);
        $payload = Validator::make($data, self::rules())->validate();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $payload['date'], AppointmentDateTime::TIMEZONE);
        $startsAt = $payload['period'] === 'afternoon' ? $date->setTime(12, 0) : $date;
        // Existing absences store inclusive ends, at second precision.
        $endsAt = $payload['period'] === 'morning' ? $date->setTime(11, 59, 59) : $date->setTime(23, 59, 59);
        $technicianIds = array_map('intval', $payload['technician_ids']);
        sort($technicianIds);

        return DB::transaction(function () use ($actor, $payload, $startsAt, $endsAt, $technicianIds): Collection {
            // Share the booking lock, in a stable order, before reading conflicts or inserting anything.
            $technicians = $this->eligibleTechnicians()->whereKey($technicianIds)->orderBy('id')->lockForUpdate()
                ->get(['id', 'first_name', 'last_name'])->keyBy('id');
            if ($technicians->count() !== count($technicianIds)) {
                throw ValidationException::withMessages([
                    'technician_ids' => 'Sélectionnez uniquement des techniciens internes actifs, non administrateurs. Rechargez la liste si un compte a changé.',
                ]);
            }

            $absences = TechnicianAbsence::query()->whereIn('technician_id', $technicianIds)
                ->where('starts_at', '<=', $endsAt)->where('ends_at', '>=', $startsAt)
                ->orderBy('technician_id')->orderBy('starts_at')->orderBy('id')
                ->get(['id', 'technician_id', 'starts_at', 'ends_at', 'reason']);
            $appointments = Appointment::query()->onPlanning()->whereIn('technician_id', $technicianIds)
                ->where('starts_at', '<=', $endsAt)->where('ends_at', '>', $startsAt)
                ->orderBy('technician_id')->orderBy('starts_at')->orderBy('id')
                ->get(['id', 'technician_id', 'starts_at', 'ends_at']);

            $conflicts = [];
            foreach (['absence' => $absences, 'appointment' => $appointments] as $type => $records) {
                foreach ($records as $record) {
                    $technician = $technicians->get($record->technician_id);
                    $label = $type === 'absence' ? 'Absence' : 'RDV';
                    $reason = $type === 'absence' ? $record->reason : null;
                    $conflicts[] = [
                        'type' => $type,
                        'id' => $record->id,
                        'technician_id' => $technician->id,
                        'technician_name' => $technician->full_name,
                        'starts_at' => AppointmentDateTime::formatStored($record->starts_at),
                        'ends_at' => AppointmentDateTime::formatStored($record->ends_at),
                        'reason' => $reason,
                        'message' => $technician->full_name.' : '.$label.' #'.$record->id.' du '
                            .$record->starts_at->format('d/m/Y H:i').' au '.$record->ends_at->format('d/m/Y H:i')
                            .(filled($reason) ? ' ('.$reason.')' : '').'.',
                    ];
                }
            }
            if ($conflicts !== []) {
                throw TechnicianAbsenceConflict::fromConflicts($conflicts);
            }

            $created = collect();
            $reason = trim((string) ($payload['reason'] ?? ''));
            foreach ($technicians as $technician) {
                $created->push(TechnicianAbsence::query()->create([
                    'technician_id' => $technician->id,
                    'created_by' => $actor->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'reason' => $reason !== '' ? $reason : null,
                ]));
            }

            return $created;
        }, 3);
    }
}
