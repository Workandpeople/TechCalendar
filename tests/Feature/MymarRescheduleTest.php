<?php

use App\Models\Appointment;
use App\Models\MymarBookingRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Mymar\MymarPendingBookings;
use App\Services\Mymar\MymarSlotGuard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    if (! Schema::hasTable('mymar_booking_requests')) {
        (require base_path('tools/deploy/mymar/booking/inactive-migrations/2026_10_31_110000_create_mymar_booking_requests_table.php'))->up();
    }
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['type' => 'MAR', 'name' => 'VISITE APRES TRAVAUX', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->settings = ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
        'port' => 19848, 'generation' => (string) Str::uuid(), 'bindings' => [['source_technician_id' => 1234, 'cofrac_user_id' => $this->tech->id]]];
    $settings = Mockery::mock(MymarActionSettings::class);
    $settings->shouldReceive('load')->andReturnUsing(fn () => $this->settings);
    $settings->shouldReceive('token')->andReturn(hash('sha256', 'synthetic-actions-token'));
    app()->instance(MymarActionSettings::class, $settings);
    $reader = Mockery::mock(MymarBookingReaderSettings::class);
    $reader->shouldReceive('load')->andReturn(['read_enabled' => true]);
    $reader->shouldReceive('token')->andReturn(hash('sha256', 'synthetic-reader-token'));
    app()->instance(MymarBookingReaderSettings::class, $reader);
    $pending = Mockery::mock(MymarPendingBookings::class);
    $pending->shouldReceive('invalidate')->andReturnNull();
    app()->instance(MymarPendingBookings::class, $pending);
    $sync = Mockery::mock(MymarObservationSync::class);
    $sync->shouldReceive('run')->andReturnUsing(fn () => $this->syncFails ? throw new RuntimeException('cache unavailable') : ['status' => 'observing']);
    app()->instance(MymarObservationSync::class, $sync);
    $this->syncFails = false;
    $this->conflicts = [];
    $guard = Mockery::mock(MymarSlotGuard::class);
    $guard->shouldReceive('pendingConflicts')->andReturn([]);
    $guard->shouldReceive('liveConflicts')->withArgs(fn ($tech, $start, $end, $ignore) => $tech === $this->tech->id && $ignore === 555)
        ->andReturnUsing(fn () => $this->conflicts);
    app()->instance(MymarSlotGuard::class, $guard);
    $this->source = ['format' => 'cofrac.mymar-scheduled-booking', 'version' => 1, 'dossier_id' => 123, 'rdv_id' => 555,
        'technician_id' => 1234, 'starts_at' => now('Europe/Paris')->addDay()->setTime(10, 0)->toIso8601String(),
        'duration_minutes' => 120, 'expected_revision' => str_repeat('a', 64), 'customer' => 'Client Test', 'address' => '10 rue Test, 75001 Paris'];
    $this->payload = ['request_id' => (string) Str::uuid(), 'dossier_id' => 123, 'rdv_id' => 555, 'expected_revision' => str_repeat('a', 64),
        'technician_id' => $this->tech->id, 'starts_at' => now('Europe/Paris')->addDays(2)->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 120];
    $this->receipt = fn (array $override = []) => array_replace(['format' => 'cofrac.mymar-booking-result', 'version' => 1,
        'request_id' => $this->payload['request_id'], 'action' => 'reschedule', 'dossier_id' => 123, 'status_id' => 16, 'rdv_id' => 556,
        'starts_at' => $this->payload['starts_at'], 'duration_minutes' => 120, 'source_committed' => true], $override);
    $this->reads = 0;
    $this->changeOnRead = 0;
    $this->writerStatus = 200;
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/scheduled-bookings/')) {
            $this->reads++;

            return Http::response(array_replace($this->source, $this->changeOnRead === $this->reads ? ['expected_revision' => str_repeat('b', 64)] : []), 200);
        }
        if ($request->method() === 'POST') {
            expect($request->url())->toBe('http://127.0.0.1:19848/v1/booking-commands')
                ->and($request['cofrac_actor_id'])->toBe($this->actor->id)
                ->and($request['technician_id'])->toBe(1234)->and($request['rdv_id'])->toBe(555);

            return Http::response(($this->receipt)(), $this->writerStatus);
        }

        return Http::response(($this->receipt)(), 200);
    });
});

