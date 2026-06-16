<?php

use App\Models\SystemErrorEvent;
use App\Models\SystemHealthCheck;
use App\Models\SystemHealthSnapshot;
use App\Models\SystemTestRun;
use App\Models\User;
use App\Jobs\RunSystemTestsJob;
use App\Services\SystemHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function fakeHealthLog(string $contents = ''): string
{
    $path = sys_get_temp_dir().'/tech-calendar-health-test-'.uniqid('', true).'.log';
    file_put_contents($path, $contents);
    config(['health.log_path' => $path]);

    return $path;
}

it('creates a persisted health snapshot with check results', function () {
    fakeHealthLog();

    $snapshot = app(SystemHealthMonitor::class)->run();

    expect($snapshot->exists)->toBeTrue()
        ->and(SystemHealthSnapshot::query()->count())->toBe(1)
        ->and(SystemHealthCheck::query()->count())->toBeGreaterThanOrEqual(7)
        ->and($snapshot->overall_status)->toBe('ok')
        ->and($snapshot->score)->toBe(100);
});

it('records laravel log errors and does not duplicate the same log line', function () {
    $date = now()->format('Y-m-d H:i:s');
    fakeHealthLog("[{$date}] local.ERROR: Something exploded {\"userId\":1}\n");

    app(SystemHealthMonitor::class)->run();
    app(SystemHealthMonitor::class)->run();

    $event = SystemErrorEvent::query()->first();

    expect(SystemErrorEvent::query()->count())->toBe(1)
        ->and($event->severity)->toBe('ERROR')
        ->and($event->occurrences)->toBe(1)
        ->and(SystemHealthSnapshot::query()->latest('id')->first()->overall_status)->toBe('fail');
});

it('marks failed jobs as a failing check', function () {
    fakeHealthLog();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Boom',
        'failed_at' => now(),
    ]);

    $snapshot = app(SystemHealthMonitor::class)->run();
    $failedJobsCheck = $snapshot->checks->firstWhere('name', 'failed_jobs');

    expect($failedJobsCheck->status)->toBe('fail')
        ->and($snapshot->overall_status)->toBe('fail');
});

it('renders the admin health dashboard for admins', function () {
    fakeHealthLog();
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'admin@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Santé du site')
        ->assertSee('Score santé');
});

it('blocks the admin health dashboard for non-admin users', function () {
    fakeHealthLog();
    $user = User::query()->create([
        'first_name' => 'Paul',
        'last_name' => 'Planning',
        'email' => 'planning@example.test',
        'password' => bcrypt('password'),
        'admin' => false,
        'role' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('clears application logs and aggregated system errors from the admin dashboard', function () {
    fakeHealthLog();
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'clear-logs@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);
    $logPath = storage_path('logs/admin-dashboard-clear-test.log');
    File::ensureDirectoryExists(dirname($logPath));
    File::put($logPath, 'local.ERROR: boom');
    SystemErrorEvent::query()->create([
        'source' => 'laravel.log',
        'severity' => 'ERROR',
        'fingerprint' => hash('sha256', 'boom'),
        'message' => 'boom',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'occurrences' => 1,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.dashboard.logs.clear'))
        ->assertRedirect();

    expect(File::get($logPath))->toBe('')
        ->and(SystemErrorEvent::query()->count())->toBe(0);
});

it('queues a system test run from the admin dashboard', function () {
    Queue::fake();
    fakeHealthLog();
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'run-tests@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.dashboard.tests.run'), [
            'suite' => SystemTestRun::SUITE_FEATURE,
        ])
        ->assertRedirect();

    $run = SystemTestRun::query()->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(SystemTestRun::STATUS_QUEUED)
        ->and($run->suite)->toBe(SystemTestRun::SUITE_FEATURE)
        ->and($run->triggered_by)->toBe($admin->id);

    Queue::assertPushed(RunSystemTestsJob::class);
});

it('does not queue parallel system test runs', function () {
    Queue::fake();
    fakeHealthLog();
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'parallel-tests@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);
    SystemTestRun::query()->create([
        'triggered_by' => $admin->id,
        'suite' => SystemTestRun::SUITE_ALL,
        'status' => SystemTestRun::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    $this->actingAs($admin)
        ->from(route('admin.dashboard'))
        ->post(route('admin.dashboard.tests.run'), [
            'suite' => SystemTestRun::SUITE_UNIT,
        ])
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHasErrors('tests');

    expect(SystemTestRun::query()->count())->toBe(1);
    Queue::assertNotPushed(RunSystemTestsJob::class);
});

it('builds dashboard test commands with isolated Pest cache paths', function () {
    $job = new RunSystemTestsJob(123);
    $runtimeDirectory = storage_path('framework/testing/system-runs/123');
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('commandForSuite');
    $method->setAccessible(true);

    $command = $method->invoke($job, SystemTestRun::SUITE_FEATURE, $runtimeDirectory);
    $serializedCommand = implode(' ', $command);

    expect($command)
        ->toContain(PHP_BINARY)
        ->toContain(base_path('vendor/bin/pest'))
        ->toContain('--compact')
        ->toContain('--colors=never')
        ->toContain('--configuration=phpunit.xml')
        ->toContain('--do-not-cache-result')
        ->toContain('--testsuite=Feature')
        ->and($serializedCommand)->toContain($runtimeDirectory.'/phpunit-cache');
});

it('forces dashboard test runs to use a safe testing environment', function () {
    $job = new RunSystemTestsJob(123);
    $runtimeDirectory = storage_path('framework/testing/system-runs/123');
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('processEnvironment');
    $method->setAccessible(true);

    $environment = $method->invoke($job, $runtimeDirectory);

    expect($environment['APP_ENV'])->toBe('testing')
        ->and($environment['APP_CONFIG_CACHE'])->toBe($runtimeDirectory.'/bootstrap-cache/config.php')
        ->and($environment['DB_CONNECTION'])->toBe('sqlite')
        ->and($environment['DB_DATABASE'])->toBe(':memory:')
        ->and($environment['CACHE_STORE'])->toBe('array')
        ->and($environment['QUEUE_CONNECTION'])->toBe('sync')
        ->and($environment['MAIL_MAILER'])->toBe('array');
});

it('documents the php extensions required by dashboard test runs', function () {
    $job = new RunSystemTestsJob(123);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('requiredPhpExtensions');
    $method->setAccessible(true);

    expect($method->invoke($job))->toContain('pdo_sqlite');
});
