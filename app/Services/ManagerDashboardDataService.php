<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\TechnicianDailyRouteMetric;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManagerDashboardDataService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Carbon $weekStart, Carbon $weekEnd): array
    {
        $metrics = TechnicianDailyRouteMetric::query()
            ->with([
                'technician:id,first_name,last_name,department_code,role',
                'technician.departments:code',
            ])
            ->whereBetween('service_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();

        $plannerEfficiency = $this->plannerEfficiency($weekStart, $weekEnd);
        $technicianEfficiency = $this->technicianEfficiency($metrics);

        $appointmentsThisWeek = Appointment::query()
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->count();
        $appointmentsLastWeek = Appointment::query()
            ->whereBetween('created_at', [$weekStart->copy()->subWeek(), $weekEnd->copy()->subWeek()])
            ->count();
        $scheduledThisWeek = Appointment::query()
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->count();
        $cancelledThisWeek = Appointment::onlyTrashed()
            ->whereBetween('deleted_at', [$weekStart, $weekEnd])
            ->count();
        $totalKm = round((float) $metrics->sum('drive_distance_km'), 1);
        $totalDriveHours = round(((int) $metrics->sum('drive_duration_minutes')) / 60, 1);
        $totalOvertimeHours = round(((int) $metrics->sum('overtime_minutes')) / 60, 1);
        $averageKmPerAppointment = $scheduledThisWeek > 0 ? round($totalKm / $scheduledThisWeek, 1) : 0;

        return [
            'generated_at' => now()->toIso8601String(),
            'period' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
            ],
            'stats' => [
                [
                    'label' => 'RDV placés cette semaine',
                    'value' => $appointmentsThisWeek,
                    'detail' => $this->formatDelta($appointmentsThisWeek, $appointmentsLastWeek).' vs semaine dernière',
                    'tone' => 'blue',
                ],
                [
                    'label' => 'RDV planifiés',
                    'value' => $scheduledThisWeek,
                    'detail' => "{$cancelledThisWeek} annulation(s) cette semaine",
                    'tone' => 'green',
                ],
                [
                    'label' => 'Km terrain estimés',
                    'value' => "{$totalKm} km",
                    'detail' => "{$averageKmPerAppointment} km / RDV",
                    'tone' => 'gold',
                ],
                [
                    'label' => 'Temps de route',
                    'value' => "{$totalDriveHours}h",
                    'detail' => $metrics->where('calculation_source', 'mapbox')->count().' jour(s) calculés Mapbox',
                    'tone' => 'pink',
                ],
                [
                    'label' => 'Heures supp terrain',
                    'value' => "{$totalOvertimeHours}h",
                    'detail' => 'Temps hors horaires, trajets inclus',
                    'tone' => 'orange',
                ],
            ],
            'plannerEfficiency' => $plannerEfficiency->values()->all(),
            'technicianEfficiency' => $technicianEfficiency->values()->all(),
            'charts' => [
                'plannerPlacements' => $plannerEfficiency->map(fn (array $item): array => [
                    'label' => $item['name'],
                    'value' => $item['appointments_count'],
                ])->values()->all(),
                'technicianKilometers' => $technicianEfficiency->take(8)->map(fn (array $item): array => [
                    'label' => $item['name'],
                    'value' => $item['drive_distance_km'],
                ])->values()->all(),
                'dailyKilometers' => $this->dailyKilometers($metrics, $weekStart)->all(),
            ],
        ];
    }

    /**
     * @return Collection<int, array{name:string,appointments_count:int,planned_hours:float}>
     */
    private function plannerEfficiency(Carbon $weekStart, Carbon $weekEnd): Collection
    {
        return Appointment::query()
            ->join('users', 'appointments.created_by', '=', 'users.id')
            ->select(
                'users.id',
                'users.first_name',
                'users.last_name',
                DB::raw('COUNT(*) as appointments_count'),
                DB::raw('SUM(appointments.duration_minutes) as planned_minutes'),
            )
            ->whereBetween('appointments.created_at', [$weekStart, $weekEnd])
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('appointments_count')
            ->get()
            ->map(fn ($row): array => [
                'name' => trim($row->first_name.' '.$row->last_name),
                'appointments_count' => (int) $row->appointments_count,
                'planned_hours' => round(((int) $row->planned_minutes) / 60, 1),
            ]);
    }

    /**
     * @param Collection<int, TechnicianDailyRouteMetric> $metrics
     * @return Collection<int, array<string, mixed>>
     */
    private function technicianEfficiency(Collection $metrics): Collection
    {
        return $metrics
            ->groupBy('technician_id')
            ->map(function (Collection $technicianMetrics): array {
                $technician = $technicianMetrics->first()->technician;
                $appointmentCount = (int) $technicianMetrics->sum('appointment_count');
                $distanceKm = round((float) $technicianMetrics->sum('drive_distance_km'), 1);

                return [
                    'name' => $technician?->full_name_with_departments ?? 'Technicien (--)',
                    'appointment_count' => $appointmentCount,
                    'drive_distance_km' => $distanceKm,
                    'drive_duration_hours' => round(((int) $technicianMetrics->sum('drive_duration_minutes')) / 60, 1),
                    'overtime_hours' => round(((int) $technicianMetrics->sum('overtime_minutes')) / 60, 1),
                    'km_per_appointment' => $appointmentCount > 0 ? round($distanceKm / $appointmentCount, 1) : 0,
                    'mapbox_days' => $technicianMetrics->where('calculation_source', 'mapbox')->count(),
                ];
            })
            ->sortByDesc('drive_distance_km')
            ->values();
    }

    /**
     * @param Collection<int, TechnicianDailyRouteMetric> $metrics
     * @return Collection<int, array{label:string,value:float}>
     */
    private function dailyKilometers(Collection $metrics, Carbon $weekStart): Collection
    {
        return collect(range(0, 5))
            ->map(function (int $dayOffset) use ($metrics, $weekStart): array {
                $date = $weekStart->copy()->addDays($dayOffset);

                return [
                    'label' => ucfirst($date->locale('fr')->isoFormat('dd D')),
                    'value' => round((float) $metrics
                        ->filter(fn (TechnicianDailyRouteMetric $metric): bool => $metric->service_date->toDateString() === $date->toDateString())
                        ->sum('drive_distance_km'), 1),
                ];
            })
            ->values();
    }

    private function formatDelta(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current > 0 ? '+100%' : 'stable';
        }

        $delta = round((($current - $previous) / $previous) * 100);

        return ($delta > 0 ? '+' : '').$delta.'%';
    }
}
