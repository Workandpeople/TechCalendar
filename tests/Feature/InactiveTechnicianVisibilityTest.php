<?php

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('only offers active internal technicians on planner and manager appointment pages', function (string $area, bool $native) {
    config(['crm.v2_enabled' => $native]);
    $actor = User::factory()->create(['role' => $area === 'planner' ? 1 : 0, 'admin' => false]);
    $active = User::factory()->create(['role' => 2, 'admin' => false]);
    $inactive = User::factory()->create(['role' => 2, 'admin' => false]);
    $inactive->delete();
    User::factory()->create(['role' => 2, 'admin' => true]);
    User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false]);
    $route = $area === 'planner' ? 'planner.tracking' : 'manager.appointments';

    $this->actingAs($actor)->get(route($route))->assertOk()
        ->assertViewHas('technicians', fn ($technicians) => $technicians->pluck('id')->all() === [$active->id]);
    $inactive->restore();
    $this->get(route($route))->assertOk()->assertViewHas('technicians', fn ($technicians) => $technicians->pluck('id')->sort()->values()->all() === [$active->id, $inactive->id]);
})->with(['planner', 'manager'])->with([true, false]);

it('preserves inactive technician names and assignments on historical appointments without offering them for reassignment', function (string $area) {
    config(['crm.v2_enabled' => true]);
    $actor = User::factory()->create(['role' => $area === 'planner' ? 1 : 0, 'admin' => false]);
    $inactive = User::factory()->create(['first_name' => 'Ancien', 'last_name' => 'TECHNICIEN', 'role' => 2, 'admin' => false]);
    $active = User::factory()->create(['role' => 2, 'admin' => false]);
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite historique', 'average_duration_minutes' => 60]);
    $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $inactive->id,
        'created_by' => $actor->id, 'customer_first_name' => 'ARCHIVE-CUSTOMER', 'customer_last_name' => 'TEST',
        'customer_phone' => '0600000000', 'address' => '1 rue du Test, Paris',
        'starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHour(), 'duration_minutes' => 60]);
    $inactive->delete();

    $this->actingAs($actor)->get(route($area === 'planner' ? 'planner.tracking' : 'manager.appointments'))->assertOk()
        ->assertViewHas('technicians', fn ($technicians) => $technicians->modelKeys() === [$active->id]);
    $this->postJson(route($area === 'planner' ? 'planner.tracking.search' : 'manager.appointments.search'), ['q' => 'ARCHIVE-CUSTOMER'])
        ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('appointments.0.id', $appointment->id)
        ->assertJsonPath('appointments.0.extendedProps.technician_id', $inactive->id)
        ->assertJsonPath('appointments.0.extendedProps.technician_name', 'Ancien TECHNICIEN (--)');
    expect($appointment->fresh()->technician_id)->toBe($inactive->id)
        ->and(User::withTrashed()->findOrFail($inactive->id)->trashed())->toBeTrue();
})->with(['planner', 'manager']);

it('rejects reassignment to a disabled technician from an already open appointment screen', function () {
    config(['crm.v2_enabled' => true]);
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $active = User::factory()->create(['role' => 2, 'admin' => false]);
    $inactive = User::factory()->create(['role' => 2, 'admin' => false]);
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite', 'average_duration_minutes' => 60]);
    $inactive->services()->attach($service);
    $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $active->id,
        'created_by' => $actor->id, 'customer_first_name' => 'Client', 'customer_last_name' => 'TEST',
        'customer_phone' => '0600000000', 'address' => 'Paris', 'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(), 'duration_minutes' => 60]);
    $inactive->delete();
    $this->actingAs($actor)->patchJson(route('planner.tracking.appointments.technician', $appointment), ['technician_id' => $inactive->id])
        ->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    expect($appointment->fresh()->technician_id)->toBe($active->id);
});
