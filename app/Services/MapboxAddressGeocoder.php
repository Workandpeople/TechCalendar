<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MapboxAddressGeocoder
{
    public function geocode(?string $address): array
    {
        if (blank($address)) {
            return $this->emptyResult('Adresse absente.');
        }

        $token = (string) config('services.mapbox.token');

        if ($token === '') {
            return $this->emptyResult('Token Mapbox absent.');
        }

        try {
            $response = Http::timeout(8)
                ->retry(1, 150)
                ->get('https://api.mapbox.com/geocoding/v5/mapbox.places/'.rawurlencode((string) $address).'.json', [
                    'access_token' => $token,
                    'country' => 'fr',
                    'limit' => 1,
                    'types' => 'address,place,postcode,locality',
                ]);

            if (! $response->ok()) {
                return $this->emptyResult('Mapbox n a pas pu geocoder l adresse.');
            }

            $feature = $response->json('features.0');

            if (! is_array($feature)) {
                return $this->emptyResult('Aucun resultat Mapbox pour cette adresse.');
            }

            $center = $feature['center'] ?? null;

            if (! is_array($center) || count($center) < 2) {
                return $this->emptyResult('Coordonnees Mapbox absentes.');
            }

            return [
                'latitude' => (float) $center[1],
                'longitude' => (float) $center[0],
                'formatted_address' => $feature['place_name'] ?? $address,
                'mapbox_id' => $feature['id'] ?? null,
                'mapbox_confidence' => $feature['relevance'] ?? null,
                'warnings' => [],
            ];
        } catch (\Throwable) {
            return $this->emptyResult('Erreur pendant le geocodage Mapbox.');
        }
    }

    private function emptyResult(string $warning): array
    {
        return [
            'latitude' => null,
            'longitude' => null,
            'formatted_address' => null,
            'mapbox_id' => null,
            'mapbox_confidence' => null,
            'warnings' => [$warning],
        ];
    }
}
