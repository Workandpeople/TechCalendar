<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierRegistry;
use App\Services\MapboxAddressGeocoder;
use App\Services\SidebarNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'crm.v2_enabled' => true,
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'test-token',
        'services.coffrac.ignored_references' => [],
    ]);
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    Mail::fake();
});

it('renders booking without collection connectors or remote calls in v2', function (bool $configured) {
    if (! $configured) {
        config(['services.coffrac.api_url' => null, 'services.coffrac.api_token' => null]);
    }

    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => 'local-request',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'customer_first_name' => 'Camille',
        'customer_last_name' => 'ANCIENNE COPIE',
        'address' => '10 rue de Lyon, 75012 Paris',
        'department_code' => '75',
        'latitude' => null,
        'longitude' => null,
    ]);

    $dossier = Dossier::create(['reference' => 'NATIF-1', 'first_name' => 'Camille', 'last_name' => 'LOCAL', 'address' => '10 rue de Lyon', 'postal_code' => '75012', 'city' => 'Paris']);
    $operation = $dossier->operations()->create([]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();

    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($planner)->get(route('planner.book'))
        ->assertOk()
        ->assertSee('LOCAL Camille')
        ->assertDontSee('ANCIENNE COPIE')
        ->assertSee('RDV à placer')
        ->assertSee('id="booking-source-switch"', false)
        ->assertSee('id="booking-lot-source"', false)
        ->assertSee('RDV manuel')
        ->assertSee('Global+')
        ->assertSee('const bookingConnectorsEnabled = false;', false)
        ->assertDontSee('id="booking-external-controls"', false)
        ->assertDontSee('Actualiser Coffrac')
        ->assertDontSee('Actualiser connecteur 2')
        ->assertDontSee('Actualiser connecteur 3')
        ->assertDontSee('RDV externes à placer');

    $this->actingAs($planner)->getJson(route('planner.book.crm-appointments.index'))
        ->assertOk()
        ->assertJsonCount(1, 'appointments')
        ->assertJsonPath('appointments.0.id', 'dossier-'.$operation->id)
        ->assertJsonPath('appointments.0.latitude', null)
        ->assertJsonPath('coffrac_api_status', null)
        ->assertJsonPath('external_sources', []);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(ExternalApiSync::query()->count())->toBe(0);
})->with([true, false]);

it('rejects obsolete collection requests from stale tabs without queuing a job in v2', function () {
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);

    $this->actingAs($planner)->postJson(route('planner.book.crm-appointments.refresh'))
        ->assertStatus(410)
        ->assertJsonPath('message', 'La collecte par connecteur est désactivée en V2.');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(ExternalApiSync::query()->count())->toBe(0);
});

function nativeBookingFixture(string $status = 'PRISE DE RDV'): array
{
    $actor = User::factory()->create(['role' => 1]);
    $technician = User::factory()->create(['role' => 2]);
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Prestation native', 'average_duration_minutes' => 60]);
    $technician->services()->attach($service);
    $dossier = Dossier::create(['reference' => 'BOOKING-TEST', 'first_name' => 'Camille', 'last_name' => 'TEST', 'phone' => '0600000000', 'address' => '1 rue du Test', 'city' => 'Paris', 'postal_code' => '75001', 'source' => 'coffrac']);
    $operation = $dossier->operations()->create(['service_id' => $service->id]);
    $operation->forceFill(['legacy_status' => $status])->save();
    DB::table('legacy_records')->insert(['source_table' => 'dossiers', 'source_id' => 123, 'dossier_id' => $dossier->id,
        'payload' => json_encode(['adress' => '1 rue du Test', 'ville' => 'Paris', 'zip' => '75001', 'latitude' => 48.85, 'longitude' => 2.35])]);
    $payload = ['crm_appointment_id' => 'dossier-'.$operation->id, 'technician_id' => $technician->id, 'starts_at' => now()->addWeek()->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 60];

    return compact('actor', 'technician', 'service', 'dossier', 'operation', 'payload');
}

it('lists only pending operations with their own services comments and documents', function () {
    extract(nativeBookingFixture());
    $dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'Appeler avant la visite']);
    $document = $dossier->documents()->create(['name' => 'Plan', 'path' => 'crm/test.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
    foreach (['RDV ATTENTE VISITE', 'PROBLÈME RENDEZ-VOUS', 'DOSSIERS FACTURER', 'DOSSIER ANNULE', null] as $status) {
        $other = $dossier->operations()->create(['service_id' => $service->id]);
        $other->forceFill(['legacy_status' => $status])->save();
    }
    $this->actingAs($actor)->getJson(route('planner.book.crm-appointments.index'))->assertOk()
        ->assertJsonCount(1, 'appointments')->assertJsonPath('appointments.0.dossier_operation_id', $operation->id)
        ->assertJsonPath('appointments.0.service.id', $service->id)->assertJsonPath('appointments.0.latitude', 48.85)
        ->assertJsonPath('appointments.0.comments.0.comment', 'Appeler avant la visite')
        ->assertJsonPath('appointments.0.documents.0.url', route('v2.documents.download', $document));
    $this->get(route('planner.dashboard'))->assertOk()->assertSee('TEST Camille')->assertSee('dossier-'.$operation->id);
    expect(app(SidebarNotifications::class)->forUser($actor)['pending'])->toBe(1);
    Http::assertNothingSent();
});

