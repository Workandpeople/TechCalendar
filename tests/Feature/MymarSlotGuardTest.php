<?php

use App\Exceptions\AppointmentPlacementConflict;
use App\Models\MymarBookingRequest;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarPilotSettings;
use App\Services\Mymar\MymarSlotGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->configuration = ['guard_enabled' => true, 'bindings' => [['source_technician_id' => 44, 'cofrac_user_id' => $this->tech->id]]];
    $settings = Mockery::mock(MymarActionSettings::class);
    $settings->shouldReceive('load')->andReturnUsing(fn () => $this->configuration);
    app()->instance(MymarActionSettings::class, $settings);
    $pilot = Mockery::mock(MymarPilotSettings::class);
    $pilot->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    app()->instance(MymarPilotSettings::class, $pilot);
    $this->from = now('Europe/Paris')->addDay()->setTime(10, 0);
    $this->to = $this->from->copy()->addHour();
    Http::preventStrayRequests();
});

it('checks fresh source availability with explicit Paris and hour interpretation', function (string $case) {
    Http::fake(function ($request) use ($case) {
        $query = $request->data();
        expect($request->method())->toBe('GET')->and($query['technician_ids'])->toBe('44');
        $body = ['format' => MymarObservation::FORMAT, 'version' => 1, 'complete' => true, 'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours',
            'generated_at' => gmdate(DATE_ATOM), 'window_start' => $query['window_start'], 'window_end' => $query['window_end'],
            'technician_ids' => [44], 'status_ids' => [3, 16], 'appointments' => $case === 'empty' ? [] : [[
                'rdv_id' => 99, 'dossier_id' => 123, 'technician_id' => 44, 'status_id' => 16, 'is_old' => 0,
                'stored_start' => $this->from->format('Y-m-d H:i:s'), 'raw_duration' => $case === 'invalid' ? null : 1]]];

        return Http::response($body);
    });
    $conflicts = app(MymarSlotGuard::class)->liveConflicts($this->tech->id, $this->from, $this->to);
    expect(array_column($conflicts, 'type'))->toBe(match ($case) {
        'empty' => [], 'overlap' => ['mymar_overlap'], default => ['mymar_unknown']
    });
})->with(['empty', 'overlap', 'invalid']);

it('fails closed on unavailable source without exposing secrets', function () {
    Http::fake(fn () => throw new RuntimeException('PRIVATE_TOKEN_CANARY'));
    $result = app(MymarSlotGuard::class)->liveConflicts($this->tech->id, $this->from, $this->to);
    expect($result[0]['type'])->toBe('mymar_unknown')->and(json_encode($result))->not->toContain('PRIVATE_TOKEN_CANARY');
});

it('does not read source or a missing journal when the guard is absent or the technician is not mapped', function () {
    expect(app(MymarSlotGuard::class)->liveConflicts($this->tech->id + 999, $this->from, $this->to))->toBe([]);
    $this->configuration = null;
    expect(app(MymarSlotGuard::class)->pendingConflicts($this->tech->id, $this->from, $this->to))->toBe([])
        ->and(app(MymarSlotGuard::class)->liveConflicts($this->tech->id, $this->from, $this->to))->toBe([]);
    Http::assertNothingSent();
});

it('keeps uncertain reservations unforceable and ignores only the command currently being dispatched', function () {
    if (! Schema::hasTable('mymar_booking_requests')) {
        (require base_path('tools/deploy/mymar/booking/inactive-migrations/2026_10_31_110000_create_mymar_booking_requests_table.php'))->up();
    }
    $record = MymarBookingRequest::forceCreate(['id' => (string) Str::uuid(), 'author_id' => $this->tech->id,
        'source_dossier_id' => 12, 'active_dossier_id' => 12, 'cofrac_technician_id' => $this->tech->id,
        'reserved_starts_at' => $this->from, 'reserved_ends_at' => $this->to, 'status' => 'uncertain', 'fingerprint' => str_repeat('a', 64), 'command' => ['action' => 'place']]);
    $guard = app(MymarSlotGuard::class);
    expect($guard->pendingConflicts($this->tech->id, $this->from, $this->to))->toHaveCount(1)
        ->and($guard->pendingConflicts($this->tech->id, $this->from, $this->to, $record->id))->toBe([]);
    Http::fake(fn () => throw new RuntimeException('unavailable'));
    try {
        app(AppointmentSlotAvailability::class)->assertAvailable($this->tech, $this->from, $this->to, [], forced: true);
        test()->fail('An uncertain MAR reservation was overridden.');
    } catch (AppointmentPlacementConflict $error) {
        expect($error->canForce)->toBeFalse();
    }
});
