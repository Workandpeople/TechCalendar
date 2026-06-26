<?php

namespace App\Services;

use App\Events\LotImportPreviewProgressed;
use App\Models\LotImportPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LotImportPreviewProcessor
{
    public function __construct(
        private readonly LotSpreadsheetExtractor $extractor,
        private readonly LotAppointmentAiNormalizer $normalizer,
        private readonly MapboxAddressGeocoder $geocoder,
        private readonly ImportedAddressCleaner $addressCleaner,
    ) {
    }

    public function process(LotImportPreview $preview): void
    {
        $this->mark($preview, LotImportPreview::STATUS_PROCESSING, 5, 'Lecture du fichier original.');

        $file = $this->uploadedFileFromPreview($preview);
        $this->mark($preview, LotImportPreview::STATUS_PROCESSING, 12, 'Extraction des lignes du fichier.');

        $rows = $this->extractor->extract($file);
        $rawRowsByNumber = $rows->keyBy('row_number');

        $this->mark($preview, LotImportPreview::STATUS_PROCESSING, 25, sprintf(
            'Extraction terminée: %d ligne(s) détectée(s).',
            $rows->count(),
        ), [
            'total_rows' => $rows->count(),
        ]);

        $this->mark($preview, LotImportPreview::STATUS_PROCESSING, 30, 'Normalisation OpenAI en cours.');

        $normalized = $this->normalizer->normalize($rows, $preview->name, $preview->type);
        $appointments = collect($normalized['appointments'] ?? []);
        $totalAppointments = max(1, $appointments->count());

        $this->mark($preview, LotImportPreview::STATUS_PROCESSING, 35, sprintf(
            'Normalisation OpenAI terminée: %d RDV normalise(s), %d rejet(s).',
            $appointments->count(),
            count($normalized['rejected_rows'] ?? []),
        ));

        $enrichedAppointments = $appointments
            ->values()
            ->map(function (array $appointmentPayload, int $index) use ($preview, $rawRowsByNumber, $totalAppointments): array {
                $rowNumber = (int) ($appointmentPayload['row_number'] ?? 0);
                $address = $this->fullAddress($appointmentPayload);
                $geocoding = $this->geocoder->geocode($address);
                $warnings = collect($appointmentPayload['warnings'] ?? [])
                    ->merge($geocoding['warnings'] ?? [])
                    ->filter()
                    ->values()
                    ->all();

                $this->mark($preview, LotImportPreview::STATUS_PROCESSING, 35 + (int) floor((($index + 1) / $totalAppointments) * 55), sprintf(
                    'Geocodage Mapbox %d/%d: %s',
                    $index + 1,
                    $totalAppointments,
                    $this->customerName($appointmentPayload),
                ));

                return [
                    'selected' => true,
                    'row_number' => $rowNumber > 0 ? $rowNumber : null,
                    'external_reference' => $this->nullableString($appointmentPayload['external_reference'] ?? null),
                    'customer_first_name' => $this->nullableString($appointmentPayload['customer_first_name'] ?? null),
                    'customer_last_name' => $this->nullableString($appointmentPayload['customer_last_name'] ?? null),
                    'customer_name' => $this->customerName($appointmentPayload),
                    'customer_phone' => $this->phoneString($appointmentPayload['customer_phone'] ?? null),
                    'address' => $address,
                    'address_line' => $this->nullableString($appointmentPayload['address_line'] ?? null),
                    'postal_code' => $this->nullableString($appointmentPayload['postal_code'] ?? null),
                    'city' => $this->nullableString($appointmentPayload['city'] ?? null),
                    'department_code' => $this->nullableString($appointmentPayload['department_code'] ?? null) ?: $this->departmentFromPostalCode($appointmentPayload['postal_code'] ?? null),
                    'latitude' => $geocoding['latitude'] ?? $this->coordinate($appointmentPayload['latitude'] ?? null, -90, 90),
                    'longitude' => $geocoding['longitude'] ?? $this->coordinate($appointmentPayload['longitude'] ?? null, -180, 180),
                    'mapbox_address' => $geocoding['formatted_address'] ?? null,
                    'mapbox_id' => $geocoding['mapbox_id'] ?? null,
                    'mapbox_confidence' => $geocoding['mapbox_confidence'] ?? null,
                    'service_type' => null,
                    'service_name' => null,
                    'duration_minutes' => null,
                    'comment' => $this->nullableString($appointmentPayload['comment'] ?? null),
                    'ai_confidence' => $this->confidence($appointmentPayload['confidence'] ?? null),
                    'warnings' => $warnings,
                    'raw_address_parts' => $appointmentPayload['raw_address_parts'] ?? [],
                    'raw_payload' => $rawRowsByNumber->get($rowNumber)['data'] ?? null,
                ];
            })
            ->all();

        $preview->update([
            'status' => LotImportPreview::STATUS_COMPLETED,
            'progress' => 100,
            'stage' => 'Preview prêt: vérifie les lignes avant création du lot.',
            'normalized_rows' => count($enrichedAppointments),
            'rejected_rows' => count($normalized['rejected_rows'] ?? []),
            'payload' => [
                'summary' => $normalized['summary'] ?? null,
                'appointments' => $enrichedAppointments,
                'rejected_rows' => $normalized['rejected_rows'] ?? [],
            ],
            'error_message' => null,
            'completed_at' => now(),
        ]);
        $this->broadcast($preview);
    }

    private function uploadedFileFromPreview(LotImportPreview $preview): UploadedFile
    {
        $disk = Storage::disk((string) $preview->original_file_disk);
        $path = $disk->path((string) $preview->original_file_path);

        return new UploadedFile(
            $path,
            $preview->original_filename,
            $preview->original_file_mime,
            null,
            true,
        );
    }

    private function mark(LotImportPreview $preview, string $status, int $progress, string $stage, array $attributes = []): void
    {
        $preview->forceFill(array_merge([
            'status' => $status,
            'progress' => $progress,
            'stage' => $stage,
            'error_message' => null,
        ], $attributes))->save();

        $this->broadcast($preview);
    }

    private function broadcast(LotImportPreview $preview): void
    {
        try {
            broadcast(new LotImportPreviewProgressed($preview->refresh()));
        } catch (\Throwable $exception) {
            Log::warning('Lot import progress broadcast failed.', [
                'preview_id' => $preview->id,
                'stage' => $preview->stage,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fullAddress(array $payload): ?string
    {
        $address = $this->addressCleaner->clean($this->nullableString($payload['address'] ?? null));

        if ($address) {
            return $address;
        }

        $parts = [
            $this->addressCleaner->clean($this->nullableString($payload['address_line'] ?? null)),
            $this->nullableString($payload['postal_code'] ?? null),
            $this->nullableString($payload['city'] ?? null),
        ];

        return trim(implode(' ', array_filter($parts))) ?: null;
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

    private function departmentFromPostalCode(mixed $postalCode): ?string
    {
        $postalCode = $this->nullableString($postalCode);

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
