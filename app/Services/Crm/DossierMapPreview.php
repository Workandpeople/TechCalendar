<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use App\Services\MapboxAddressGeocoder;
use App\Services\PlanningAddressGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DossierMapPreview
{
    public function __construct(private readonly MapboxAddressGeocoder $geocoder) {}

    public function coordinates(User $actor, Dossier $dossier, bool $forPlanning = false): array
    {
        Gate::forUser($actor)->authorize('view', $dossier);
        $address = DossierLocation::address($dossier);
        if (blank($dossier->address)) {
            return ['coordinates' => null, 'message' => 'Adresse non renseignée.'];
        }
        if ($point = DossierLocation::coordinates($dossier)) {
            $approximate = ($dossier->inspection_geocode['precision'] ?? null) === 'city';

            return ['coordinates' => $point, 'precision' => $approximate ? 'city' : 'address', 'message' => $approximate
                ? 'Position approximative : commune / code postal. Adresse exacte à confirmer avant la visite.'
                : 'Position corrigée et vérifiée avec Mapbox.'];
        }

        if ($forPlanning) {
            // Imported GPS points are not proof of an address match. Check only the selected dossier.
            return $this->geocodedCoordinates($address, 'planning-location-v3:'.$dossier->id.':'.hash('sha256', $address), $dossier);
        }

        $appointment = $dossier->appointments()->withTrashed()->whereNotNull('latitude')->whereNotNull('longitude')
            ->where('address', $address)
            ->where(fn ($query) => $query->whereNull('appointments.deleted_at')->orWhere('appointments.external_source', 'coffrac_archive'))
            ->orderByDesc('appointments.updated_at')->first(['appointments.latitude', 'appointments.longitude']);
        if ($point = $this->point($appointment?->latitude, $appointment?->longitude)) {
            return ['coordinates' => $point, 'message' => 'Position enregistrée du lieu d’inspection.'];
        }

        if ($dossier->source === 'coffrac') {
            $payload = DB::table('legacy_records')->where('source_table', 'dossiers')->where('dossier_id', $dossier->id)->value('payload');
            $source = json_decode($payload ?? '{}', true);
            // Never reuse the imported GPS point after a local address correction.
            if (is_array($source) && trim($source['adress'] ?? '') === trim($dossier->address)
                && trim($source['ville'] ?? '') === trim($dossier->city)
                && trim($source['zip'] ?? '') === trim($dossier->postal_code ?? '')
                && ($point = $this->point($source['latitude'] ?? null, $source['longitude'] ?? null))) {
                return ['coordinates' => $point, 'message' => 'Position importée du lieu d’inspection.'];
            }
        }

        $key = 'dossier-map-preview:'.$dossier->id.':'.hash('sha256', $address);

        return $this->geocodedCoordinates($address, $key);
    }

    private function geocodedCoordinates(string $address, string $key, ?Dossier $planningDossier = null): array
    {
        return Cache::remember($key, now()->addMinutes(5), function () use ($address, $planningDossier): array {
            $result = $planningDossier
                ? app(PlanningAddressGeocoder::class)->geocode($address, $planningDossier->postal_code, $planningDossier->city)
                : $this->geocoder->geocode($address);
            $point = $this->point($result['latitude'] ?? null, $result['longitude'] ?? null);
            $approximate = ($result['precision'] ?? null) === 'city';

            return ['coordinates' => $point, 'postal_code' => $result['postal_code'] ?? null, 'city' => $result['city'] ?? null,
                'precision' => $approximate ? 'city' : 'address', 'message' => $point
                ? ($approximate ? 'Position approximative : commune / code postal. Adresse exacte à confirmer avant la visite.'
                    : 'Position estimée par Mapbox : '.($result['formatted_address'] ?? $address))
                : 'Adresse non localisée. Ouvrir le dossier pour vérifier l’adresse.'];
        });
    }

    private function point(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180
            || ((float) $latitude === 0.0 && (float) $longitude === 0.0)) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }
}
