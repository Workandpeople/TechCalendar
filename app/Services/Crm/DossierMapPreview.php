<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DossierMapPreview
{
    public function __construct(private readonly MapboxAddressGeocoder $geocoder) {}

    public function coordinates(User $actor, Dossier $dossier): array
    {
        Gate::forUser($actor)->authorize('view', $dossier);
        $address = DossierLocation::address($dossier);
        if (blank($dossier->address)) {
            return ['coordinates' => null, 'message' => 'Adresse non renseignée.'];
        }
        if ($point = DossierLocation::coordinates($dossier)) {
            return ['coordinates' => $point, 'message' => 'Position corrigée et vérifiée avec Mapbox.'];
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

        return Cache::remember($key, now()->addMinutes(5), function () use ($address): array {
            $result = $this->geocoder->geocode($address);
            $point = $this->point($result['latitude'] ?? null, $result['longitude'] ?? null);

            return ['coordinates' => $point, 'message' => $point
                ? 'Position estimée par Mapbox : '.($result['formatted_address'] ?? $address)
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
