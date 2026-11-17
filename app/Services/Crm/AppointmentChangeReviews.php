<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        if (! $actor || ! Gate::forUser($actor)->allows('viewAny', Dossier::class)
            || ! $appointment->dossier_operation_id || $appointment->external_source === 'mymar'
            || ! $appointment->isDirty(['starts_at', 'ends_at', 'technician_id', 'duration_minutes'])) {
            return;
        }
        $before = $this->snapshot($appointment, true);
        $after = $this->snapshot($appointment, false);
        if ($before === $after) {
            return;
        }
        $review = $appointment->getOriginal('global_plus_review') ?? [];
        $appointment->forceFill([
            'global_plus_review_version' => (int) $appointment->getOriginal('global_plus_review_version') + 1,
            'global_plus_review_pending' => true,
            'global_plus_review' => ['before' => $appointment->getOriginal('global_plus_review_pending') ? ($review['before'] ?? $before) : $before,
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

        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(20)->withQueryString();
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
            'duration_minutes' => (int) ($original ? $appointment->getOriginal('duration_minutes') : $appointment->duration_minutes)];
    }
}
