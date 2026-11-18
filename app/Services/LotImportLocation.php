<?php

namespace App\Services;

use App\Models\LotAppointment;
use App\Services\Crm\DossierLocation;
use Illuminate\Validation\ValidationException;

final class LotImportLocation
{
    public const CITY_WARNING = 'Position approximative : centre de la commune. Adresse exacte à confirmer avant la visite.';

    public const UNVERIFIED_WARNING = DossierLocation::UNVERIFIED_APPOINTMENT_WARNING;

    public function __construct(private readonly MapboxAddressGeocoder $geocoder) {}

    public function geocode(?string $address, ?string $postalCode, ?string $city, string $mode): array
    {
        if ($mode === 'address') {
            return $this->geocoder->geocode(trim(implode(' ', array_filter([$address, $postalCode, $city]))) ?: null);
        }
        if (! in_array($mode, ['city', 'unverified'], true) || blank($address) || blank($city)
            || ! preg_match('/^[0-9]{5}$/D', $postalCode ?? '')) {
            throw ValidationException::withMessages(['geocoding_mode' => 'Renseignez l’adresse, un code postal à 5 chiffres et la ville avant de choisir ce repli.']);
        }
        if ($mode === 'unverified') {
            return ['latitude' => null, 'longitude' => null, 'precision' => 'unverified', 'provider' => null,
                'warnings' => [self::UNVERIFIED_WARNING]];
        }
        $result = $this->geocoder->geocodeCity($postalCode.' '.$city);
        if (($result['precision'] ?? null) !== 'city' || ! DossierLocation::point($result['latitude'] ?? null, $result['longitude'] ?? null)) {
            throw ValidationException::withMessages(['geocoding_mode' => 'Commune non confirmée : vérifiez le code postal et la ville, ou choisissez de conserver l’adresse sans GPS.']);
        }

        return [...$result, 'warnings' => [self::CITY_WARNING]];
    }

    public static function review(string $mode, array $row, int $authorId): ?array
    {
        return in_array($mode, ['city', 'unverified'], true) ? ['version' => 1, 'mode' => $mode,
            'address_hash' => self::addressHash($row), 'reviewed_by' => $authorId, 'reviewed_at' => now()->toIso8601String()] : null;
    }

    public static function acceptedMode(array $row): ?string
    {
        $review = $row['location_review'] ?? null;
        if (! is_array($review) || ($review['version'] ?? null) !== 1 || ! in_array($review['mode'] ?? null, ['city', 'unverified'], true)
            || ! is_int($review['reviewed_by'] ?? null) || $review['reviewed_by'] < 1 || ! is_string($review['reviewed_at'] ?? null)
            || ! is_string($review['address_hash'] ?? null) || ! hash_equals(self::addressHash($row), $review['address_hash'])
            || blank($row['address'] ?? null) || blank($row['city'] ?? null) || ! preg_match('/^[0-9]{5}$/D', $row['postal_code'] ?? '')) {
            return null;
        }
        $point = DossierLocation::point($row['latitude'] ?? null, $row['longitude'] ?? null);

        return ($review['mode'] === 'city') === ($point !== null) ? $review['mode'] : null;
    }

    public static function blockingWarnings(array $row): array
    {
        $accepted = match (self::acceptedMode($row)) {
            'city' => self::CITY_WARNING, 'unverified' => self::UNVERIFIED_WARNING, default => null,
        };

        return array_values(array_filter($row['warnings'] ?? [], fn ($warning) => filled($warning) && $warning !== $accepted));
    }

    public static function geocodingWarnings(array $row): array
    {
        // Older previews did not distinguish location warnings from business validation errors.
        return $row['location_warnings'] ?? ['Adresse absente.', 'Token Mapbox absent.', 'Mapbox n’a pas pu géocoder l’adresse.',
            'Adresse non localisée avec suffisamment de précision. Vérifiez la rue, le code postal et la ville ; aucune position approximative n’est utilisée pour le planning.',
            'Erreur pendant le géocodage Mapbox.', self::CITY_WARNING, self::UNVERIFIED_WARNING];
    }

    public static function appointmentRow(LotAppointment $appointment): array
    {
        return [...($appointment->raw_payload ?? []), ...$appointment->only(['address', 'postal_code', 'city', 'latitude', 'longitude'])];
    }

    public static function canPlan(LotAppointment $appointment): bool
    {
        return DossierLocation::point($appointment->latitude, $appointment->longitude) !== null
            || (config('crm.v2_enabled') && self::acceptedMode(self::appointmentRow($appointment)) === 'unverified');
    }

    public static function planningMetadata(LotAppointment $appointment): array
    {
        $mode = self::acceptedMode(self::appointmentRow($appointment));

        return ['location_precision' => $mode ?? (DossierLocation::point($appointment->latitude, $appointment->longitude) ? 'address' : 'unverified'),
            'location_warning' => match ($mode) {
                'city' => self::CITY_WARNING, 'unverified' => self::UNVERIFIED_WARNING, default => null
            }];
    }

    private static function addressHash(array $row): string
    {
        return hash('sha256', json_encode(array_map(fn ($key) => trim((string) ($row[$key] ?? '')), ['address', 'postal_code', 'city']), JSON_THROW_ON_ERROR));
    }
}