it('books a native pending dossier atomically without calling Coffrac and prevents a second booking', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor)->postJson(route('planner.book.appointments.store'), $payload)->assertCreated();
    $appointment = Appointment::sole();
    expect($appointment->dossier_operation_id)->toBe($operation->id)
        ->and($appointment->external_source)->toBeNull()
        ->and($operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
    $this->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(0, 'appointments');
    expect(app(SidebarNotifications::class)->forUser($actor)['pending'])->toBe(0);
    $this->postJson(route('planner.book.appointments.store'), [...$payload, 'starts_at' => now()->addWeeks(2)->setTime(10, 0)->toIso8601String()])->assertNotFound();
    expect(Appointment::count())->toBe(1);
    Http::assertNothingSent();
});

it('does not offer an already scheduled operation even if its old status is still pending', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor)->postJson(route('planner.book.appointments.store'), $payload)->assertCreated();
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(0, 'appointments');
    $this->postJson(route('planner.book.analyze'), ['crm_appointment_id' => $payload['crm_appointment_id']])->assertNotFound();
    Http::assertNothingSent();
});

it('keeps a pending dossier without coordinates visible and geocodes only the selected dossier', function () {
    extract(nativeBookingFixture());
    DB::table('legacy_records')->delete();
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->with('1 rue du Test, 75001 Paris')->andReturn(['latitude' => 48.85, 'longitude' => 2.35]);
    $this->actingAs($actor)->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(1, 'appointments')->assertJsonPath('appointments.0.latitude', null);
    $this->postJson(route('planner.book.analyze'), ['crm_appointment_id' => $payload['crm_appointment_id']])->assertOk()->assertJsonPath('crm_appointment.latitude', 48.85);
    Http::assertNothingSent();
});

it('puts newly created native dossiers in the pending booking list', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor);
    $created = app(DossierRegistry::class)->create($actor, [
        'reference' => 'NEW-NATIVE', 'company_name' => 'Bénéficiaire natif', 'address' => '2 rue du Test', 'city' => 'Paris', 'service_ids' => [$service->id],
    ]);
    expect($created->operations()->sole()->legacy_status)->toBe('PRISE DE RDV');
    $this->getJson(route('planner.book.crm-appointments.index'))->assertOk()->assertJsonCount(2, 'appointments')
        ->assertJsonPath('appointments.0.dossier_id', $created->id);
    Http::assertNothingSent();
});

it('updates the native address and comments without overwriting the comment history', function () {
    extract(nativeBookingFixture());
    $dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'Ancien commentaire']);
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->with('2 rue du Test, 69001 Lyon')
        ->andReturn(['latitude' => 45.76, 'longitude' => 4.83, 'formatted_address' => '2 rue du Test, 69001 Lyon, France']);
    $this->actingAs($actor)->patchJson(route('planner.book.crm-appointments.update', $payload['crm_appointment_id']), [
        'address' => '2 rue du Test, 69001 Lyon', 'comment' => 'Nouveau commentaire', 'service_id' => $service->id,
    ])->assertOk()->assertJsonPath('appointment.latitude', 45.76)->assertJsonPath('appointment.city', 'Lyon')->assertJsonCount(2, 'appointment.comments');
    expect($dossier->fresh()->address)->toBe('2 rue du Test')->and($dossier->fresh()->postal_code)->toBe('69001');
    Http::assertNothingSent();
});

it('reports a native pending problem with mandatory comments and callback details', function () {
    extract(nativeBookingFixture());
    $url = route('planner.book.crm-appointments.problem', $payload['crm_appointment_id']);
    $this->actingAs($actor)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['comment', 'problem_type']);
    $this->postJson($url, ['comment' => 'Client indisponible', 'problem_type' => 'Demande de rapl'])->assertUnprocessable()->assertJsonValidationErrors(['recall_date', 'recall_time']);
    $this->postJson($url, ['comment' => 'Client indisponible', 'problem_type' => 'Demande de rapl', 'recall_date' => '2026-10-20', 'recall_time' => '10:30'])
        ->assertOk()->assertJsonCount(0, 'appointments');
    expect($operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($dossier->comments()->first()->body)->toContain('Client indisponible')
        ->and($dossier->activities()->where('kind', 'status_changed')->first()->body)->toContain('2026-10-20', '10:30');
    Http::assertNothingSent();
});

it('rolls back a new appointment if the dossier changes before the final lock', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor);
    $booking = app(DossierBooking::class);
    $request = $booking->find($actor, $payload['crm_appointment_id']);
    $dossier->increment('version');
    try {
        DB::transaction(function () use ($booking, $actor, $technician, $service, $request): void {
            $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $technician->id, 'created_by' => $actor->id,
                'customer_first_name' => 'Camille', 'customer_last_name' => 'TEST', 'customer_phone' => '', 'address' => 'Paris',
                'starts_at' => now(), 'ends_at' => now()->addHour(), 'duration_minutes' => 60, 'status' => 'scheduled']);
            $booking->markPlaced($actor, $appointment, $request);
        });
        $this->fail('Stale dossier must not be booked.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('crm_appointment_id');
    }
    expect(Appointment::count())->toBe(0)->and($operation->fresh()->legacy_status)->toBe('PRISE DE RDV');
});

it('preserves booking authorization in v2', function () {
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);

    $this->actingAs($technician)->get(route('planner.book'))->assertForbidden();
    $this->getJson(route('planner.book.crm-appointments.index'))->assertForbidden();
    $this->postJson(route('planner.book.crm-appointments.refresh'))->assertForbidden();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps the legacy booking connectors available outside v2', function () {
    config(['crm.v2_enabled' => false]);
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);

    $this->actingAs($planner)->get(route('planner.book'))
        ->assertOk()
        ->assertSee('id="booking-external-controls"', false)
        ->assertSee('Actualiser Coffrac')
        ->assertSee('Actualiser connecteur 2')
        ->assertSee('Actualiser connecteur 3')
        ->assertSee('const bookingConnectorsEnabled = true;', false);
});
