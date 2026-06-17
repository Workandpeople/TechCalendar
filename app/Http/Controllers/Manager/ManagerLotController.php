<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotImportPreview;
use App\Services\LotExcelImportService;
use App\Services\LotAutoCompletionCalculator;
use App\Services\LotAppointmentUpdateService;
use App\Services\LotImportConfirmationService;
use App\Services\LotImportPreviewService;
use App\Services\LotImportPreviewRowUpdateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ManagerLotController extends Controller
{
    public function index(Request $request, LotAutoCompletionCalculator $autoCompletion): View
    {
        abort_unless($this->canAccess($request), 403);

        $filters = $this->validatedFilters($request);
        $lots = $this->lotQuery($filters)
            ->latest()
            ->get()
            ->map(fn (Lot $lot): array => $this->serializeLot($lot, $autoCompletion));

        return view('manager.lots.index', [
            'lots' => $lots,
            'lotTypes' => Lot::types(),
            'lotStatuses' => Lot::statuses(),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'stats' => [
                'lots_count' => $lots->count(),
                'appointments_count' => $lots->sum('appointments_count'),
                'placeable_count' => $lots->sum('placeable_count'),
                'placed_count' => $lots->sum('placed_count'),
            ],
            'activeImportPreview' => $this->activeImportPreview($request),
            'mapboxToken' => config('services.mapbox.token'),
        ]);
    }

    public function store(Request $request, LotExcelImportService $importer): RedirectResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'type' => ['required', 'string', Rule::in(array_keys(Lot::types()))],
            'sampling_percentage' => [
                Rule::requiredIf(fn (): bool => Lot::requiresSamplingPercentageFor($request->input('type'))),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ]);

        try {
            $lot = $importer->import(
                file: $payload['file'],
                userId: (int) $request->user()->id,
                requestedLotName: $payload['name'] ?? null,
                lotType: $payload['type'],
                samplingPercentage: isset($payload['sampling_percentage']) ? (float) $payload['sampling_percentage'] : null,
                source: null,
            );
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except('file'))
                ->withErrors(['file' => $exception->getMessage()]);
        }

        return redirect()
            ->route('manager.lots')
            ->with('status', sprintf('Lot "%s" importé : %d RDV créé(s), %d ligne(s) rejetée(s).', $lot->name, $lot->imported_rows, $lot->rejected_rows));
    }

    public function startImport(Request $request, LotImportPreviewService $imports): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'type' => ['required', 'string', Rule::in(array_keys(Lot::types()))],
            'sampling_percentage' => [
                Rule::requiredIf(fn (): bool => Lot::requiresSamplingPercentageFor($request->input('type'))),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ]);

        $preview = $imports->createFromUpload(
            file: $payload['file'],
            userId: (int) $request->user()->id,
            lotType: $payload['type'],
            samplingPercentage: isset($payload['sampling_percentage']) ? (float) $payload['sampling_percentage'] : null,
            requestedLotName: $payload['name'] ?? null,
        );

        return response()->json([
            'uuid' => $preview->uuid,
            'status' => $preview->status,
            'progress' => $preview->progress,
            'stage' => $preview->stage,
            'status_url' => route('manager.lots.imports.show', $preview),
            'confirm_url' => route('manager.lots.imports.confirm', $preview),
            'retry_url' => route('manager.lots.imports.retry', $preview),
        ], 202);
    }

    public function importStatus(Request $request, LotImportPreview $preview): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        return response()->json($this->serializePreview($preview->refresh()));
    }

    public function retryImport(
        Request $request,
        LotImportPreview $preview,
        LotImportPreviewService $imports,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $preview = $imports->retry($preview);

        return response()->json($this->serializePreview($preview), 202);
    }

    public function confirmImport(
        Request $request,
        LotImportPreview $preview,
        LotImportConfirmationService $confirmation,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'selected_rows' => ['required', 'array', 'min:1'],
            'selected_rows.*' => ['required', 'integer', 'min:1'],
        ]);

        $lot = $confirmation->confirm($preview, $payload['selected_rows']);

        return response()->json([
            'message' => sprintf('Lot "%s" créé avec %d RDV.', $lot->name, $lot->appointments()->count()),
            'redirect_url' => route('manager.lots'),
            'lot_id' => $lot->id,
        ]);
    }

    public function updateImportRow(
        Request $request,
        LotImportPreview $preview,
        int $rowNumber,
        LotImportPreviewRowUpdateService $rowUpdater,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'department_code' => ['nullable', 'string', 'max:3'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'force_geocode' => ['nullable', 'boolean'],
        ]);

        try {
            $preview = $rowUpdater->update($preview, $rowNumber, $payload);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($this->serializePreview($preview));
    }

    public function download(Request $request, Lot $lot)
    {
        abort_unless($this->canAccess($request), 403);
        abort_unless(filled($lot->original_file_disk) && filled($lot->original_file_path), 404);

        $disk = Storage::disk((string) $lot->original_file_disk);
        abort_unless($disk->exists((string) $lot->original_file_path), 404);

        return $disk->download(
            (string) $lot->original_file_path,
            $lot->original_filename ?: sprintf('lot-%d.xlsx', $lot->id),
        );
    }

    public function updateAppointment(
        Request $request,
        LotAppointment $lotAppointment,
        LotAppointmentUpdateService $updater,
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:120'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'department_code' => ['nullable', 'string', 'max:3'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'force_geocode' => ['nullable', 'boolean'],
        ]);

        $lotAppointment = $updater->update($lotAppointment, $payload)
            ->loadMissing([
                'lot',
                'appointment:id,technician_id,service_id,starts_at,ends_at',
                'appointment.service:id,type,name',
                'appointment.technician:id,first_name,last_name,department_code,role',
                'appointment.technician.departments:code',
            ]);

        return response()->json([
            'message' => 'RDV du lot mis à jour.',
            'appointment' => $this->serializeLotAppointment($lotAppointment, $lotAppointment->lot),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', Rule::in(array_keys(Lot::types()))],
            'status' => ['nullable', 'string', Rule::in(array_keys(Lot::statuses()))],
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Builder<Lot>
     */
    private function lotQuery(array $filters): Builder
    {
        return Lot::query()
            ->with([
                'creator:id,first_name,last_name',
                'appointments' => fn ($query) => $query
                    ->with([
                        'appointment:id,technician_id,service_id,starts_at,ends_at',
                        'appointment.service:id,type,name',
                        'appointment.technician:id,first_name,last_name,department_code,role',
                        'appointment.technician.departments:code',
                    ])
                    ->when(! empty($filters['q']), fn ($query) => $this->applySearchFilter($query, trim((string) $filters['q'])))
                    ->orderByRaw('CASE WHEN `row_number` IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('row_number')
                    ->orderBy('customer_name'),
            ])
            ->when(! empty($filters['type']), fn (Builder $query) => $query->where('type', $filters['type']))
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['q']), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['q']);

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('source', 'like', "%{$search}%")
                        ->orWhere('original_filename', 'like', "%{$search}%")
                        ->orWhereHas('appointments', fn (Builder $query) => $this->applySearchFilter($query, $search));
                });
            });
    }

    private function applySearchFilter($query, string $search)
    {
        return $query->where(function ($query) use ($search): void {
            $query
                ->where('external_reference', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_first_name', 'like', "%{$search}%")
                ->orWhere('customer_last_name', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('postal_code', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('department_code', 'like', "%{$search}%");
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLot(Lot $lot, LotAutoCompletionCalculator $autoCompletion): array
    {
        $appointments = $lot->appointments;
        $placedAppointments = $appointments->filter(fn (LotAppointment $appointment): bool => $this->isPlacedLotAppointment($appointment));
        $placeableAppointments = $appointments->filter(fn (LotAppointment $appointment): bool => $this->isPlaceableLotAppointment($appointment));
        $status = $lot->status ?: Lot::STATUS_NOT_STARTED;
        $statusMeta = $this->statusMeta($status);
        $autoCompletionData = $autoCompletion->calculate($lot, $appointments);

        return [
            'id' => $lot->id,
            'title' => $lot->name,
            'type' => $lot->type,
            'type_label' => $lot->typeLabel(),
            'status' => $status,
            'status_label' => Lot::statuses()[$status] ?? Lot::statuses()[Lot::STATUS_NOT_STARTED],
            'status_color' => $statusMeta['color'],
            'status_background' => $statusMeta['background'],
            'sampling_percentage' => $lot->sampling_percentage,
            'source' => $lot->source,
            'original_filename' => $lot->original_filename,
            'original_file_size' => $lot->original_file_size,
            'can_download_original_file' => filled($lot->original_file_disk) && filled($lot->original_file_path),
            'download_url' => route('manager.lots.download', $lot),
            'imported_at' => $lot->imported_at,
            'import_summary' => $lot->import_summary,
            'auto_completion' => $autoCompletionData,
            'appointments' => $appointments->map(fn (LotAppointment $appointment): array => $this->serializeLotAppointment($appointment, $lot))->values(),
            'appointments_count' => $appointments->count(),
            'placed_count' => $placedAppointments->count(),
            'placeable_count' => $placeableAppointments->count(),
            'departments' => $appointments->pluck('department_code')->filter()->unique()->sort()->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLotAppointment(LotAppointment $appointment, Lot $lot): array
    {
        $rawPayload = $appointment->raw_payload ?? [];

        return [
            'id' => $appointment->id,
            'update_url' => route('manager.lots.appointments.update', $appointment),
            'external_reference' => $appointment->external_reference,
            'row_number' => $appointment->row_number,
            'source' => $appointment->source ?: $lot->source,
            'customer_name' => $appointment->customer_name,
            'customer_first_name' => $appointment->customer_first_name,
            'customer_last_name' => $appointment->customer_last_name,
            'customer_phone' => $appointment->customer_phone,
            'address' => $appointment->address,
            'postal_code' => $appointment->postal_code ?: ($rawPayload['postal_code'] ?? null),
            'city' => $appointment->city ?: ($rawPayload['city'] ?? null),
            'department_code' => $appointment->department_code,
            'latitude' => $appointment->latitude,
            'longitude' => $appointment->longitude,
            'comment' => $appointment->comment,
            'status' => $appointment->status,
            'status_label' => $appointment->statusLabel(),
            'appointment_id' => $appointment->appointment_id,
            'is_placed' => $this->isPlacedLotAppointment($appointment),
            'placed_at' => $appointment->appointment?->starts_at,
            'placed_technician_name' => $appointment->appointment?->technician?->full_name_with_departments,
            'placed_service_label' => $appointment->appointment?->service
                ? $appointment->appointment->service->type.' - '.$appointment->appointment->service->name
                : null,
            'tracking_url' => $this->trackingUrlForLotAppointment($appointment, 'manager.appointments'),
            'ai_confidence' => $appointment->ai_confidence,
            'ai_warnings' => $appointment->ai_warnings ?? [],
        ];
    }

    /**
     * @return array{color:string,background:string}
     */
    private function statusMeta(string $status): array
    {
        return match ($status) {
            Lot::STATUS_IN_PROGRESS => ['color' => '#1d4ed8', 'background' => '#dbeafe'],
            Lot::STATUS_COMPLETED => ['color' => '#15803d', 'background' => '#dcfce7'],
            default => ['color' => '#b45309', 'background' => '#fef3c7'],
        };
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }

    private function isPlacedLotAppointment(LotAppointment $appointment): bool
    {
        return $appointment->appointment_id !== null || $appointment->status === LotAppointment::STATUS_PLACED;
    }

    private function isPlaceableLotAppointment(LotAppointment $appointment): bool
    {
        return ! $this->isPlacedLotAppointment($appointment)
            && in_array($appointment->status, [LotAppointment::STATUS_PENDING, LotAppointment::STATUS_NEEDS_REVIEW], true);
    }

    private function trackingUrlForLotAppointment(LotAppointment $lotAppointment, string $routeName): ?string
    {
        $appointment = $lotAppointment->appointment;

        if (! $appointment) {
            return null;
        }

        return route($routeName, array_filter([
            'technician_id' => $appointment->technician_id,
            'appointment_id' => $appointment->id,
            'date' => $appointment->starts_at?->toDateString(),
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePreview(LotImportPreview $preview): array
    {
        $payload = $preview->payload ?? [];
        $appointments = collect($payload['appointments'] ?? [])
            ->values()
            ->map(function (array $appointment) use ($preview): array {
                $rowNumber = (int) ($appointment['row_number'] ?? 0);

                if ($rowNumber > 0 && $preview->status === LotImportPreview::STATUS_COMPLETED) {
                    $appointment['update_url'] = route('manager.lots.imports.rows.update', [$preview, $rowNumber]);
                }

                return $appointment;
            })
            ->all();
        $rejectedRows = collect($payload['rejected_rows'] ?? [])->values()->all();

        return [
            'uuid' => $preview->uuid,
            'status' => $preview->status,
            'progress' => $preview->progress,
            'stage' => $preview->stage,
            'error_message' => $preview->error_message,
            'type' => $preview->type,
            'type_label' => Lot::types()[$preview->type] ?? $preview->type,
            'sampling_percentage' => $preview->sampling_percentage,
            'total_rows' => $preview->total_rows,
            'normalized_rows' => $preview->normalized_rows,
            'rejected_rows' => $preview->rejected_rows,
            'summary' => $payload['summary'] ?? null,
            'appointments' => $preview->status === LotImportPreview::STATUS_COMPLETED ? $appointments : [],
            'rejected' => $preview->status === LotImportPreview::STATUS_COMPLETED ? $rejectedRows : [],
            'status_url' => route('manager.lots.imports.show', $preview),
            'confirm_url' => route('manager.lots.imports.confirm', $preview),
            'retry_url' => route('manager.lots.imports.retry', $preview),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeImportPreview(Request $request): ?array
    {
        $preview = LotImportPreview::query()
            ->where('created_by', $request->user()->id)
            ->whereIn('status', [
                LotImportPreview::STATUS_PENDING,
                LotImportPreview::STATUS_PROCESSING,
            ])
            ->latest()
            ->first();

        return $preview ? $this->serializePreview($preview) : null;
    }
}
