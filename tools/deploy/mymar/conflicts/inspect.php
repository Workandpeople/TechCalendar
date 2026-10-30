<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\User;
use App\Services\Crm\AppointmentDateTime;
use App\Services\Mymar\MymarOccupancySnapshot;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

umask(0077);
ini_set('display_errors', '0');
$phase = 'preflight';
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid')
        || explode('.', gethostname())[0] !== 'ns365601' || (posix_getpwnam('cofrac2-app')['uid'] ?? -1) !== posix_geteuid()) {
        throw new RuntimeException('account');
    }
    $request = json_decode(file_get_contents(__DIR__.'/REQUEST.json'), true, 32, JSON_THROW_ON_ERROR);
    chdir('/var/www/cofrac2');
    foreach ($request['application_files'] as $path => $sha) {
        if (! preg_match('~^(?:app/Services/(?:Mymar|Crm)|app/Models)/[A-Za-z]+\.php$~D', $path)
            || realpath($path) !== '/var/www/cofrac2/'.$path || ! hash_equals($sha, hash_file('sha256', $path))) {
            throw new RuntimeException('contract');
        }
    }
    $phase = 'bootstrap';
    require 'vendor/autoload.php';
    // Do not boot Laravel providers: ApplicationSettings::applyToConfig() may write its cache.
    $configuration = require 'bootstrap/cache/config.php';
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository($configuration));
    $database = new DatabaseManager($app, new ConnectionFactory($app));
    $app->instance('db', $database);
    Facade::setFacadeApplication($app);
    Model::setConnectionResolver($database);
    Model::unsetEventDispatcher();
    date_default_timezone_set(AppointmentDateTime::TIMEZONE);
    require __DIR__.'/MymarOccupancySnapshot.php';
    if (config('app.env') !== 'production' || config('app.timezone') !== AppointmentDateTime::TIMEZONE
        || ! config('crm.v2_enabled') || config('app.debug')
        || config('cache.stores.'.config('cache.default').'.driver') !== 'database') {
        throw new RuntimeException('configuration');
    }
    $connection = DB::connection();
    $cacheConnection = DB::connection(config('cache.stores.'.config('cache.default').'.connection'));
    if ($connection !== $cacheConnection || $connection->getDriverName() !== 'mysql'
        || $connection->getDatabaseName() !== 'cofrac2production'
        || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('database');
    }
    // All audit reads, including the raw cache, share one explicitly READ ONLY bounded transaction.
    $connection->statement('SET SESSION max_execution_time=1500');
    $connection->statement('SET SESSION lock_wait_timeout=2');
    $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->statement('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    try {
        $phase = 'cache';
        $pilot = app(MymarPilotSettings::class)->load(); // Never loads the API token.
        $key = $pilot->cacheKey();
        $prefix = config('cache.prefix');
        $table = config('cache.stores.'.config('cache.default').'.table');
        if (! is_string($prefix) || strlen($prefix) > 128 || ! is_string($table) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new RuntimeException('cache_configuration');
        }
        $now = new DateTimeImmutable;
        // Cache::get() would delete expired rows; read and decode only these two exact keys instead.
        $values = $connection->table($table)->whereIn('key', [$prefix.$key, $prefix.$key.':failure'])
            ->where('expiration', '>', $now->getTimestamp())->get(['key', 'value']);
        $cached = $values->firstWhere('key', $prefix.$key)?->value;
        if ($cached !== null && (! is_string($cached) || strlen($cached) > 2097152)) {
            throw new RuntimeException('oversized_cache');
        }
        $data = $cached === null ? null : unserialize($cached, ['allowed_classes' => false, 'max_depth' => 32]);
        if ($data !== null && ! is_array($data)) {
            throw new RuntimeException('cache_format');
        }
        $snapshot = MymarOccupancySnapshot::fromCache($pilot, $data, $values->contains('key', $prefix.$key.':failure'), $now);
        $summary = $snapshot->summary();
        $targets = array_values($pilot->technicianMap);
        if (! $summary['cache_ready'] || $targets === []
            || User::query()->internal()->where('role', 2)->where('admin', false)->whereIn('id', $targets)->count() !== count($targets)) {
            throw new RuntimeException('cache_or_mapping_not_ready');
        }
        $phase = 'overlaps';
        $from = AppointmentDateTime::parse($summary['window_start']);
        $to = AppointmentDateTime::parse($summary['window_end']);
        $appointments = Appointment::query()->onPlanning()->whereIn('technician_id', $targets)
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->orderBy('id')->limit(5001)
            ->get(['id', 'technician_id', 'dossier_operation_id', 'starts_at', 'ends_at']);
        if ($appointments->count() > 5000) {
            throw new RuntimeException('native_window_too_large');
        }
        $conflicts = [];
        $unknown = 0;
        foreach ($appointments as $appointment) {
            $checked = $snapshot->inspect((int) $appointment->technician_id, $appointment->starts_at, $appointment->ends_at);
            if (! $checked['known']) {
                $unknown++;
            }
            foreach ($checked['overlaps'] as $overlap) {
                $conflicts[] = ['cofrac_appointment_id' => $appointment->id, 'cofrac_operation_id' => $appointment->dossier_operation_id,
                    'cofrac_user_id' => (int) $appointment->technician_id, 'cofrac_starts_at' => $appointment->starts_at->toIso8601String(),
                    'cofrac_ends_at' => $appointment->ends_at->toIso8601String(), ...$overlap];
            }
            if (count($conflicts) > 10000) {
                throw new RuntimeException('conflict_limit_exceeded');
            }
        }
        echo json_encode(['format' => 'cofrac.mymar-conflict-preview', 'version' => 1, 'complete' => true,
            'checked_at_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'runtime_revision' => $request['runtime_revision'],
            'dry_run' => true, 'cache' => $summary, 'native_appointments_checked' => $appointments->count(),
            'native_appointments_without_full_coverage' => $unknown, 'overlap_pairs' => count($conflicts),
            'conflicting_native_appointments' => count(array_unique(array_column($conflicts, 'cofrac_appointment_id'))),
            'conflicts' => array_slice($conflicts, 0, 30), 'conflict_details_truncated' => count($conflicts) > 30,
            'read_only_transaction' => (bool) $connection->selectOne('SELECT @@transaction_read_only AS enabled')->enabled,
            'native_database_writes' => false, 'source_database_read' => false, 'http_requests' => false,
            'blocking_enabled' => false, 'services_changed' => false, 'accounts_changed' => false,
            'application_bootstrapped' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $connection->rollBack();
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'STOP : verification a blanc non confirmee ; phase='.$phase.' ; type='.get_class($exception).'. Aucun blocage active.'.PHP_EOL);
    exit(1);
}
