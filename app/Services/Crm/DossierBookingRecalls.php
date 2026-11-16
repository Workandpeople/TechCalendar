<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DossierBookingRecalls
{
    public function remember(DossierOperation $operation, DossierActivity $activity, array $data): void
    {
        $operation->forceFill(['booking_recall_at' => $this->date($data)?->format('Y-m-d H:i:s'),
            'booking_recall_activity_id' => $activity->id])->saveQuietly();
    }

    /** Reconcile structured V2 history only; never interpret free-text comments. */
    public function backfill(bool $apply = false, int $limit = 500, int $afterId = 0): array
    {
        $counts = ['examined' => 0, 'eligible' => 0, 'due' => 0, 'armed' => 0, 'skipped' => 0, 'next_after_id' => $afterId];
        $candidates = DossierOperation::query()->whereIn('legacy_status', DossierStatusCatalog::STATUSES['problem']['values'])
            ->whereNull('booking_recall_activity_id')->whereHas('dossier', fn ($query) => $query->whereNull('deleted_at'))
            ->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get(['id', 'dossier_id']);
        foreach ($candidates as $candidate) {
            $counts['examined']++;
            $counts['next_after_id'] = $candidate->id;
            $inspect = function () use ($candidate, $apply): array {
                $dossier = Dossier::withTrashed()->when($apply, fn ($query) => $query->lockForUpdate())->find($candidate->dossier_id);
                $operation = DossierOperation::query()->when($apply, fn ($query) => $query->lockForUpdate())->find($candidate->id);
                if (! $dossier || ! $operation || $operation->booking_recall_activity_id !== null || ! $this->isProblem($dossier, $operation)) {
                    return ['skipped' => 1];
                }
                $activity = $this->latestDecision($operation);
                $due = $this->fromActivity($operation, $activity);
                if (! $due || $operation->appointments()->where('status', Appointment::STATUS_SCHEDULED)->exists()) {
                    return ['skipped' => 1];
                }
                $result = ['eligible' => 1, 'due' => (int) $due->lte($this->parisNow())];
                if ($apply) {
                    $this->remember($operation, $activity, $activity->changed_fields);
                    $result['armed'] = 1;
                }

                return $result;
            };
            foreach ($apply ? DB::transaction($inspect, 3) : $inspect() as $key => $value) {
                $counts[$key] += $value;
            }
        }

        return $counts;
    }

    public function process(bool $apply = false, int $limit = 200): array
    {
        $counts = ['examined' => 0, 'ready' => 0, 'reopened' => 0, 'skipped' => 0];
        $candidates = DossierOperation::query()->where('booking_recall_at', '<=', $this->parisNow()->format('Y-m-d H:i:s'))
            ->orderBy('booking_recall_at')->orderBy('id')->limit($limit)->get(['id', 'dossier_id']);
        foreach ($candidates as $candidate) {
            $counts['examined']++;
            $inspect = function () use ($candidate, $apply): array {
                $dossier = Dossier::withTrashed()->when($apply, fn ($query) => $query->lockForUpdate())->find($candidate->dossier_id);
                $operation = DossierOperation::query()->when($apply, fn ($query) => $query->lockForUpdate())->find($candidate->id);
                // A concurrent placement or a replacement reminder may have won the lock.
                if (! $dossier || ! $operation || ! $operation->booking_recall_at
                    || $operation->booking_recall_at > $this->parisNow()->format('Y-m-d H:i:s')) {
                    return ['skipped' => 1];
                }
                $activity = $this->latestDecision($operation);
                $due = $this->fromActivity($operation, $activity);
                if (! $this->isProblem($dossier, $operation) || ! $due
                    || (int) $operation->booking_recall_activity_id !== $activity?->id
                    || $due->format('Y-m-d H:i:s') !== $operation->booking_recall_at
                    || $operation->appointments()->where('status', Appointment::STATUS_SCHEDULED)->exists()) {
                    if ($apply) {
                        $operation->forceFill(['booking_recall_at' => null])->saveQuietly();
                    }

                    return ['skipped' => 1];
                }
                // DATETIME repeats the autumn wall-clock hour; wait for the actual Paris instant.
                if ($due->gt($this->parisNow())) {
                    return ['skipped' => 1];
                }
                if (! $apply) {
                    return ['ready' => 1];
                }
                $previous = $operation->legacy_status;
                // Retain the source activity as a consumption marker; do not notify external accounts.
                $operation->forceFill(['legacy_status' => 'PRISE DE RDV', 'booking_recall_at' => null])->saveQuietly();
                $dossier->activities()->create(['kind' => 'status_changed', 'body' => 'Rappel arrivé à échéance : fiche remise en prise de RDV.',
                    'client_visible' => false, 'delegataire_visible' => false,
                    'changed_fields' => ['operation_id' => $operation->id, 'action' => 'booking_recall_due',
                        'previous_status' => $previous, 'status' => 'PRISE DE RDV',
                        'recall_activity_id' => $activity->id, 'recall_at' => $due->toIso8601String()]]);
                $dossier->increment('version');

                return ['ready' => 1, 'reopened' => 1];
            };
            foreach ($apply ? DB::transaction($inspect, 3) : $inspect() as $key => $value) {
                $counts[$key] += $value;
            }
        }

        return $counts;
    }

    private function parisNow(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->setTimezone(AppointmentDateTime::TIMEZONE);
    }

    private function isProblem(Dossier $dossier, DossierOperation $operation): bool
    {
        return ! $dossier->trashed() && (int) $operation->dossier_id === $dossier->id
            && DossierStatusCatalog::describe($operation->legacy_status)['key'] === 'problem';
    }

    private function latestDecision(DossierOperation $operation): ?DossierActivity
    {
        return DossierActivity::where('dossier_id', $operation->dossier_id)
            ->whereIn('kind', ['status_changed', 'appointment_linked', 'appointment_rescheduled', 'post_visit',
                'dossier_deleted', 'dossier_restored', 'legacy_history'])
            ->where(fn ($query) => $query->where('changed_fields->operation_id', $operation->id)->orWhereNull('changed_fields->operation_id'))
            ->latest('id')->first();
    }

    private function fromActivity(DossierOperation $operation, ?DossierActivity $activity): ?CarbonImmutable
    {
        $fields = $activity?->changed_fields ?? [];
        if (! $activity || ! is_array($fields) || ! is_scalar($fields['operation_id'] ?? null) || ! is_string($fields['status'] ?? null)
            || $activity->kind !== 'status_changed' || (int) $fields['operation_id'] !== $operation->id
            || DossierStatusCatalog::describe($fields['status'] ?? null)['key'] !== 'problem') {
            return null;
        }
        try {
            return $this->date($fields);
        } catch (ValidationException) {
            return null;
        }
    }

    private function date(array $fields): ?CarbonImmutable
    {
        if (blank($fields['recall_date'] ?? null)) {
            return null;
        }
        $data = validator($fields, ['recall_date' => ['required', 'date_format:Y-m-d'],
            'recall_time' => ['nullable', 'date_format:H:i']])->validate();

        $due = AppointmentDateTime::parse($data['recall_date'].' '.(filled($data['recall_time'] ?? null) ? $data['recall_time'] : '00:00'), 'recall_time')->toImmutable();
        $wallClock = $due->format('Y-m-d H:i:s');
        $wallTimestamp = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $wallClock, 'UTC')->getTimestamp();
        // A repeated autumn hour always means its later occurrence, independent of today's DST offset.
        foreach ($due->getTimezone()->getTransitions($wallTimestamp - 86400, $wallTimestamp + 86400) as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($wallTimestamp - $transition['offset'])->setTimezone(AppointmentDateTime::TIMEZONE);
            if ($candidate->format('Y-m-d H:i:s') === $wallClock && $candidate->gt($due)) {
                $due = $candidate;
            }
        }

        return $due;
    }
}
