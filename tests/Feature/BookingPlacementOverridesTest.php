<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'notification_mail_enabled' => false, 'notification_push_enabled' => false]);
    $this->service = Service::create(['name' => 'Visite test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->actingAs($this->actor);
    $this->payload = ['manual_appointment' => ['first_name' => 'Camille', 'last_name' => 'Test', 'phone' => '0600000000',
        'address' => '10 rue de Rivoli, 75001 Paris', 'department_code' => '75', 'latitude' => 48.8566, 'longitude' => 2.3522,
        'service_id' => $this->service->id], 'technician_id' => $this->tech->id,
        'starts_at' => '2026-11-02T14:00:00', 'duration_minutes' => 60];
});

function blockingAppointment($test, array $attributes = []): Appointment
{
    return Appointment::forceCreate($attributes + ['created_by' => $test->actor->id, 'technician_id' => $test->tech->id,
        'service_id' => $test->service->id, 'customer_first_name' => 'Autre', 'customer_last_name' => 'Client',
        'customer_phone' => '0600000001', 'address' => '80 avenue de Lyon, 69000 Lyon', 'latitude' => 45.76, 'longitude' => 4.84,
        'starts_at' => '2026-11-02T14:30:00', 'ends_at' => '2026-11-02T15:30:00', 'duration_minutes' => 60, 'status' => 'scheduled']);
}

