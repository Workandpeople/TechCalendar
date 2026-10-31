<?php

use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarPendingBookings;
use App\Services\Mymar\MymarPilotSettings;
use App\Services\Mymar\MymarSlotGuard;
use Cofrac2\Deploy\Mymar\ReadinessDiagnostic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fixtures\MymarPilotFixture;

require_once __DIR__.'/../../tools/deploy/mymar/actions-diagnostic/ReadinessDiagnostic.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    (require base_path('tools/deploy/mymar/booking/inactive-migrations/2026_10_31_110000_create_mymar_booking_requests_table.php'))->up();
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->config = ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => false,
        'port' => 19848, 'generation' => (string) Str::uuid(), 'bindings' => [['source_technician_id' => 44, 'cofrac_user_id' => $this->tech->id]]];
    $this->actions = Mockery::mock(MymarActionSettings::class);
    $this->actions->shouldReceive('load')->andReturnUsing(fn () => $this->config);
    $this->actions->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    $reader = Mockery::mock(MymarBookingReaderSettings::class);
    $reader->shouldReceive('load')->andReturn(['read_enabled' => true, 'port' => 19847]);
    $reader->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    $this->pilotConfig = MymarPilotFixture::pilot([44 => $this->tech->id]);
    $this->pilot = Mockery::mock(MymarPilotSettings::class);
    $this->pilot->shouldReceive('load')->andReturnUsing(fn () => $this->pilotConfig);
    $this->pilot->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    $this->conflicts = [];
    $guard = Mockery::mock(MymarSlotGuard::class);
    $guard->shouldReceive('liveConflicts')->andReturnUsing(fn () => $this->conflicts);
    $this->pending = Mockery::mock(MymarPendingBookings::class);
    $this->pending->shouldReceive('page')->with(0, 1, true)->andReturnUsing(fn () => $this->projectionBusy
        ? throw new RuntimeException('mymar.booking_reader_busy') : ['items' => []]);
    $this->projectionBusy = false;
    $this->diagnostic = new ReadinessDiagnostic($this->actions, $reader, $this->pilot, $guard, Http::getFacadeRoot(), $this->pending);
    $this->occupationResponse = function ($request) {
        $query = $request->data();

        return Http::response(['format' => MymarObservation::FORMAT, 'version' => 1, 'complete' => true, 'source_timezone' => 'Europe/Paris',
            'duration_unit' => 'hours', 'generated_at' => gmdate(DATE_ATOM), 'window_start' => $query['window_start'], 'window_end' => $query['window_end'],
            'technician_ids' => [44], 'status_ids' => [3, 16], 'appointments' => [['rdv_id' => 10, 'dossier_id' => 11, 'technician_id' => 44,
                'status_id' => 16, 'is_old' => 0, 'stored_start' => now('Europe/Paris')->format('Y-m-d H:i:s'), 'raw_duration' => null]]]);
    };
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), ':19847/')) {
            return Http::response(['format' => 'cofrac.mymar-pending-bookings', 'version' => 1, 'items' => [], 'next_after' => null]);
        }

        return ($this->occupationResponse)($request);
    });
});

it('diagnoses each prerequisite without posting or writing native business data', function () {
    DB::enableQueryLog();
    $report = $this->diagnostic->inspect();
    expect($report['readiness_passed'])->toBeTrue()->and($report['source_post_requests'])->toBeFalse()
        ->and($report['business_writes'])->toBeFalse()->and($report['activation_performed'])->toBeFalse();
    foreach (DB::getQueryLog() as $query) {
        expect($query['query'])->not->toMatch('/\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/i');
    }
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('pinpoints a rejected native technician without exporting names or emails', function () {
    $this->tech->delete();
    $report = $this->diagnostic->inspect();
    expect($report['readiness_passed'])->toBeFalse()->and($report['checks']['active_native_technicians'])->toBe(['ok' => false, 'expected' => 1, 'active' => 0]);
    expect(json_encode($report))->not->toContain($this->tech->email);
});

it('reveals source quality issue codes without exporting appointment rows', function () {
    $this->conflicts = [['type' => 'mymar_unknown']];
    $report = $this->diagnostic->inspect();
    $gateway = $report['checks']['live_availability_'.$this->tech->id]['gateway'];
    expect($report['readiness_passed'])->toBeFalse()->and($gateway['http_status'])->toBe(200)
        ->and($gateway['issue_codes'])->toBe(['invalid_duration'])
        ->and($gateway['issue_references'])->toBe([['code' => 'invalid_duration', 'source_rdv_id' => 10, 'source_dossier_id' => 11]]);
    expect(json_encode($report))->not->toContain('stored_start', 'raw_duration', 'Authorization');
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('reports safe HTTP error codes but redacts arbitrary backend content', function (string $error, string $expected) {
    $this->conflicts = [['type' => 'mymar_unknown']];
    $this->occupationResponse = fn () => Http::response(['error' => $error], 403);
    $gateway = $this->diagnostic->inspect()['checks']['live_availability_'.$this->tech->id]['gateway'];
    expect($gateway)->toBe(['http_status' => 403, 'source_error' => $expected]);
})->with([['access_denied', 'access_denied'], ['PRIVATE_TOKEN_CANARY', 'redacted']]);

it('never exports raw response bodies or exception messages', function (string $case) {
    $this->conflicts = [['type' => 'mymar_unknown']];
    $this->occupationResponse = fn () => $case === 'exception' ? throw new RuntimeException('PASSWORD_CANARY')
        : Http::response('PASSWORD_CANARY', 200, ['Content-Type' => 'text/html']);
    $report = $this->diagnostic->inspect();
    expect($report['readiness_passed'])->toBeFalse()->and(json_encode($report))->not->toContain('PASSWORD_CANARY');
})->with(['exception', 'html']);

it('shows closed guard and mismatched observation mappings without changing configuration', function () {
    $this->config['guard_enabled'] = false;
    $this->pilotConfig = MymarPilotFixture::pilot([44 => $this->tech->id + 1]);
    $report = $this->diagnostic->inspect();
    expect($report['checks']['actions_configuration']['ok'])->toBeFalse()
        ->and($report['checks']['occupation_configuration']['bindings_match'])->toBeFalse()
        ->and($this->config['writes_enabled'])->toBeFalse();
});

it('distinguishes a busy native projection from a successful source reader', function () {
    $this->projectionBusy = true;
    $report = $this->diagnostic->inspect();
    expect($report['readiness_passed'])->toBeFalse()->and($report['checks']['pending_reader']['ok'])->toBeTrue()
        ->and($report['checks']['pending_projection']['reason'])->toBe('mymar.booking_reader_busy');
});
