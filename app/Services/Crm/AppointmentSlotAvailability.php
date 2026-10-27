<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentSlotAvailability
{
    public const NEARBY_DISTANCE_KM = 1.0;

    public function sameSite(array $location, Appointment $appointment): bool
    {
        $address = $this->normalizeAddress($location['address'] ?? '');
        if ($address !== '' && $address === $this->normalizeAddress($appointment->address ?? '')) {
            return true;
        }
        $from = DossierLocation::point($location['latitude'] ?? null, $location['longitude'] ?? null);
        $to = DossierLocation::point($appointment->latitude, $appointment->longitude);
        if ($from === null || $to === null) {
            return false;
        }
        $deltaLat = deg2rad($to['latitude'] - $from['latitude']);
        $deltaLng = deg2rad($to['longitude'] - $from['longitude']);
        $a = min(1.0, max(0.0, sin($deltaLat / 2) ** 2
            + cos(deg2rad($from['latitude'])) * cos(deg2rad($to['latitude'])) * sin($deltaLng / 2) ** 2));

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 9) < self::NEARBY_DISTANCE_KM;
    }

    public function overlaps(int $technicianId, CarbonInterface $start, CarbonInterface $end, ?int $ignoreId = null): Collection
    {
        return Appointment::query()->where('technician_id', $technicianId)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['id', 'address', 'latitude', 'longitude', 'starts_at', 'ends_at']);
    }

    /** Called after locking the technician, inside the booking/edit transaction. */
    public function assertAvailable(User $technician, CarbonInterface $start, CarbonInterface $end, array $location, ?int $ignoreId = null, bool $forced = false): array
    {
        $overlaps = $this->overlaps($technician->id, $start, $end, $ignoreId);
        $absences = $technician->absences()->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get(['id']);
        if (! $forced) {
            if ($absences->isNotEmpty()) {
                throw ValidationException::withMessages(['starts_at' => 'Le technicien est absent sur ce créneau.']);
            }
            if ($overlaps->contains(fn (Appointment $appointment): bool => ! $this->sameSite($location, $appointment))) {
                throw ValidationException::withMessages(['starts_at' => 'Ce technicien a déjà un RDV sur ce créneau à une autre adresse (distance non vérifiée ou supérieure ou égale à 1 km).']);
            }
        }

        return ['overlapping_appointment_ids' => $overlaps->modelKeys(), 'absence_ids' => $absences->modelKeys()];
    }

    public function recordForcedPlacement(User $actor, Appointment $appointment, array $checks): void
    {
        $dossier = $appointment->dossierOperation?->dossier;
        if (! $dossier) {
            throw ValidationException::withMessages(['force_placement' => 'Impossible de tracer ce placement forcé sur le dossier. Aucun placement effectué.']);
        }
        $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'appointment_forced',
            'body' => 'Placement forcé du RDV #'.$appointment->id.' confirmé pour le '.$appointment->starts_at->format('d/m/Y H:i').'. Contraintes de planning outrepassées.',
            'changed_fields' => ['appointment_id' => $appointment->id, 'technician_id' => $appointment->technician_id,
                'starts_at' => $appointment->starts_at->toIso8601String(), 'ends_at' => $appointment->ends_at->toIso8601String(),
                'forced_placement' => true, 'confirmed_at' => now()->toIso8601String(), ...$checks],
            'client_visible' => false, 'delegataire_visible' => false]);
    }

    private function normalizeAddress(string $address): string
    {
        $normalized = Str::squish(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($address))) ?? '');

        // A city name, empty label or placeholder alone is not proof of an identical address.
        return strlen($normalized) >= 8 && str_contains($normalized, ' ')
            && preg_match('/[a-z]/', $normalized) && preg_match('/[0-9]/', $normalized) ? $normalized : '';
    }
}