it('allows partial or full overlaps at the same address or less than one km away', function (array $attributes) {
    $existing = blockingAppointment($this, $attributes);
    $this->postJson(route('planner.book.appointments.store'), $this->payload)->assertCreated()->assertJsonPath('forced_placement', false);
    expect(Appointment::count())->toBe(2)->and($existing->fresh()->starts_at->format('H:i'))->toBe(substr($attributes['starts_at'] ?? '14:30', -5))
        ->and(DossierActivity::where('kind', 'appointment_forced')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'same full address without GPS' => [['address' => ' 10 RUE de RIVOLI , 75001 PARIS ', 'latitude' => null, 'longitude' => null]],
    'nearby 445m partial overlap' => [['latitude' => 48.8606, 'longitude' => 2.3522]],
    'same point full overlap' => [['latitude' => 48.8566, 'longitude' => 2.3522, 'starts_at' => '2026-11-02T14:00', 'ends_at' => '2026-11-02T15:00']],
]);

it('blocks a distant or unverified overlapping address without a force', function (array $attributes) {
    blockingAppointment($this, $attributes);
    $this->postJson(route('planner.book.appointments.store'), $this->payload)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    expect(Appointment::count())->toBe(1)->and(Dossier::count())->toBe(0);
})->with([
    'distant' => [[]], 'unknown GPS' => [['latitude' => null, 'longitude' => null]],
    'invalid zero GPS' => [['latitude' => 0, 'longitude' => 0]],
]);

it('checks every overlapping appointment, not only the nearest one', function () {
    blockingAppointment($this, ['latitude' => 48.8566, 'longitude' => 2.3522]);
    blockingAppointment($this);
    $this->postJson(route('planner.book.appointments.store'), $this->payload)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    expect(Appointment::count())->toBe(2);
});

it('ignores deleted appointments and exact interval boundaries, but not another real overlap', function () {
    blockingAppointment($this)->delete();
    blockingAppointment($this, ['starts_at' => '2026-11-02T13:00', 'ends_at' => '2026-11-02T14:00']);
    blockingAppointment($this, ['starts_at' => '2026-11-02T15:00', 'ends_at' => '2026-11-02T16:00']);
    $this->postJson(route('planner.book.appointments.store'), $this->payload)->assertCreated();
});

it('requires an explicit positive confirmation for forced placements', function (mixed $confirmation) {
    blockingAppointment($this);
    $payload = $this->payload + ['force_placement' => true];
    if ($confirmation !== null) {
        $payload['force_confirmed'] = $confirmation;
    }
    $this->postJson(route('planner.book.appointments.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('force_confirmed');
    expect(Appointment::count())->toBe(1)->and(DossierActivity::count())->toBe(0);
})->with([null, false, 'no', 0]);

it('forces a distant overlap and an absence only after confirmation and audits the override internally', function () {
    $existing = blockingAppointment($this);
    $absence = TechnicianAbsence::create(['technician_id' => $this->tech->id, 'created_by' => $this->actor->id,
        'starts_at' => '2026-11-02 00:00:00', 'ends_at' => '2026-11-03 00:00:00']);
    $this->postJson(route('planner.book.appointments.store'), $this->payload)->assertUnprocessable();
    $response = $this->postJson(route('planner.book.appointments.store'), $this->payload + ['force_placement' => true, 'force_confirmed' => true])
        ->assertCreated()->assertJsonPath('forced_placement', true);
    $audit = DossierActivity::where('kind', 'appointment_forced')->sole();
    expect($audit->author_id)->toBe($this->actor->id)->and($audit->changed_fields['appointment_id'])->toBe($response->json('appointment_id'))
        ->and($audit->changed_fields['overlapping_appointment_ids'])->toBe([$existing->id])
        ->and($audit->changed_fields['absence_ids'])->toBe([$absence->id])
        ->and($audit->changed_fields['forced_placement'])->toBeTrue()
        ->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse()
        ->and($existing->fresh()->starts_at->format('H:i'))->toBe('14:30');
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('never bypasses authentication, technician status, qualifications or valid input', function (string $case) {
    $payload = $this->payload + ['force_placement' => true, 'force_confirmed' => true];
    if ($case === 'external' || $case === 'technician') {
        $this->actingAs(User::factory()->create(['role' => $case === 'external' ? 3 : 2, 'admin' => false]));
    }
    if ($case === 'deleted tech') {
        $this->tech->delete();
    }
    if ($case === 'qualification') {
        $this->tech->services()->detach();
    }
    if ($case === 'duration') {
        $payload['duration_minutes'] = 0;
    }
    if ($case === 'missing service') {
        unset($payload['manual_appointment']['service_id']);
    }
    $response = $this->postJson(route('planner.book.appointments.store'), $payload);
    if (in_array($case, ['external', 'technician'])) {
        $response->assertForbidden();
    } else {
        $response->assertUnprocessable();
    }
    expect(Appointment::count())->toBe(0)->and(DossierActivity::count())->toBe(0);
})->with(['external', 'technician', 'deleted tech', 'qualification', 'duration', 'missing service']);

it('does not bypass a deleted dossier or a prestation that is already scheduled', function (bool $deleted) {
    $dossier = Dossier::create(['reference' => 'SOURCE-TEST', 'address' => '10 rue de Rivoli', 'postal_code' => '75001', 'city' => 'Paris']);
    $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    if ($deleted) {
        $dossier->delete();
    } else {
        blockingAppointment($this, ['dossier_operation_id' => $operation->id]);
    }
    $this->postJson(route('planner.book.appointments.store'), ['crm_appointment_id' => 'dossier-'.$operation->id,
        'technician_id' => $this->tech->id, 'starts_at' => $this->payload['starts_at'], 'duration_minutes' => 60,
        'force_placement' => true, 'force_confirmed' => true])->assertNotFound();
    expect(DossierActivity::where('kind', 'appointment_forced')->count())->toBe(0);
})->with([true, false]);

it('can replace an existing appointment into an occupied distant slot with a confirmed override', function () {
    $existing = blockingAppointment($this);
    $this->postJson(route('planner.book.appointments.store'), array_replace($this->payload, ['starts_at' => '2026-11-02T10:00:00']))->assertCreated();
    $placed = Appointment::whereKeyNot($existing->id)->sole();
    $this->postJson(route('planner.book.appointments.store'), ['replace_appointment_id' => $placed->id,
        'technician_id' => $this->tech->id, 'starts_at' => $this->payload['starts_at'], 'duration_minutes' => 60,
        'force_placement' => true, 'force_confirmed' => true])->assertOk()->assertJsonPath('appointment_id', $placed->id);
    expect(Appointment::count())->toBe(2)->and($placed->fresh()->starts_at->format('H:i'))->toBe('14:00')
        ->and(DossierActivity::where('kind', 'appointment_forced')->count())->toBe(1);
});

it('allows a nearby overlap while editing an appointment but clears stale GPS when its address changes', function () {
    $other = blockingAppointment($this, ['latitude' => 48.8566, 'longitude' => 2.3522]);
    $edited = blockingAppointment($this, ['starts_at' => '2026-11-02T10:00', 'ends_at' => '2026-11-02T11:00']);
    $payload = ['starts_at' => '2026-11-02T14:00', 'duration_minutes' => 60, 'address' => $edited->address,
        'latitude' => 48.8606, 'longitude' => 2.3522];
    $url = route('planner.tracking.appointments.details', $edited);
    $this->patchJson($url, $payload)->assertOk();
    $payload['address'] = 'Une adresse nouvelle sans coordonnées';
    unset($payload['latitude'], $payload['longitude']);
    $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
});

it('uses a strict one km threshold without rounding to displayed kilometres', function () {
    $rules = app(AppointmentSlotAvailability::class);
    $location = ['address' => 'Lieu source', 'latitude' => 0, 'longitude' => 1];
    foreach ([[0.999, true], [1.0, false], [1.001, false]] as [$distance, $expected]) {
        $other = new Appointment(['address' => 'Destination différente', 'latitude' => rad2deg($distance / 6371), 'longitude' => 1]);
        expect($rules->sameSite($location, $other))->toBe($expected);
    }
});

it('does not enable forced placement on the legacy connector workflow', function () {
    config(['crm.v2_enabled' => false]);
    $this->postJson(route('planner.book.appointments.store'), $this->payload + ['force_placement' => true, 'force_confirmed' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('force_placement');
});

it('lets a manager confirm a force without giving technicians or external users the same permission', function () {
    $this->actingAs(User::factory()->create(['role' => 0, 'admin' => false]));
    blockingAppointment($this);
    $this->postJson(route('planner.book.appointments.store'), $this->payload + ['force_placement' => true, 'force_confirmed' => true])->assertCreated();
});

it('rolls back a forced placement if its mandatory internal audit cannot be recorded', function () {
    blockingAppointment($this);
    $event = 'eloquent.creating: '.DossierActivity::class;
    Event::listen($event, static function (DossierActivity $activity): void {
        if ($activity->kind === 'appointment_forced') {
            throw new RuntimeException('Fixture audit failure.');
        }
    });
    try {
        $this->postJson(route('planner.book.appointments.store'), $this->payload + ['force_placement' => true, 'force_confirmed' => true])->assertUnprocessable();
    } finally {
        Event::forget($event);
    }
    expect(Appointment::count())->toBe(1)->and(Dossier::count())->toBe(0)->and(DossierActivity::count())->toBe(0);
});

it('never treats an empty address or a shared placeholder with no GPS as the same site', function () {
    $rules = app(AppointmentSlotAvailability::class);
    foreach (['', 'Paris', 'Non renseigné', 'Adresse inconnue'] as $address) {
        expect($rules->sameSite(['address' => $address], new Appointment(['address' => $address])))->toBeFalse();
    }
});