it('loads fresh replacement data and only offers active mapped MAR-qualified technicians', function () {
    $unqualified = User::factory()->create(['role' => 2, 'admin' => false]);
    $disabled = User::factory()->create(['role' => 2, 'admin' => false, 'deleted_at' => now()]);
    $disabled->services()->attach($this->service);
    $this->settings['bindings'][] = ['source_technician_id' => 1235, 'cofrac_user_id' => $unqualified->id];
    $this->settings['bindings'][] = ['source_technician_id' => 1236, 'cofrac_user_id' => $disabled->id];
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.scheduled', 555))->assertOk()
        ->assertJsonPath('rdv_id', 555)->assertJsonPath('technician_id', $this->tech->id)
        ->assertJsonPath('duration_minutes', 120)->assertJsonCount(1, 'technicians');
    Http::assertSentCount(1);
    expect(MymarBookingRequest::count())->toBe(0);
});

it('preserves the actual MYMAR duration and confirms a new appointment without creating a native copy', function () {
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload)
        ->assertOk()->assertJsonPath('confirmed', true)->assertJsonPath('source_result.rdv_id', 556);
    expect($this->reads)->toBe(2)->and(MymarBookingRequest::first()->status)->toBe('confirmed')->and(Appointment::count())->toBe(0);
    $this->postJson(route('planner.book.mar.reschedule'), $this->payload)->assertOk()->assertJsonPath('confirmed', true);
    Http::assertSentCount(3);
});

it('rejects revisions changed before enqueue or immediately before dispatch without a POST', function (int $read) {
    $this->changeOnRead = $read;
    $response = $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload);
    if ($read === 1) {
        $response->assertUnprocessable();
        expect(MymarBookingRequest::count())->toBe(0);
    } else {
        $response->assertOk()->assertJsonPath('state', 'rejected');
    }
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([1, 2]);

it('refuses a changed duration, appointment id or forced placement', function (string $case) {
    $changes = match ($case) {
        'duration' => ['duration_minutes' => 60], 'rdv' => ['rdv_id' => 999], default => ['force_placement' => true]
    };
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), array_replace($this->payload, $changes))->assertStatus($case === 'rdv' ? 503 : 422);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    expect(MymarBookingRequest::count())->toBe(0);
})->with(['duration', 'rdv', 'force']);

it('does not overwrite another Cofrac appointment or an absence', function (string $case) {
    $start = Carbon::parse($this->payload['starts_at']);
    if ($case === 'absence') {
        $this->tech->absences()->create(['created_by' => $this->actor->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'reason' => 'Formation']);
    } else {
        Appointment::create(['service_id' => $this->service->id, 'technician_id' => $this->tech->id, 'created_by' => $this->actor->id,
            'customer_first_name' => 'Autre', 'customer_last_name' => 'Client', 'customer_phone' => '0600000000',
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'duration_minutes' => 60, 'address' => 'Autre adresse', 'status' => 'scheduled']);
    }
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload)->assertUnprocessable();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with(['absence', 'overlap']);

it('rejects a conflict with another MAR appointment and never forces the source write', function () {
    $this->conflicts = [['field' => 'starts_at', 'type' => 'mymar', 'message' => 'Autre rendez-vous MAR.']];
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload)->assertOk()->assertJsonPath('state', 'rejected');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('resolves an uncertain replacement by reading its receipt without replaying the POST', function () {
    $this->writerStatus = 503;
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload)->assertStatus(202)->assertJsonPath('state', 'uncertain');
    $record = MymarBookingRequest::first();
    $record->forceFill(['lease_until' => time() - 1])->save();
    $this->getJson(route('planner.book.mar.status', $record->id))->assertOk()->assertJsonPath('confirmed', true);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
});

it('keeps a confirmed result visible even when calendar synchronization fails', function () {
    $this->syncFails = true;
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload)->assertOk()
        ->assertJsonPath('confirmed', true)->assertJsonPath('message', fn ($message) => str_contains($message, 'attente de synchronisation'));
});

it('denies external accounts, technicians and disabled actions before calling MYMAR', function (string $case) {
    if ($case === 'disabled') {
        $this->settings['writes_enabled'] = false;
    } else {
        $this->actor->update(['role' => $case === 'technician' ? 2 : 3]);
    }
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.scheduled', 555))->assertStatus($case === 'disabled' ? 503 : 403);
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.reschedule'), $this->payload)->assertStatus($case === 'disabled' ? 503 : 403);
    Http::assertNothingSent();
})->with(['external', 'technician', 'disabled']);
