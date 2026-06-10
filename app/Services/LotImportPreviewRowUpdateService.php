<?php

namespace App\Services;

use App\Models\LotImportPreview;
use RuntimeException;

class LotImportPreviewRowUpdateService
{
    public function __construct(
        private readonly ImportedAddressCleaner $addressCleaner,
        private readonly MapboxAddressGeocoder $geocoder,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(LotImportPreview $preview, int $rowNumber, array $attributes): LotImportPreview
    {
        if ($preview->status !== LotImportPreview::STATUS_COMPLETED) {
            throw new RuntimeException('La preview doit etre terminee avant modification.');
        }

        $payload = $preview->payload ?? [];
        $appointments = collect($payload['appointments'] ?? [])->values();
        $appointmentIndex = $appointments->search(fn (array $appointment): bool => (int) ($appointment['row_number'] ?? 0) === $rowNumber);

        if ($appointmentIndex === false) {
            throw new RuntimeException('Ligne introuvable dans la preview.');
        }

        $appointment = $appointments->get($appointmentIndex);
        $cleanAddress = $this->addressCleaner->clean($this->nullableString($attributes['address'] ?? null));
        $postalCode = $this->nullableString($attributes['postal_code'] ?? null);
        $city = $this->nullableString($attributes['city'] ?? null);
        $departmentCode = $this->nullableString($attributes['department_code'] ?? null) ?: $this->departmentFromPostalCode($postalCode);
        $geocoding = $this->geocoder->geocode($this->fullAddress($cleanAddress, $postalCode, $city));

        $appointment = array_merge($appointment, [
            'customer_name' => $this->customerName($attributes),
            'customer_first_name' => $this->nullableString($attributes['customer_first_name'] ?? null),
            'customer_last_name' => $this->nullableString($attributes['customer_last_name'] ?? null),
            'customer_phone' => $this->nullableString($attributes['customer_phone'] ?? null),
            'address' => $cleanAddress,
            'address_line' => $cleanAddress,
            'postal_code' => $postalCode,
            'city' => $city,
            'department_code' => $departmentCode,
            'latitude' => $geocoding['latitude'] ?? null,
            'longitude' => $geocoding['longitude'] ?? null,
            'mapbox_address' => $geocoding['formatted_address'] ?? null,
            'mapbox_id' => $geocoding['mapbox_id'] ?? null,
            'mapbox_confidence' => $geocoding['mapbox_confidence'] ?? null,
            'comment' => $this->nullableString($attributes['comment'] ?? null),
            'warnings' => collect($geocoding['warnings'] ?? [])->filter()->values()->all(),
            'edited_manually' => true,
            'edited_at' => now()->toIso8601String(),
        ]);

        $appointments->put($appointmentIndex, $appointment);

        $payload['appointments'] = $appointments->values()->all();

        $preview->update([
            'payload' => $payload,
            'normalized_rows' => $appointments->count(),
            'rejected_rows' => count($payload['rejected_rows'] ?? []),
            'stage' => sprintf('Ligne %d modifiee et geocodee.', $rowNumber),
            'error_message' => null,
        ]);

        return $preview->refresh();
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
        ]))) ?: 'Client a qualifier';
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
