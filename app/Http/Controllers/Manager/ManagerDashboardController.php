<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\TechnicianDailyRouteMetric;
use App\Services\TechnicianDailyRouteMetricService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    public function __invoke(Request $request, TechnicianDailyRouteMetricService $routeMetrics): View
    {
        abort_unless($this->canAccess($request), 403);

        $now = now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        $routeMetrics->ensureForPeriod($weekStart, $weekEnd);

        $metrics = TechnicianDailyRouteMetric::query()
            ->with('technician:id,first_name,last_name,department_code')
            ->whereBetween('service_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();

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
        $averageKmPerAppointment = $scheduledThisWeek > 0 ? round($totalKm / $scheduledThisWeek, 1) : 0;

        return view('manager.dashboard', [
            'stats' => [
                [
                    'label' => 'RDV places cette semaine',
                    'value' => $appointmentsThisWeek,
                    'detail' => $this->formatDelta($appointmentsThisWeek, $appointmentsLastWeek).' vs semaine derniere',
                    'tone' => 'blue',
                ],
                [
                    'label' => 'RDV planifies',
                    'value' => $scheduledThisWeek,
                    'detail' => "{$cancelledThisWeek} annulation(s) cette semaine",
                    'tone' => 'green',
                ],
                [
                    'label' => 'Km terrain estimes',
                    'value' => "{$totalKm} km",
                    'detail' => "{$averageKmPerAppointment} km / RDV",
                    'tone' => 'gold',
                ],
                [
                    'label' => 'Temps de route',
                    'value' => "{$totalDriveHours}h",
                    'detail' => $metrics->where('calculation_source', 'mapbox')->count().' jour(s) calcules Mapbox',
                    'tone' => 'pink',
                ],
            ],
            'plannerEfficiency' => $this->plannerEfficiency($weekStart, $weekEnd),
            'technicianEfficiency' => $this->technicianEfficiency($metrics),
            'charts' => [
                'plannerPlacements' => $this->plannerEfficiency($weekStart, $weekEnd)->map(fn (array $item): array => [
                    'label' => $item['name'],
                    'value' => $item['appointments_count'],
                ])->values(),
                'technicianKilometers' => $this->technicianEfficiency($metrics)->take(8)->map(fn (array $item): array => [
                    'label' => $item['name'],
                    'value' => $item['drive_distance_km'],
                ])->values(),
                'dailyKilometers' => $this->dailyKilometers($metrics, $weekStart),
            ],
        ]);
    }

    private function plannerEfficiency(Carbon $weekStart, Carbon $weekEnd)
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

    private function technicianEfficiency($metrics)
    {
        return $metrics
            ->groupBy('technician_id')
            ->map(function ($technicianMetrics): array {
                $technician = $technicianMetrics->first()->technician;
                $appointmentCount = (int) $technicianMetrics->sum('appointment_count');
                $distanceKm = round((float) $technicianMetrics->sum('drive_distance_km'), 1);

                return [
                    'name' => trim(($technician?->last_name ?? '').' '.($technician?->first_name ?? '')),
                    'department_code' => $technician?->department_code ?: '--',
                    'appointment_count' => $appointmentCount,
                    'drive_distance_km' => $distanceKm,
                    'drive_duration_hours' => round(((int) $technicianMetrics->sum('drive_duration_minutes')) / 60, 1),
                    'km_per_appointment' => $appointmentCount > 0 ? round($distanceKm / $appointmentCount, 1) : 0,
                    'mapbox_days' => $technicianMetrics->where('calculation_source', 'mapbox')->count(),
                ];
            })
            ->sortByDesc('drive_distance_km')
            ->values();
    }

    private function dailyKilometers($metrics, Carbon $weekStart)
    {
        return collect(range(0, 4))
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

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
