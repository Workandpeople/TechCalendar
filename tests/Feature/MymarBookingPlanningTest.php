<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\MapboxDrivingRouteService;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarPendingBookings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.mapbox.token' => '']);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 48.85, 'longitude' => 2.35]);
    $this->service = Service::create(['type' => 'MAR', 'name' => 'VISITE APRES TRAVAUX', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->config = ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
        'port' => 19848, 'generation' => (string) Str::uuid(), 'bindings' => [['source_technician_id' => 1234, 'cofrac_user_id' => $this->tech->id]]];
    $settings = Mockery::mock(MymarActionSettings::class);
    $settings->shouldReceive('load')->andReturnUsing(fn () => $this->config);
    app()->instance(MymarActionSettings::class, $settings);
    $this->item = ['dossier_id' => 123, 'status_id' => 14, 'phase' => 'after_works', 'expected_revision' => str_repeat('a', 64), 'first_name' => 'Camille',
        'last_name' => 'MAR', 'phone' => '0600000000', 'email' => null, 'postal_code' => '75001', 'city' => 'Paris',
        'address' => '10 rue Test, 75001 Paris', 'latitude' => 48.86, 'longitude' => 2.34];
    $pending = Mockery::mock(MymarPendingBookings::class);
    $pending->shouldReceive('find')->with(123)->andReturnUsing(fn () => $this->item);
    app()->instance(MymarPendingBookings::class, $pending);
    $routes = Mockery::mock(MapboxDrivingRouteService::class);
    $routes->shouldReceive('estimate')->andReturn(['distance_km' => 2, 'duration_minutes' => 10, 'source' => 'mapbox']);
    app()->instance(MapboxDrivingRouteService::class, $routes);
});

it('uses the configured MAR duration and ignores legacy browser duration while requiring qualification', function () {
    User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 48.85, 'longitude' => 2.35]);
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 120])
        ->assertOk()->assertJsonPath('crm_appointment.is_mymar', true)->assertJsonPath('crm_appointment.service.average_duration_minutes', 60)
        ->assertJsonPath('crm_appointment.service.id', $this->service->id)
        ->assertJsonPath('crm_appointment.service_name', 'Visite après travaux')->assertJsonCount(1, 'technicians')
        ->assertJsonPath('technicians.0.id', $this->tech->id);
    expect(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Http::assertNothingSent();
});

it('starts without a duration choice rejects mixed sources and never routes MAR through native creation', function () {
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])->assertOk();
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 45])->assertUnprocessable();
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60, 'crm_appointment_id' => 'dossier-1'])->assertUnprocessable();
    $this->postJson(route('planner.book.appointments.store'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60])->assertUnprocessable();
    $this->config['writes_enabled'] = false;
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60])->assertUnprocessable();
    expect(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Http::assertNothingSent();
});

it('excludes an approved but unqualified technician and keeps unknown source mappings closed', function () {
    $this->tech->services()->detach();
    $qualified = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 48.85, 'longitude' => 2.35]);
    $qualified->services()->attach($this->service);
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])->assertOk()->assertJsonCount(0, 'technicians');
    $this->config['bindings'][] = ['source_technician_id' => 1235, 'cofrac_user_id' => $qualified->id];
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])->assertOk()
        ->assertJsonCount(1, 'technicians')->assertJsonPath('technicians.0.id', $qualified->id);
});

it('aligns only the Cofrac fifty-minute MAR service on explicit command without external calls', function () {
    $this->service->update(['average_duration_minutes' => 50]);
    $other = Service::create(['type' => 'COFFRAC', 'name' => 'Other', 'average_duration_minutes' => 50]);
    $this->artisan('mymar:align-service-duration')->assertSuccessful();
    expect($this->service->fresh()->average_duration_minutes)->toBe(50);
    $this->artisan('mymar:align-service-duration', ['--apply' => true])->assertSuccessful();
    expect($this->service->fresh()->average_duration_minutes)->toBe(60)->and($other->fresh()->average_duration_minutes)->toBe(50);
    $this->artisan('mymar:align-service-duration', ['--apply' => true])->assertSuccessful();
    Http::assertNothingSent();
});

it('refuses an incompatible duration without silently rounding or updating the service', function () {
    $this->service->update(['average_duration_minutes' => 50]);
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])
        ->assertUnprocessable()->assertJsonValidationErrors('mymar_dossier_id');
    expect($this->service->fresh()->average_duration_minutes)->toBe(50);
    Http::assertNothingSent();
});

it('refuses an absent or ambiguous MAR catalogue rather than choosing another service', function () {
    $this->service->update(['name' => 'Autre visite']);
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])
        ->assertUnprocessable()->assertJsonValidationErrors('mymar_dossier_id');
    $this->service->update(['name' => 'VISITE APRES TRAVAUX']);
    Service::create(['type' => 'MAR', 'name' => 'Visite après travaux', 'average_duration_minutes' => 120]);
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])
        ->assertUnprocessable()->assertJsonValidationErrors('mymar_dossier_id');
    Http::assertNothingSent();
});

it('rejects initial visits before looking for technicians or geocoding their address', function () {
    $this->item = array_replace($this->item, ['status_id' => 1, 'phase' => 'initial', 'latitude' => null, 'longitude' => null]);
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60])
        ->assertUnprocessable()->assertJsonValidationErrors('mymar_dossier_id');
    expect(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Http::assertNothingSent();
});
