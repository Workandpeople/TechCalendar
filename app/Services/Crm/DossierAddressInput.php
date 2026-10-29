<?php

namespace App\Services\Crm;

use App\Services\MapboxAddressGeocoder;
use Illuminate\Validation\ValidationException;

final class DossierAddressInput
{
    public function resolve(array $data): ?array
    {
        $cityOnly = ($data['address_precision'] ?? null) === 'city';
        if (! $cityOnly && blank($data['mapbox_id'] ?? null)) {
            return null;
        }
        $query = trim(implode(' ', array_filter([$cityOnly ? null : ($data['address'] ?? null), $data['postal_code'] ?? null, $data['city'] ?? null])));
        $geocoder = app(MapboxAddressGeocoder::class);
        $result = $cityOnly ? $geocoder->geocodeCity($query, $data['mapbox_id'] ?? null) : $geocoder->geocode($query);
        if (! DossierLocation::point($result['latitude'] ?? null, $result['longitude'] ?? null)
            || (filled($data['mapbox_id'] ?? null) && $result['mapbox_id'] !== $data['mapbox_id'])) {
            throw ValidationException::withMessages([$cityOnly ? 'city' : 'address' => 'La localisation n’a pas pu être confirmée. Vérifiez la commune ou choisissez une nouvelle suggestion. Vos informations sont conservées.']);
        }

        return $result;
    }
}
