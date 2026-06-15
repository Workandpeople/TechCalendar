<?php

use App\Models\Appointment;
use App\Mail\TechnicianAppointmentNotificationMail;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('reassigns a tracking appointment to another compatible technician', function () {
    Mail::fake();

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $oldTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $newTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'address' => '10 Rue de la Barre, Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
    ]);
    $oldTechnician->services()->attach($service);
    $newTechnician->services()->attach($service);

    $startsAt = Carbon::parse('2026-06-12 10:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $oldTechnician->id,
        'created_by' => $manager->id,
        'customer_first_name' => 'Claire',
        'customer_last_name' => 'Martin',
        'customer_phone' => '0600000001',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'starts_at' => $startsAt,
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addMinutes(120),
    ]);

    foreach ([$oldTechnician, $newTechnician] as $technician) {
        TechnicianDailyRouteMetric::query()->create([
            'technician_id' => $technician->id,
            'service_date' => $startsAt->toDateString(),
            'appointment_count' => 1,
            'drive_distance_km' => 12.5,
            'drive_duration_minutes' => 24,
            'overtime_minutes' => 0,
            'calculation_source' => 'haversine',
            'route_hash' => hash('sha256', 'stale-'.$technician->id),
            'calculated_at' => now(),
        ]);
    }

    $this->actingAs($manager)
        ->patchJson(route('planner.tracking.appointments.technician', $appointment), [
            'technician_id' => $newTechnician->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Rendez-vous réaffecté.')
        ->assertJsonPath('technician.id', $newTechnician->id)
        ->assertJsonPath('technician.name', $newTechnician->full_name);

    expect($appointment->refresh()->technician_id)->toBe($newTechnician->id)
        ->and(TechnicianDailyRouteMetric::query()
            ->whereDate('service_date', $startsAt->toDateString())
            ->whereIn('technician_id', [$oldTechnician->id, $newTechnician->id])
            ->exists())->toBeFalse();

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'reassigned_from'
            && $mail->hasTo($oldTechnician->email)
            && $mail->appointment->id === $appointment->id,
    );
    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'reassigned_to'
            && $mail->hasTo($newTechnician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('updates tracking appointment date duration and address', function () {
    Mail::fake();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);

    $startsAt = Carbon::parse('2026-06-18 09:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'Modif',
        'customer_phone' => '0600000020',
        'address' => '1 Rue Ancienne, 69001 Lyon',
        'latitude' => 45.767,
        'longitude' => 4.833,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
    ]);

    TechnicianDailyRouteMetric::query()->create([
        'technician_id' => $technician->id,
        'service_date' => $startsAt->toDateString(),
        'appointment_count' => 1,
        'drive_distance_km' => 12.5,
        'drive_duration_minutes' => 24,
        'overtime_minutes' => 0,
        'calculation_source' => 'haversine',
        'route_hash' => hash('sha256', 'stale-details'),
        'calculated_at' => now(),
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.details', $appointment), [
            'starts_at' => '2026-06-19 14:30:00',
            'duration_minutes' => 135,
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'latitude' => 45.7578,
            'longitude' => 4.832,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Rendez-vous mis à jour.')
        ->assertJsonPath('appointment.duration_minutes', 135)
        ->assertJsonPath('appointment.postal_code', '69002')
        ->assertJsonPath('appointment.city', 'Lyon');

    $appointment->refresh();

    expect($appointment->starts_at->format('Y-m-d H:i:s'))->toBe('2026-06-19 14:30:00')
        ->and($appointment->ends_at->format('Y-m-d H:i:s'))->toBe('2026-06-19 16:45:00')
        ->and($appointment->address)->toBe('20 Place Bellecour, 69002 Lyon, France')
        ->and(TechnicianDailyRouteMetric::query()
            ->where('technician_id', $technician->id)
            ->whereDate('service_date', '2026-06-18')
            ->exists())->toBeFalse();

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'details_updated'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('rejects tracking appointment update that overlaps another appointment', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle',
        'average_duration_minutes' => 90,
    ]);

    $startsAt = Carbon::parse('2026-06-20 09:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Source',
        'customer_phone' => '0600000021',
        'address' => '1 Rue Source, Lyon',
        'latitude' => 45.76,
        'longitude' => 4.83,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addMinutes(60),
    ]);
    Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Existant',
        'customer_phone' => '0600000022',
        'address' => '2 Rue Existant, Lyon',
        'latitude' => 45.77,
        'longitude' => 4.84,
        'starts_at' => Carbon::parse('2026-06-20 14:00:00'),
        'duration_minutes' => 90,
        'ends_at' => Carbon::parse('2026-06-20 15:30:00'),
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.details', $appointment), [
            'starts_at' => '2026-06-20 13:30:00',
            'duration_minutes' => 90,
            'address' => '1 Rue Source, Lyon',
            'latitude' => 45.76,
            'longitude' => 4.83,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');

    expect($appointment->refresh()->starts_at->format('H:i:s'))->toBe('09:00:00');
});

