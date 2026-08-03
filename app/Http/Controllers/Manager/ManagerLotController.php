<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotImportPreview;
use App\Models\Service;
use App\Services\CoffracAppointmentService;
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
use Illuminate\Support\Facades\DB;
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
        $query = $this->lotQuery($filters)->latest();
        $statsLots = (clone $query)
            ->get()
            ->map(fn (Lot $lot): array => $this->serializeLot($lot, $autoCompletion));
        $lots = $query
            ->paginate(9)
            ->withQueryString();

        $lots->setCollection(
            $lots
                ->getCollection()
                ->map(fn (Lot $lot): array => $this->serializeLot($lot, $autoCompletion))
        );

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
                'lots_count' => $statsLots->count(),
                'appointments_count' => $statsLots->sum('appointments_count'),
                'placeable_count' => $statsLots->sum('placeable_count'),
                'placed_count' => $statsLots->sum('placed_count'),
                'contact_processed_count' => $statsLots->sum('contact_processed_count'),
            ],
            'activeImportPreview' => $this->activeImportPreview($request),
            'canForceDeleteStartedLots' => $this->canForceDeleteStartedLots($request),
            'mapboxToken' => config('services.mapbox.token'),
            'services' => Service::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'average_duration_minutes']),
            'delegataires' => ExternalDelegataire::query()
                ->where('source', CoffracAppointmentService::SOURCE)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'company_name', 'email']),
        ]);
    }

    public function show(Request $request, Lot $lot, LotAutoCompletionCalculator $autoCompletion): View
    {
        abort_unless($this->canAccess($request), 403);

        $appointmentFilters = $this->validatedLotAppointmentFilters($request);
        $lot->load([
            'creator:id,first_name,last_name',
            'service:id,type,name,average_duration_minutes',
            'appointments' => fn ($query) => $query
                ->with($this->lotAppointmentRelations())
                ->orderByRaw('CASE WHEN `row_number` IS NULL THEN 1 ELSE 0 END')
                ->orderBy('row_number')
                ->orderBy('customer_name'),
        ]);

        $appointments = $this->lotAppointmentQuery($lot, $appointmentFilters)
            ->paginate(25, ['*'], 'appointments_page')
            ->withQueryString();

        $appointments->setCollection(
            $appointments
                ->getCollection()
                ->map(fn (LotAppointment $appointment): array => $this->serializeLotAppointment($appointment, $lot))
        );

        return view('manager.lots.show', [
            'lot' => $this->serializeLot($lot, $autoCompletion, $appointments->getCollection()),
            'appointments' => $appointments,
            'appointmentFilters' => [
                'appointment_q' => $appointmentFilters['appointment_q'] ?? '',
                'appointment_status' => $appointmentFilters['appointment_status'] ?? '',
                'appointment_processing' => $appointmentFilters['appointment_processing'] ?? '',
            ],
            'lotAppointmentStatuses' => LotAppointment::statuses(),
            'lotAppointmentProcessingFilters' => $this->lotAppointmentProcessingFilters(),
            'lotTypes' => Lot::types(),
            'lotStatuses' => Lot::statuses(),
            'mapboxToken' => config('services.mapbox.token'),
            'services' => Service::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'average_duration_minutes']),
        ]);
    }

    public function store(Request $request, LotExcelImportService $importer): RedirectResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'delegataire_id' => [
                'nullable',
                'integer',
                Rule::exists('external_delegataires', 'id')->where(fn ($query) => $query
                    ->where('source', CoffracAppointmentService::SOURCE)
                    ->where('is_active', true)),
            ],
            'delegataire' => ['nullable', 'string', 'max:190', 'required_without:delegataire_id'],
            'type' => ['required', 'string', Rule::in(array_keys(Lot::types()))],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'sampling_percentage' => [
                Rule::requiredIf(fn (): bool => Lot::requiresSamplingPercentageFor($request->input('type'))),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'physical_sampling_percentage' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === Lot::TYPE_HYBRID_LOCATION_CONTACT),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'contact_sampling_percentage' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === Lot::TYPE_HYBRID_LOCATION_CONTACT),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'global_plus' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ]);
        $delegataireName = $this->delegataireNameFromPayload($payload);
        $samplingPayload = $this->normalizedSamplingPayload($payload);

        try {
            $lot = $importer->import(
                file: $payload['file'],
                userId: (int) $request->user()->id,
                requestedLotName: $payload['name'] ?? null,
                lotType: $payload['type'],
                samplingPercentage: $samplingPayload['sampling_percentage'],
                source: null,
                delegataire: $delegataireName,
                physicalSamplingPercentage: $samplingPayload['physical_sampling_percentage'],
                contactSamplingPercentage: $samplingPayload['contact_sampling_percentage'],
                globalPlus: (bool) ($payload['global_plus'] ?? false),
                serviceId: (int) $payload['service_id'],
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

    public function update(Request $request, Lot $lot): RedirectResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $this->validatedLotPayload($request);
        $samplingPayload = $this->normalizedSamplingPayload($payload);

        $lot->fill([
            'name' => trim((string) $payload['name']),
            'type' => $payload['type'],
            'status' => $payload['status'],
            'sampling_percentage' => $samplingPayload['sampling_percentage'],
            'physical_sampling_percentage' => $samplingPayload['physical_sampling_percentage'],
            'contact_sampling_percentage' => $samplingPayload['contact_sampling_percentage'],
            'delegataire' => filled($payload['delegataire'] ?? null) ? trim((string) $payload['delegataire']) : null,
            'global_plus' => (bool) ($payload['global_plus'] ?? false),
            'service_id' => (int) $payload['service_id'],
        ])->save();

        $this->syncOpenLotAppointmentsService($lot, (int) $payload['service_id']);

        return back()->with('status', sprintf('Lot "%s" mis à jour.', $lot->name));
    }

    public function destroy(Request $request, Lot $lot): RedirectResponse
    {
        abort_unless($this->canAccess($request), 403);

        $canForceDelete = $this->canForceDeleteStartedLots($request);
        $placedAppointmentsCount = $lot->appointments()
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('appointment_id')
                    ->orWhere('status', LotAppointment::STATUS_PLACED)
                    ->orWhere('status', LotAppointment::STATUS_CONTACT_PROCESSED);
            })
            ->count();

        if ($placedAppointmentsCount > 0 && ! $canForceDelete) {
            return back()->withErrors([
                'lot' => sprintf(
                    'Impossible de supprimer ce lot: %d RDV est/sont déjà placé(s).',
                    $placedAppointmentsCount,
                ),
            ]);
        }

        $lotName = $lot->name;
        $originalFileDisk = $lot->original_file_disk;
        $originalFilePath = $lot->original_file_path;
        $appointmentIds = $canForceDelete
            ? $lot->appointments()
                ->whereNotNull('appointment_id')
                ->pluck('appointment_id')
                ->filter()
                ->unique()
                ->values()
            : collect();

        DB::transaction(function () use ($lot, $appointmentIds): void {
            if ($appointmentIds->isNotEmpty()) {
                Appointment::query()
                    ->whereKey($appointmentIds->all())
                    ->delete();
            }

            $lot->delete();
        });

        if (filled($originalFileDisk) && filled($originalFilePath)) {
            Storage::disk($originalFileDisk)->delete($originalFilePath);
        }

        return redirect()
            ->route('manager.lots')
            ->with('status', sprintf('Lot "%s" supprimé.', $lotName));
    }

    public function startImport(Request $request, LotImportPreviewService $imports): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'delegataire_id' => [
                'nullable',
                'integer',
                Rule::exists('external_delegataires', 'id')->where(fn ($query) => $query
                    ->where('source', CoffracAppointmentService::SOURCE)
                    ->where('is_active', true)),
            ],
            'delegataire' => ['nullable', 'string', 'max:190', 'required_without:delegataire_id'],
            'type' => ['required', 'string', Rule::in(array_keys(Lot::types()))],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'sampling_percentage' => [
                Rule::requiredIf(fn (): bool => Lot::requiresSamplingPercentageFor($request->input('type'))),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'physical_sampling_percentage' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === Lot::TYPE_HYBRID_LOCATION_CONTACT),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'contact_sampling_percentage' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === Lot::TYPE_HYBRID_LOCATION_CONTACT),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'global_plus' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ]);
        $delegataireName = $this->delegataireNameFromPayload($payload);
        $samplingPayload = $this->normalizedSamplingPayload($payload);

        $preview = $imports->createFromUpload(
            file: $payload['file'],
            userId: (int) $request->user()->id,
            lotType: $payload['type'],
            serviceId: (int) $payload['service_id'],
            samplingPercentage: $samplingPayload['sampling_percentage'],
            physicalSamplingPercentage: $samplingPayload['physical_sampling_percentage'],
            contactSamplingPercentage: $samplingPayload['contact_sampling_percentage'],
            globalPlus: (bool) ($payload['global_plus'] ?? false),
            requestedLotName: $payload['name'] ?? null,
            delegataire: $delegataireName,
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
            'redirect_url' => route('manager.lots.show', $lot),
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
            'company_name' => ['nullable', 'string', 'max:190'],
            'site_name' => ['nullable', 'string', 'max:190'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'department_code' => ['nullable', 'string', 'max:3'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'unsuccessful_visits_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
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
            'company_name' => ['nullable', 'string', 'max:190'],
            'site_name' => ['nullable', 'string', 'max:190'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
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
                'contactProcessor:id,first_name,last_name',
                'statsExcluder:id,first_name,last_name',
            ]);

        return response()->json([
            'message' => 'RDV du lot mis à jour.',
            'appointment' => $this->serializeLotAppointment($lotAppointment, $lotAppointment->lot),
        ]);
    }

    public function updateAppointmentVisits(Request $request, LotAppointment $lotAppointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);
        abort_unless($this->isPlacedLotAppointment($lotAppointment), 422, 'Le nombre de portes concerne uniquement les RDV en déplacement.');

        $payload = $request->validate([
            'unsuccessful_visits_count' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $lotAppointment->update([
            'unsuccessful_visits_count' => (int) $payload['unsuccessful_visits_count'],
        ]);

        $lotAppointment->loadMissing([
            'lot',
            'appointment:id,technician_id,service_id,starts_at,ends_at',
            'appointment.service:id,type,name',
            'appointment.technician:id,first_name,last_name,department_code,role',
            'appointment.technician.departments:code',
            'contactProcessor:id,first_name,last_name',
            'statsExcluder:id,first_name,last_name',
        ]);

        return response()->json([
            'message' => 'Nombre de portes mis à jour.',
            'appointment' => $this->serializeLotAppointment($lotAppointment, $lotAppointment->lot),
        ]);
    }

    public function updateAppointmentStatsExclusion(Request $request, LotAppointment $lotAppointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'excluded_from_lot_stats' => ['required', 'boolean'],
        ]);

        $excluded = (bool) $payload['excluded_from_lot_stats'];

        $lotAppointment->update([
            'excluded_from_lot_stats' => $excluded,
            'excluded_from_lot_stats_at' => $excluded ? now() : null,
            'excluded_from_lot_stats_by' => $excluded ? $request->user()->id : null,
        ]);

        $lotAppointment->loadMissing([
            'lot',
            'appointment:id,technician_id,service_id,starts_at,ends_at',
            'appointment.service:id,type,name',
            'appointment.technician:id,first_name,last_name,department_code,role',
            'appointment.technician.departments:code',
            'contactProcessor:id,first_name,last_name',
            'statsExcluder:id,first_name,last_name',
        ]);

        $this->refreshLotStatus($lotAppointment->lot);

        return response()->json([
            'message' => $excluded
                ? 'Dossier sorti des statistiques du lot.'
                : 'Dossier réintégré dans les statistiques du lot.',
            'appointment' => $this->serializeLotAppointment($lotAppointment, $lotAppointment->lot),
            'reload_required' => true,
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
     * @return array<string, mixed>
     */
    private function validatedLotAppointmentFilters(Request $request): array
    {
        return $request->validate([
            'appointment_q' => ['nullable', 'string', 'max:120'],
            'appointment_status' => ['nullable', 'string', Rule::in(array_keys(LotAppointment::statuses()))],
            'appointment_processing' => ['nullable', 'string', Rule::in(array_keys($this->lotAppointmentProcessingFilters()))],
        ]);
    }

    private function validatedLotPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'delegataire' => ['nullable', 'string', 'max:190'],
            'type' => ['required', 'string', Rule::in(array_keys(Lot::types()))],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'status' => ['required', 'string', Rule::in(array_keys(Lot::statuses()))],
            'sampling_percentage' => [
                Rule::requiredIf(fn (): bool => Lot::requiresSamplingPercentageFor($request->input('type'))),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'physical_sampling_percentage' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === Lot::TYPE_HYBRID_LOCATION_CONTACT),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'contact_sampling_percentage' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === Lot::TYPE_HYBRID_LOCATION_CONTACT),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
            ],
            'global_plus' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{sampling_percentage:?float,physical_sampling_percentage:?float,contact_sampling_percentage:?float}
     */
    private function normalizedSamplingPayload(array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type === Lot::TYPE_HYBRID_LOCATION_CONTACT) {
            return [
                'sampling_percentage' => null,
                'physical_sampling_percentage' => isset($payload['physical_sampling_percentage'])
                    ? (float) $payload['physical_sampling_percentage']
                    : null,
                'contact_sampling_percentage' => isset($payload['contact_sampling_percentage'])
                    ? (float) $payload['contact_sampling_percentage']
                    : null,
            ];
        }

        return [
            'sampling_percentage' => Lot::requiresSamplingPercentageFor($type) && isset($payload['sampling_percentage'])
                ? (float) $payload['sampling_percentage']
                : null,
            'physical_sampling_percentage' => null,
            'contact_sampling_percentage' => null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function delegataireNameFromPayload(array $payload): string
    {
        if (filled($payload['delegataire_id'] ?? null)) {
            $delegataire = ExternalDelegataire::query()
                ->where('source', CoffracAppointmentService::SOURCE)
                ->where('is_active', true)
                ->findOrFail((int) $payload['delegataire_id']);

            return $delegataire->name;
        }

        return trim((string) ($payload['delegataire'] ?? ''));
    }

    private function syncOpenLotAppointmentsService(Lot $lot, int $serviceId): void
    {
        $service = Service::query()->find($serviceId);

        if (! $service) {
            return;
        }

        $lot->appointments()
            ->whereNull('appointment_id')
            ->whereIn('status', [
                LotAppointment::STATUS_PENDING,
                LotAppointment::STATUS_NEEDS_REVIEW,
                LotAppointment::STATUS_CONTACT_PROCESSED,
            ])
            ->update([
                'service_id' => $service->id,
                'service_type' => $service->type,
                'service_name' => $service->name,
                'duration_minutes' => $service->average_duration_minutes,
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
                'service:id,type,name,average_duration_minutes',
                'appointments' => fn ($query) => $query
                    ->with($this->lotAppointmentRelations())
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
                        ->orWhere('delegataire', 'like', "%{$search}%")
                        ->orWhere('original_filename', 'like', "%{$search}%")
                        ->orWhereHas('service', function (Builder $query) use ($search): void {
                            $query
                                ->where('type', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('appointments', fn (Builder $query) => $this->applySearchFilter($query, $search));
                });
            });
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function lotAppointmentQuery(Lot $lot, array $filters)
    {
        return $lot->appointments()
            ->with($this->lotAppointmentRelations())
            ->when(! empty($filters['appointment_q']), fn ($query) => $this->applySearchFilter($query, trim((string) $filters['appointment_q'])))
            ->when(! empty($filters['appointment_status']), fn ($query) => $query->where('status', $filters['appointment_status']))
            ->when(! empty($filters['appointment_processing']), function ($query) use ($filters): void {
                match ($filters['appointment_processing']) {
                    'physical' => $query->where(function ($query): void {
                        $query
                            ->where('processing_mode', LotAppointment::PROCESSING_MODE_PHYSICAL)
                            ->orWhereNotNull('appointment_id')
                            ->orWhere('status', LotAppointment::STATUS_PLACED);
                    }),
                    'contact' => $query->where(function ($query): void {
                        $query
                            ->where('processing_mode', LotAppointment::PROCESSING_MODE_CONTACT)
                            ->orWhere('status', LotAppointment::STATUS_CONTACT_PROCESSED)
                            ->orWhereNotNull('contact_satisfaction');
                    }),
                    'excluded' => $query->where('excluded_from_lot_stats', true),
                    default => null,
                };
            })
            ->orderByRaw('CASE WHEN `row_number` IS NULL THEN 1 ELSE 0 END')
            ->orderBy('row_number')
            ->orderBy('customer_name');
    }

    /**
     * @return array<int, string>
     */
    private function lotAppointmentRelations(): array
    {
        return [
            'appointment:id,technician_id,service_id,starts_at,ends_at',
            'service:id,type,name,average_duration_minutes',
            'appointment.service:id,type,name',
            'appointment.technician:id,first_name,last_name,department_code,role',
            'appointment.technician.departments:code',
            'contactProcessor:id,first_name,last_name',
            'statsExcluder:id,first_name,last_name',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function lotAppointmentProcessingFilters(): array
    {
        return [
            'physical' => 'RDV physique',
            'contact' => 'Traitement téléphone',
            'excluded' => 'Hors statistiques',
        ];
    }

    private function applySearchFilter($query, string $search)
    {
        return $query->where(function ($query) use ($search): void {
            $query
                ->where('external_reference', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('site_name', 'like', "%{$search}%")
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
    private function serializeLot(Lot $lot, LotAutoCompletionCalculator $autoCompletion, $displayAppointments = null): array
    {
        $appointments = $lot->appointments;
        $statsAppointments = $appointments->reject(fn (LotAppointment $appointment): bool => $this->isExcludedFromLotStats($appointment));
        $placedAppointments = $statsAppointments->filter(fn (LotAppointment $appointment): bool => $this->isPlacedLotAppointment($appointment));
        $placeableAppointments = $statsAppointments->filter(fn (LotAppointment $appointment): bool => $this->isPlaceableLotAppointment($appointment));
        $contactProcessedAppointments = $statsAppointments->filter(fn (LotAppointment $appointment): bool => $this->isContactProcessedLotAppointment($appointment));
        $status = $lot->status ?: Lot::STATUS_NOT_STARTED;
        $statusMeta = $this->statusMeta($status);
        $autoCompletionData = $autoCompletion->calculate($lot, $statsAppointments);

        return [
            'id' => $lot->id,
            'show_url' => route('manager.lots.show', $lot),
            'update_url' => route('manager.lots.update', $lot),
            'delete_url' => route('manager.lots.destroy', $lot),
            'title' => $lot->name,
            'type' => $lot->type,
            'type_label' => $lot->typeLabel(),
            'service_id' => $lot->service_id,
            'service_label' => $lot->service
                ? $lot->service->type.' - '.$lot->service->name
                : null,
            'status' => $status,
            'status_label' => Lot::statuses()[$status] ?? Lot::statuses()[Lot::STATUS_NOT_STARTED],
            'status_color' => $statusMeta['color'],
            'status_background' => $statusMeta['background'],
            'sampling_percentage' => $lot->sampling_percentage,
            'physical_sampling_percentage' => $lot->physical_sampling_percentage,
            'contact_sampling_percentage' => $lot->contact_sampling_percentage,
            'global_plus' => (bool) $lot->global_plus,
            'supports_physical' => $lot->supportsPhysicalProcessing(),
            'supports_contact' => $lot->supportsContactProcessing(),
            'is_hybrid' => $lot->isHybrid(),
            'delegataire' => $lot->delegataire,
            'source' => $lot->source,
            'original_filename' => $lot->original_filename,
            'original_file_size' => $lot->original_file_size,
            'can_download_original_file' => filled($lot->original_file_disk) && filled($lot->original_file_path),
            'download_url' => route('manager.lots.download', $lot),
            'imported_at' => $lot->imported_at,
            'import_summary' => $lot->import_summary,
            'auto_completion' => $autoCompletionData,
            'appointments' => $displayAppointments ?? $appointments->map(fn (LotAppointment $appointment): array => $this->serializeLotAppointment($appointment, $lot))->values(),
            'appointments_count' => $appointments->count(),
            'stats_excluded_count' => $appointments->count() - $statsAppointments->count(),
            'placed_count' => $placedAppointments->count(),
            'placeable_count' => $placeableAppointments->count(),
            'contact_processed_count' => $contactProcessedAppointments->count(),
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
            'visits_update_url' => route('manager.lots.appointments.visits.update', $appointment),
            'stats_exclusion_update_url' => route('manager.lots.appointments.stats-exclusion.update', $appointment),
            'external_reference' => $appointment->external_reference,
            'row_number' => $appointment->row_number,
            'source' => $appointment->source ?: $lot->source,
            'customer_name' => $appointment->customer_name,
            'company_name' => $appointment->company_name,
            'site_name' => $appointment->site_name,
            'customer_first_name' => $appointment->customer_first_name,
            'customer_last_name' => $appointment->customer_last_name,
            'customer_phone' => $appointment->customer_phone,
            'address' => $appointment->address,
            'postal_code' => $appointment->postal_code ?: ($rawPayload['postal_code'] ?? null),
            'city' => $appointment->city ?: ($rawPayload['city'] ?? null),
            'department_code' => $appointment->department_code,
            'latitude' => $appointment->latitude,
            'longitude' => $appointment->longitude,
            'service_id' => $appointment->service_id ?: $lot->service_id,
            'service_label' => $appointment->service
                ? $appointment->service->type.' - '.$appointment->service->name
                : ($lot->service ? $lot->service->type.' - '.$lot->service->name : null),
            'comment' => $appointment->comment,
            'status' => $appointment->status,
            'status_label' => $appointment->statusLabel(),
            'processing_mode' => $appointment->processing_mode,
            'contact_satisfaction' => $appointment->contact_satisfaction,
            'contact_comment' => $appointment->contact_comment,
            'contact_processed_at' => $appointment->contact_processed_at,
            'contact_processed_by_name' => $appointment->contactProcessor?->full_name,
            'physical_satisfaction' => $appointment->physical_satisfaction,
            'physical_satisfaction_synced_at' => $appointment->physical_satisfaction_synced_at,
            'unsuccessful_visits_count' => $appointment->unsuccessful_visits_count ?? 0,
            'excluded_from_lot_stats' => (bool) $appointment->excluded_from_lot_stats,
            'excluded_from_lot_stats_at' => $appointment->excluded_from_lot_stats_at,
            'excluded_from_lot_stats_by_name' => $appointment->statsExcluder?->full_name,
            'appointment_id' => $appointment->appointment_id,
            'is_placed' => $this->isPlacedLotAppointment($appointment),
            'is_contact_processed' => $this->isContactProcessedLotAppointment($appointment),
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

    private function canForceDeleteStartedLots(Request $request): bool
    {
        return (bool) $request->user()?->admin;
    }

    private function isPlacedLotAppointment(LotAppointment $appointment): bool
    {
        return $appointment->appointment_id !== null || $appointment->status === LotAppointment::STATUS_PLACED;
    }

    private function isContactProcessedLotAppointment(LotAppointment $appointment): bool
    {
        return $appointment->status === LotAppointment::STATUS_CONTACT_PROCESSED
            || $appointment->contact_satisfaction !== null;
    }

    private function isExcludedFromLotStats(LotAppointment $appointment): bool
    {
        return (bool) $appointment->excluded_from_lot_stats;
    }

    private function isPlaceableLotAppointment(LotAppointment $appointment): bool
    {
        return ! $this->isExcludedFromLotStats($appointment)
            && ! $this->isPlacedLotAppointment($appointment)
            && ! $this->isContactProcessedLotAppointment($appointment)
            && in_array($appointment->status, [LotAppointment::STATUS_PENDING, LotAppointment::STATUS_NEEDS_REVIEW], true);
    }

    private function refreshLotStatus(?Lot $lot): void
    {
        if (! $lot) {
            return;
        }

        $baseQuery = $lot->appointments()
            ->where('excluded_from_lot_stats', false);

        $totalAppointments = (clone $baseQuery)->count();
        $completedAppointments = (clone $baseQuery)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('appointment_id')
                    ->orWhere('status', LotAppointment::STATUS_PLACED)
                    ->orWhere('status', LotAppointment::STATUS_CONTACT_PROCESSED)
                    ->orWhereNotNull('contact_satisfaction');
            })
            ->count();

        $status = match (true) {
            $totalAppointments > 0 && $completedAppointments >= $totalAppointments => Lot::STATUS_COMPLETED,
            $completedAppointments > 0 => Lot::STATUS_IN_PROGRESS,
            default => Lot::STATUS_NOT_STARTED,
        };

        if ($lot->status !== $status) {
            $lot->update(['status' => $status]);
        }
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
            'service_id' => $preview->service_id,
            'service_label' => $preview->service
                ? $preview->service->type.' - '.$preview->service->name
                : null,
            'sampling_percentage' => $preview->sampling_percentage,
            'physical_sampling_percentage' => $preview->physical_sampling_percentage,
            'contact_sampling_percentage' => $preview->contact_sampling_percentage,
            'global_plus' => (bool) $preview->global_plus,
            'delegataire' => $preview->delegataire,
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
