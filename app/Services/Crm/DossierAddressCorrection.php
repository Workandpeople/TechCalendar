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

    public function retainedAddress(Dossier $dossier): string
    {
        $address = trim($dossier->address ?? '');
        if ($address !== trim($dossier->city ?? '') || ($dossier->inspection_geocode['precision'] ?? null) !== 'city'
            || ! DossierLocation::coordinates($dossier)) {
            return $address;
        }

        // Follow only a contiguous locality-correction audit chain, never an unrelated old address.
        $state = $dossier->only(['address', 'postal_code', 'city']);
        foreach ($dossier->activities()->where('kind', 'address_corrected')->latest('id')->limit(25)->get(['changed_fields']) as $activity) {
            $before = $activity->changed_fields['before'] ?? null;
            $after = $activity->changed_fields['after'] ?? null;
            if (! is_array($before) || ! is_array($after) || ($after['inspection_geocode']['precision'] ?? null) !== 'city') {
                break;
            }
            foreach ($state as $key => $value) {
                if (! array_key_exists($key, $after) || $after[$key] !== $value) {
                    return $address;
                }
            }
            $street = $before['address'] ?? null;
            if (is_string($street) && trim($street) !== '' && trim($street) !== trim($before['city'] ?? '')
                && mb_strlen($street) <= 500 && ! preg_match('/[\x00-\x1F\x7F]/', $street)) {
                return trim($street);
            }
            if (! array_key_exists('address', $before) || ! array_key_exists('postal_code', $before) || ! array_key_exists('city', $before)) {
                break;
            }
            $state = array_intersect_key($before, $state);
        }

        return $address;
    }

    public function correct(User $actor, Dossier $dossier, array $data): Dossier
    {
        Gate::forUser($actor)->authorize('update', $dossier);
        $this->checkVersion($dossier, (int) $data['version']);
        // Resolve on the server, outside the SQL transaction; never trust coordinates from the browser.
        $cityOnly = MapboxGeocodingMatch::isCityQuery($data['address_query'])
            || preg_match('/^(place|postcode|locality|commune)\./', $data['mapbox_id'] ?? '');
        $result = $cityOnly ? $this->geocoder->geocodeCity(trim($data['address_query']), $data['mapbox_id'] ?? null) : $this->geocoder->geocode(trim($data['address_query']));
        if (! DossierLocation::point($result['latitude'] ?? null, $result['longitude'] ?? null)
            || (! $cityOnly && ($result['feature_type'] ?? null) !== 'address')
            || (filled($data['mapbox_id'] ?? null) && ($result['mapbox_id'] ?? null) !== $data['mapbox_id'])
            || (! $cityOnly && blank($result['street_address'] ?? null)) || blank($result['city'] ?? null)
            || mb_strlen($result['street_address']) > 500 || mb_strlen($result['city']) > 190
            || mb_strlen($result['postal_code'] ?? '') > 10) {
            throw ValidationException::withMessages(['address_query' => $cityOnly
                ? 'La commune et le code postal n’ont pas pu être confirmés. Choisissez la commune dans la liste puis réessayez. L’adresse actuelle est conservée.'
                : 'Mapbox n’a pas pu localiser cette adresse. Sélectionnez une suggestion précise puis réessayez. L’adresse actuelle est conservée.']);
        }

        return DB::transaction(function () use ($actor, $dossier, $data, $result): Dossier {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $this->checkVersion($locked, (int) $data['version']);
            $before = $locked->only(['address', 'postal_code', 'city', 'inspection_geocode']);
            $oldKey = 'dossier-map-preview:'.$locked->id.':'.hash('sha256', DossierLocation::address($locked));
            $approximate = ($result['precision'] ?? null) === 'city';
            $street = $approximate ? (array_key_exists('display_address', $data)
                ? trim((string) $data['display_address']) : $this->retainedAddress($locked)) : $result['street_address'];
            if ($approximate && $street === trim($locked->city ?? '')) {
                $street = '';
            }
            $locked->fill(['address' => $street ?: $result['city'], 'postal_code' => $result['postal_code'] ?? null, 'city' => $result['city']]);
            $locked->forceFill(['inspection_geocode' => DossierLocation::metadata($locked, $result), 'version' => $locked->version + 1])->save();
            $locked->activities()->create(['author_id' => $actor->id, 'kind' => 'address_corrected',
                'body' => ($approximate ? 'Localisation approximative sur la commune / code postal ; voie conservée pour affichage, non utilisée pour le calcul de position.' : 'Adresse d’intervention validée et position recalculée avec Mapbox.').' Rendez-vous existants conservés.',
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
