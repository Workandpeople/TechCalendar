<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\SimulatedCrmAppointmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlannerAppointmentController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canAccess($request), 403);

        return view('planner.book', [
            'mapboxToken' => (string) config('services.mapbox.token'),
            'services' => Service::query()->orderBy('type')->orderBy('name')->get(['id', 'type', 'name', 'average_duration_minutes']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'service_id' => ['required', Rule::exists('services', 'id')],
            'technician_id' => ['required', Rule::exists('users', 'id')],
            'customer_first_name' => ['required', 'string', 'max:120'],
            'customer_last_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $technician = User::query()
            ->where('id', (int) $payload['technician_id'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $startsAt = Carbon::parse($payload['starts_at']);
        $duration = (int) $payload['duration_minutes'];
        $endsAt = (clone $startsAt)->addMinutes($duration);

        Appointment::query()->create([
            'service_id' => (int) $payload['service_id'],
            'technician_id' => $technician->id,
            'created_by' => $request->user()->id,
            'customer_first_name' => $payload['customer_first_name'],
            'customer_last_name' => $payload['customer_last_name'],
            'customer_phone' => $payload['customer_phone'],
            'address' => $payload['address'],
            'latitude' => (float) $payload['latitude'],
            'longitude' => (float) $payload['longitude'],
            'starts_at' => $startsAt,
            'duration_minutes' => $duration,
            'ends_at' => $endsAt,
            'comment' => $payload['comment'] ?? null,
        ]);

        return redirect()->route('planner.book')->with('status', 'Rendez-vous cree avec succes.');
    }

    public function crmAppointments(Request $request, SimulatedCrmAppointmentService $crmAppointments): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        return response()->json([
            'appointments' => $crmAppointments->pending(5),
        ]);
    }

    public function suggestTechnicians(Request $request): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'appointment_date' => ['nullable', 'date'],
        ]);

        $originLat = (float) $payload['latitude'];
        $originLng = (float) $payload['longitude'];
        $appointmentDate = filled($payload['appointment_date'] ?? null)
            ? Carbon::parse($payload['appointment_date'])
            : null;

        $technicians = User::query()
            ->where('role', 2)
            ->where('admin', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'latitude', 'longitude', 'address', 'phone']);

        if ($technicians->isEmpty()) {
            throw ValidationException::withMessages([
                'address' => 'Aucun technicien geolocalise disponible.',
            ]);
        }

        if ($appointmentDate) {
            return response()->json([
                'closest_driving' => $this->resolveDateAwareTechnicians($originLat, $originLng, $appointmentDate, $technicians),
                'result_limit' => 6,
                'search_mode' => 'date',
            ]);
        }

        $closestCrow = $technicians
            ->map(function (User $user) use ($originLat, $originLng): array {
                $distanceKm = $this->haversine($originLat, $originLng, (float) $user->latitude, (float) $user->longitude);

                return [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'latitude' => (float) $user->latitude,
                    'longitude' => (float) $user->longitude,
                    'crow_distance_km' => round($distanceKm, 2),
                ];
            })
            ->sortBy('crow_distance_km')
            ->take(5)
            ->values();

        $closestDriving = $this->resolveDrivingTop5($originLat, $originLng, $closestCrow->all());

        return response()->json([
            'closest_driving' => $closestDriving,
            'result_limit' => 4,
            'search_mode' => 'home',
        ]);
    }

    public function calendarEvents(Request $request): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'technician_ids' => ['required', 'array', 'min:1', 'max:6'],
            'technician_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $appointments = Appointment::withTrashed()
            ->with([
                'service:id,type,name',
                'technician:id,first_name,last_name,phone,address,latitude,longitude',
                'creator:id,first_name,last_name',
            ])
            ->whereIn('technician_id', $payload['technician_ids'])
            ->where('starts_at', '<', Carbon::parse($payload['end']))
            ->where('ends_at', '>', Carbon::parse($payload['start']))
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'events' => $appointments->map(function (Appointment $appointment): array {
                $technicianName = $appointment->technician?->full_name ?? 'Technicien';
                $serviceLabel = $appointment->service
                    ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
                    : 'Prestation';

                return [
                    'id' => $appointment->id,
                    'title' => sprintf('%s | %s', $technicianName, $serviceLabel),
                    'start' => $appointment->starts_at?->toIso8601String(),
                    'end' => $appointment->ends_at?->toIso8601String(),
                    'extendedProps' => [
                        'technician_id' => $appointment->technician_id,
                        'technician_name' => $technicianName,
                        'technician_phone' => $appointment->technician?->phone,
                        'technician_address' => $appointment->technician?->address,
                        'technician_latitude' => $appointment->technician?->latitude,
                        'technician_longitude' => $appointment->technician?->longitude,
                        'service_label' => $serviceLabel,
                        'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                        'customer_phone' => $appointment->customer_phone,
                        'address' => $appointment->address,
                        'latitude' => $appointment->latitude,
                        'longitude' => $appointment->longitude,
                        'duration_minutes' => $appointment->duration_minutes,
                        'comment' => $appointment->comment,
                        'deleted_at' => $appointment->deleted_at?->toIso8601String(),
                        'created_by_name' => $appointment->creator?->full_name,
                    ],
                ];
            })->values(),
        ]);
    }

    public function suggestTechniciansForSlot(Request $request): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'technician_ids' => ['required', 'array', 'min:1', 'max:6'],
            'technician_ids.*' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 2)->where('admin', false)),
            ],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'starts_at' => ['required', 'date'],
        ]);

        $startsAt = Carbon::parse($payload['starts_at']);
        $technicians = User::query()
            ->whereIn('id', $payload['technician_ids'])
            ->where('role', 2)
            ->where('admin', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'phone', 'address', 'latitude', 'longitude']);

        $candidates = $technicians
            ->map(function (User $technician) use ($startsAt): ?array {
                $isBusy = Appointment::query()
                    ->where('technician_id', $technician->id)
                    ->where('starts_at', '<=', $startsAt)
                    ->where('ends_at', '>', $startsAt)
                    ->exists();

                if ($isBusy) {
                    return null;
                }

                $previousAppointment = Appointment::query()
                    ->with('service:id,type,name')
                    ->where('technician_id', $technician->id)
                    ->where('ends_at', '<=', $startsAt)
                    ->orderByDesc('ends_at')
                    ->first();

                return [
                    'id' => $technician->id,
                    'full_name' => $technician->full_name,
                    'phone' => $technician->phone,
                    'address' => $technician->address,
                    'latitude' => (float) $technician->latitude,
                    'longitude' => (float) $technician->longitude,
                    'origin_type' => $previousAppointment ? 'previous_appointment' : 'home',
                    'origin_label' => $previousAppointment ? 'RDV precedent' : 'Domicile tech',
                    'origin_address' => $previousAppointment?->address ?? $technician->address,
                    'origin_latitude' => (float) ($previousAppointment?->latitude ?? $technician->latitude),
                    'origin_longitude' => (float) ($previousAppointment?->longitude ?? $technician->longitude),
                    'previous_appointment' => $previousAppointment ? [
                        'id' => $previousAppointment->id,
                        'customer_name' => trim($previousAppointment->customer_first_name.' '.$previousAppointment->customer_last_name),
                        'service_label' => $previousAppointment->service
                            ? sprintf('%s - %s', $previousAppointment->service->type, $previousAppointment->service->name)
                            : 'Prestation',
                        'starts_at' => $previousAppointment->starts_at?->toIso8601String(),
                        'ends_at' => $previousAppointment->ends_at?->toIso8601String(),
                        'address' => $previousAppointment->address,
                    ] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'closest_driving' => $this->resolveDrivingFromDynamicOrigins(
                (float) $payload['latitude'],
                (float) $payload['longitude'],
                $candidates,
            ),
        ]);
    }

    public function updateComment(Request $request, int $appointment): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        $appointment = Appointment::withTrashed()->findOrFail($appointment);

        $payload = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment->update([
            'comment' => $payload['comment'] ?? null,
        ]);

        return response()->json([
            'comment' => $appointment->comment,
        ]);
    }

    private function resolveDateAwareTechnicians(float $destinationLat, float $destinationLng, Carbon $date, $technicians): array
    {
        $appointmentsByTechnician = Appointment::query()
            ->with('service:id,type,name')
            ->whereIn('technician_id', $technicians->pluck('id'))
            ->whereBetween('starts_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('technician_id');

        $candidates = $technicians
            ->map(function (User $technician) use ($appointmentsByTechnician, $destinationLat, $destinationLng): array {
                $bestOrigin = [
                    'type' => 'home',
                    'label' => 'Domicile tech',
                    'address' => $technician->address,
                    'latitude' => (float) $technician->latitude,
                    'longitude' => (float) $technician->longitude,
                    'appointment' => null,
                ];
                $bestDistanceKm = $this->haversine(
                    $destinationLat,
                    $destinationLng,
                    (float) $technician->latitude,
                    (float) $technician->longitude,
                );

                foreach ($appointmentsByTechnician->get($technician->id, collect()) as $appointment) {
                    $appointmentDistanceKm = $this->haversine(
                        $destinationLat,
                        $destinationLng,
                        (float) $appointment->latitude,
                        (float) $appointment->longitude,
                    );

                    if ($appointmentDistanceKm >= $bestDistanceKm) {
                        continue;
                    }

                    $bestDistanceKm = $appointmentDistanceKm;
                    $bestOrigin = [
                        'type' => 'day_appointment',
                        'label' => 'RDV du jour',
                        'address' => $appointment->address,
                        'latitude' => (float) $appointment->latitude,
                        'longitude' => (float) $appointment->longitude,
                        'appointment' => [
                            'id' => $appointment->id,
                            'customer_name' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                            'service_label' => $appointment->service
                                ? sprintf('%s - %s', $appointment->service->type, $appointment->service->name)
                                : 'Prestation',
                            'starts_at' => $appointment->starts_at?->toIso8601String(),
                            'ends_at' => $appointment->ends_at?->toIso8601String(),
                            'address' => $appointment->address,
                        ],
                    ];
                }

                return [
                    'id' => $technician->id,
                    'full_name' => $technician->full_name,
                    'phone' => $technician->phone,
                    'address' => $technician->address,
                    'latitude' => (float) $technician->latitude,
                    'longitude' => (float) $technician->longitude,
                    'origin_type' => $bestOrigin['type'],
                    'origin_label' => $bestOrigin['label'],
                    'origin_address' => $bestOrigin['address'],
                    'origin_latitude' => $bestOrigin['latitude'],
                    'origin_longitude' => $bestOrigin['longitude'],
                    'origin_appointment' => $bestOrigin['appointment'],
                    'crow_distance_km' => round($bestDistanceKm, 2),
                ];
            })
            ->sortBy('crow_distance_km')
            ->take(12)
            ->values()
            ->all();

        return collect($this->resolveDrivingFromDynamicOrigins($destinationLat, $destinationLng, $candidates))
            ->take(6)
            ->values()
            ->all();
    }

    private function resolveDrivingTop5(float $originLat, float $originLng, array $candidates): array
    {
        $token = (string) config('services.mapbox.token');

        if ($token === '') {
            return array_map(function (array $candidate): array {
                $candidate['drive_duration_minutes'] = null;
                $candidate['drive_distance_km'] = null;

                return $candidate;
            }, array_slice($candidates, 0, 5));
        }

        $coordinates = collect($candidates)
            ->map(fn (array $candidate): string => sprintf('%F,%F', $candidate['longitude'], $candidate['latitude']))
            ->implode(';');

        $origin = sprintf('%F,%F', $originLng, $originLat);
        $url = sprintf('https://api.mapbox.com/directions-matrix/v1/mapbox/driving/%s;%s', $origin, $coordinates);

        $response = Http::timeout(10)->get($url, [
            'access_token' => $token,
            'annotations' => 'duration,distance',
        ]);

        if (! $response->ok()) {
            return array_map(function (array $candidate): array {
                $candidate['drive_duration_minutes'] = null;
                $candidate['drive_distance_km'] = null;

                return $candidate;
            }, array_slice($candidates, 0, 5));
        }

        $durations = $response->json('durations.0', []);
        $distances = $response->json('distances.0', []);

        return collect($candidates)
            ->values()
            ->map(function (array $candidate, int $index) use ($durations, $distances): array {
                $seconds = $durations[$index + 1] ?? null;
                $meters = $distances[$index + 1] ?? null;
                $candidate['drive_duration_minutes'] = is_numeric($seconds) ? round(((float) $seconds) / 60, 1) : null;
                $candidate['drive_distance_km'] = is_numeric($meters) ? round(((float) $meters) / 1000, 1) : null;

                return $candidate;
            })
            ->sortBy(fn (array $candidate): float => $candidate['drive_duration_minutes'] ?? INF)
            ->take(5)
            ->values()
            ->all();
    }

    private function resolveDrivingFromDynamicOrigins(float $destinationLat, float $destinationLng, array $candidates): array
    {
        $token = (string) config('services.mapbox.token');

        if ($token === '' || $candidates === []) {
            return collect($candidates)
                ->map(function (array $candidate) use ($destinationLat, $destinationLng): array {
                    $candidate['drive_duration_minutes'] = null;
                    $candidate['drive_distance_km'] = null;
                    $candidate['crow_distance_km'] = round($this->haversine(
                        $destinationLat,
                        $destinationLng,
                        (float) $candidate['origin_latitude'],
                        (float) $candidate['origin_longitude'],
                    ), 2);

                    return $candidate;
                })
                ->sortBy('crow_distance_km')
                ->values()
                ->all();
        }

        $origins = collect($candidates)
            ->map(fn (array $candidate): string => sprintf('%F,%F', $candidate['origin_longitude'], $candidate['origin_latitude']));
        $destination = sprintf('%F,%F', $destinationLng, $destinationLat);
        $coordinates = $origins->push($destination)->implode(';');
        $destinationIndex = count($candidates);

        $url = sprintf('https://api.mapbox.com/directions-matrix/v1/mapbox/driving/%s', $coordinates);
        $response = Http::timeout(10)->get($url, [
            'access_token' => $token,
            'annotations' => 'duration,distance',
            'sources' => implode(';', range(0, count($candidates) - 1)),
            'destinations' => (string) $destinationIndex,
        ]);

        if (! $response->ok()) {
            return collect($candidates)
                ->map(function (array $candidate): array {
                    $candidate['drive_duration_minutes'] = null;
                    $candidate['drive_distance_km'] = null;

                    return $candidate;
                })
                ->values()
                ->all();
        }

        $durations = $response->json('durations', []);
        $distances = $response->json('distances', []);

        return collect($candidates)
            ->values()
            ->map(function (array $candidate, int $index) use ($durations, $distances): array {
                $seconds = $durations[$index][0] ?? null;
                $meters = $distances[$index][0] ?? null;
                $candidate['drive_duration_minutes'] = is_numeric($seconds) ? round(((float) $seconds) / 60, 1) : null;
                $candidate['drive_distance_km'] = is_numeric($meters) ? round(((float) $meters) / 1000, 1) : null;

                return $candidate;
            })
            ->sortBy(fn (array $candidate): float => $candidate['drive_duration_minutes'] ?? INF)
            ->values()
            ->all();
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 1);
    }
}
