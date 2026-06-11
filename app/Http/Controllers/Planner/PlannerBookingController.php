<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\LotAutoCompletionCalculator;
use App\Services\MapboxDrivingRouteService;
use App\Services\SimulatedCrmAppointmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlannerBookingController extends Controller
{
    public function index(
        Request $request,
        SimulatedCrmAppointmentService $crmAppointments,
        LotAutoCompletionCalculator $autoCompletion
    ): View
    {
        abort_unless($this->canAccess($request), 403);

        return view('planner.book', [
            'crmAppointments' => $crmAppointments->pending(15),
            'lotRequests' => $this->lotAppointmentRequests($autoCompletion),
            'initialCrmAppointmentId' => $request->query('crm_appointment_id'),
            'mapboxToken' => config('services.mapbox.token'),
            'services' => Service::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'average_duration_minutes']),
        ]);
    }

    public function analyze(
        Request $request,
        SimulatedCrmAppointmentService $crmAppointments,
        MapboxDrivingRouteService $drivingRoutes
    ): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate($this->appointmentRequestRules());

        $crmAppointment = $this->resolveRequestedAppointment($payload, $crmAppointments);

        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        $technicians = $this->eligibleTechnicians($crmAppointment, $drivingRoutes);
        $technicianIds = $technicians->pluck('id');
        $calendarStart = now()->startOfWeek();
        $calendarEnd = now()->copy()->addWeeks(8)->endOfWeek();
        $preferredStartsAt = $this->preferredStartsAt($crmAppointment);

        if ($preferredStartsAt) {
            $preferredWeekStart = $preferredStartsAt->copy()->startOfWeek();
            $preferredWeekEnd = $preferredStartsAt->copy()->endOfWeek();

            if ($preferredWeekStart->lt($calendarStart)) {
                $calendarStart = $preferredWeekStart;
            }

            if ($preferredWeekEnd->gt($calendarEnd)) {
                $calendarEnd = $preferredWeekEnd;
            }
        }

        $this->loadAbsencesForTechnicians($technicians, $calendarStart, $calendarEnd);
        $appointments = $this->appointmentsForTechnicians($technicianIds, $calendarStart, $calendarEnd);

        return response()->json([
            'crm_appointment' => $crmAppointment,
            'filters' => [
                'department_code' => $crmAppointment['department_code'],
                'service_required' => $crmAppointment['service'] !== null,
                'preferred_starts_at' => $crmAppointment['preferred_starts_at'] ?? null,
                'source' => $crmAppointment['source'],
                'is_manual' => (bool) ($crmAppointment['is_manual'] ?? false),
                'is_lot' => (bool) ($crmAppointment['is_lot'] ?? false),
            ],
            'technicians' => $this->serializeTechnicians($technicians),
            'events' => $this->calendarEvents($appointments),
            'suggestions' => $this->buildSlotSuggestions($technicians, $appointments, $crmAppointment, $drivingRoutes),
            'calendar_range' => [
                'start' => $calendarStart->toDateString(),
                'end' => $calendarEnd->toDateString(),
            ],
        ]);
    }

    public function searchTechnicians(
        Request $request,
        SimulatedCrmAppointmentService $crmAppointments,
        MapboxDrivingRouteService $drivingRoutes
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            ...$this->appointmentRequestRules(),
            'query' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $crmAppointment = $this->resolveRequestedAppointment($payload, $crmAppointments);
        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        $technicians = $this->searchTechniciansForAppointment(
            $crmAppointment,
            trim($payload['query']),
            $drivingRoutes,
        );
        $this->loadAbsencesForTechnicians($technicians, now()->copy()->startOfDay(), now()->copy()->addWeeks(8)->endOfWeek());

        return response()->json([
            'technicians' => $this->serializeTechnicians($technicians),
        ]);
    }

    public function calendarWindow(
        Request $request,
        SimulatedCrmAppointmentService $crmAppointments,
        MapboxDrivingRouteService $drivingRoutes
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            ...$this->appointmentRequestRules(),
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'technician_ids' => ['nullable', 'array', 'max:200'],
            'technician_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
        ]);

        $crmAppointment = $this->resolveRequestedAppointment($payload, $crmAppointments);
        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        $windowStart = Carbon::parse($payload['start']);
        $windowEnd = Carbon::parse($payload['end']);
        $technicians = isset($payload['technician_ids'])
            ? $this->techniciansByIdsForAppointment($payload['technician_ids'], $crmAppointment, $drivingRoutes)
            : $this->eligibleTechnicians($crmAppointment, $drivingRoutes);
        $this->loadAbsencesForTechnicians($technicians, $windowStart, $windowEnd);
        $appointments = $this->appointmentsForTechnicians($technicians->pluck('id'), $windowStart, $windowEnd);

        return response()->json([
            'technicians' => $this->serializeTechnicians($technicians),
            'events' => $this->calendarEvents($appointments),
            'suggestions' => $this->buildSlotSuggestions(
                $technicians,
                $appointments,
                $crmAppointment,
                $drivingRoutes,
                $windowStart,
                $windowEnd,
            ),
            'calendar_range' => [
                'start' => $windowStart->toIso8601String(),
                'end' => $windowEnd->toIso8601String(),
            ],
        ]);
    }

    public function store(
        Request $request,
        SimulatedCrmAppointmentService $crmAppointments
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            ...$this->appointmentRequestRules(),
            'technician_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:480'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $crmAppointment = $this->resolveRequestedAppointment($payload, $crmAppointments);
        abort_if(! $crmAppointment, 404, 'Demande de rendez-vous introuvable.');

        if (! $crmAppointment['service']) {
            $serviceErrorKey = ! empty($payload['lot_appointment_id']) ? 'lot_service_id' : 'crm_appointment_id';

            throw ValidationException::withMessages([
                $serviceErrorKey => 'Impossible de valider sans prestation renseignee.',
            ]);
        }

        $startsAt = Carbon::parse($payload['starts_at']);
        $durationMinutes = (int) $payload['duration_minutes'];
        $endsAt = (clone $startsAt)->addMinutes($durationMinutes);
        $absence = $this->absenceOverlapForTechnician((int) $payload['technician_id'], $startsAt, $endsAt);

        if ($absence) {
            throw ValidationException::withMessages([
                'technician_id' => 'Ce technicien est absent '.$this->absenceLabel($absence).'.',
            ]);
        }

        $appointment = Appointment::query()->create([
            'service_id' => $crmAppointment['service']['id'],
            'technician_id' => $payload['technician_id'],
            'created_by' => $request->user()->id,
            'customer_first_name' => $crmAppointment['first_name'],
            'customer_last_name' => $crmAppointment['last_name'],
            'customer_phone' => $crmAppointment['phone'],
            'address' => $crmAppointment['address'],
            'latitude' => $crmAppointment['latitude'],
            'longitude' => $crmAppointment['longitude'],
            'starts_at' => $startsAt,
            'duration_minutes' => $durationMinutes,
            'ends_at' => $endsAt,
            'comment' => $payload['comment'] ?? null,
        ]);

        if (! empty($payload['lot_appointment_id'])) {
            $lotAppointment = LotAppointment::query()
                ->with('lot')
                ->whereKey((int) $payload['lot_appointment_id'])
                ->first();

            if ($lotAppointment) {
                $lotAppointment->update([
                    'appointment_id' => $appointment->id,
                    'service_id' => $crmAppointment['service']['id'],
                    'status' => LotAppointment::STATUS_PLACED,
                ]);

                $this->refreshLotStatus($lotAppointment->lot);
            }
        }

        return response()->json([
            'message' => 'Rendez-vous cree.',
            'appointment_id' => $appointment->id,
        ], 201);
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleTechnicians(array $crmAppointment, MapboxDrivingRouteService $drivingRoutes): Collection
    {
        $candidateTechnicians = User::query()
            ->with(['departments:code,name'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($crmAppointment['service'], function ($query) use ($crmAppointment): void {
                $query->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('services.id', $crmAppointment['service']['id']));
            })
            ->get();

        $techniciansByDistance = $candidateTechnicians
            ->map(function (User $technician) use ($crmAppointment): User {
                $technician->setAttribute('flight_distance_km', $this->haversine(
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                    (float) $crmAppointment['latitude'],
                    (float) $crmAppointment['longitude'],
                ));
                $technician->setAttribute(
                    'covers_requested_department',
                    $technician->departments->contains('code', $crmAppointment['department_code'])
                );

                return $technician;
            })
            ->sortBy(fn (User $technician): float => (float) $technician->getAttribute('flight_distance_km'))
            ->values();
        $strictTechnicians = $techniciansByDistance
            ->filter(fn (User $technician): bool => (bool) $technician->getAttribute('covers_requested_department'))
            ->values();
        $routingPool = $strictTechnicians->count() >= 3
            ? $strictTechnicians
            : $strictTechnicians->merge(
                $techniciansByDistance->whereNotIn('id', $strictTechnicians->pluck('id'))->take(3 - $strictTechnicians->count())
            );

        return $routingPool
            ->map(function (User $technician) use ($crmAppointment, $drivingRoutes): User {
                $route = $drivingRoutes->estimate(
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                    (float) $crmAppointment['latitude'],
                    (float) $crmAppointment['longitude'],
                );

                $technician->setAttribute('driving_distance_km', $route['distance_km']);
                $technician->setAttribute('driving_duration_minutes', $route['duration_minutes']);
                $technician->setAttribute('route_source', $route['source']);

                return $technician;
            })
            ->sort(function (User $leftTechnician, User $rightTechnician): int {
                $durationComparison = (int) $leftTechnician->getAttribute('driving_duration_minutes')
                    <=> (int) $rightTechnician->getAttribute('driving_duration_minutes');

                if ($durationComparison !== 0) {
                    return $durationComparison;
                }

                return (float) $leftTechnician->getAttribute('driving_distance_km')
                    <=> (float) $rightTechnician->getAttribute('driving_distance_km');
            })
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function searchTechniciansForAppointment(
        array $crmAppointment,
        string $query,
        MapboxDrivingRouteService $drivingRoutes
    ): Collection {
        $terms = collect(preg_split('/\s+/', trim($query)) ?: [])
            ->filter()
            ->values();

        if ($terms->isEmpty()) {
            return collect();
        }

        $serviceId = $crmAppointment['service']['id'] ?? null;

        $technicians = User::query()
            ->with(['departments:code,name'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($serviceId, function ($query) use ($serviceId): void {
                $query->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('services.id', $serviceId));
            })
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $term).'%';

                    $query->where(function ($termQuery) use ($like): void {
                        $termQuery
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhere('address', 'like', $like)
                            ->orWhere('department_code', 'like', $like);
                    });
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(12)
            ->get();

        return $this->withRouteAttributes($technicians, $crmAppointment, $drivingRoutes)
            ->sort(function (User $leftTechnician, User $rightTechnician): int {
                $coverageComparison = (int) $rightTechnician->getAttribute('covers_requested_department')
                    <=> (int) $leftTechnician->getAttribute('covers_requested_department');

                if ($coverageComparison !== 0) {
                    return $coverageComparison;
                }

                $durationComparison = (int) $leftTechnician->getAttribute('driving_duration_minutes')
                    <=> (int) $rightTechnician->getAttribute('driving_duration_minutes');

                if ($durationComparison !== 0) {
                    return $durationComparison;
                }

                return (float) $leftTechnician->getAttribute('driving_distance_km')
                    <=> (float) $rightTechnician->getAttribute('driving_distance_km');
            })
            ->values();
    }

    /**
     * @param array<int, mixed> $technicianIds
     * @return Collection<int, User>
     */
    private function techniciansByIdsForAppointment(
        array $technicianIds,
        array $crmAppointment,
        MapboxDrivingRouteService $drivingRoutes
    ): Collection {
        $orderedIds = collect($technicianIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($orderedIds->isEmpty()) {
            return collect();
        }

        $positions = $orderedIds
            ->flip()
            ->map(fn ($position): int => (int) $position)
            ->all();
        $serviceId = $crmAppointment['service']['id'] ?? null;

        $technicians = User::query()
            ->with(['departments:code,name'])
            ->whereIn('id', $orderedIds->all())
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($serviceId, function ($query) use ($serviceId): void {
                $query->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('services.id', $serviceId));
            })
            ->get();

        return $this->withRouteAttributes($technicians, $crmAppointment, $drivingRoutes)
            ->sortBy(fn (User $technician): int => $positions[$technician->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * @param Collection<int, User> $technicians
     * @return Collection<int, User>
     */
    private function withRouteAttributes(Collection $technicians, array $crmAppointment, MapboxDrivingRouteService $drivingRoutes): Collection
    {
        return $technicians
            ->map(function (User $technician) use ($crmAppointment, $drivingRoutes): User {
                $route = $drivingRoutes->estimate(
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                    (float) $crmAppointment['latitude'],
                    (float) $crmAppointment['longitude'],
                );

                $technician->setAttribute(
                    'covers_requested_department',
                    $technician->departments->contains('code', $crmAppointment['department_code'])
                );
                $technician->setAttribute('driving_distance_km', $route['distance_km']);
                $technician->setAttribute('driving_duration_minutes', $route['duration_minutes']);
                $technician->setAttribute('route_source', $route['source']);

                return $technician;
            })
            ->values();
    }

    /**
     * @param Collection<int, User> $technicians
     * @return Collection<int, array<string, mixed>>
     */
    private function serializeTechnicians(Collection $technicians): Collection
    {
        return $technicians->map(function (User $technician): array {
            $absences = $technician->relationLoaded('absences')
                ? $technician->absences
                : collect();

            return [
                'id' => $technician->id,
                'name' => $technician->full_name,
                'phone' => $technician->phone,
                'address' => $technician->address,
                'department_code' => $technician->department_code,
                'latitude' => $technician->latitude,
                'longitude' => $technician->longitude,
                'driving_distance_km' => round((float) $technician->getAttribute('driving_distance_km'), 1),
                'driving_duration_minutes' => (int) $technician->getAttribute('driving_duration_minutes'),
                'route_source' => $technician->getAttribute('route_source'),
                'covers_requested_department' => (bool) $technician->getAttribute('covers_requested_department'),
                'absence_label' => $absences
                    ->map(fn (TechnicianAbsence $absence): string => 'Abs '.$this->absenceLabel($absence))
                    ->implode(' · '),
                'absences' => $absences
                    ->map(fn (TechnicianAbsence $absence): array => [
                        'id' => $absence->id,
                        'starts_at' => $absence->starts_at?->toIso8601String(),
                        'ends_at' => $absence->ends_at?->toIso8601String(),
                        'label' => 'Abs '.$this->absenceLabel($absence),
                        'reason' => $absence->reason,
                    ])
                    ->values(),
            ];
        })->values();
    }

    /**
     * @param Collection<int, User> $technicians
     */
    private function loadAbsencesForTechnicians(Collection $technicians, Carbon $start, Carbon $end): void
    {
        $technicianIds = $technicians
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return;
        }

        $absences = TechnicianAbsence::query()
            ->whereIn('technician_id', $technicianIds->all())
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('technician_id');

        $technicians->each(function (User $technician) use ($absences): void {
            $technician->setRelation(
                'absences',
                $absences->get($technician->id, (new TechnicianAbsence())->newCollection())
            );
        });
    }

    /**
     * @param Collection<int, int> $technicianIds
     * @return Collection<int, Appointment>
     */
    private function appointmentsForTechnicians(Collection $technicianIds, Carbon $start, Carbon $end): Collection
    {
        if ($technicianIds->isEmpty()) {
            return collect();
        }

        return Appointment::withTrashed()
            ->with(['service:id,type,name', 'technician:id,first_name,last_name,latitude,longitude,address'])
            ->whereIn('technician_id', $technicianIds)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function appointmentRequestRules(): array
    {
        return [
            'crm_appointment_id' => ['nullable', 'string', 'required_without_all:manual_appointment,lot_appointment_id'],
            'lot_appointment_id' => [
                'nullable',
                'integer',
                'required_without_all:crm_appointment_id,manual_appointment',
                Rule::exists('lot_appointments', 'id'),
            ],
            'lot_service_id' => [
                'nullable',
                'integer',
                'required_with:lot_appointment_id',
                Rule::exists('services', 'id'),
            ],
            'manual_appointment' => ['nullable', 'array', 'required_without_all:crm_appointment_id,lot_appointment_id'],
            'manual_appointment.first_name' => ['required_with:manual_appointment', 'string', 'max:120'],
            'manual_appointment.last_name' => ['required_with:manual_appointment', 'string', 'max:120'],
            'manual_appointment.phone' => ['required_with:manual_appointment', 'string', 'max:30'],
            'manual_appointment.address' => ['required_with:manual_appointment', 'string', 'max:255'],
            'manual_appointment.department_code' => ['required_with:manual_appointment', 'string', 'max:3'],
            'manual_appointment.latitude' => ['required_with:manual_appointment', 'numeric', 'between:-90,90'],
            'manual_appointment.longitude' => ['required_with:manual_appointment', 'numeric', 'between:-180,180'],
            'manual_appointment.service_id' => [
                'required_with:manual_appointment',
                'integer',
                Rule::exists('services', 'id'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveRequestedAppointment(array $payload, SimulatedCrmAppointmentService $crmAppointments): ?array
    {
        if (! empty($payload['crm_appointment_id'])) {
            $appointment = $crmAppointments->find((string) $payload['crm_appointment_id']);

            return $appointment ? [
                ...$appointment,
                'is_manual' => false,
                'preferred_starts_at' => null,
            ] : null;
        }

        if (! empty($payload['lot_appointment_id'])) {
            return $this->lotAppointmentFromId(
                (int) $payload['lot_appointment_id'],
                isset($payload['lot_service_id']) ? (int) $payload['lot_service_id'] : null,
            );
        }

        if (! isset($payload['manual_appointment']) || ! is_array($payload['manual_appointment'])) {
            return null;
        }

        return $this->manualAppointmentFromPayload($payload['manual_appointment']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lotAppointmentRequests(LotAutoCompletionCalculator $autoCompletion): Collection
    {
        $placeableStatus = [
            LotAppointment::STATUS_PENDING,
            LotAppointment::STATUS_NEEDS_REVIEW,
        ];

        return Lot::query()
            ->with([
                'appointments' => fn ($query) => $query
                    ->with([
                        'appointment:id,technician_id,service_id,starts_at,ends_at',
                        'appointment.service:id,type,name',
                        'appointment.technician:id,first_name,last_name',
                    ])
                    ->where(function ($query) use ($placeableStatus): void {
                        $query
                            ->where(function ($query) use ($placeableStatus): void {
                                $query
                                    ->whereNull('appointment_id')
                                    ->whereIn('status', $placeableStatus);
                            })
                            ->orWhereNotNull('appointment_id')
                            ->orWhere('status', LotAppointment::STATUS_PLACED);
                    })
                    ->orderByRaw('CASE WHEN `row_number` IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('row_number')
                    ->orderBy('customer_name'),
            ])
            ->whereHas('appointments', fn ($query) => $query
                ->whereNull('appointment_id')
                ->whereIn('status', $placeableStatus))
            ->where('status', '!=', Lot::STATUS_COMPLETED)
            ->latest()
            ->limit(50)
            ->get()
            ->map(function (Lot $lot) use ($autoCompletion): array {
                $placedAppointments = $lot->appointments->filter(fn (LotAppointment $appointment): bool => $this->isPlacedLotAppointment($appointment));
                $placeableAppointments = $lot->appointments->filter(fn (LotAppointment $appointment): bool => $this->isPlaceableLotAppointment($appointment));
                $status = $lot->status ?: Lot::STATUS_NOT_STARTED;
                $statusMeta = $this->lotStatusMeta($status);

                return [
                    'id' => $lot->id,
                    'title' => $lot->name,
                    'type_label' => $lot->typeLabel(),
                    'status_label' => Lot::statuses()[$status] ?? Lot::statuses()[Lot::STATUS_NOT_STARTED],
                    'status_color' => $statusMeta['color'],
                    'status_background' => $statusMeta['background'],
                    'imported_at' => $lot->imported_at,
                    'auto_completion' => $autoCompletion->calculate($lot, $lot->appointments),
                    'appointments_count' => $lot->appointments->count(),
                    'placeable_count' => $placeableAppointments->count(),
                    'placed_count' => $placedAppointments->count(),
                    'departments' => $lot->appointments->pluck('department_code')->filter()->unique()->sort()->values(),
                    'appointments' => $lot->appointments->map(fn (LotAppointment $appointment): array => [
                        'id' => $appointment->id,
                        'customer_name' => $appointment->customer_name,
                        'customer_phone' => $appointment->customer_phone,
                        'address' => $appointment->address,
                        'department_code' => $appointment->department_code,
                        'row_number' => $appointment->row_number,
                        'external_reference' => $appointment->external_reference,
                        'service_id' => $appointment->service_id,
                        'status' => $appointment->status,
                        'status_label' => $appointment->statusLabel(),
                        'appointment_id' => $appointment->appointment_id,
                        'is_placed' => $this->isPlacedLotAppointment($appointment),
                        'placed_at' => $appointment->appointment?->starts_at,
                        'placed_technician_name' => $appointment->appointment?->technician?->full_name,
                        'placed_service_label' => $appointment->appointment?->service
                            ? $appointment->appointment->service->type.' - '.$appointment->appointment->service->name
                            : null,
                        'tracking_url' => $this->trackingUrlForLotAppointment($appointment, 'planner.tracking'),
                        'can_search' => $this->isPlaceableLotAppointment($appointment)
                            && filled($appointment->address)
                            && filled($appointment->department_code)
                            && $appointment->latitude !== null
                            && $appointment->longitude !== null,
                    ])->values(),
                ];
            })
            ->values();
    }

    /**
     * @return array{color:string,background:string}
     */
    private function lotStatusMeta(string $status): array
    {
        return match ($status) {
            Lot::STATUS_IN_PROGRESS => ['color' => '#1d4ed8', 'background' => '#dbeafe'],
            Lot::STATUS_COMPLETED => ['color' => '#15803d', 'background' => '#dcfce7'],
            default => ['color' => '#b45309', 'background' => '#fef3c7'],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lotAppointmentFromId(int $id, ?int $serviceId = null): ?array
    {
        $lotAppointment = LotAppointment::query()
            ->with(['lot:id,name,type,status', 'service:id,type,name,average_duration_minutes'])
            ->whereNull('appointment_id')
            ->whereKey($id)
            ->first();

        if (! $lotAppointment || ! filled($lotAppointment->address) || ! filled($lotAppointment->department_code)) {
            return null;
        }

        if ($lotAppointment->latitude === null || $lotAppointment->longitude === null) {
            return null;
        }

        [$firstName, $lastName] = $this->splitCustomerName($lotAppointment);
        $service = $serviceId
            ? Service::query()->find($serviceId)
            : $lotAppointment->service;

        return [
            'id' => 'lot-'.$lotAppointment->id,
            'lot_appointment_id' => $lotAppointment->id,
            'source' => $lotAppointment->lot ? 'Lot - '.$lotAppointment->lot->name : 'Lot',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $lotAppointment->customer_phone,
            'address' => $lotAppointment->address,
            'department_code' => strtoupper((string) $lotAppointment->department_code),
            'latitude' => (float) $lotAppointment->latitude,
            'longitude' => (float) $lotAppointment->longitude,
            'preferred_starts_at' => null,
            'is_manual' => false,
            'is_lot' => true,
            'service' => $service ? [
                'id' => $service->id,
                'type' => $service->type,
                'name' => $service->name,
                'average_duration_minutes' => $service->average_duration_minutes,
            ] : null,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitCustomerName(LotAppointment $lotAppointment): array
    {
        $firstName = trim((string) $lotAppointment->customer_first_name);
        $lastName = trim((string) $lotAppointment->customer_last_name);

        if ($firstName !== '' || $lastName !== '') {
            return [$firstName, $lastName];
        }

        $parts = preg_split('/\s+/', trim($lotAppointment->customer_name), 2) ?: [];

        return [
            $parts[0] ?? 'Client',
            $parts[1] ?? 'Lot',
        ];
    }

    private function refreshLotStatus(?Lot $lot): void
    {
        if (! $lot) {
            return;
        }

        $totalAppointments = $lot->appointments()->count();
        $placedAppointments = $lot->appointments()
            ->whereNotNull('appointment_id')
            ->count();

        $status = match (true) {
            $totalAppointments > 0 && $placedAppointments >= $totalAppointments => Lot::STATUS_COMPLETED,
            $placedAppointments > 0 => Lot::STATUS_IN_PROGRESS,
            default => Lot::STATUS_NOT_STARTED,
        };

        if ($lot->status !== $status) {
            $lot->update(['status' => $status]);
        }
    }

    /**
     * @param array<string, mixed> $manualAppointment
     * @return array<string, mixed>|null
     */
    private function manualAppointmentFromPayload(array $manualAppointment): ?array
    {
        $service = Service::query()->find($manualAppointment['service_id'] ?? null);

        if (! $service) {
            return null;
        }

        $normalizedPayload = [
            'first_name' => trim((string) $manualAppointment['first_name']),
            'last_name' => trim((string) $manualAppointment['last_name']),
            'phone' => trim((string) $manualAppointment['phone']),
            'address' => trim((string) $manualAppointment['address']),
            'department_code' => strtoupper(trim((string) $manualAppointment['department_code'])),
            'latitude' => (float) $manualAppointment['latitude'],
            'longitude' => (float) $manualAppointment['longitude'],
            'service_id' => (int) $service->id,
        ];

        return [
            'id' => 'manual-'.hash('sha1', json_encode($normalizedPayload, JSON_THROW_ON_ERROR)),
            'source' => 'RDV manuel',
            'first_name' => $normalizedPayload['first_name'],
            'last_name' => $normalizedPayload['last_name'],
            'phone' => $normalizedPayload['phone'],
            'address' => $normalizedPayload['address'],
            'department_code' => $normalizedPayload['department_code'],
            'latitude' => $normalizedPayload['latitude'],
            'longitude' => $normalizedPayload['longitude'],
            'preferred_starts_at' => null,
            'is_manual' => true,
            'service' => [
                'id' => $service->id,
                'type' => $service->type,
                'name' => $service->name,
                'average_duration_minutes' => $service->average_duration_minutes,
            ],
        ];
    }

    /**
     * @param Collection<int, Appointment> $appointments
     * @return Collection<int, array<string, mixed>>
     */
    private function calendarEvents(Collection $appointments): Collection
    {
        $activeAppointmentsByTechnicianAndDay = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->deleted_at === null)
            ->groupBy(fn (Appointment $appointment): string => $appointment->technician_id.'|'.$appointment->starts_at?->toDateString());

        return $appointments->map(function (Appointment $appointment) use ($activeAppointmentsByTechnicianAndDay): array {
            $serviceLabel = $appointment->service
                ? $appointment->service->type.' - '.$appointment->service->name
                : 'Prestation';
            $isDeleted = $appointment->trashed();
            $sameDayAppointments = $activeAppointmentsByTechnicianAndDay
                ->get($appointment->technician_id.'|'.$appointment->starts_at?->toDateString(), collect())
                ->sortBy('starts_at')
                ->values();
            $previousAppointment = $sameDayAppointments
                ->filter(fn (Appointment $candidate): bool => $candidate->starts_at?->lt($appointment->starts_at))
                ->last();
            $originLat = $previousAppointment ? (float) $previousAppointment->latitude : (float) $appointment->technician?->latitude;
            $originLng = $previousAppointment ? (float) $previousAppointment->longitude : (float) $appointment->technician?->longitude;

            return [
                'id' => $appointment->id,
                'title' => $appointment->technician?->full_name.' | '.$serviceLabel,
                'start' => $appointment->starts_at?->toIso8601String(),
                'end' => $appointment->ends_at?->toIso8601String(),
                'backgroundColor' => $isDeleted ? 'rgba(190,18,60,0.22)' : '#9ccfe3',
                'borderColor' => $isDeleted ? '#be123c' : '#31424c',
                'textColor' => '#31424c',
                'extendedProps' => [
                    'technician_id' => $appointment->technician_id,
                    'technician_name' => $appointment->technician?->full_name,
                    'technician_address' => $appointment->technician?->address,
                    'technician_latitude' => $appointment->technician?->latitude ? (float) $appointment->technician->latitude : null,
                    'technician_longitude' => $appointment->technician?->longitude ? (float) $appointment->technician->longitude : null,
                    'service_label' => $serviceLabel,
                    'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                    'customer_phone' => $appointment->customer_phone,
                    'address' => $appointment->address,
                    'latitude' => (float) $appointment->latitude,
                    'longitude' => (float) $appointment->longitude,
                    'duration_minutes' => (int) $appointment->duration_minutes,
                    'comment' => $appointment->comment,
                    'deleted_at' => $appointment->deleted_at?->toIso8601String(),
                    'origin_label' => $previousAppointment ? 'rdv precedent' : 'domicile',
                    'origin_latitude' => $originLat,
                    'origin_longitude' => $originLng,
                    'origin_name' => $previousAppointment
                        ? trim($previousAppointment->customer_first_name.' '.$previousAppointment->customer_last_name)
                        : 'Domicile',
                ],
            ];
        })->values();
    }

    /**
     * @param Collection<int, User> $technicians
     * @param Collection<int, Appointment> $appointments
     * @param array<string, mixed> $crmAppointment
     * @return array<int, array<string, mixed>>
     */
    private function buildSlotSuggestions(
        Collection $technicians,
        Collection $appointments,
        array $crmAppointment,
        MapboxDrivingRouteService $drivingRoutes,
        ?Carbon $windowStart = null,
        ?Carbon $windowEnd = null,
    ): array {
        $durationMinutes = (int) ($crmAppointment['service']['average_duration_minutes'] ?? 60);
        $durationMinutes = max(30, $durationMinutes);
        $preferredStartsAt = $this->preferredStartsAt($crmAppointment);

        if ($preferredStartsAt) {
            if ($windowStart && $windowEnd && ! $preferredStartsAt->betweenIncluded($windowStart, $windowEnd)) {
                return [];
            }

            return $this->buildPreferredSlotSuggestions(
                $technicians,
                $appointments,
                $crmAppointment,
                $durationMinutes,
                $preferredStartsAt,
                $drivingRoutes
            );
        }

        $startDate = $windowStart?->copy()->startOfDay() ?? now()->copy()->startOfDay();
        $endDate = $windowEnd?->copy() ?? $startDate->copy()->addWeeks(2);
        $today = now()->copy()->startOfDay();

        if ($startDate->lt($today)) {
            $startDate = $today;
        }

        $days = collect();

        for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
            if ($this->isBookableDay($date)) {
                $days->push($date->copy());
            }
        }

        $appointmentsByTechnician = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->deleted_at === null)
            ->filter(fn (Appointment $appointment): bool => $appointment->starts_at?->betweenIncluded($startDate, $endDate))
            ->groupBy('technician_id');

        return $technicians
            ->flatMap(function (User $technician) use ($days, $appointmentsByTechnician, $crmAppointment, $durationMinutes, $drivingRoutes): array {
                $dailySuggestions = [];

                foreach ($days as $date) {
                    $dayAppointments = $appointmentsByTechnician
                        ->get($technician->id, collect())
                        ->filter(fn (Appointment $appointment): bool => $appointment->starts_at?->isSameDay($date))
                        ->sortBy('starts_at')
                        ->values();
                    $suggestion = $this->suggestSlotForDay(
                        $technician,
                        $dayAppointments,
                        $crmAppointment,
                        $durationMinutes,
                        $date,
                        $drivingRoutes
                    );

                    if ($suggestion !== null) {
                        $dailySuggestions[] = $suggestion;
                    }
                }

                return $dailySuggestions;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $crmAppointment
     */
    private function preferredStartsAt(array $crmAppointment): ?Carbon
    {
        if (empty($crmAppointment['preferred_starts_at'])) {
            return null;
        }

        return Carbon::parse($crmAppointment['preferred_starts_at']);
    }

    /**
     * @param Collection<int, User> $technicians
     * @param Collection<int, Appointment> $appointments
     * @param array<string, mixed> $crmAppointment
     * @return array<int, array<string, mixed>>
     */
    private function buildPreferredSlotSuggestions(
        Collection $technicians,
        Collection $appointments,
        array $crmAppointment,
        int $durationMinutes,
        Carbon $preferredStartsAt,
        MapboxDrivingRouteService $drivingRoutes
    ): array {
        if (! $this->isBookableDay($preferredStartsAt) || $preferredStartsAt->lt(now())) {
            return [];
        }

        $appointmentsByTechnician = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->deleted_at === null)
            ->filter(fn (Appointment $appointment): bool => (bool) $appointment->starts_at?->isSameDay($preferredStartsAt))
            ->groupBy('technician_id');

        return $technicians
            ->map(fn (User $technician): ?array => $this->suggestPreferredSlotForDay(
                $technician,
                $appointmentsByTechnician
                    ->get($technician->id, collect())
                    ->sortBy('starts_at')
                    ->values(),
                $crmAppointment,
                $durationMinutes,
                $preferredStartsAt,
                $drivingRoutes
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Appointment> $dayAppointments
     * @param array<string, mixed> $crmAppointment
     * @return array<string, mixed>|null
     */
    private function suggestSlotForDay(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        int $durationMinutes,
        Carbon $date,
        MapboxDrivingRouteService $drivingRoutes
    ): ?array {
        $dayStart = Carbon::parse($date->format('Y-m-d').' '.($technician->day_start_time ?: '08:00'));
        $dayEnd = Carbon::parse($date->format('Y-m-d').' '.($technician->day_end_time ?: '17:00'));

        if ($this->technicianHasLoadedAbsenceOverlap($technician, $dayStart, $dayEnd)) {
            return null;
        }

        if ($date->isSameDay(now()) && $dayStart->lt(now())) {
            $dayStart = now()->copy()->addMinutes(15);
        }

        if ($dayStart->gte($dayEnd)) {
            return null;
        }

        $destination = [
            'lat' => (float) $crmAppointment['latitude'],
            'lng' => (float) $crmAppointment['longitude'],
            'label' => trim($crmAppointment['first_name'].' '.$crmAppointment['last_name']),
        ];
        $home = [
            'lat' => (float) $technician->latitude,
            'lng' => (float) $technician->longitude,
            'label' => 'Domicile',
        ];

        if ($dayAppointments->isEmpty()) {
            return $this->buildSuggestionIfFits(
                technician: $technician,
                crmAppointment: $crmAppointment,
                date: $date,
                availableAt: $dayStart,
                origin: $home,
                nextAppointment: null,
                dayEnd: $dayEnd,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: 'domicile',
            );
        }

        $beforeFirst = $this->buildSuggestionIfFits(
            technician: $technician,
            crmAppointment: $crmAppointment,
            date: $date,
            availableAt: $dayStart,
            origin: $home,
            nextAppointment: $dayAppointments->first(),
            dayEnd: $dayEnd,
            durationMinutes: $durationMinutes,
            drivingRoutes: $drivingRoutes,
            originLabel: 'domicile',
        );

        if ($beforeFirst !== null) {
            return $beforeFirst;
        }

        foreach ($dayAppointments as $index => $appointment) {
            $nextAppointment = $dayAppointments->get($index + 1);
            $origin = [
                'lat' => (float) $appointment->latitude,
                'lng' => (float) $appointment->longitude,
                'label' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
            ];
            $suggestion = $this->buildSuggestionIfFits(
                technician: $technician,
                crmAppointment: $crmAppointment,
                date: $date,
                availableAt: $appointment->ends_at,
                origin: $origin,
                nextAppointment: $nextAppointment,
                dayEnd: $dayEnd,
                durationMinutes: $durationMinutes,
                drivingRoutes: $drivingRoutes,
                originLabel: 'rdv precedent',
            );

            if ($suggestion !== null) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * @param Collection<int, Appointment> $dayAppointments
     * @param array<string, mixed> $crmAppointment
     * @return array<string, mixed>|null
     */
    private function suggestPreferredSlotForDay(
        User $technician,
        Collection $dayAppointments,
        array $crmAppointment,
        int $durationMinutes,
        Carbon $preferredStartsAt,
        MapboxDrivingRouteService $drivingRoutes
    ): ?array {
        $dayStart = Carbon::parse($preferredStartsAt->format('Y-m-d').' '.($technician->day_start_time ?: '08:00'));
        $dayEnd = Carbon::parse($preferredStartsAt->format('Y-m-d').' '.($technician->day_end_time ?: '17:00'));
        $startsAt = $this->roundUpToNextHalfHour($preferredStartsAt);
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        if ($this->technicianHasLoadedAbsenceOverlap($technician, $startsAt, $endsAt)) {
            return null;
        }

        if ($startsAt->lt($dayStart) || $endsAt->gt($dayEnd)) {
            return null;
        }

        $previousAppointment = $dayAppointments
            ->filter(fn (Appointment $appointment): bool => (bool) $appointment->starts_at?->lt($startsAt))
            ->last();

        if ($previousAppointment && $previousAppointment->ends_at?->gt($startsAt)) {
            return null;
        }

        $nextAppointment = $dayAppointments
            ->first(fn (Appointment $appointment): bool => (bool) $appointment->starts_at?->gte($startsAt));

        if ($nextAppointment && $nextAppointment->starts_at?->lt($endsAt)) {
            return null;
        }

        $origin = $previousAppointment ? [
            'lat' => (float) $previousAppointment->latitude,
            'lng' => (float) $previousAppointment->longitude,
            'label' => trim($previousAppointment->customer_first_name.' '.$previousAppointment->customer_last_name),
        ] : [
            'lat' => (float) $technician->latitude,
            'lng' => (float) $technician->longitude,
            'label' => 'Domicile',
        ];
        $originAvailableAt = $previousAppointment?->ends_at ?: $dayStart;
        $originLabel = $previousAppointment ? 'rdv precedent' : 'domicile';
        $destination = [
            'lat' => (float) $crmAppointment['latitude'],
            'lng' => (float) $crmAppointment['longitude'],
        ];
        $travelTo = $drivingRoutes->estimate($origin['lat'], $origin['lng'], $destination['lat'], $destination['lng']);

        if ($originAvailableAt->copy()->addMinutes((int) $travelTo['duration_minutes'])->gt($startsAt)) {
            return null;
        }

        if ($nextAppointment) {
            $travelAfter = $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $nextAppointment->latitude,
                (float) $nextAppointment->longitude
            );

            if ($endsAt->copy()->addMinutes((int) $travelAfter['duration_minutes'])->gt($nextAppointment->starts_at)) {
                return null;
            }
        } else {
            $travelAfter = $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $technician->latitude,
                (float) $technician->longitude
            );

            if ($endsAt->copy()->addMinutes((int) $travelAfter['duration_minutes'])->gt($dayEnd)) {
                return null;
            }
        }

        return $this->suggestionPayload(
            technician: $technician,
            crmAppointment: $crmAppointment,
            date: $preferredStartsAt,
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            origin: $origin,
            originLabel: $originLabel,
            travelTo: $travelTo,
            travelAfter: $travelAfter,
            nextAppointment: $nextAppointment,
            isPreferred: true,
        );
    }

    /**
     * @param array{lat: float, lng: float, label: string} $origin
     * @param array<string, mixed> $crmAppointment
     * @return array<string, mixed>|null
     */
    private function buildSuggestionIfFits(
        User $technician,
        array $crmAppointment,
        Carbon $date,
        Carbon $availableAt,
        array $origin,
        ?Appointment $nextAppointment,
        Carbon $dayEnd,
        int $durationMinutes,
        MapboxDrivingRouteService $drivingRoutes,
        string $originLabel,
    ): ?array {
        $destination = [
            'lat' => (float) $crmAppointment['latitude'],
            'lng' => (float) $crmAppointment['longitude'],
        ];
        $travelTo = $drivingRoutes->estimate($origin['lat'], $origin['lng'], $destination['lat'], $destination['lng']);
        $startsAt = $this->roundUpToNextHalfHour(
            (clone $availableAt)->addMinutes((int) $travelTo['duration_minutes'])
        );
        $endsAt = (clone $startsAt)->addMinutes($durationMinutes);

        if ($this->technicianHasLoadedAbsenceOverlap($technician, $startsAt, $endsAt)) {
            return null;
        }

        if ($nextAppointment) {
            $travelAfter = $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $nextAppointment->latitude,
                (float) $nextAppointment->longitude
            );
            $latestEnd = $nextAppointment->starts_at?->copy()->subMinutes((int) $travelAfter['duration_minutes']);
        } else {
            $travelAfter = $drivingRoutes->estimate(
                $destination['lat'],
                $destination['lng'],
                (float) $technician->latitude,
                (float) $technician->longitude
            );
            $latestEnd = (clone $dayEnd)->subMinutes((int) $travelAfter['duration_minutes']);
        }

        if (! $latestEnd || $endsAt->gt($latestEnd)) {
            return null;
        }

        return $this->suggestionPayload(
            technician: $technician,
            crmAppointment: $crmAppointment,
            date: $date,
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            origin: $origin,
            originLabel: $originLabel,
            travelTo: $travelTo,
            travelAfter: $travelAfter,
            nextAppointment: $nextAppointment,
        );
    }

    private function roundUpToNextHalfHour(Carbon $date): Carbon
    {
        $intervalSeconds = 30 * 60;
        $timestamp = $date->getTimestamp();
        $roundedTimestamp = intdiv($timestamp + $intervalSeconds - 1, $intervalSeconds) * $intervalSeconds;

        return Carbon::createFromTimestamp($roundedTimestamp, $date->getTimezone());
    }

    private function isBookableDay(Carbon $date): bool
    {
        return ! $date->isSunday();
    }

    private function absenceOverlapForTechnician(int $technicianId, Carbon $startsAt, Carbon $endsAt): ?TechnicianAbsence
    {
        return TechnicianAbsence::query()
            ->where('technician_id', $technicianId)
            ->where('starts_at', '<=', $endsAt)
            ->where('ends_at', '>=', $startsAt)
            ->orderBy('starts_at')
            ->first();
    }

    private function technicianHasLoadedAbsenceOverlap(User $technician, Carbon $startsAt, Carbon $endsAt): bool
    {
        if (! $technician->relationLoaded('absences')) {
            return $this->absenceOverlapForTechnician((int) $technician->id, $startsAt, $endsAt) !== null;
        }

        return $technician->absences->contains(
            fn (TechnicianAbsence $absence): bool => $absence->starts_at?->lte($endsAt)
                && $absence->ends_at?->gte($startsAt)
        );
    }

    private function absenceLabel(TechnicianAbsence $absence): string
    {
        $startsOn = $absence->starts_at?->format('d/m/Y') ?? '-';
        $endsOn = $absence->ends_at?->format('d/m/Y') ?? '-';

        return "du {$startsOn} au {$endsOn}";
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
     * @param array{lat: float, lng: float, label: string} $origin
     * @param array<string, mixed> $crmAppointment
     * @param array<string, mixed> $travelTo
     * @param array<string, mixed> $travelAfter
     * @return array<string, mixed>
     */
    private function suggestionPayload(
        User $technician,
        array $crmAppointment,
        Carbon $date,
        Carbon $startsAt,
        int $durationMinutes,
        array $origin,
        string $originLabel,
        array $travelTo,
        array $travelAfter,
        ?Appointment $nextAppointment,
        bool $isPreferred = false,
    ): array {
        $kind = $isPreferred ? 'preferred' : 'suggestion';
        $endsAt = (clone $startsAt)->addMinutes($durationMinutes);

        return [
            'id' => sprintf('%s-%d-%s-%s', $kind, $technician->id, $date->format('Ymd'), $startsAt->format('Hi')),
            'title' => ($isPreferred ? 'Dispo client' : 'Proposition').' | '.$technician->full_name,
            'start' => $startsAt->toIso8601String(),
            'end' => $endsAt->toIso8601String(),
            'extendedProps' => [
                'technician_id' => $technician->id,
                'technician_name' => $technician->full_name,
                'technician_address' => $technician->address,
                'technician_latitude' => $technician->latitude ? (float) $technician->latitude : null,
                'technician_longitude' => $technician->longitude ? (float) $technician->longitude : null,
                'is_suggestion' => true,
                'origin_label' => $originLabel,
                'origin_latitude' => $origin['lat'],
                'origin_longitude' => $origin['lng'],
                'origin_name' => $origin['label'],
                'latitude' => (float) $crmAppointment['latitude'],
                'longitude' => (float) $crmAppointment['longitude'],
                'address' => $crmAppointment['address'],
                'customer_name' => trim($crmAppointment['first_name'].' '.$crmAppointment['last_name']),
                'customer_phone' => $crmAppointment['phone'],
                'service_label' => $crmAppointment['service']
                    ? $crmAppointment['service']['type'].' - '.$crmAppointment['service']['name']
                    : 'Prestation non renseignee',
                'crm_appointment_id' => $crmAppointment['id'],
                'lot_appointment_id' => $crmAppointment['lot_appointment_id'] ?? null,
                'can_validate' => $crmAppointment['service'] !== null,
                'travel_to_distance_km' => round((float) $travelTo['distance_km'], 1),
                'travel_to_minutes' => (int) $travelTo['duration_minutes'],
                'travel_after_distance_km' => round((float) $travelAfter['distance_km'], 1),
                'travel_after_minutes' => (int) $travelAfter['duration_minutes'],
                'duration_minutes' => $durationMinutes,
                'next_appointment_id' => $nextAppointment?->id,
                'preferred_locked' => $isPreferred,
            ],
        ];
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 1);
    }

    private function haversine(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
