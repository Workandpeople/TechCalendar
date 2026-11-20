<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AppointmentChangeReviews
{
    public const MAX_BULK = 1000;

    private function query(): Builder
    {
        return Appointment::withTrashed()->whereHas('dossierOperation.dossier', fn ($query) => $query->whereNull('dossiers.deleted_at'));
    }

    public function counts(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $query = $this->query()->where('global_plus_review_pending', true);
        AppointmentChangeReviewScope::apply($query);
        $rows = $query
            ->orderBy('appointments.id')->toBase()->select(['appointments.id', 'global_plus_review_version'])->cursor();
        $pending = 0;
        $revision = 0;
        $fingerprint = hash_init('sha256');
        // Hash identities as well as versions: a simultaneous acknowledgement must not mask another edit.
        foreach ($rows as $row) {
            $pending++;
            $revision += (int) $row->global_plus_review_version;
            hash_update($fingerprint, $row->id.':'.$row->global_plus_review_version.';');
        }

        return ['pending' => $pending, 'revision' => $revision, 'fingerprint' => hash_final($fingerprint)];
    }

    public function capture(Appointment $appointment, ?User $actor): void
    {
        if (! config('crm.v2_enabled')) {
            return;
        }
        if (! $actor || ! Gate::forUser($actor)->allows('viewAny', Dossier::class)
            || ! $appointment->dossier_operation_id || in_array($appointment->external_source, ['mymar', 'mar'], true)
            || ! $appointment->isDirty(['starts_at', 'technician_id'])) {
            return;
        }
        // A status activity may have refreshed the review earlier in the same booking transaction.
        $baseline = Appointment::withTrashed()->lockForUpdate()->find($appointment->id,
            ['id', 'global_plus_review', 'global_plus_review_version', 'global_plus_review_pending']);
        if (! $baseline) {
            return;
        }
        $appointment->unsetRelation('dossierOperation');
        $before = $this->snapshot($appointment, true);
        $after = $this->snapshot($appointment, false);
        if ($before === $after) {
            return;
        }
        $reasons = [];
        if ($before['technician_id'] !== $after['technician_id']) {
            $reasons[] = 'technician';
        }
        if ($before['starts_at'] !== $after['starts_at']) {
            $reasons[] = 'schedule';
        }
        if ($reasons !== []) {
            $this->applyReview($appointment, $actor, $before, $after, $reasons, $baseline);
        }
    }

    public function captureStatusActivity(DossierActivity $activity): void
    {
        if (! config('crm.v2_enabled') || ! in_array($activity->kind, ['status_changed', 'appointment_linked', 'post_visit'], true)) {
            return;
        }
        $change = $activity->changed_fields ?? [];
        $previous = $change['previous_status'] ?? data_get($change, 'before.legacy_status');
        $status = $change['status'] ?? data_get($change, 'after.legacy_status');
        $actor = $activity->author;
        if (! is_int($change['operation_id'] ?? null) || (! Arr::has($change, 'previous_status') && ! Arr::has($change, 'before.legacy_status'))
            || ($previous !== null && ! is_string($previous)) || ! is_string($status)
            || ! ($reason = AppointmentChangeReviewScope::transition($previous, $status))
            || ! $actor || $actor->trashed() || $actor->isExternal() || $actor->must_change_password) {
            return;
        }
        DB::transaction(function () use ($activity, $change, $actor, $previous, $status, $reason): void {
            $operation = DossierOperation::query()->where('dossier_id', $activity->dossier_id)->lockForUpdate()->find($change['operation_id']);
            if (! $operation || $operation->legacy_status !== $status) {
                return;
            }
            $query = $operation->appointments()->withTrashed()
                ->where(fn ($query) => $query->whereNull('external_source')->orWhereNotIn('external_source', ['mymar', 'mar']));
            if (isset($change['appointment_id'])) {
                $query->whereKey($change['appointment_id']);
            }
            // A prestation can retain historical visits; only its current/latest appointment is affected.
            $appointment = $query->orderByRaw("CASE WHEN status = 'scheduled' AND deleted_at IS NULL THEN 0 ELSE 1 END")
                ->orderByDesc('starts_at')->orderByDesc('id')->lockForUpdate()->first();
            if (! $appointment || ! Gate::forUser($actor)->allows('viewAny', Dossier::class)) {
                return;
            }
            $appointment->setRelation('dossierOperation', $operation);
            $snapshot = $this->snapshot($appointment, false);
            $before = [...$snapshot, 'operation_status' => $previous];
            $after = [...$snapshot, 'operation_status' => $status];
            if ($appointment->global_plus_review_pending && ($appointment->global_plus_review['after'] ?? null) === $after) {
                return;
            }
            $this->applyReview($appointment, $actor, $before, $after, [$reason]);
            // The status activity and its review share the transaction; do not invalidate a booking form.
            $appointment->timestamps = false;
            $appointment->saveQuietly();
        }, 3);
    }

    private function applyReview(Appointment $appointment, User $actor, array $before, array $after, array $reasons, ?Appointment $baseline = null): void
    {
        $baseline ??= $appointment;
        $review = $baseline->getOriginal('global_plus_review') ?? [];
        $appointment->forceFill([
            'global_plus_review_version' => (int) $baseline->getOriginal('global_plus_review_version') + 1,
            'global_plus_review_pending' => true,
            'global_plus_review' => ['before' => $baseline->getOriginal('global_plus_review_pending') ? ($review['before'] ?? $before) : $before,
                'reasons' => array_values(array_unique([...($baseline->getOriginal('global_plus_review_pending')
                    ? AppointmentChangeReviewScope::reasons($review) : []), ...$reasons])),
                'after' => $after, 'changed_at' => now()->toIso8601String(), 'changed_by' => $actor->id,
                'changed_by_name' => $actor->full_name],
        ]);
    }

    public function search(User $actor, array $filters): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $query = $this->filtered($filters)->with(['dossierOperation.dossier', 'service']);

        return $query->orderByDesc('global_plus_review->changed_at')->orderByDesc('id')->paginate(20)->withQueryString();
    }

    private function filtered(array $filters): Builder
    {
        $query = $this->query()->where('global_plus_review_version', '>', 0);
        if (($filters['status'] ?? 'pending') === 'excluded') {
            $query->whereNotIn('id', $this->relevantIds());
        } else {
            AppointmentChangeReviewScope::apply($query);
        }
        if (! in_array($filters['status'] ?? 'pending', ['all', 'excluded'], true)) {
            $query->where('global_plus_review_pending', ($filters['status'] ?? 'pending') === 'pending');
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->whereHas('dossierOperation.dossier', function ($query) use ($term): void {
                $query->where(function ($query) use ($term): void {
                    foreach (['reference', 'first_name', 'last_name', 'company_name', 'legacy_lot_name'] as $column) {
                        $query->orWhereRaw("$column LIKE ? ESCAPE '!'", [$term]);
                    }
                });
            });
        }

        return $query;
    }

    private function relevantIds(): Builder
    {
        $query = Appointment::withTrashed()->select('id');
        AppointmentChangeReviewScope::apply($query);

        return $query;
    }

    public function bulkPreview(User $actor, array $filters): ?array
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        if (($filters['status'] ?? 'pending') !== 'pending') {
            return null;
        }
        $rows = $this->filtered($filters)->orderBy('id')->limit(self::MAX_BULK + 1)->get(['id', 'global_plus_review_version']);
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->count() > self::MAX_BULK) {
            return ['count' => $rows->count(), 'snapshot' => null];
        }

        return ['count' => $rows->count(), 'snapshot' => Crypt::encryptString(json_encode([
            'purpose' => 'global-plus-bulk-v1', 'actor' => $actor->id, 'issued' => now()->timestamp,
            'rows' => $rows->map(fn ($row) => ['id' => $row->id, 'version' => $row->global_plus_review_version])->all(),
        ], JSON_THROW_ON_ERROR))];
    }

    public function acknowledgeBulk(User $actor, string $snapshot): int
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        try {
            $data = json_decode(Crypt::decryptString($snapshot), true, 8, JSON_THROW_ON_ERROR);
            $valid = ($data['purpose'] ?? null) === 'global-plus-bulk-v1' && ($data['actor'] ?? null) === $actor->id
                && is_int($data['issued'] ?? null) && $data['issued'] <= now()->timestamp
                && $data['issued'] >= now()->subMinutes(15)->timestamp && is_array($data['rows'] ?? null)
                && array_is_list($data['rows']) && count($data['rows']) >= 1 && count($data['rows']) <= self::MAX_BULK;
            if (! $valid) {
                throw new \RuntimeException('Invalid selection.');
            }
            foreach ($data['rows'] ?? [] as $row) {
                $valid = $valid && is_array($row) && is_int($row['id'] ?? null) && $row['id'] > 0
                    && is_int($row['version'] ?? null) && $row['version'] > 0;
            }
            if (! $valid || count(array_unique(array_column($data['rows'], 'id'))) !== count($data['rows'])) {
                throw new \RuntimeException('Invalid selection.');
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['snapshot' => 'Cette sélection est expirée ou invalide. Rechargez la file avant de confirmer.']);
        }

        return DB::transaction(function () use ($actor, $data): int {
            $ids = array_column($data['rows'], 'id');
            $dossierIds = $this->query()->whereIn('appointments.id', $ids)->join('dossier_operations', 'dossier_operations.id', '=', 'appointments.dossier_operation_id')
                ->pluck('dossier_operations.dossier_id')->unique()->sort();
            $dossiers = Dossier::query()->whereIn('id', $dossierIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $appointments = $this->query()->with('dossierOperation')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $count = 0;
            foreach ($data['rows'] as $row) {
                $appointment = $appointments->get($row['id']);
                if (! $appointment || AppointmentChangeReviewScope::reasons($appointment->global_plus_review ?? []) === []) {
                    throw ValidationException::withMessages(['snapshot' => 'La sélection a changé. Aucun lot partiel : rechargez la file.']);
                }
                $dossier = $dossiers->get($appointment->dossierOperation->dossier_id);
                abort_unless($dossier, 404);
                $count += (int) $this->acknowledgeLocked($actor, $appointment, $dossier, $row['version']);
            }

            return $count;
        }, 3);
    }

    public function acknowledge(User $actor, int $id, int $version): void
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        DB::transaction(function () use ($actor, $id, $version): void {
            $appointment = Appointment::withTrashed()->lockForUpdate()->findOrFail($id);
            abort_unless($appointment->dossierOperation && $appointment->global_plus_review_version > 0, 404);
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($appointment->dossierOperation->dossier_id);
            $this->acknowledgeLocked($actor, $appointment, $dossier, $version);
        }, 3);
    }

    private function acknowledgeLocked(User $actor, Appointment $appointment, Dossier $dossier, int $version): bool
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        abort_unless(AppointmentChangeReviewScope::reasons($appointment->global_plus_review ?? []) !== [], 422, 'Modification hors du périmètre Global+.');
        if ($appointment->global_plus_review_version !== $version) {
            throw ValidationException::withMessages(['version' => 'Ce RDV a encore changé. Rechargez la file et reportez la dernière version avant de valider.']);
        }
        if (! $appointment->global_plus_review_pending) {
            return false;
        }
        // A manual acknowledgement does not change the visit or invalidate a scheduling form.
        $appointment->timestamps = false;
        $appointment->forceFill(['global_plus_review_pending' => false, 'global_plus_review' => [
            ...$appointment->global_plus_review, 'acknowledged_at' => now()->toIso8601String(),
            'acknowledged_by' => $actor->id, 'acknowledged_by_name' => $actor->full_name,
        ]])->save();
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'global_plus_change_acknowledged',
            'body' => 'Modification du RDV #'.$appointment->id.' marquée comme reportée manuellement sur Global+ (version '.$version.').',
            'client_visible' => false, 'delegataire_visible' => false,
            'changed_fields' => ['appointment_id' => $appointment->id, 'review_version' => $version, 'review' => $appointment->global_plus_review]]);

        return true;
    }

    private function snapshot(Appointment $appointment, bool $original): array
    {
        $technicianId = (int) ($original ? $appointment->getOriginal('technician_id') : $appointment->technician_id);
        $technician = User::withTrashed()->find($technicianId);

        return ['technician_id' => $technicianId, 'technician' => $technician?->full_name ?: 'Technicien indisponible',
            'starts_at' => AppointmentDateTime::formatStored($original ? $appointment->getOriginal('starts_at') : $appointment->starts_at),
            'ends_at' => AppointmentDateTime::formatStored($original ? $appointment->getOriginal('ends_at') : $appointment->ends_at),
            'duration_minutes' => (int) ($original ? $appointment->getOriginal('duration_minutes') : $appointment->duration_minutes),
            'appointment_status' => $original ? $appointment->getOriginal('status') : $appointment->status,
            'operation_status' => $appointment->dossierOperation?->legacy_status];
    }

    public static function appointmentStatusLabel(?string $status): string
    {
        return match ($status) {
            Appointment::STATUS_SCHEDULED => 'Planifié', Appointment::STATUS_PROBLEM => 'Problème RDV',
            Appointment::STATUS_CANCELLED => 'RDV annulé', default => $status ?: 'Non renseigné',
        };
    }

    public static function operationStatusLabel(?string $status): string
    {
        $description = DossierStatusCatalog::describe($status);

        return $description['key'] === 'unknown' ? ($status ?: 'Non renseigné') : $description['label'];
    }
}
