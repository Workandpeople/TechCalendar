<?php

use App\Http\Controllers\Planner\PlannerBookingController;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('includes the unambiguous full technician name in both placed events and suggestions without additional routing', function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $technician = User::factory()->create(['role' => 2, 'first_name' => 'Camille', 'last_name' => 'TECH']);
    $department = Department::firstOrCreate(['code' => '75'], ['name' => 'Paris']);
    $technician->departments()->attach($department);
    $technician->load('departments');
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Test', 'average_duration_minutes' => 60]);
    $date = Carbon::parse('2026-11-02 10:00:00');
    Appointment::forceCreate(['service_id' => $service->id, 'technician_id' => $technician->id, 'created_by' => $technician->id,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '0600000000',
        'address' => 'Rue de test', 'latitude' => 48.9, 'longitude' => 2.4, 'starts_at' => $date,
        'ends_at' => $date->copy()->addHour(), 'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
    $controller = app(PlannerBookingController::class);
    $events = (new ReflectionMethod($controller, 'calendarEvents'))->invoke($controller,
        Appointment::with('technician.departments', 'service')->get());
    expect($events->sole()['extendedProps']['technician_full_name'])->toBe('Camille TECH')
        ->and($events->sole()['extendedProps']['technician_name'])->toContain('75');
    $request = ['id' => 'manual-test', 'first_name' => 'Client', 'last_name' => 'Test', 'phone' => '0600000000',
        'address' => 'Rue de test', 'latitude' => 48.9, 'longitude' => 2.4, 'service' => ['name' => 'Test']];
    $route = ['distance_km' => 10, 'duration_minutes' => 15];
    $suggestion = (new ReflectionMethod($controller, 'suggestionPayload'))->invoke($controller, $technician, $request,
        $date, $date, 60, ['lat' => 48.8, 'lng' => 2.3, 'label' => 'Domicile'], 'domicile', $route, $route, null, null);
    expect($suggestion['extendedProps']['technician_full_name'])->toBe('Camille TECH')
        ->and($suggestion['extendedProps']['technician_name'])->toContain('75')
        ->and($suggestion['extendedProps']['home_to_minutes'])->toBe(15);
    Http::assertNothingSent();
});
