<?php

use App\Mail\TechnicianAppointmentNotificationMail;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Dossier;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierLocation;
use App\Services\Crm\DossierRegistry;
use App\Services\MapboxAddressGeocoder;
use App\Services\SidebarNotifications;
use Carbon\Carbon;
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
        ->assertSee('id="booking-source-tabs"', false)
        ->assertSee('RDV Cofrac')
        ->assertSee('RDV lots')
        ->assertSee('RDV MAR')
        ->assertSee('Demandes MAR à placer')
        ->assertSee('data-mymar-pending', false)
        ->assertSee("Le placement et le signalement d'un problème depuis cet espace ne sont pas encore activés.", false)
        ->assertDontSee('id="booking-source-switch"', false)
        ->assertSee('id="booking-lot-source"', false)
        ->assertSee('RDV manuel')
        ->assertSee('Global+')
        ->assertSee('const bookingConnectorsEnabled = false;', false)
        ->assertDontSee('id="booking-external-controls"', false)
        ->assertDontSee('Actualiser Cofrac')
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
    $dossier->forceFill(['inspection_geocode' => DossierLocation::metadata($dossier, ['latitude' => 48.85, 'longitude' => 2.35])])->save();
    $operation = $dossier->operations()->create(['service_id' => $service->id]);
    $operation->forceFill(['legacy_status' => $status])->save();
    DB::table('legacy_records')->insert(['source_table' => 'dossiers', 'source_id' => 123, 'dossier_id' => $dossier->id,
        'payload' => json_encode(['adress' => '1 rue du Test', 'ville' => 'Paris', 'zip' => '75001', 'latitude' => 48.85, 'longitude' => 2.35])]);
    $payload = ['crm_appointment_id' => 'dossier-'.$operation->id, 'technician_id' => $technician->id, 'starts_at' => now()->addWeek()->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 60];

    return compact('actor', 'technician', 'service', 'dossier', 'operation', 'payload');
}

it('renders bold phones full postal codes and comment triggers only on the name', function () {
    extract(nativeBookingFixture());
    $dossier->forceFill(['postal_code' => '01300'])->save();
    $response = $this->actingAs($actor)->get(route('planner.book'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    $row = $xpath->query('//*[@data-crm-id="dossier-'.$operation->id.'"]')->item(0);
    expect($row)->not->toBeNull();
    expect($xpath->query('.//*[@data-crm-comments-trigger]', $row)->length)->toBe(1)
        ->and($xpath->query('.//*[contains(@class,"gc-booking-request-phone") and @data-crm-comments-trigger]', $row)->length)->toBe(0)
        ->and($xpath->query('.//*[contains(@class,"gc-booking-request-phone") and contains(@class,"font-bold")]', $row)->item(0)->textContent)->toBe('0600000000')
        ->and($xpath->query('.//*[contains(@class,"gc-booking-request-postal")]', $row)->item(0)->textContent)->toBe('CP 01300')
        ->and($xpath->query('.//*[contains(@class,"gc-booking-request-address") and @data-crm-comments-trigger]', $row)->length)->toBe(0);
    Http::assertNothingSent();
});

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

it('lists an already scheduled pending operation but still refuses creating a duplicate appointment', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor)->postJson(route('planner.book.appointments.store'), $payload)->assertCreated();
    $operation->refresh()->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(1, 'appointments')
        ->assertJsonPath('appointments.0.replace_appointment_id', Appointment::sole()->id);
    $this->postJson(route('planner.book.analyze'), ['crm_appointment_id' => $payload['crm_appointment_id']])->assertNotFound();
    Http::assertNothingSent();
});

