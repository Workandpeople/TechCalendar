<?php

namespace App\Services;

use App\Models\LotAppointment;

class LotAppointmentUpdateService
{
    public function __construct(
        private readonly ImportedAddressCleaner $addressCleaner,
        private readonly MapboxAddressGeocoder $geocoder,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(LotAppointment $appointment, array $attributes): LotAppointment
    {
        $cleanAddress = $this->addressCleaner->clean($this->nullableString($attributes['address'] ?? null));
        $postalCode = $this->nullableString($attributes['postal_code'] ?? null);
        $city = $this->nullableString($attributes['city'] ?? null);
        $departmentCode = $this->departmentFromPostalCode($postalCode)
            ?: $this->nullableString($attributes['department_code'] ?? null);
        $rawPayload = $appointment->raw_payload ?? [];
        $addressChanged = $this->fullAddress($appointment->address, $appointment->postal_code, $appointment->city)
            !== $this->fullAddress($cleanAddress, $postalCode, $city);
        $geocoding = $addressChanged || $appointment->latitude === null || $appointment->longitude === null
            ? $this->geocoder->geocode($this->fullAddress($cleanAddress, $postalCode, $city))
            : null;

        $rawPayload = array_merge($rawPayload, [
            'postal_code' => $postalCode,
            'city' => $city,
            'edited_manually' => true,
            'edited_at' => now()->toIso8601String(),
        ]);

        if ($geocoding !== null) {
            $rawPayload = array_merge($rawPayload, [
                'mapbox_address' => $geocoding['formatted_address'] ?? null,
                'mapbox_id' => $geocoding['mapbox_id'] ?? null,
                'mapbox_confidence' => $geocoding['mapbox_confidence'] ?? null,
            ]);
        }

        $appointment->update([
            'external_reference' => $this->nullableString($attributes['external_reference'] ?? null),
            'customer_name' => $this->customerName($attributes),
            'customer_first_name' => $this->nullableString($attributes['customer_first_name'] ?? null),
            'customer_last_name' => $this->nullableString($attributes['customer_last_name'] ?? null),
            'customer_phone' => $this->nullableString($attributes['customer_phone'] ?? null),
            'address' => $cleanAddress,
            'postal_code' => $postalCode,
            'city' => $city,
            'department_code' => $departmentCode,
            'latitude' => $geocoding !== null ? ($geocoding['latitude'] ?? null) : $appointment->latitude,
            'longitude' => $geocoding !== null ? ($geocoding['longitude'] ?? null) : $appointment->longitude,
            'ai_warnings' => $geocoding !== null ? collect($geocoding['warnings'] ?? [])->filter()->values()->all() : $appointment->ai_warnings,
            'raw_payload' => $rawPayload,
            'comment' => $this->nullableString($attributes['comment'] ?? null),
        ]);

        return $appointment->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function customerName(array $payload): string
    {
        $customerName = $this->nullableString($payload['customer_name'] ?? null);

        if ($customerName) {
            return $customerName;
        }

        return trim(implode(' ', array_filter([
            $this->nullableString($payload['customer_first_name'] ?? null),
            $this->nullableString($payload['customer_last_name'] ?? null),
        ]))) ?: 'Client à qualifier';
    }

    private function fullAddress(?string $address, ?string $postalCode, ?string $city): ?string
    {
        return trim(implode(' ', array_filter([$address, $postalCode, $city]))) ?: null;
    }

    private function departmentFromPostalCode(?string $postalCode): ?string
    {
        if (! $postalCode || ! preg_match('/\b(\d{2})\d{3}\b/', $postalCode, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
