<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\SystemHealthMonitor;
use App\Services\TechnicianDailyRouteMetricService;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('route-metrics:compute {--from=} {--to=}', function (TechnicianDailyRouteMetricService $routeMetrics): int {
    $from = $this->option('from')
        ? Carbon::parse($this->option('from'))->startOfDay()
        : now()->startOfWeek();
    $to = $this->option('to')
        ? Carbon::parse($this->option('to'))->endOfDay()
        : now()->endOfWeek();

    $metrics = $routeMetrics->ensureForPeriod($from, $to);

    $this->info(sprintf(
        '%d metrique(s) de route disponibles du %s au %s.',
        $metrics->count(),
        $from->toDateString(),
        $to->toDateString(),
    ));

    return 0;
})->purpose('Calcule et met en cache les kilometres et heures supp journalieres des techniciens.');

Artisan::command('health:check', function (SystemHealthMonitor $healthMonitor): int {
    $snapshot = $healthMonitor->run();

    $this->info(sprintf(
        'Health check #%d: %s (%d/100).',
        $snapshot->id,
        strtoupper($snapshot->overall_status),
        $snapshot->score,
    ));

    return $snapshot->overall_status === 'fail' ? 1 : 0;
})->purpose('Execute les checks de sante applicative et persiste un snapshot.');

Schedule::command('health:check')
    ->everyFiveMinutes();

Schedule::command('route-metrics:compute')
    ->hourly();