it('shows pending operations with an existing appointment as replacements without allowing duplicate placement', function (bool $past) {
    extract(nativeBookingFixture());
    $this->actingAs($actor)->postJson(route('planner.book.appointments.store'), $payload)->assertCreated();
    $appointment = Appointment::sole();
    if ($past) {
        $appointment->forceFill(['starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHour()])->save();
    }
    $this->patch(route('v2.dossiers.operations.status', [$dossier, $operation]), [
        'version' => $dossier->fresh()->version, 'status' => 'pending',
    ])->assertSessionHasNoErrors();
    $this->get(route('planner.book'))->assertOk()
        ->assertViewHas('crmAppointments', fn ($rows) => $rows->count() === 1 && $rows->first()['dossier_operation_id'] === $operation->id)
        ->assertSee('Replacer le RDV')->assertSee('TEST Camille')->assertSee('1 dossier(s) · 1 prestation(s)')
        ->assertSee(route('planner.appointments.modify', ['replace_appointment_id' => $appointment->id]), false);
    $this->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(1, 'appointments')
        ->assertJsonPath('appointments.0.replace_appointment_id', $appointment->id);
    expect(app(SidebarNotifications::class)->forUser($actor)['pending'])->toBe(1);
    $this->postJson(route('planner.book.analyze'), ['crm_appointment_id' => $payload['crm_appointment_id']])->assertNotFound();
    $this->postJson(route('planner.book.analyze'), ['replace_appointment_id' => $appointment->id])->assertOk();
    $this->get(route('planner.appointments.modify', ['replace_appointment_id' => $appointment->id]))->assertOk()
        ->assertViewHas('crmAppointments', fn ($rows) => $rows->isEmpty());
    $this->get(route('planner.dashboard'))->assertOk()->assertSee(route('planner.appointments.modify', ['replace_appointment_id' => $appointment->id]), false);
    expect(Appointment::count())->toBe(1)->and($operation->fresh()->legacy_status)->toBe('PRISE DE RDV');
    $this->postJson(route('planner.book.appointments.store'), [
        'replace_appointment_id' => $appointment->id, 'technician_id' => $technician->id,
        'starts_at' => now()->addWeeks(2)->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 60,
    ])->assertOk();
    expect(Appointment::sole()->id)->toBe($appointment->id)
        ->and($operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and(app(SidebarNotifications::class)->forUser($actor)['pending'])->toBe(0);
    $this->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(0, 'appointments');
    Http::assertNothingSent();
})->with([false, true]);

it('lists each pending operation once with only its latest live scheduled appointment and excludes deleted dossiers', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor);
    foreach (range(1, 13) as $index) {
        $row = $dossier->operations()->create(['service_id' => $service->id]);
        $row->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
        foreach ([10, 12] as $hour) {
            $row->appointments()->create(['service_id' => $service->id, 'technician_id' => $technician->id, 'created_by' => $actor->id,
                'customer_first_name' => 'Camille', 'customer_last_name' => 'TEST',
                'address' => 'Paris', 'customer_phone' => '', 'duration_minutes' => 60, 'starts_at' => now()->addWeek()->setTime($hour, 0),
                'ends_at' => now()->addWeek()->setTime($hour + 1, 0), 'status' => Appointment::STATUS_SCHEDULED]);
        }
    }
    $this->get(route('planner.book'))->assertOk()->assertSee('1 dossier(s) · 14 prestation(s)')
        ->assertViewHas('crmAppointments', fn ($rows) => $rows->count() === 14
            && $rows->pluck('dossier_operation_id')->unique()->count() === 14
            && $rows->whereNotNull('replace_appointment_id')->count() === 13
            && $rows->whereNotNull('replace_appointment_id')->every(fn ($row) => Carbon::parse($row['existing_appointment_at'])->hour === 12));
    $dossier->delete();
    expect(app(DossierBooking::class)->pending($actor))->toBeEmpty();
    Http::assertNothingSent();
});

it('uses the same pending dossier count in categories the sidebar and the booking queue', function () {
    extract(nativeBookingFixture());
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->andReturn(['latitude' => 48.85, 'longitude' => 2.35, 'mapbox_id' => 'address.fixture']);
    $this->actingAs($actor);
    foreach (range(1, 7) as $index) {
        $created = app(DossierRegistry::class)->create($actor, [
            'reference' => 'COUNT-'.$index, 'company_name' => 'Client '.$index,
            'address' => 'Rue Test', 'city' => 'Paris', 'mapbox_id' => 'address.fixture', 'service_ids' => [$service->id],
        ]);
        if ($index <= 2) {
            $created->operations()->first()->appointments()->create([
                'service_id' => $service->id, 'technician_id' => $technician->id, 'created_by' => $actor->id,
                'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '',
                'address' => 'Paris', 'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED,
                'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            ]);
        }
    }
    $additional = $dossier->operations()->create(['service_id' => $service->id]);
    $additional->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $categories = app(DossierRegistry::class)->categories($actor, []);
    expect($categories['pending']['count'])->toBe(8)
        ->and(app(SidebarNotifications::class)->forUser($actor)['pending'])->toBe(8);
    $this->getJson(route('planner.book.crm-appointments.index'))->assertOk()->assertJsonCount(9, 'appointments');
    $this->get(route('planner.book'))->assertOk()->assertSee('8 dossier(s) · 9 prestation(s)');
    Http::assertNothingSent();
});

it('keeps a pending dossier without coordinates visible and geocodes only the selected dossier', function () {
    extract(nativeBookingFixture());
    $dossier->forceFill(['inspection_geocode' => null])->save();
    DB::table('legacy_records')->delete();
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->with('1 rue du Test, 75001 Paris')->andReturn(['latitude' => 48.85, 'longitude' => 2.35]);
    $this->actingAs($actor)->getJson(route('planner.book.crm-appointments.index'))->assertJsonCount(1, 'appointments')->assertJsonPath('appointments.0.latitude', null);
    $this->postJson(route('planner.book.analyze'), ['crm_appointment_id' => $payload['crm_appointment_id']])->assertOk()->assertJsonPath('crm_appointment.latitude', 48.85);
    Http::assertNothingSent();
});

it('puts newly created native dossiers in the pending booking list', function () {
    extract(nativeBookingFixture());
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->andReturn(['latitude' => 48.85, 'longitude' => 2.35, 'mapbox_id' => 'address.fixture']);
    $this->actingAs($actor);
    $created = app(DossierRegistry::class)->create($actor, [
        'reference' => 'NEW-NATIVE', 'company_name' => 'Bénéficiaire natif', 'address' => '2 rue du Test', 'city' => 'Paris', 'mapbox_id' => 'address.fixture', 'service_ids' => [$service->id],
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
        ->assertSee('Actualiser Cofrac')
        ->assertSee('Actualiser connecteur 2')
        ->assertSee('Actualiser connecteur 3')
        ->assertSee('const bookingConnectorsEnabled = true;', false);
});

it('provides a separate read-only dossier recap with the selected operation and both organisations', function () {
    extract(nativeBookingFixture());
    $client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'BOOKING-CLIENT', 'kind' => 'client', 'name' => 'Client distinct']);
    $delegate = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'BOOKING-DELEGATE', 'kind' => 'delegataire', 'name' => 'Délégataire distinct']);
    $dossier->update(['client_id' => $client->id, 'delegataire_id' => $delegate->id,
        'email' => 'beneficiaire@example.invalid', 'phone' => '609285333', 'site_name' => 'Site de test', 'installer_name' => 'Installateur test']);
    $dossier->forceFill(['legacy_lot_name' => 'LOT TEST'])->save();
    $this->actingAs($actor)->getJson(route('planner.book.crm-appointments.index'))->assertOk()
        ->assertJsonPath('appointments.0.dossier_summary.beneficiary', 'Camille TEST')
        ->assertJsonPath('appointments.0.dossier_summary.phone_numbers.0.label', '06 09 28 53 33')
        ->assertJsonPath('appointments.0.dossier_summary.client', 'Client distinct')
        ->assertJsonPath('appointments.0.dossier_summary.delegataire', 'Délégataire distinct')
        ->assertJsonPath('appointments.0.dossier_summary.service', 'Prestation native')
        ->assertJsonPath('appointments.0.dossier_summary.address', '1 rue du Test, 75001 Paris')
        ->assertJsonPath('appointments.0.dossier_summary.dossier_url', route('v2.dossiers.show', $dossier, absolute: false));
    $response = $this->get(route('planner.book'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@id="booking-results-anchor"]//section[@id="booking-dossier-summary"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="booking-dossier-summary"]//form')->length)->toBe(0)
        ->and($dossier->fresh()->version)->toBe(1);
    Http::assertNothingSent();
});

it('uses manually entered contacts in the recap and performs no queries for already loaded dossiers', function () {
    extract(nativeBookingFixture());
    $dossier->update(['client_contact' => ['name' => 'Client saisi à la main'],
        'delegataire_contact' => ['company_name' => 'Délégataire saisi à la main']]);
    $operation->load(['service', 'dossier.client', 'dossier.delegataire']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $summary = app(DossierBooking::class)->summary($operation);
    expect($summary['client'])->toBe('Client saisi à la main')->and($summary['delegataire'])->toBe('Délégataire saisi à la main')
        ->and(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();
    Http::assertNothingSent();
});

it('keeps the native dossier recap available when replacing an existing booking', function () {
    extract(nativeBookingFixture());
    $this->actingAs($actor)->postJson(route('planner.book.appointments.store'), $payload)->assertCreated();
    $appointment = Appointment::sole();
    $this->postJson(route('planner.book.analyze'), ['replace_appointment_id' => $appointment->id])->assertOk()
        ->assertJsonPath('crm_appointment.dossier_summary.beneficiary', 'Camille TEST')
        ->assertJsonPath('crm_appointment.dossier_summary.status', 'RDV en attente de visite')
        ->assertJsonPath('crm_appointment.dossier_summary.dossier_url', route('v2.dossiers.show', $dossier, absolute: false));
    expect(Appointment::count())->toBe(1);
    Http::assertNothingSent();
});

it('does not expose the booking recap to a technician without planning access', function () {
    extract(nativeBookingFixture());
    $this->actingAs($technician)->getJson(route('planner.book.crm-appointments.index'))->assertForbidden();
    Http::assertNothingSent();
});

it('never suggests weekends in v2 but keeps placed saturday appointments visible', function () {
    Carbon::setTestNow('2026-10-29 09:00:00');
    try {
        config(['services.mapbox.token' => null]);
        extract(nativeBookingFixture());
        $technician->update(['address' => '1 rue du Test, 75001 Paris', 'department_code' => '75',
            'latitude' => 48.85, 'longitude' => 2.35, 'day_start_time' => '07:00', 'day_end_time' => '21:00']);
        Department::updateOrCreate(['code' => '75'], ['name' => 'Paris']);
        $technician->departments()->attach('75');
        $saturday = Appointment::create(['service_id' => $service->id, 'technician_id' => $technician->id,
            'created_by' => $actor->id, 'customer_first_name' => 'RDV', 'customer_last_name' => 'Samedi',
            'customer_phone' => '0600000000', 'address' => '1 rue du Test, 75001 Paris', 'latitude' => 48.85, 'longitude' => 2.35,
            'starts_at' => '2026-10-31 10:00:00', 'ends_at' => '2026-10-31 11:00:00', 'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
        $request = ['crm_appointment_id' => 'dossier-'.$operation->id];
        $response = $this->actingAs($actor)->postJson(route('planner.book.analyze'), $request)->assertOk();
        expect($response->json('suggestions'))->not->toBeEmpty();
        foreach ($response->json('suggestions') as $suggestion) {
            expect(Carbon::parse($suggestion['start'])->isWeekend())->toBeFalse();
        }
        $response = $this->postJson(route('planner.book.calendar-window'), [...$request,
            'start' => '2026-10-26', 'end' => '2026-11-02', 'technician_ids' => [$technician->id]])->assertOk();
        expect($response->json('suggestions'))->not->toBeEmpty();
        foreach ($response->json('suggestions') as $suggestion) {
            expect(Carbon::parse($suggestion['start'])->isWeekend())->toBeFalse();
        }
        expect(collect($response->json('events'))->pluck('id')->map(fn ($id) => (int) $id)->all())->toContain($saturday->id);
        $this->postJson(route('planner.book.calendar-window'), [...$request,
            'start' => '2026-10-31', 'end' => '2026-11-02', 'technician_ids' => [$technician->id]])->assertOk()
            ->assertJsonCount(0, 'suggestions')->assertJsonCount(1, 'events');
        expect($saturday->fresh()->starts_at->isSaturday())->toBeTrue();
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    } finally {
        Carbon::setTestNow();
    }
});

it('still allows explicit manual placement of a native dossier on saturday', function () {
    Carbon::setTestNow('2026-10-29 09:00:00');
    try {
        extract(nativeBookingFixture());
        $payload['starts_at'] = '2026-10-31T10:00:00+01:00';
        $this->actingAs($actor)->postJson(route('planner.book.appointments.store'), $payload)->assertCreated();
        expect(Appointment::sole()->starts_at->isSaturday())->toBeTrue();
        Http::assertNothingSent();
        Mail::assertQueued(TechnicianAppointmentNotificationMail::class);
        Mail::assertNothingSent();
    } finally {
        Carbon::setTestNow();
    }
});