it('notifies the technician when a tracking appointment comment is updated cancelled and restored', function () {
    Mail::fake();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_MAR,
        'name' => 'Contrôle MAR',
        'average_duration_minutes' => 60,
    ]);

    $startsAt = Carbon::parse('2026-06-21 09:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Cycle',
        'customer_phone' => '0600000023',
        'address' => '1 Rue Cycle, Lyon',
        'latitude' => 45.76,
        'longitude' => 4.83,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addMinutes(60),
        'comment' => 'Commentaire initial',
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.comment', $appointment), [
            'comment' => 'Commentaire modifié',
        ])
        ->assertOk();

    $this->actingAs($planner)
        ->deleteJson(route('planner.tracking.appointments.destroy', $appointment), [
            'comment' => 'Annulation client',
        ])
        ->assertOk();

    $this->actingAs($planner)
        ->postJson(route('planner.tracking.appointments.restore', $appointment), [
            'comment' => 'Réactivation confirmée',
        ])
        ->assertOk();

    foreach (['comment_updated', 'cancelled', 'restored'] as $eventType) {
        Mail::assertQueued(
            TechnicianAppointmentNotificationMail::class,
            fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === $eventType
                && $mail->hasTo($technician->email)
                && $mail->appointment->id === $appointment->id,
        );
    }
});

it('rejects reassignment to a technician that does not cover the appointment service', function () {
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
    $oldTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $incompatibleTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $oldTechnician->services()->attach($service);
    $incompatibleTechnician->services()->attach($otherService);

    $startsAt = Carbon::parse('2026-06-12 14:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $oldTechnician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Paul',
        'customer_last_name' => 'Client',
        'customer_phone' => '0600000002',
        'address' => '1 Rue Nationale, Villeurbanne',
        'latitude' => 45.7719,
        'longitude' => 4.8902,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.technician', $appointment), [
            'technician_id' => $incompatibleTechnician->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('technician_id');

    expect($appointment->refresh()->technician_id)->toBe($oldTechnician->id);
});

it('filters tracking events by service', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $targetService = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle cible',
        'average_duration_minutes' => 90,
    ]);
    $otherService = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit hors filtre',
        'average_duration_minutes' => 120,
    ]);

    $startsAt = Carbon::parse('2026-06-15 09:00:00');
    $targetAppointment = Appointment::query()->create([
        'service_id' => $targetService->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Alice',
        'customer_last_name' => 'Filtre',
        'customer_phone' => '0600000010',
        'address' => '1 Rue de la Paix, Paris',
        'latitude' => 48.8686,
        'longitude' => 2.3305,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
    ]);
    Appointment::query()->create([
        'service_id' => $otherService->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Bob',
        'customer_last_name' => 'Masque',
        'customer_phone' => '0600000011',
        'address' => '2 Rue de la Paix, Paris',
        'latitude' => 48.869,
        'longitude' => 2.331,
        'starts_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addHours(5),
    ]);

    $events = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-15 00:00:00',
            'end' => '2026-06-16 00:00:00',
            'service_id' => $targetService->id,
        ])
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe($targetAppointment->id)
        ->and($events[0]['extendedProps']['service_id'])->toBe($targetService->id);
});

it('filters tracking events by appointment soft-delete status', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_MAR,
        'name' => 'Contrôle MAR',
        'average_duration_minutes' => 60,
    ]);

    $startsAt = Carbon::parse('2026-06-16 10:00:00');
    $activeAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Actif',
        'customer_phone' => '0600000012',
        'address' => '3 Rue Nationale, Lyon',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addMinutes(60),
    ]);
    $deletedAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Supprimé',
        'customer_phone' => '0600000013',
        'address' => '4 Rue Nationale, Lyon',
        'latitude' => 45.765,
        'longitude' => 4.836,
        'starts_at' => $startsAt->copy()->addHours(2),
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addHours(3),
    ]);
    $deletedAppointment->delete();

    $activeEvents = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-16 00:00:00',
            'end' => '2026-06-17 00:00:00',
            'appointment_status' => 'active',
        ])
        ->assertOk()
        ->json('events');

    $deletedEvents = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-16 00:00:00',
            'end' => '2026-06-17 00:00:00',
            'appointment_status' => 'deleted',
        ])
        ->assertOk()
        ->json('events');

    expect($activeEvents)->toHaveCount(1)
        ->and($activeEvents[0]['id'])->toBe($activeAppointment->id)
        ->and($activeEvents[0]['extendedProps']['deleted_at'])->toBeNull()
        ->and($deletedEvents)->toHaveCount(1)
        ->and($deletedEvents[0]['id'])->toBe($deletedAppointment->id)
        ->and($deletedEvents[0]['extendedProps']['deleted_at'])->not->toBeNull();
});
