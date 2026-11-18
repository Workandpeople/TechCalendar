<?php

declare(strict_types=1);

use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
$stage = 'invocation';
try {
    if (PHP_SAPI !== 'cli' || posix_geteuid() !== (posix_getpwnam('cofrac2-app')['uid'] ?? null) || $argc !== 2) {
        throw new RuntimeException;
    }
    $expected = json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR);
    chdir('/var/www/cofrac2');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'configuration';
    $pilot = app(MymarPilotSettings::class)->load();
    if (! app()->environment('production') || config('app.debug') || ! config('crm.v2_enabled')
        || DB::connection()->getDatabaseName() !== 'cofrac2production'
        || $pilot->technicianMap !== array_column($expected['remaining'], 'cofrac_user_id', 'source_technician_id')
        || app(MymarActionSettings::class)->load() !== $expected['client']) {
        throw new RuntimeException;
    }
    $stage = 'cache_sync';
    $sync = ($expected['refresh'] ?? true) ? app(MymarObservationSync::class)->run() : ['status' => 'busy'];
    if (! in_array($sync['status'] ?? '', ['observing', 'busy'], true)) {
        throw new RuntimeException;
    }
    $stage = 'cache_validation';
    $cache = Cache::get($pilot->cacheKey());
    if (! is_array($cache)) {
        throw new RuntimeException;
    }
    MymarCachedObservation::validate($cache, $pilot);
    $age = time() - MymarCachedObservation::instant($cache['generated_at'])->getTimestamp();
    if ($age < -60 || $age > 300 || Cache::has($pilot->cacheKey().':failure')) {
        throw new RuntimeException;
    }
    echo json_encode(['ready' => true, 'mapped_technicians' => count($pilot->technicianMap), 'periods' => count($cache['periods']),
        'generated_at' => $cache['generated_at'], 'cache_ready' => true, 'get_only' => true,
        'messages_sent' => false, 'business_writes' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['ready' => false, 'stage' => $stage, 'error_class' => $error::class,
        'messages_sent' => false, 'business_writes' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
