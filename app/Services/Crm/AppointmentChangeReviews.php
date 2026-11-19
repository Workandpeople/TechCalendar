<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AppointmentChangeReviews
{
    public function counts(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $rows = Appointment::withTrashed()->where('global_plus_review_pending', true)->whereHas('dossierOperation.dossier')
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
        $statusChanged = $appointment->isDirty('status');
        $assignedTechnicianReport = $actor && ! $actor->trashed() && ! $actor->isExternal() && ! $actor->must_change_password && $actor->role === 2
            && $actor->id === (int) $appointment->technician_id && $statusChanged
            && $appointment->getOriginal('status') === Appointment::STATUS_SCHEDULED && $appointment->status === Appointment::STATUS_PROBLEM;
        if (! $actor || (! Gate::forUser($actor)->allows('viewAny', Dossier::class) && ! $assignedTechnicianReport)
            || ! $appointment->dossier_operation_id || in_array($appointment->external_source, ['mymar', 'mar'], true)
            || ! $appointment->isDirty(['starts_at', 'ends_at', 'technician_id', 'duration_minutes', 'status'])) {
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
        $this->applyReview($appointment, $actor, $before, $after, $baseline);
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
            || DossierStatusCatalog::describe($previous)['key'] === DossierStatusCatalog::describe($status)['key']
            || DossierStatusCatalog::describe($status)['key'] === 'unknown'
            || ! $actor || $actor->trashed() || $actor->isExternal() || $actor->must_change_password) {
            return;
        }
        DB::transaction(function () use ($activity, $change, $actor, $previous, $status): void {
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
            if ($activity->kind === 'appointment_linked' && ! $appointment?->global_plus_review_version) {
                return;
            }
            $assignedTechnicianReport = $appointment && $actor->role === 2 && $actor->id === (int) $appointment->technician_id
                && ($change['action'] ?? null) === 'on_site_return_to_booking' && ($change['appointment_id'] ?? null) === $appointment->id;
            if (! $appointment || (! Gate::forUser($actor)->allows('viewAny', Dossier::class) && ! $assignedTechnicianReport)) {
                return;
            }
            $appointment->setRelation('dossierOperation', $operation);
            $snapshot = $this->snapshot($appointment, false);
            $before = [...$snapshot, 'operation_status' => $previous];
            $after = [...$snapshot, 'operation_status' => $status];
            if ($appointment->global_plus_review_pending && ($appointment->global_plus_review['after'] ?? null) === $after) {
                return;
            }
            $this->applyReview($appointment, $actor, $before, $after);
            // The status activity and its review share the transaction; do not invalidate a booking form.
            $appointment->timestamps = false;
            $appointment->saveQuietly();
        }, 3);
    }

    private function applyReview(Appointment $appointment, User $actor, array $before, array $after, ?Appointment $baseline = null): void
    {
        $baseline ??= $appointment;
        $review = $baseline->getOriginal('global_plus_review') ?? [];
        $appointment->forceFill([
            'global_plus_review_version' => (int) $baseline->getOriginal('global_plus_review_version') + 1,
            'global_plus_review_pending' => true,
            'global_plus_review' => ['before' => $baseline->getOriginal('global_plus_review_pending') ? ($review['before'] ?? $before) : $before,
                'after' => $after, 'changed_at' => now()->toIso8601String(), 'changed_by' => $actor->id,
                'changed_by_name' => $actor->full_name],
        ]);
    }

    public function search(User $actor, array $filters): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        $query = Appointment::withTrashed()->where('global_plus_review_version', '>', 0)
            ->whereHas('dossierOperation.dossier')->with(['dossierOperation.dossier', 'service']);
        if (($filters['status'] ?? 'pending') !== 'all') {
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

        return $query->orderByDesc('global_plus_review->changed_at')->orderByDesc('id')->paginate(20)->withQueryString();
    }

    public function acknowledge(User $actor, int $id, int $version): void
    {
        Gate::forUser($actor)->authorize('viewAny', Dossier::class);
        DB::transaction(function () use ($actor, $id, $version): void {
            $appointment = Appointment::withTrashed()->lockForUpdate()->findOrFail($id);
            abort_unless($appointment->dossierOperation && $appointment->global_plus_review_version > 0, 404);
            if ($appointment->global_plus_review_version !== $version) {
                throw ValidationException::withMessages(['version' => 'Ce RDV a encore changé. Rechargez la file et reportez la dernière version avant de valider.']);
            }
            if (! $appointment->global_plus_review_pending) {
                return;
            }
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($appointment->dossierOperation->dossier_id);
            Gate::forUser($actor)->authorize('update', $dossier);
            // A manual acknowledgement does not change the visit or invalidate a scheduling form.
            $appointment->timestamps = false;
            $appointment->forceFill(['global_plus_review_pending' => false, 'global_plus_review' => [
                ...$appointment->global_plus_review, 'acknowledged_at' => now()->toIso8601String(),
                'acknowledged_by' => $actor->id, 'acknowledged_by_name' => $actor->full_name,
            ]])->save();
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'global_plus_change_acknowledged',
                'body' => 'Modification du RDV #'.$id.' marquée comme reportée manuellement sur Global+ (version '.$version.').',
                'client_visible' => false, 'delegataire_visible' => false,
                'changed_fields' => ['appointment_id' => $id, 'review_version' => $version, 'review' => $appointment->global_plus_review]]);
        }, 3);
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
