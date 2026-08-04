<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotImportPreview;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $selectedRowsWithWarnings = $appointments
            ->filter(fn (array $appointment): bool => collect($appointment['warnings'] ?? [])->filter()->isNotEmpty())
            ->map(fn (array $appointment): int => (int) ($appointment['row_number'] ?? 0))
            ->filter()
            ->values();

        if ($selectedRowsWithWarnings->isNotEmpty()) {
            throw new \RuntimeException(sprintf(
                'Corrige ou décoche les lignes avec warning avant validation : %s.',
                $selectedRowsWithWarnings->join(', '),
            ));
        }

        return DB::transaction(function () use ($preview, $payload, $appointments): Lot {
            $service = $preview->service_id
                ? Service::query()->find((int) $preview->service_id)
                : null;

            $lot = Lot::query()->create([
                'name' => $preview->name ?: pathinfo($preview->original_filename, PATHINFO_FILENAME),
                'type' => $preview->type,
                'service_id' => $service?->id,
                'status' => Lot::STATUS_NOT_STARTED,
                'sampling_percentage' => $preview->sampling_percentage,
                'physical_sampling_percentage' => $preview->physical_sampling_percentage,
                'contact_sampling_percentage' => $preview->contact_sampling_percentage,
                'delegataire' => $preview->delegataire,
                'received_at' => $preview->received_at,
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
                    'service_id' => $service?->id,
                    'external_reference' => $this->nullableString($appointmentPayload['external_reference'] ?? null),
                    'row_number' => (int) ($appointmentPayload['row_number'] ?? 0) ?: null,
                    'source' => null,
                    'customer_name' => $this->requiredCustomerName($appointmentPayload),
                    'company_name' => $this->nullableString($appointmentPayload['company_name'] ?? null),
                    'site_name' => $this->nullableString($appointmentPayload['site_name'] ?? null),
                    'customer_first_name' => $this->nullableString($appointmentPayload['customer_first_name'] ?? null),
                    'customer_last_name' => $this->nullableString($appointmentPayload['customer_last_name'] ?? null),
                    'customer_phone' => $this->phoneString($appointmentPayload['customer_phone'] ?? null),
                    'address' => $this->nullableString($appointmentPayload['address'] ?? null),
                    'postal_code' => $this->nullableString($appointmentPayload['postal_code'] ?? null),
                    'city' => $this->nullableString($appointmentPayload['city'] ?? null),
                    'department_code' => $this->nullableString($appointmentPayload['department_code'] ?? null),
                    'latitude' => $this->coordinate($appointmentPayload['latitude'] ?? null, -90, 90),
                    'longitude' => $this->coordinate($appointmentPayload['longitude'] ?? null, -180, 180),
                    'service_type' => $service?->type,
                    'service_name' => $service?->name,
                    'duration_minutes' => $service?->average_duration_minutes,
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

            return $lot->load(['appointments', 'service']);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param Collection<int, string> $warnings
     */
    private function statusForPayload(array $payload, Collection $warnings): string
    {
        if (! $this->hasCustomerIdentity($payload) || ! filled($payload['address'] ?? null)) {
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
        $companyName = $this->nullableString($payload['company_name'] ?? null);

        if ($companyName) {
            return $companyName;
        }

        $customerName = $this->nullableString($payload['customer_name'] ?? null);

        if ($customerName) {
            return $customerName;
        }

        $individualName = trim(implode(' ', array_filter([
            $this->nullableString($payload['customer_first_name'] ?? null),
            $this->nullableString($payload['customer_last_name'] ?? null),
        ])));

        if ($individualName !== '') {
            return $individualName;
        }

        return $this->nullableString($payload['site_name'] ?? null) ?: 'Client à qualifier';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasCustomerIdentity(array $payload): bool
    {
        return filled($this->nullableString($payload['customer_name'] ?? null))
            || filled($this->nullableString($payload['company_name'] ?? null))
            || filled($this->nullableString($payload['site_name'] ?? null))
            || filled($this->nullableString($payload['customer_first_name'] ?? null))
            || filled($this->nullableString($payload['customer_last_name'] ?? null));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function phoneString(mixed $value): ?string
    {
        $phone = $this->nullableString($value);

        return $phone === null ? null : Str::limit($phone, 255, '');
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
