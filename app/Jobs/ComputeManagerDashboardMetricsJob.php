<?php

namespace App\Jobs;

use App\Models\DashboardMetricRun;
use App\Services\ManagerDashboardDataService;
use App\Services\TechnicianDailyRouteMetricService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ComputeManagerDashboardMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public function __construct(private readonly int $runId)
    {
    }

    public function handle(
        TechnicianDailyRouteMetricService $routeMetrics,
        ManagerDashboardDataService $dashboardData,
    ): void {
        $run = DashboardMetricRun::query()->findOrFail($this->runId);

        $run->update([
            'status' => 'running',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            $routeMetrics->ensureForPeriodWithProgress(
                $run->period_start->copy()->startOfDay(),
                $run->period_end->copy()->endOfDay(),
                function (int $processed, int $total) use ($run): void {
                    $run->forceFill([
                        'processed_steps' => $processed,
                        'total_steps' => $total,
                    ])->save();
                },
            );

            $result = $dashboardData->payload(
                $run->period_start->copy()->startOfWeek(),
                $run->period_end->copy()->endOfWeek(),
            );

            $run->update([
                'status' => 'completed',
                'processed_steps' => max($run->processed_steps, $run->total_steps),
                'result' => $result,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }
}
