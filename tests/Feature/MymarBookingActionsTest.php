<?php

use App\Models\Appointment;
use App\Models\MymarBookingRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarPendingBookings;
use App\Services\Mymar\MymarSlotGuard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->config = ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
        'port' => 19848, 'generation' => (string) Str::uuid(), 'bindings' => [['source_technician_id' => 1234, 'cofrac_user_id' => $this->tech->id]]];
    $settings = Mockery::mock(MymarActionSettings::class);
    $settings->shouldReceive('load')->andReturnUsing(fn () => $this->config);
    $settings->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    app()->instance(MymarActionSettings::class, $settings);
    $this->source = ['dossier_id' => 123, 'expected_revision' => str_repeat('a', 64), 'address' => '10 rue Test, 75001 Paris', 'latitude' => 48.86, 'longitude' => 2.35];
    $pending = Mockery::mock(MymarPendingBookings::class);
    $pending->shouldReceive('find')->andReturnUsing(fn () => $this->source);
    $pending->shouldReceive('invalidate')->andReturnNull();
    app()->instance(MymarPendingBookings::class, $pending);
    $reader = Mockery::mock(MymarBookingReaderSettings::class);
    $reader->shouldReceive('load')->andReturn(['read_enabled' => true]);
    $reader->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    app()->instance(MymarBookingReaderSettings::class, $reader);
    $guard = Mockery::mock(MymarSlotGuard::class);
    $guard->shouldReceive('pendingConflicts')->andReturnUsing(fn ($id, $from, $to, $ignore = null) => MymarBookingRequest::where('cofrac_technician_id', $id)
        ->whereIn('status', ['queued', 'dispatching', 'uncertain'])->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()
        ? [['field' => 'starts_at', 'type' => 'mymar_pending', 'message' => 'Confirmation MAR en attente.']] : []);
    $guard->shouldReceive('liveConflicts')->andReturnUsing(function () {
        expect(DB::transactionLevel())->toBeGreaterThan(0);

        return [];
    });
    app()->instance(MymarSlotGuard::class, $guard);
    $this->payload = ['request_id' => (string) Str::uuid(), 'dossier_id' => 123, 'expected_revision' => str_repeat('a', 64),
        'technician_id' => $this->tech->id, 'starts_at' => now('Europe/Paris')->addDay()->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 60];
    $this->receipt = fn (array $data = []) => array_replace(['format' => 'cofrac.mymar-booking-result', 'version' => 1,
        'request_id' => $this->payload['request_id'], 'action' => 'place', 'dossier_id' => 123, 'status_id' => 16,
        'rdv_id' => 555, 'starts_at' => $this->payload['starts_at'], 'duration_minutes' => 60, 'source_committed' => true], $data);
    Http::preventStrayRequests();
});

it('confirms a placement with server-selected identities and no native appointment copy', function () {
    Http::fake(function ($request) {
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        expect($request['cofrac_actor_id'])->toBe($this->actor->id)->and($request['technician_id'])->toBe(1234);

        return Http::response(($this->receipt)(), 200, ['Content-Type' => 'application/json']);
    });
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.place'), $this->payload)->assertOk()->assertJsonPath('confirmed', true);
    expect(MymarBookingRequest::first()->status)->toBe('confirmed')->and(Appointment::count())->toBe(0);
    $this->postJson(route('planner.book.mar.place'), $this->payload)->assertOk()->assertJsonPath('confirmed', true);
    Http::assertSentCount(1);
});

it('keeps an uncertain reservation and only reads the receipt on subsequent verification', function () {
    Http::fakeSequence()->push(['error' => 'booking_source_unavailable'], 503)->push(($this->receipt)(), 200);
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.place'), $this->payload)->assertStatus(202)->assertJsonPath('state', 'uncertain');
    $record = MymarBookingRequest::first();
    expect($record->cofrac_technician_id)->toBe($this->tech->id)->and($record->active_dossier_id)->toBe(123);
    $record->forceFill(['lease_until' => time() - 1])->save();
    $this->getJson(route('planner.book.mar.status', $record->id))->assertOk()->assertJsonPath('confirmed', true);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/booking-commands/'.$record->id));
});

