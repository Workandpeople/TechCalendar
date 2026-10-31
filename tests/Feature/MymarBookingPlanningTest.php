<?php

use App\Models\Appointment;
use App\Models\Dossier;
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
    $this->config = ['format' => 'cofrac.mymar-actions-client', 'version' => 1, 'guard_enabled' => true, 'writes_enabled' => true,
        'port' => 19848, 'generation' => (string) Str::uuid(), 'bindings' => [['source_technician_id' => 1234, 'cofrac_user_id' => $this->tech->id]]];
    $settings = Mockery::mock(MymarActionSettings::class);
    $settings->shouldReceive('load')->andReturnUsing(fn () => $this->config);
    app()->instance(MymarActionSettings::class, $settings);
    $this->item = ['dossier_id' => 123, 'phase' => 'after_works', 'expected_revision' => str_repeat('a', 64), 'first_name' => 'Camille',
        'last_name' => 'MAR', 'phone' => '0600000000', 'email' => null, 'postal_code' => '75001', 'city' => 'Paris',
        'address' => '10 rue Test, 75001 Paris', 'latitude' => 48.86, 'longitude' => 2.34];
    $pending = Mockery::mock(MymarPendingBookings::class);
    $pending->shouldReceive('find')->with(123)->andReturnUsing(fn () => $this->item);
    app()->instance(MymarPendingBookings::class, $pending);
    $routes = Mockery::mock(MapboxDrivingRouteService::class);
    $routes->shouldReceive('estimate')->andReturn(['distance_km' => 2, 'duration_minutes' => 10, 'source' => 'mapbox']);
    app()->instance(MapboxDrivingRouteService::class, $routes);
});

it('analyzes MAR with an explicit duration and only approved active technicians without cloning source data', function () {
    User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 48.85, 'longitude' => 2.35]);
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 120])
        ->assertOk()->assertJsonPath('crm_appointment.is_mymar', true)->assertJsonPath('crm_appointment.service.average_duration_minutes', 120)
        ->assertJsonPath('crm_appointment.service_name', 'Visite après travaux')->assertJsonCount(1, 'technicians')
        ->assertJsonPath('technicians.0.id', $this->tech->id);
    expect(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Http::assertNothingSent();
});

it('requires an explicit MAR duration rejects mixed sources and never routes MAR through native creation', function () {
    $this->actingAs($this->actor)->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123])->assertUnprocessable();
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 45])->assertUnprocessable();
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60, 'crm_appointment_id' => 'dossier-1'])->assertUnprocessable();
    $this->postJson(route('planner.book.appointments.store'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60])->assertUnprocessable();
    $this->config['writes_enabled'] = false;
    $this->postJson(route('planner.book.analyze'), ['mymar_dossier_id' => 123, 'mymar_duration_minutes' => 60])->assertUnprocessable();
    expect(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Http::assertNothingSent();
});
