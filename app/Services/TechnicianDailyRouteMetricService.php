<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class TechnicianDailyRouteMetricService
{
    /**
     * @return Collection<int, TechnicianDailyRouteMetric>
     */
    public function ensureForPeriod(Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->ensureForPeriodWithProgress($startDate, $endDate);
    }

    /**
     * @param callable(int $processed, int $total): void|null $progressCallback
     * @return Collection<int, TechnicianDailyRouteMetric>
     */
    public function ensureForPeriodWithProgress(Carbon $startDate, Carbon $endDate, ?callable $progressCallback = null): Collection
    {
        $appointments = Appointment::query()
            ->with('technician:id,first_name,last_name,address,latitude,longitude,day_start_time,day_end_time')
            ->whereBetween('starts_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->whereHas('technician', fn ($query) => $query
                ->where('role', 2)
                ->where('admin', false)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude'))
            ->orderBy('technician_id')
            ->orderBy('starts_at')
            ->get();

        $groups = $appointments
            ->groupBy(fn (Appointment $appointment): string => $appointment->technician_id.'|'.$appointment->starts_at->toDateString())
            ->values();
        $total = $groups->count();
        $processed = 0;

        if ($progressCallback) {
            $progressCallback($processed, $total);
        }

        $metrics = $groups->map(function (Collection $dayAppointments) use (&$processed, $total, $progressCallback): TechnicianDailyRouteMetric {
            $metric = $this->ensureForDay($dayAppointments);
            $processed++;

            if ($progressCallback) {
                $progressCallback($processed, $total);
            }

            return $metric;
        });

        return $metrics->values();
    }

    /**
     * @return Collection<int, TechnicianDailyRouteMetric>
     */
    public function ensureForTechnicianPeriod(User $technician, Carbon $startDate, Carbon $endDate): Collection
    {
        if ($technician->role !== 2 || ! $technician->latitude || ! $technician->longitude) {
            return collect();
        }

        $appointments = Appointment::query()
            ->with('technician:id,first_name,last_name,address,latitude,longitude,day_start_time,day_end_time,role,admin')
            ->where('technician_id', $technician->id)
            ->whereBetween('starts_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        return $appointments
            ->groupBy(fn (Appointment $appointment): string => $appointment->technician_id.'|'.$appointment->starts_at->toDateString())
            ->map(fn (Collection $dayAppointments): TechnicianDailyRouteMetric => $this->ensureForDay($dayAppointments))
            ->values();
    }

    /**
     * @param Collection<int, Appointment> $appointments
     */
    private function ensureForDay(Collection $appointments): TechnicianDailyRouteMetric
    {
        /** @var Appointment $firstAppointment */
        $firstAppointment = $appointments->first();
        /** @var User $technician */
        $technician = $firstAppointment->technician;
        $serviceDate = $firstAppointment->starts_at->toDateString();
        $routePoints = $this->routePoints($technician, $appointments);
        $routeHash = hash('sha256', json_encode([
            'route_points' => $routePoints,
            'day_start_time' => $technician->day_start_time ?: '08:00',
            'day_end_time' => $technician->day_end_time ?: '17:00',
        ], JSON_THROW_ON_ERROR));

        $metric = TechnicianDailyRouteMetric::query()
            ->firstOrNew([
                'technician_id' => $technician->id,
                'service_date' => $serviceDate,
            ]);

        if ($metric->exists && $metric->route_hash === $routeHash) {
            return $metric;
        }

        $calculation = $this->calculateRoute($routePoints);
        $overtimeMinutes = $this->calculateOvertimeMinutes($technician, $appointments, $calculation);

        $metric->fill([
            'appointment_count' => $appointments->count(),
            'drive_distance_km' => $calculation['distance_km'],
            'drive_duration_minutes' => $calculation['duration_minutes'],
            'overtime_minutes' => $overtimeMinutes,
            'calculation_source' => $calculation['source'],
            'route_hash' => $routeHash,
            'route_points' => $routePoints,
            'calculated_at' => now(),
        ])->save();

        return $metric;
    }

    /**
     * @param Collection<int, Appointment> $appointments
     * @return array<int, array<string, mixed>>
     */
    private function routePoints(User $technician, Collection $appointments): array
    {
        $home = [
            'type' => 'home',
            'label' => 'Domicile',
            'lat' => round((float) $technician->latitude, 7),
            'lng' => round((float) $technician->longitude, 7),
        ];

        return collect([$home])
            ->merge($appointments->map(fn (Appointment $appointment): array => [
                'type' => 'appointment',
                'label' => trim($appointment->customer_first_name.' '.$appointment->customer_last_name),
                'appointment_id' => $appointment->id,
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'ends_at' => $appointment->ends_at?->toIso8601String(),
                'lat' => round((float) $appointment->latitude, 7),
                'lng' => round((float) $appointment->longitude, 7),
            ]))
            ->push($home)
            ->values()
            ->all();
    }

    /**
     * @param array<int, array{lat:float,lng:float}> $routePoints
     * @return array{distance_km:float,duration_minutes:int,source:string,legs:array<int,array{distance_km:float,duration_minutes:int,source:string}>}
     */
    private function calculateRoute(array $routePoints): array
    {
        $token = (string) config('services.mapbox.token');

        if ($token !== '' && count($routePoints) >= 2) {
            $mapboxResult = $this->calculateWithMapbox($routePoints, $token);

            if ($mapboxResult !== null) {
                return $mapboxResult;
            }
        }

        return $this->calculateWithHaversine($routePoints);
    }

    /**
     * @param array<int, array{lat:float,lng:float}> $routePoints
     * @return array{distance_km:float,duration_minutes:int,source:string,legs:array<int,array{distance_km:float,duration_minutes:int,source:string}>}|null
     */
    private function calculateWithMapbox(array $routePoints, string $token): ?array
    {
        $coordinates = collect($routePoints)
            ->map(fn (array $point): string => sprintf('%F,%F', $point['lng'], $point['lat']))
            ->implode(';');

        $response = Http::timeout(10)->get(
            sprintf('https://api.mapbox.com/directions/v5/mapbox/driving/%s', $coordinates),
            [
                'access_token' => $token,
                'alternatives' => 'false',
                'geometries' => 'geojson',
                'overview' => 'false',
            ],
        );

        if (! $response->ok()) {
            return null;
        }

        $route = $response->json('routes.0');
        $distanceMeters = $route['distance'] ?? null;
        $durationSeconds = $route['duration'] ?? null;
        $routeLegs = $route['legs'] ?? null;

        if (! is_numeric($distanceMeters) || ! is_numeric($durationSeconds) || ! is_array($routeLegs)) {
            return null;
        }

        $legs = collect($routeLegs)
            ->map(function (mixed $leg): ?array {
                if (! is_array($leg)) {
                    return null;
                }

                $distanceMeters = $leg['distance'] ?? null;
                $durationSeconds = $leg['duration'] ?? null;

                if (! is_numeric($distanceMeters) || ! is_numeric($durationSeconds)) {
                    return null;
                }

                return [
                    'distance_km' => round(((float) $distanceMeters) / 1000, 2),
                    'duration_minutes' => (int) round(((float) $durationSeconds) / 60),
                    'source' => 'mapbox',
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (count($legs) !== max(0, count($routePoints) - 1)) {
            return null;
        }

        return [
            'distance_km' => round(((float) $distanceMeters) / 1000, 2),
            'duration_minutes' => (int) round(((float) $durationSeconds) / 60),
            'source' => 'mapbox',
            'legs' => $legs,
        ];
    }

    /**
     * @param array<int, array{lat:float,lng:float}> $routePoints
     * @return array{distance_km:float,duration_minutes:int,source:string,legs:array<int,array{distance_km:float,duration_minutes:int,source:string}>}
     */
    private function calculateWithHaversine(array $routePoints): array
    {
        $distanceKm = 0.0;
        $legs = [];

        foreach (array_values($routePoints) as $index => $point) {
            $nextPoint = $routePoints[$index + 1] ?? null;

            if (! $nextPoint) {
                continue;
            }

            $legDistanceKm = $this->haversine($point['lat'], $point['lng'], $nextPoint['lat'], $nextPoint['lng']);
            $distanceKm += $legDistanceKm;
            $legs[] = [
                'distance_km' => round($legDistanceKm, 2),
                'duration_minutes' => (int) round(($legDistanceKm / 45) * 60),
                'source' => 'haversine',
            ];
        }

        return [
            'distance_km' => round($distanceKm, 2),
            'duration_minutes' => (int) round(($distanceKm / 45) * 60),
            'source' => 'haversine',
            'legs' => $legs,
        ];
    }

    /**
     * @param Collection<int, Appointment> $appointments
     * @param array{legs:array<int,array{duration_minutes:int}>} $calculation
     */
    private function calculateOvertimeMinutes(User $technician, Collection $appointments, array $calculation): int
    {
        /** @var Appointment|null $firstAppointment */
        $firstAppointment = $appointments->first();
        /** @var Appointment|null $lastAppointment */
        $lastAppointment = $appointments->last();

        if (! $firstAppointment || ! $lastAppointment) {
            return 0;
        }

        $legs = array_values($calculation['legs'] ?? []);
        $firstTravelMinutes = (int) ($legs[0]['duration_minutes'] ?? 0);
        $lastLegKey = array_key_last($legs);
        $lastTravelMinutes = $lastLegKey === null ? 0 : (int) ($legs[$lastLegKey]['duration_minutes'] ?? 0);

        $workStartsAt = $firstAppointment->starts_at->copy()->subMinutes($firstTravelMinutes);
        $workEndsAt = $lastAppointment->ends_at->copy()->addMinutes($lastTravelMinutes);
        $serviceDate = $firstAppointment->starts_at->toDateString();
        $standardStartsAt = Carbon::parse($serviceDate.' '.($technician->day_start_time ?: '08:00'));
        $standardEndsAt = Carbon::parse($serviceDate.' '.($technician->day_end_time ?: '17:00'));

        if ($standardEndsAt->lte($standardStartsAt)) {
            $standardEndsAt->addDay();
        }

        return $this->minutesOutsideWindow($workStartsAt, $workEndsAt, $standardStartsAt, $standardEndsAt);
    }

    private function minutesOutsideWindow(Carbon $workStartsAt, Carbon $workEndsAt, Carbon $standardStartsAt, Carbon $standardEndsAt): int
    {
        if ($workEndsAt->lte($workStartsAt)) {
            return 0;
        }

        $minutes = 0;

        if ($workStartsAt->lt($standardStartsAt)) {
            $beforeEndsAt = $workEndsAt->lt($standardStartsAt) ? $workEndsAt : $standardStartsAt;
            $minutes += $this->positiveDiffInMinutes($workStartsAt, $beforeEndsAt);
        }

        if ($workEndsAt->gt($standardEndsAt)) {
            $afterStartsAt = $workStartsAt->gt($standardEndsAt) ? $workStartsAt : $standardEndsAt;
            $minutes += $this->positiveDiffInMinutes($afterStartsAt, $workEndsAt);
        }

        return $minutes;
    }

    private function positiveDiffInMinutes(Carbon $startsAt, Carbon $endsAt): int
    {
        return max(0, (int) round($startsAt->diffInMinutes($endsAt, false)));
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