it('does not send on a changed source, disabled feature, unlinked tech or missing author rights', function (string $case) {
    if ($case === 'changed') {
        $this->source['expected_revision'] = str_repeat('b', 64);
    }
    if ($case === 'disabled') {
        $this->config['writes_enabled'] = false;
    }
    if ($case === 'unlinked') {
        $this->payload['technician_id'] = 9999;
    }
    if ($case === 'external') {
        $this->actor->update(['role' => 3]);
    }
    $response = $this->actingAs($this->actor)->postJson(route('planner.book.mar.place'), $this->payload);
    expect($response->status())->toBe(in_array($case, ['changed', 'unlinked']) ? 422 : ($case === 'external' ? 403 : 503));
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertNothingSent();
})->with(['changed', 'disabled', 'unlinked', 'external']);

it('rejects overlaps and absences before a MAR request is queued', function (string $case) {
    $start = Carbon::parse($this->payload['starts_at']);
    if ($case === 'absence') {
        $this->tech->absences()->create(['created_by' => $this->actor->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'reason' => 'Formation']);
    } else {
        $service = Service::create(['name' => 'Service test', 'type' => 'COFRAC', 'average_duration_minutes' => 60]);
        Appointment::create(['service_id' => $service->id, 'technician_id' => $this->tech->id, 'created_by' => $this->actor->id,
            'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '0600000000',
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'duration_minutes' => 60, 'address' => 'Autre adresse', 'status' => 'scheduled']);
    }
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.place'), $this->payload)->assertUnprocessable();
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertNothingSent();
})->with(['absence', 'overlap']);

it('records problems using the dedicated contract without a local appointment', function () {
    Http::fake(fn () => Http::response(($this->receipt)(['action' => 'problem', 'status_id' => 15, 'rdv_id' => null, 'starts_at' => null, 'duration_minutes' => null]), 200));
    $payload = array_intersect_key($this->payload, array_flip(['request_id', 'dossier_id', 'expected_revision'])) + ['comment' => 'Bénéficiaire indisponible', 'reason' => 'Rappel', 'recall_at' => $this->payload['starts_at']];
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.problem'), $payload)->assertOk()->assertJsonPath('confirmed', true);
    expect(MymarBookingRequest::first()->cofrac_technician_id)->toBeNull()->and(Appointment::count())->toBe(0);
});

it('denies forging, forcing, invalid hour units and access to another authors receipt', function () {
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.place'), $this->payload + ['force_placement' => true])->assertUnprocessable();
    $this->postJson(route('planner.book.mar.place'), array_replace($this->payload, ['duration_minutes' => 45]))->assertUnprocessable();
    Http::fake(fn () => Http::response(($this->receipt)()));
    $this->postJson(route('planner.book.mar.place'), $this->payload)->assertOk();
    $other = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($other)->getJson(route('planner.book.mar.status', $this->payload['request_id']))->assertForbidden();
    Http::assertSentCount(1);
});

it('does not initiate an unsent command from a status GET', function () {
    $command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1, 'cofrac_actor_id' => $this->actor->id,
        'action' => 'place', ...array_replace($this->payload, ['technician_id' => 1234])]);
    MymarBookingRequest::forceCreate(['id' => $this->payload['request_id'], 'author_id' => $this->actor->id, 'source_dossier_id' => 123,
        'active_dossier_id' => 123, 'fingerprint' => $command->fingerprint(), 'command' => $command->payload, 'status' => 'queued']);
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.status', $this->payload['request_id']))->assertStatus(202)->assertJsonPath('state', 'queued');
    Http::assertNothingSent();
});

it('checks readiness through reads only and refuses an already enabled writer', function () {
    $guard = Mockery::mock(MymarSlotGuard::class);
    $guard->shouldReceive('liveConflicts')->once()->andReturn([]);
    app()->instance(MymarSlotGuard::class, $guard);
    $pending = app(MymarPendingBookings::class);
    $pending->shouldReceive('page')->once()->with(0, 1, true)->andReturn(['items' => []]);
    $this->config['writes_enabled'] = false;
    $this->artisan('mymar:actions-check')->expectsOutputToContain('"ready":true')->assertSuccessful();
    $this->config['writes_enabled'] = true;
    $this->artisan('mymar:actions-check')->expectsOutputToContain('"ready":false')->assertFailed();
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertNothingSent();
});
