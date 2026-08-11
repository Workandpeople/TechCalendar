<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\RunSystemTestsJob;
use App\Models\SystemTestRun;
use App\Services\CoffracAppointmentService;
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

Artisan::command('system-tests:schedule {--suite=all : Suite à lancer: all, feature ou unit.}', function (): int {
    $suite = (string) $this->option('suite');

    if (! in_array($suite, [
        SystemTestRun::SUITE_ALL,
        SystemTestRun::SUITE_UNIT,
        SystemTestRun::SUITE_FEATURE,
    ], true)) {
        $this->error('La suite doit être all, feature ou unit.');

        return 1;
    }

    $activeRunExists = SystemTestRun::query()
        ->whereIn('status', [SystemTestRun::STATUS_QUEUED, SystemTestRun::STATUS_RUNNING])
        ->exists();

    if ($activeRunExists) {
        $this->warn('Une exécution de tests est déjà en cours ou en attente.');

        return 0;
    }

    $run = SystemTestRun::query()->create([
        'triggered_by' => null,
        'suite' => $suite,
        'status' => SystemTestRun::STATUS_QUEUED,
    ]);

    RunSystemTestsJob::dispatch($run->id);

    $this->info(sprintf('Suite de tests #%d planifiée (%s).', $run->id, $suite));

    return 0;
})->purpose('Planifie une execution asynchrone des tests visibles dans le dashboard admin.');

Artisan::command('coffrac:sync {--incremental : Ne récupère que les changements depuis la dernière synchronisation réussie.} {--status=all : Statut Coffrac à récupérer: pending, placed, problem ou all.} {--page-size= : Nombre de dossiers récupérés par appel API Coffrac.}', function (CoffracAppointmentService $coffracAppointments): int {
    $status = (string) $this->option('status');

    if (! in_array($status, [
        CoffracAppointmentService::REMOTE_STATUS_PENDING,
        CoffracAppointmentService::REMOTE_STATUS_PLACED,
        CoffracAppointmentService::REMOTE_STATUS_PROBLEM,
        CoffracAppointmentService::REMOTE_STATUS_ALL,
    ], true)) {
        $this->error('Le statut doit être pending, placed, problem ou all.');

        return 1;
    }

    $result = $coffracAppointments->sync(
        pageSize: (int) ($this->option('page-size') ?: 0),
        incremental: (bool) $this->option('incremental'),
        status: $status,
    );

    $this->info($result['message']);

    return $result['available'] ? 0 : 1;
})->purpose('Synchronise les RDV Coffrac a placer et deja places dans la base locale.');

Schedule::command('health:check')
    ->everyFiveMinutes();

Schedule::command('health:check')
    ->dailyAt('01:57')
    ->withoutOverlapping();

Schedule::command('system-tests:schedule --suite=all')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('route-metrics:compute')
    ->hourly();

Schedule::command('coffrac:sync --incremental')
    ->everyTenMinutes()
    ->withoutOverlapping(60);

Schedule::command('coffrac:sync --status=placed')
    ->cron('5-59/10 * * * *')
    ->withoutOverlapping(60);
