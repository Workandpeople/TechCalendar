<?php

namespace App\Services\Crm;

use App\Models\Dossier;

final class DossierLocation
{
    public static function address(Dossier $dossier): string
    {
        $street = trim($dossier->address ?? '');

        return trim(implode(', ', array_filter([$street === trim($dossier->city ?? '') ? null : $street, trim($dossier->postal_code.' '.$dossier->city)])));
    }

    public static function point(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180
            || ((float) $latitude === 0.0 && (float) $longitude === 0.0)) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    public static function coordinates(Dossier $dossier): ?array
    {
        $stored = $dossier->inspection_geocode;
        if (! is_array($stored) || ($stored['address_hash'] ?? null) !== hash('sha256', self::address($dossier))) {
            return null;
        }

        return self::point($stored['latitude'] ?? null, $stored['longitude'] ?? null);
    }

    public static function metadata(Dossier $dossier, array $result): array
    {
        return [
            'address_hash' => hash('sha256', self::address($dossier)),
            'latitude' => (float) $result['latitude'], 'longitude' => (float) $result['longitude'],
            'mapbox_id' => $result['mapbox_id'] ?? null, 'verified_at' => now()->toIso8601String(),
            'precision' => $result['precision'] ?? 'address',
            'provider' => $result['provider'] ?? 'mapbox',
        ];
    }
}
