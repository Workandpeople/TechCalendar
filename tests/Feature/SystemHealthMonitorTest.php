<?php

use App\Models\SystemErrorEvent;
use App\Models\SystemHealthCheck;
use App\Models\SystemHealthSnapshot;
use App\Models\User;
use App\Services\SystemHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        ->assertSee('Sante du site')
        ->assertSee('Score sante');
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
