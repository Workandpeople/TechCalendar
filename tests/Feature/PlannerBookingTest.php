<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Mail\TechnicianAppointmentNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('links dashboard crm appointments to an auto-start booking search', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.dashboard'))
        ->assertOk()
        ->assertSee('RDV à placer')
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
        ->assertSee('booking-crm-refresh')
        ->assertSee('const bookingInitialCrmAppointmentId = "crm-audit-lyon-001";', false);
});

it('refreshes simulated crm appointment requests on the booking page', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.refresh'))
        ->assertOk()
        ->assertJsonCount(15, 'appointments')
        ->assertJsonStructure([
            'appointments' => [[
                'id',
                'source',
                'first_name',
                'last_name',
                'phone',
                'address',
                'department_code',
                'latitude',
                'longitude',
                'service',
            ]],
        ]);
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
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $placedStartsAt = now()->copy()->addDay()->setTime(11, 0);
    $lot = Lot::query()->create([
        'name' => 'Lot Rhône',
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
    $placedAppointment = Appointment::query()->create([
        'service_id' => Service::query()->first()->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Place',
        'customer_phone' => '0600000004',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'starts_at' => $placedStartsAt,
        'duration_minutes' => 120,
        'ends_at' => $placedStartsAt->copy()->addMinutes(120),
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $placedAppointment->service_id,
        'appointment_id' => $placedAppointment->id,
        'customer_name' => 'Client Place',
        'customer_phone' => '0600000004',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'status' => LotAppointment::STATUS_PLACED,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.book'))
        ->assertOk()
        ->assertSee('depuis des lots')
        ->assertSee('booking-crm-pagination')
        ->assertSee('Lot Rhône')
        ->assertSee('Client Lot')
        ->assertSee('Client Place')
        ->assertSee('RDV placé')
        ->assertSee('Audit interne')
        ->assertSee('Placer le RDV')
        ->assertSee('Voir le RDV')
        ->assertSee('appointment_id='.$placedAppointment->id, false);
});

it('searches additional booking technicians compatible with the requested service', function () {
    config(['services.mapbox.token' => null]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle initial',
        'average_duration_minutes' => 90,
    ]);
    $otherService = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);

    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $compatibleTechnician = User::factory()->create([
        'first_name' => 'Arthur',
        'last_name' => 'Martin',
        'role' => 2,
        'admin' => false,
        'phone' => '0600000001',
        'address' => '1 Rue de la République, Lyon',
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

    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'address' => '1 Rue de la République, Lyon',
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

it('includes saturday in booking slot suggestions', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit samedi',
            'average_duration_minutes' => 90,
        ]);

        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'address' => '1 Rue de la République, Lyon',
            'department_code' => '69',
            'latitude' => 45.764,
            'longitude' => 4.8357,
            'day_start_time' => '07:00',
            'day_end_time' => '21:00',
        ]);
        $technician->services()->attach($service);
        $technician->departments()->attach('69');

        $response = $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'manual_appointment' => [
                    'first_name' => 'Claire',
                    'last_name' => 'Samedi',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
            ])
            ->assertOk();

        expect($response->json('suggestions'))->not->toBeEmpty()
            ->and(collect($response->json('suggestions'))
                ->contains(fn (array $suggestion): bool => \Carbon\Carbon::parse($suggestion['start'])->isSaturday()))
            ->toBeTrue();

        $firstSuggestionProps = $response->json('suggestions.0.extendedProps');

        expect(array_key_exists('travel_to_distance_km', $firstSuggestionProps))->toBeTrue()
            ->and(array_key_exists('travel_after_distance_km', $firstSuggestionProps))->toBeTrue()
            ->and($firstSuggestionProps['travel_to_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($firstSuggestionProps['travel_after_distance_km'])->toBeGreaterThanOrEqual(0);
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('keeps absent technicians visible but suppresses booking suggestions during absence', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit absence',
            'average_duration_minutes' => 90,
        ]);

        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'address' => '1 Rue de la République, Lyon',
            'department_code' => '69',
            'latitude' => 45.764,
            'longitude' => 4.8357,
            'day_start_time' => '07:00',
            'day_end_time' => '21:00',
        ]);
        $technician->services()->attach($service);
        $technician->departments()->attach('69');

        TechnicianAbsence::query()->create([
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'starts_at' => '2026-06-11 00:00:00',
            'ends_at' => '2026-06-25 23:59:59',
            'reason' => 'Conges',
        ]);

        $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'manual_appointment' => [
                    'first_name' => 'Claire',
                    'last_name' => 'Absence',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'technicians')
            ->assertJsonPath('technicians.0.id', $technician->id)
            ->assertJsonPath('technicians.0.absence_label', 'Abs du 11/06/2026 au 25/06/2026')
            ->assertJsonCount(0, 'suggestions');
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('rejects booking creation during technician absence', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit absence',
            'average_duration_minutes' => 90,
        ]);
        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'latitude' => 45.764,
            'longitude' => 4.8357,
        ]);
        $technician->services()->attach($service);

        TechnicianAbsence::query()->create([
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'starts_at' => '2026-06-12 00:00:00',
            'ends_at' => '2026-06-12 23:59:59',
        ]);

        $this->actingAs($planner)
            ->postJson(route('planner.book.appointments.store'), [
                'manual_appointment' => [
                    'first_name' => 'Claire',
                    'last_name' => 'Absence',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
                'technician_id' => $technician->id,
                'starts_at' => '2026-06-12 10:00:00',
                'duration_minutes' => 90,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technician_id');

        expect(\App\Models\Appointment::query()->exists())->toBeFalse();
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('links a placed appointment back to its lot appointment', function () {
    config(['services.mapbox.token' => null]);
    Mail::fake();

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
        'name' => 'Lot à placer',
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

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'created'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $lotAppointment->appointment_id,
    );
});
