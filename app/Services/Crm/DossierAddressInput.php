<?php

namespace App\Services\Crm;

use App\Services\MapboxAddressGeocoder;
use Illuminate\Validation\ValidationException;

final class DossierAddressInput
{
    public function resolve(array $data, bool $requireSelection = false): ?array
    {
        $cityOnly = ($data['address_precision'] ?? null) === 'city';
        if ($requireSelection && blank($data['address'] ?? null)) {
            throw ValidationException::withMessages(['address' => 'Renseignez l’adresse de l’intervention, y compris en mode adresse non trouvée.']);
        }
        if ($cityOnly && (! preg_match('/^[0-9]{5}$/D', (string) ($data['postal_code'] ?? '')) || blank($data['city'] ?? null))) {
            throw ValidationException::withMessages(['city' => 'Renseignez un code postal à 5 chiffres et la ville.']);
        }
        if (! $cityOnly && blank($data['mapbox_id'] ?? null)) {
            if ($requireSelection) {
                throw ValidationException::withMessages(['address' => 'Choisissez une suggestion Mapbox ou utilisez « Adresse non trouvée » avec le code postal et la ville.']);
            }

            return null;
        }
        if (! $cityOnly && ! preg_match('/^address\.[a-zA-Z0-9_-]+$/D', (string) $data['mapbox_id'])) {
            throw ValidationException::withMessages(['address' => 'Choisissez une adresse précise ou utilisez « Adresse non trouvée » pour localiser la commune.']);
        }
        $query = trim(implode(' ', array_filter([$cityOnly ? null : ($data['address'] ?? null), $data['postal_code'] ?? null, $data['city'] ?? null])));
        $geocoder = app(MapboxAddressGeocoder::class);
        $result = $cityOnly ? $geocoder->geocodeCity($query, $data['mapbox_id'] ?? null) : $geocoder->geocode($query, $data['mapbox_id']);
        if (! DossierLocation::point($result['latitude'] ?? null, $result['longitude'] ?? null)
            || (filled($data['mapbox_id'] ?? null) && ($result['mapbox_id'] ?? null) !== $data['mapbox_id'])) {
            throw ValidationException::withMessages([$cityOnly ? 'city' : 'address' => 'La localisation n’a pas pu être confirmée. Vérifiez la commune ou choisissez une nouvelle suggestion. Vos informations sont conservées.']);
        }

        return $result;
    }
}
