<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Jobs\ComputeManagerDashboardMetricsJob;
use App\Models\DashboardMetricRun;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($this->canAccess($request), 403);

        return view('manager.dashboard', [
            'dataUrl' => route('manager.dashboard.data'),
            'refreshUrl' => route('manager.dashboard.refresh'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        [$weekStart, $weekEnd] = $this->currentWeekPeriod();
        $run = $this->latestRun($weekStart, $weekEnd);

        if (! $run) {
            $run = $this->dispatchRun($weekStart, $weekEnd);
        }

        return response()->json($this->serializeRun($run->refresh()));
    }

    public function refresh(Request $request): JsonResponse
    {
        abort_unless($this->canAccess($request), 403);

        [$weekStart, $weekEnd] = $this->currentWeekPeriod();
        $run = $this->dispatchRun($weekStart, $weekEnd);

        return response()->json($this->serializeRun($run));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentWeekPeriod(): array
    {
        $now = now();

        return [
            $now->copy()->startOfWeek(),
            $now->copy()->endOfWeek(),
        ];
    }

    private function latestRun(Carbon $weekStart, Carbon $weekEnd): ?DashboardMetricRun
    {
        $activeRun = DashboardMetricRun::query()
            ->where('dashboard', 'manager')
            ->whereDate('period_start', $weekStart->toDateString())
            ->whereDate('period_end', $weekEnd->toDateString())
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        if ($activeRun) {
            return $activeRun;
        }

        return DashboardMetricRun::query()
            ->where('dashboard', 'manager')
            ->whereDate('period_start', $weekStart->toDateString())
            ->whereDate('period_end', $weekEnd->toDateString())
            ->where('status', 'completed')
            ->latest('finished_at')
            ->latest('id')
            ->first();
    }

    private function dispatchRun(Carbon $weekStart, Carbon $weekEnd): DashboardMetricRun
    {
        $run = DashboardMetricRun::query()->create([
            'dashboard' => 'manager',
            'period_start' => $weekStart->toDateString(),
            'period_end' => $weekEnd->toDateString(),
            'status' => 'pending',
            'processed_steps' => 0,
            'total_steps' => 0,
        ]);

        ComputeManagerDashboardMetricsJob::dispatch($run->id);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(DashboardMetricRun $run): array
    {
        $progress = match ($run->status) {
            'completed' => 100,
            'failed' => 100,
            default => $run->total_steps > 0
                ? min(99, (int) floor(($run->processed_steps / $run->total_steps) * 100))
                : 5,
        };

        return [
            'id' => $run->id,
            'status' => $run->status,
            'progress' => $progress,
            'processed_steps' => $run->processed_steps,
            'total_steps' => $run->total_steps,
            'error_message' => $run->error_message,
            'generated_at' => $run->finished_at?->toIso8601String(),
            'result' => $run->status === 'completed' ? $run->result : null,
        ];
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || $user->role === 0);
    }
}
