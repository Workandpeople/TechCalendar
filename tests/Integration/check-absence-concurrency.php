<?php

// ABSENCE_TEST_MYSQL_PORT=<disposable container port> php tests/Integration/check-absence-concurrency.php
declare(strict_types=1);

use App\Http\Controllers\Manager\ManagerUserController;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\AppointmentDateTime;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\TechnicianAbsenceBlocking;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$port = filter_var(getenv('ABSENCE_TEST_MYSQL_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
if (! $port || $port === 3306 || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    throw new RuntimeException('Explicit disposable MySQL port (not 3306), pcntl and posix required.');
}
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing to boot with cached application configuration.');
}
// Boot without ever connecting to the application's configured database.
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'null', 'BROADCAST_CONNECTION' => 'null', 'SIMULATION_MODE' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Refusing to boot with cached application configuration.');
}
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Uncached testing environment required.');
}
config([
    'database.default' => 'absence_test',
    'database.connections.absence_test' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
        'database' => 'testing_absence_concurrency', 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
    ],
    'crm.v2_enabled' => true, 'simulation.enabled' => false, 'services.smsfactor.enabled' => false,
]);
Http::preventStrayRequests();
Mail::fake();
Queue::fake();
if ((int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
    throw new RuntimeException('Only an EMPTY disposable testing_absence_concurrency database is accepted.');
}
Artisan::call('migrate', ['--force' => true]);

function runAbsenceOperation(string $operation, User $actor, array $technicianIds, int $serviceId): string
{
    if ($operation === 'bulk') {
        app(TechnicianAbsenceBlocking::class)->block($actor, [
            'technician_ids' => array_reverse($technicianIds), 'date' => '2026-11-02', 'period' => 'day',
        ]);

        return 'created';
    }
    if ($operation === 'legacy') {
        $session = app('session')->driver();
        $session->start();
        $session->flush();
        $request = Request::create('/manager/users/'.$technicianIds[0].'/absences', 'POST', [
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02',
        ]);
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession($session);
        app(ManagerUserController::class)->storeAbsence($request, $technicianIds[0]);

        return $session->get('errors')?->has('absence') ? 'conflict' : 'created';
    }

    return DB::transaction(function () use ($actor, $technicianIds, $serviceId): string {
        // Exact row-lock/check sequence used by the v2 booking controller.
        $technician = User::query()->where('role', 2)->where('admin', false)->lockForUpdate()->findOrFail($technicianIds[0]);
        $start = Carbon::parse('2026-11-02 09:00:00', AppointmentDateTime::TIMEZONE);
        $end = $start->copy()->addHour();
        app(AppointmentSlotAvailability::class)->assertAvailable($technician, $start, $end, []);
        Appointment::create([
            'service_id' => $serviceId, 'technician_id' => $technician->id, 'created_by' => $actor->id,
            'customer_first_name' => 'Synthetic', 'customer_last_name' => 'TEST', 'customer_phone' => '0600000000',
            'address' => 'Synthetic concurrency test', 'starts_at' => $start, 'ends_at' => $end,
            'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED,
        ]);

        return 'created';
    });
}

$scenarios = [
    ['legacy', 'legacy', 'conflict', 1, 0],
    ['bulk', 'legacy', 'conflict', 1, 0],
    ['legacy', 'bulk', 'conflict', 1, 0],
    ['bulk', 'bulk', 'conflict', 1, 0],
    ['legacy', 'booking', 'conflict', 1, 0],
    // Legacy intentionally still permits an absence over an existing appointment.
    ['booking', 'legacy', 'created', 1, 1],
    ['disable_technician', 'legacy', 'not_found', 0, 0],
    ['revoke_manager', 'legacy', 'forbidden', 0, 0],
];
foreach ($scenarios as [$holder, $contender, $expected, $absenceCount, $appointmentCount]) {
    $actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $otherTechnician = User::factory()->create(['role' => 2, 'admin' => false]);
    $service = Service::firstOrCreate(['type' => 'COFFRAC', 'name' => 'Synthetic concurrency test'], ['average_duration_minutes' => 60]);
    $ids = [$technician->id, $otherTechnician->id];
    // Never inherit a live database socket across fork.
    DB::purge('absence_test');
    [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Fork failed.');
    }
    if ($pid === 0) {
        fclose($parentSocket);
        pcntl_alarm(15);
        try {
            fread($childSocket, 1);
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            $announced = false;
            DB::connection()->beforeExecuting(function ($sql) use ($childSocket, &$announced): void {
                if (! $announced && str_contains(strtolower($sql), 'for update') && str_contains($sql, '`users`')) {
                    $announced = true;
                    fwrite($childSocket, "locking\n");
                }
            });
            $result = runAbsenceOperation($contender, $actor, $contender === 'bulk' ? $ids : [$ids[0]], $service->id);
        } catch (ValidationException) {
            $result = 'conflict';
        } catch (ModelNotFoundException) {
            $result = 'not_found';
        } catch (AuthorizationException) {
            $result = 'forbidden';
        } catch (HttpException $exception) {
            $result = $exception->getStatusCode() === 403 ? 'forbidden' : 'unexpected_http_'.$exception->getStatusCode();
        } catch (Throwable $exception) {
            $result = get_class($exception).': '.$exception->getMessage();
        }
        fwrite($childSocket, $result."\n");
        fclose($childSocket);
        DB::disconnect('absence_test');
        exit(0);
    }
    fclose($childSocket);
    stream_set_timeout($parentSocket, 15);
    $reaped = false;
    try {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
        DB::beginTransaction();
        User::query()->where('role', 2)->where('admin', false)->lockForUpdate()->findOrFail($ids[0]);
        fwrite($parentSocket, '1');
        if (trim((string) fgets($parentSocket)) !== 'locking') {
            throw new RuntimeException($holder.'/'.$contender.': contender did not reach the technician lock.');
        }
        $read = [$parentSocket];
        $write = $except = [];
        if (stream_select($read, $write, $except, 0, 250000) !== 0) {
            throw new RuntimeException($holder.'/'.$contender.': contender finished before the technician lock was released.');
        }
        match ($holder) {
            'disable_technician' => $technician->delete(),
            'revoke_manager' => User::whereKey($actor->id)->update(['role' => 1]),
            default => runAbsenceOperation($holder, $actor, [$ids[0]], $service->id),
        };
        DB::commit();
        $actual = trim((string) fgets($parentSocket));
        pcntl_waitpid($pid, $status);
        $reaped = true;
        if ($actual !== $expected || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException($holder.'/'.$contender.': expected '.$expected.', received '.$actual);
        }
        if (TechnicianAbsence::whereIn('technician_id', $ids)->count() !== $absenceCount
            || Appointment::whereIn('technician_id', $ids)->count() !== $appointmentCount) {
            throw new RuntimeException($holder.'/'.$contender.': unexpected writes or partial batch.');
        }
        echo 'PASS '.$holder.' -> '.$contender.': waited, then '.$actual.PHP_EOL;
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (! $reaped) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        fclose($parentSocket);
    }
}
Http::assertNothingSent();
Mail::assertNothingOutgoing();
Queue::assertNothingPushed();
echo "All 8 isolated MySQL absence locking scenarios passed.\n";
