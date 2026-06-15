<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\SimulatedCrmAppointmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlannerDashboardController extends Controller
{
    public function __invoke(Request $request, SimulatedCrmAppointmentService $crmAppointments): View
    {
        abort_unless($this->canAccess($request), 403);

        $now = now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();
        $lastWeekStart = $weekStart->copy()->subWeek();
        $lastWeekEnd = $weekEnd->copy()->subWeek();

        $placedThisWeek = Appointment::query()
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->count();
        $placedLastWeek = Appointment::query()
            ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])
            ->count();
        $placedToday = Appointment::query()
            ->whereBetween('created_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->count();
        $placedByMeThisWeek = Appointment::query()
            ->where('created_by', $request->user()->id)
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->count();
        $scheduledHoursThisWeek = round((int) Appointment::query()
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->sum('duration_minutes') / 60, 1);
        $softDeletedThisWeek = Appointment::onlyTrashed()
            ->whereBetween('deleted_at', [$weekStart, $weekEnd])
            ->count();
        $upcomingNext7Days = Appointment::query()
            ->whereBetween('starts_at', [$now, $now->copy()->addDays(7)])
            ->count();
        $activeTechniciansThisWeek = Appointment::query()
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->distinct('technician_id')
            ->count('technician_id');

        return view('planner.dashboard', [
            'stats' => [
                [
                    'label' => 'RDV placés cette semaine',
                    'value' => $placedThisWeek,
                    'detail' => $this->formatDelta($placedThisWeek, $placedLastWeek).' vs semaine dernière',
                    'tone' => 'blue',
                ],
                [
                    'label' => 'Places aujourd hui',
                    'value' => $placedToday,
                    'detail' => "{$placedByMeThisWeek} créés par toi cette semaine",
                    'tone' => 'green',
                ],
                [
                    'label' => 'Charge planifiée',
                    'value' => "{$scheduledHoursThisWeek}h",
                    'detail' => "{$activeTechniciansThisWeek} techniciens actifs cette semaine",
                    'tone' => 'gold',
                ],
                [
                    'label' => 'RDV à venir J+7',
                    'value' => $upcomingNext7Days,
                    'detail' => "{$softDeletedThisWeek} annulation(s) cette semaine",
                    'tone' => 'pink',
                ],
            ],
            'charts' => [
                'weeklyTrend' => $this->weeklyTrend($now),
                'serviceTypes' => $this->serviceTypeDistribution($weekStart, $weekEnd),
                'plannerEfficiency' => $this->plannerEfficiency($weekStart, $weekEnd),
            ],
            'crmAppointments' => $crmAppointments->pending(15),
        ]);
    }

    private function weeklyTrend(Carbon $now): array
    {
        $start = $now->copy()->startOfWeek()->subWeeks(5);
        $rows = Appointment::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, 5))
            ->map(function (int $weekOffset) use ($start, $rows): array {
                $week = $start->copy()->addWeeks($weekOffset);
                $weekTotal = collect(range(0, 6))
                    ->sum(fn (int $dayOffset): int => (int) ($rows[$week->copy()->addDays($dayOffset)->toDateString()] ?? 0));

                return [
                    'label' => 'S'.$week->isoWeek(),
                    'value' => $weekTotal,
                ];
            })
            ->all();
    }

    private function serviceTypeDistribution(Carbon $weekStart, Carbon $weekEnd): array
    {
        return Appointment::query()
            ->join('services', 'appointments.service_id', '=', 'services.id')
            ->select('services.type', DB::raw('COUNT(*) as total'))
            ->whereBetween('appointments.starts_at', [$weekStart, $weekEnd])
            ->groupBy('services.type')
            ->orderBy('services.type')
            ->get()
            ->map(fn ($row): array => [
                'label' => $row->type,
                'value' => (int) $row->total,
            ])
            ->all();
    }

    private function plannerEfficiency(Carbon $weekStart, Carbon $weekEnd): array
    {
        return Appointment::query()
            ->join('users', 'appointments.created_by', '=', 'users.id')
            ->select('users.id', 'users.first_name', 'users.last_name', DB::raw('COUNT(*) as total'))
            ->whereBetween('appointments.created_at', [$weekStart, $weekEnd])
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'label' => trim($row->first_name.' '.$row->last_name),
                'value' => (int) $row->total,
            ])
            ->all();
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

        return (bool) $user && ($user->admin || $user->role === 1);
    }
}
