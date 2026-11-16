<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;

final class DossierLocation
{
    public const UNVERIFIED_APPOINTMENT_WARNING = 'Adresse non localisée : position et trajet à vérifier manuellement avant la visite.';

    public static function appointmentWarning(Appointment $appointment): ?string
    {
        if (self::point($appointment->latitude, $appointment->longitude) === null
            || ($appointment->external_payload['location_precision'] ?? null) === 'unverified') {
            return self::UNVERIFIED_APPOINTMENT_WARNING;
        }

        return ($appointment->external_payload['location_precision'] ?? null) === 'city'
            ? 'Position approximative : centre de la commune. Adresse exacte et trajet à confirmer avant la visite.' : null;
    }

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

    public static function departmentCode(?string $postalCode): string
    {
        $postalCode = trim($postalCode ?? '');
        if (! preg_match('/^[0-9]{5}$/D', $postalCode)) {
            return '';
        }

        return str_starts_with($postalCode, '20') ? ((int) $postalCode < 20200 ? '2A' : '2B')
            : substr($postalCode, 0, preg_match('/^9[78]/', $postalCode) ? 3 : 2);
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
        $point = self::point($result['latitude'] ?? null, $result['longitude'] ?? null);

        return [
            'address_hash' => hash('sha256', self::address($dossier)),
            'latitude' => $point['latitude'] ?? null, 'longitude' => $point['longitude'] ?? null,
            'mapbox_id' => $point ? ($result['mapbox_id'] ?? null) : null, 'verified_at' => $point ? now()->toIso8601String() : null,
            'precision' => $point ? ($result['precision'] ?? 'address') : 'unverified',
            'provider' => $point ? ($result['provider'] ?? 'mapbox') : null,
        ];
    }

    public static function needsReview(Dossier $dossier): bool
    {
        return ($dossier->inspection_geocode['precision'] ?? null) === 'unverified'
            && ($dossier->inspection_geocode['address_hash'] ?? null) === hash('sha256', self::address($dossier));
    }
}
