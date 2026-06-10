<?php

use App\Models\Department;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links dashboard crm appointments to an auto-start booking search', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.dashboard'))
        ->assertOk()
        ->assertSee('RDV a placer')
        ->assertSee('15 demande(s)')
        ->assertSee(route('planner.book', ['crm_appointment_id' => 'crm-audit-lyon-001']), false);
});

it('exposes the initial crm appointment id on the booking page', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.book', ['crm_appointment_id' => 'crm-audit-lyon-001']))
        ->assertOk()
        ->assertSee('15 demande(s)')
        ->assertSee('const bookingInitialCrmAppointmentId = "crm-audit-lyon-001";', false);
});

it('renders lot appointment requests on the booking page', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot Rhone',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);

    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.book'))
        ->assertOk()
        ->assertSee('depuis des lots')
        ->assertSee('booking-crm-pagination')
        ->assertSee('Lot Rhone')
        ->assertSee('Client Lot')
        ->assertSee('Audit interne')
        ->assertSee('Placer le RDV');
});

it('searches additional booking technicians compatible with the requested service', function () {
    config(['services.mapbox.token' => null]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Controle initial',
        'average_duration_minutes' => 90,
    ]);
    $otherService = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);

    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhone']);

    $compatibleTechnician = User::factory()->create([
        'first_name' => 'Arthur',
        'last_name' => 'Martin',
        'role' => 2,
        'admin' => false,
        'phone' => '0600000001',
        'address' => '1 Rue de la Republique, Lyon',
        'department_code' => '69',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'day_start_time' => '08:00',
        'day_end_time' => '18:00',
        'break_duration_minutes' => 45,
    ]);
    $compatibleTechnician->services()->attach($service);
    $compatibleTechnician->departments()->attach('69');

    $incompatibleTechnician = User::factory()->create([
        'first_name' => 'Martin',
        'last_name' => 'Durand',
        'role' => 2,
        'admin' => false,
        'phone' => '0600000002',
        'address' => '10 Rue Nationale, Villeurbanne',
        'department_code' => '69',
        'latitude' => 45.7719,
        'longitude' => 4.8902,
    ]);
    $incompatibleTechnician->services()->attach($otherService);
    $incompatibleTechnician->departments()->attach('69');

    $this->actingAs($planner)
        ->postJson(route('planner.book.technicians.search'), [
            'query' => 'Martin',
            'manual_appointment' => [
                'first_name' => 'Claire',
                'last_name' => 'Client',
                'phone' => '0700000000',
                'address' => '20 Place Bellecour, Lyon',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'service_id' => $service->id,
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'technicians')
        ->assertJsonPath('technicians.0.id', $compatibleTechnician->id)
        ->assertJsonStructure([
            'technicians' => [[
                'id',
                'name',
                'phone',
                'address',
                'department_code',
                'latitude',
                'longitude',
                'driving_distance_km',
                'driving_duration_minutes',
                'route_source',
                'covers_requested_department',
            ]],
        ]);
});

it('analyzes a lot appointment request with a selected service', function () {
    config(['services.mapbox.token' => null]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);

    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhone']);

    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'address' => '1 Rue de la Republique, Lyon',
        'department_code' => '69',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'day_start_time' => '08:00',
        'day_end_time' => '18:00',
    ]);
    $technician->services()->attach($service);
    $technician->departments()->attach('69');

    $lot = Lot::query()->create([
        'name' => 'Lot sans prestation',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.analyze'), [
            'lot_appointment_id' => $lotAppointment->id,
            'lot_service_id' => $service->id,
        ])
        ->assertOk()
        ->assertJsonPath('crm_appointment.id', 'lot-'.$lotAppointment->id)
        ->assertJsonPath('crm_appointment.is_lot', true)
        ->assertJsonPath('crm_appointment.service.id', $service->id)
        ->assertJsonPath('filters.is_lot', true)
        ->assertJsonCount(1, 'technicians')
        ->assertJsonPath('technicians.0.id', $technician->id);
});

it('links a placed appointment back to its lot appointment', function () {
    config(['services.mapbox.token' => null]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot a placer',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
            'lot_service_id' => $service->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 120,
            'comment' => 'Placement depuis lot',
        ])
        ->assertCreated()
        ->assertJsonStructure(['appointment_id']);

    $lotAppointment->refresh();
    $lot->refresh();

    expect($lotAppointment->appointment_id)->not->toBeNull()
        ->and($lotAppointment->service_id)->toBe($service->id)
        ->and($lotAppointment->status)->toBe(LotAppointment::STATUS_PLACED)
        ->and($lot->status)->toBe(Lot::STATUS_COMPLETED);
});
