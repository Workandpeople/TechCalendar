<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MapboxAddressGeocoder
{
    private const COUNTRIES = 'fr,gp,mq,gf,re,yt,pm,bl,mf,pf,nc,wf';

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
                    'country' => self::COUNTRIES,
                    'limit' => 5,
                    'types' => 'address,place,postcode,locality',
                    'language' => 'fr',
                    'autocomplete' => 'false',
                ]);

            if (! $response->ok()) {
                return $this->emptyResult('Mapbox n’a pas pu géocoder l’adresse.');
            }

            $features = $response->json('features');
            $feature = collect(is_array($features) ? $features : [])
                ->filter(fn ($candidate) => is_array($candidate)
                    && app(MapboxGeocodingMatch::class)->accepts((string) $address, $candidate, $this->addressParts($candidate)))
                ->first();

            if (! is_array($feature)) {
                return $this->emptyResult('Adresse non localisée avec suffisamment de précision. Vérifiez la rue, le code postal et la ville ; aucune position approximative n’est utilisée pour le planning.');
            }

            $center = $feature['center'];

            return [
                'latitude' => (float) $center[1],
                'longitude' => (float) $center[0],
                'formatted_address' => $feature['place_name'] ?? $address,
                'mapbox_id' => $feature['id'] ?? null,
                'mapbox_confidence' => $feature['relevance'] ?? null,
                ...$this->addressParts($feature),
                'warnings' => [],
            ];
        } catch (\Throwable) {
            return $this->emptyResult('Erreur pendant le géocodage Mapbox.');
        }
    }

    public function suggestions(string $query): array
    {
        $token = (string) config('services.mapbox.token');
        if ($token === '') {
            throw ValidationException::withMessages(['q' => 'La recherche d’adresse Mapbox n’est pas configurée.']);
        }
        try {
            $response = Http::timeout(8)->get('https://api.mapbox.com/geocoding/v5/mapbox.places/'.rawurlencode(trim($query)).'.json', [
                'access_token' => $token, 'country' => self::COUNTRIES, 'language' => 'fr',
                'limit' => 5, 'types' => 'address', 'autocomplete' => 'true',
            ]);
            if (! $response->ok() || ! is_array($response->json('features'))) {
                throw new \RuntimeException('Mapbox unavailable.');
            }

            return collect($response->json('features'))->filter(fn ($feature) => is_array($feature)
                && is_string($feature['id'] ?? null) && preg_match('/^address\.[a-zA-Z0-9_-]+$/D', $feature['id'])
                && is_string($feature['place_name'] ?? null) && trim($feature['place_name']) !== '')
                ->take(5)->map(fn ($feature) => ['id' => $feature['id'], 'label' => $feature['place_name']])->values()->all();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['q' => 'La recherche Mapbox est momentanément indisponible. Réessayez dans un instant.']);
        }
    }

    private function addressParts(array $feature): array
    {
        $context = collect(is_array($feature['context'] ?? null) ? $feature['context'] : []);
        $text = static fn ($value) => is_string($value) || is_numeric($value) ? trim((string) $value) : '';
        $part = static fn ($type) => $text($context->first(fn ($item) => is_array($item)
            && str_starts_with((string) ($item['id'] ?? ''), $type.'.'))['text'] ?? '');
        $street = trim($text($feature['address'] ?? '').' '.$text($feature['text'] ?? ''));

        return [
            'street_address' => $street,
            'postal_code' => $part('postcode') ?: null,
            'city' => $part('place') ?: $part('locality'),
            'feature_type' => is_array($feature['place_type'] ?? null) ? ($feature['place_type'][0] ?? null) : null,
        ];
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
