<?php

declare(strict_types=1);

use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Mymar\MymarPendingBookings;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
$stage = 'invocation';
try {
    if (PHP_SAPI !== 'cli' || posix_geteuid() !== (posix_getpwnam('cofrac2-app')['uid'] ?? null) || $argc !== 2) {
        throw new RuntimeException;
    }
    $expected = json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR);
    $stage = 'application_bootstrap';
    chdir('/var/www/cofrac2');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'configuration';
    $settings = app(MymarPilotSettings::class);
    $pilot = $settings->load();
    $client = app(MymarActionSettings::class)->load();
    $map = array_column($expected['expanded'], 'cofrac_user_id', 'source_technician_id');
    if (! app()->environment('production') || config('app.debug') || ! config('crm.v2_enabled')
        || DB::connection()->getDatabaseName() !== 'cofrac2production'
        || $pilot->technicianMap !== $map || $client !== $expected['current_client']) {
        throw new RuntimeException;
    }
    // GET-only synchronization; never dispatch a booking command or invoke a notification.
    $stage = 'occupation_sync';
    $result = app(MymarObservationSync::class)->run();
    if (! in_array($result['status'] ?? '', ['observing', 'busy'], true)) {
        throw new RuntimeException;
    }
    $stage = 'occupation_cache';
    $snapshot = Cache::get($pilot->cacheKey());
    if (! is_array($snapshot)) {
        throw new RuntimeException;
    }
    MymarCachedObservation::validate($snapshot, $pilot);
    $age = time() - MymarCachedObservation::instant($snapshot['generated_at'])->getTimestamp();
    if ($age < -60 || $age > 300 || Cache::has($pilot->cacheKey().':failure')) {
        throw new RuntimeException;
    }
    $stage = 'pending_reader';
    app(MymarPendingBookings::class)->page(0, 1, true);
    $stage = 'actions_reader';
    $response = app(Factory::class)->withToken(app(MymarActionSettings::class)->token())
        ->acceptJson()->withoutRedirecting()->connectTimeout(1)->timeout(5)->withOptions(['proxy' => '', 'cookies' => false])
        ->get('http://127.0.0.1:19848/v1/booking-commands/'.Str::uuid());
    if ($response->status() !== 404 || $response->json('error') !== 'booking_receipt_not_found'
        || $response->json('outcome') !== 'unknown' || $response->json('source_committed') !== null) {
        throw new RuntimeException;
    }
    echo json_encode(['ready' => true, 'mapped_technicians' => count($map), 'periods' => count($snapshot['periods']),
        'cache_ready' => true, 'get_only' => true, 'messages_sent' => false, 'post_requests' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['ready' => false, 'stage' => $stage, 'error_class' => get_class($error), 'post_requests' => false, 'messages_sent' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
