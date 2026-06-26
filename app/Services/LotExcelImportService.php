<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\LotAppointment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LotExcelImportService
{
    public function __construct(
        private readonly LotSpreadsheetExtractor $extractor,
        private readonly LotAppointmentAiNormalizer $normalizer,
    ) {
    }

    public function import(UploadedFile $file, int $userId, ?string $requestedLotName = null, ?string $lotType = null, ?float $samplingPercentage = null, ?string $source = null): Lot
    {
        $rows = $this->extractor->extract($file);
        $normalized = $this->normalizer->normalize($rows, $requestedLotName, $lotType);
        $rawRowsByNumber = $rows->keyBy('row_number');
        $storedFile = $this->storeOriginalFile($file);

        try {
            return DB::transaction(function () use ($file, $userId, $requestedLotName, $lotType, $samplingPercentage, $source, $rows, $normalized, $rawRowsByNumber, $storedFile): Lot {
                $lot = Lot::query()->create([
                    'name' => filled($requestedLotName) ? trim((string) $requestedLotName) : ($normalized['lot_name'] ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
                    'type' => filled($lotType) ? trim((string) $lotType) : null,
                    'status' => Lot::STATUS_NOT_STARTED,
                    'sampling_percentage' => $samplingPercentage,
                    'source' => filled($source) ? trim((string) $source) : null,
                    'original_filename' => $file->getClientOriginalName(),
                    'original_file_disk' => $storedFile['disk'],
                    'original_file_path' => $storedFile['path'],
                    'original_file_size' => $storedFile['size'],
                    'original_file_mime' => $storedFile['mime'],
                    'import_status' => 'completed',
                    'total_rows' => $rows->count(),
                    'imported_rows' => count($normalized['appointments'] ?? []),
                    'rejected_rows' => count($normalized['rejected_rows'] ?? []),
                    'ai_model' => config('services.openai.model'),
                    'import_summary' => [
                        'summary' => $normalized['summary'] ?? null,
                        'rejected_rows' => $normalized['rejected_rows'] ?? [],
                    ],
                    'created_by' => $userId,
                    'imported_at' => now(),
                ]);

                foreach (($normalized['appointments'] ?? []) as $appointmentPayload) {
                    $warnings = collect($appointmentPayload['warnings'] ?? [])
                        ->filter()
                        ->values();
                    $status = $this->statusForPayload($appointmentPayload, $warnings);
                    $rowNumber = (int) ($appointmentPayload['row_number'] ?? 0);
                    $rawPayload = $rawRowsByNumber->get($rowNumber)['data'] ?? null;

                    LotAppointment::query()->create([
                        'lot_id' => $lot->id,
                        'service_id' => null,
                        'external_reference' => $this->nullableString($appointmentPayload['external_reference'] ?? null),
                        'row_number' => $rowNumber > 0 ? $rowNumber : null,
                        'source' => $lot->source,
                        'customer_name' => $this->requiredCustomerName($appointmentPayload),
                        'customer_first_name' => $this->nullableString($appointmentPayload['customer_first_name'] ?? null),
                        'customer_last_name' => $this->nullableString($appointmentPayload['customer_last_name'] ?? null),
                        'customer_phone' => $this->phoneString($appointmentPayload['customer_phone'] ?? null),
                        'address' => $this->nullableString($appointmentPayload['address'] ?? null),
                        'postal_code' => $this->nullableString($appointmentPayload['postal_code'] ?? null),
                        'city' => $this->nullableString($appointmentPayload['city'] ?? null),
                        'department_code' => $this->nullableString($appointmentPayload['department_code'] ?? null),
                        'latitude' => $this->coordinate($appointmentPayload['latitude'] ?? null, -90, 90),
                        'longitude' => $this->coordinate($appointmentPayload['longitude'] ?? null, -180, 180),
                        'service_type' => null,
                        'service_name' => null,
                        'duration_minutes' => null,
                        'status' => $status,
                        'ai_confidence' => $this->confidence($appointmentPayload['confidence'] ?? null),
                        'ai_warnings' => $warnings->all(),
                        'raw_payload' => $rawPayload,
                        'comment' => $this->nullableString($appointmentPayload['comment'] ?? null),
                    ]);
                }

                return $lot->load(['appointments']);
            });
        } catch (Throwable $exception) {
            Storage::disk($storedFile['disk'])->delete($storedFile['path']);

            throw $exception;
        }
    }

    /**
     * @return array{disk:string,path:string,size:int|null,mime:string|null}
     */
    private function storeOriginalFile(UploadedFile $file): array
    {
        $disk = 'local';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'lot-imports/'.now()->format('Y/m'),
            Str::uuid()->toString().'.'.$extension,
            $disk,
        );

        if (! $path) {
            throw new \RuntimeException('Impossible de sauvegarder le fichier original du lot.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ];
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

        if ((float) ($payload['confidence'] ?? 0) < 0.65 || $warnings->isNotEmpty()) {
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
