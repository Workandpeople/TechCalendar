<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use App\Services\MapboxAddressGeocoder;
use App\Services\MapboxGeocodingMatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DossierAddressCorrection
{
    public function __construct(private readonly MapboxAddressGeocoder $geocoder) {}

    public function correct(User $actor, Dossier $dossier, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        $this->checkVersion($dossier, (int) $data['version']);
        // Resolve on the server, outside the SQL transaction; never trust coordinates from the browser.
        $cityOnly = MapboxGeocodingMatch::isCityQuery($data['address_query'])
            || preg_match('/^(place|postcode|locality)\./', $data['mapbox_id'] ?? '');
        $result = $cityOnly ? $this->geocoder->geocodeCity(trim($data['address_query'])) : $this->geocoder->geocode(trim($data['address_query']));
        if (! DossierLocation::point($result['latitude'] ?? null, $result['longitude'] ?? null)
            || (! $cityOnly && ($result['feature_type'] ?? null) !== 'address')
            || (filled($data['mapbox_id'] ?? null) && ($result['mapbox_id'] ?? null) !== $data['mapbox_id'])
            || (! $cityOnly && blank($result['street_address'] ?? null)) || blank($result['city'] ?? null)
            || mb_strlen($result['street_address']) > 500 || mb_strlen($result['city']) > 190
            || mb_strlen($result['postal_code'] ?? '') > 10) {
            throw ValidationException::withMessages(['address_query' => 'Mapbox n’a pas pu localiser cette adresse. Sélectionnez une suggestion précise puis réessayez. L’adresse actuelle est conservée.']);
        }

        return DB::transaction(function () use ($actor, $dossier, $data, $result): Dossier {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $this->checkVersion($locked, (int) $data['version']);
            $before = $locked->only(['address', 'postal_code', 'city', 'inspection_geocode']);
            $oldKey = 'dossier-map-preview:'.$locked->id.':'.hash('sha256', DossierLocation::address($locked));
            $locked->fill(['address' => $result['street_address'] ?: $result['city'], 'postal_code' => $result['postal_code'] ?? null, 'city' => $result['city']]);
            $locked->forceFill(['inspection_geocode' => DossierLocation::metadata($locked, $result), 'version' => $locked->version + 1])->save();
            $locked->activities()->create(['author_id' => $actor->id, 'kind' => 'address_corrected',
                'body' => (($result['precision'] ?? null) === 'city' ? 'Localisation approximative sur la commune / code postal, adresse exacte à confirmer.' : 'Adresse d’intervention validée et position recalculée avec Mapbox.').' Rendez-vous existants conservés.',
                'changed_fields' => ['before' => $before, 'after' => $locked->only(array_keys($before))],
                'client_visible' => false, 'delegataire_visible' => false]);
            $newKey = 'dossier-map-preview:'.$locked->id.':'.hash('sha256', DossierLocation::address($locked));
            DB::afterCommit(static function () use ($oldKey, $newKey): void {
                try {
                    Cache::forget($oldKey);
                    Cache::forget($newKey);
                } catch (\Throwable $exception) {
                    // The persisted point remains authoritative even when the cache is unavailable.
                    report($exception);
                }
            });

            return $locked;
        }, 3);
    }

    private function checkVersion(Dossier $dossier, int $version): void
    {
        if ($dossier->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Le dossier a été modifié entre-temps. Rechargez la fiche avant de corriger l’adresse.']);
        }
    }
}
