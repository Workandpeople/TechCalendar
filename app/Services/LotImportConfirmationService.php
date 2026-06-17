<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotImportPreview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LotImportConfirmationService
{
    public function confirm(LotImportPreview $preview, array $selectedRowNumbers): Lot
    {
        if ($preview->status === LotImportPreview::STATUS_CONFIRMED && $preview->confirmedLot) {
            return $preview->confirmedLot->load(['appointments']);
        }

        if ($preview->status !== LotImportPreview::STATUS_COMPLETED) {
            throw new \RuntimeException('L import doit être terminé avant validation.');
        }

        $selectedRowNumbers = collect($selectedRowNumbers)
            ->map(fn ($rowNumber): int => (int) $rowNumber)
            ->filter(fn (int $rowNumber): bool => $rowNumber > 0)
            ->unique()
            ->values();

        if ($selectedRowNumbers->isEmpty()) {
            throw new \RuntimeException('Sélectionne au moins une ligne à importer.');
        }

        $payload = $preview->payload ?? [];
        $appointments = collect($payload['appointments'] ?? [])
            ->filter(fn (array $appointment): bool => $selectedRowNumbers->contains((int) ($appointment['row_number'] ?? 0)))
            ->values();

        if ($appointments->isEmpty()) {
            throw new \RuntimeException('Aucune ligne sélectionnée ne correspond à la preview.');
        }

        return DB::transaction(function () use ($preview, $payload, $appointments): Lot {
            $lot = Lot::query()->create([
                'name' => $preview->name ?: pathinfo($preview->original_filename, PATHINFO_FILENAME),
                'type' => $preview->type,
                'status' => Lot::STATUS_NOT_STARTED,
                'sampling_percentage' => $preview->sampling_percentage,
                'original_filename' => $preview->original_filename,
                'original_file_disk' => $preview->original_file_disk,
                'original_file_path' => $preview->original_file_path,
                'original_file_size' => $preview->original_file_size,
                'original_file_mime' => $preview->original_file_mime,
                'import_status' => 'completed',
                'total_rows' => $preview->total_rows,
                'imported_rows' => $appointments->count(),
                'rejected_rows' => $preview->rejected_rows,
                'ai_model' => $preview->ai_model,
                'import_summary' => [
                    'summary' => $payload['summary'] ?? null,
                    'rejected_rows' => $payload['rejected_rows'] ?? [],
                    'selected_rows' => $appointments->pluck('row_number')->values()->all(),
                ],
                'created_by' => $preview->created_by,
                'imported_at' => now(),
            ]);

            foreach ($appointments as $appointmentPayload) {
                $warnings = collect($appointmentPayload['warnings'] ?? [])
                    ->filter()
                    ->values();

                LotAppointment::query()->create([
                    'lot_id' => $lot->id,
                    'service_id' => null,
                    'external_reference' => $this->nullableString($appointmentPayload['external_reference'] ?? null),
                    'row_number' => (int) ($appointmentPayload['row_number'] ?? 0) ?: null,
                    'source' => null,
                    'customer_name' => $this->requiredCustomerName($appointmentPayload),
                    'customer_first_name' => $this->nullableString($appointmentPayload['customer_first_name'] ?? null),
                    'customer_last_name' => $this->nullableString($appointmentPayload['customer_last_name'] ?? null),
                    'customer_phone' => $this->nullableString($appointmentPayload['customer_phone'] ?? null),
                    'address' => $this->nullableString($appointmentPayload['address'] ?? null),
                    'postal_code' => $this->nullableString($appointmentPayload['postal_code'] ?? null),
                    'city' => $this->nullableString($appointmentPayload['city'] ?? null),
                    'department_code' => $this->nullableString($appointmentPayload['department_code'] ?? null),
                    'latitude' => $this->coordinate($appointmentPayload['latitude'] ?? null, -90, 90),
                    'longitude' => $this->coordinate($appointmentPayload['longitude'] ?? null, -180, 180),
                    'service_type' => null,
                    'service_name' => null,
                    'duration_minutes' => null,
                    'status' => $this->statusForPayload($appointmentPayload, $warnings),
                    'ai_confidence' => $this->confidence($appointmentPayload['ai_confidence'] ?? null),
                    'ai_warnings' => $warnings->all(),
                    'raw_payload' => $appointmentPayload,
                    'comment' => $this->nullableString($appointmentPayload['comment'] ?? null),
                ]);
            }

            $preview->update([
                'status' => LotImportPreview::STATUS_CONFIRMED,
                'confirmed_lot_id' => $lot->id,
                'confirmed_at' => now(),
            ]);

            return $lot->load(['appointments']);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param Collection<int, string> $warnings
     */
    private function statusForPayload(array $payload, Collection $warnings): string
    {
        if (! filled($payload['customer_name'] ?? null) || ! filled($payload['address'] ?? null)) {
            return LotAppointment::STATUS_NEEDS_REVIEW;
        }

        if (! filled($payload['latitude'] ?? null) || ! filled($payload['longitude'] ?? null)) {
            return LotAppointment::STATUS_NEEDS_REVIEW;
        }

        if ((float) ($payload['ai_confidence'] ?? 0) < 0.65 || $warnings->isNotEmpty()) {
            return LotAppointment::STATUS_NEEDS_REVIEW;
        }

        return LotAppointment::STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredCustomerName(array $payload): string
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function coordinate(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return $coordinate >= $min && $coordinate <= $max ? $coordinate : null;
    }

    private function confidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(1, (float) $value));
    }
}
