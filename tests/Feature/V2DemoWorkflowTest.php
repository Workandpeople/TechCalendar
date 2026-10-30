<?php

use App\Jobs\PushLotAppointmentDocumentToCoffracJob;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentDocumentSerializer;
use App\Services\CoffracAppointmentService;
use App\Services\Crm\AppointmentComments;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.coffrac.api_url' => 'https://coffrac.test/api', 'services.coffrac.api_token' => 'unused']);
    Http::preventStrayRequests();
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->andReturn(['latitude' => 48.85, 'longitude' => 2.35]);
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['name' => 'Contrôle test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->slot = ['technician_id' => $this->tech->id, 'starts_at' => now()->addWeek()->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 60];
    $this->actingAs($this->actor);
});

function demoLotRow($test): LotAppointment
{
    $delegataire = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '123', 'name' => 'Délégataire démo', 'is_active' => true]);
    $lot = Lot::create(['name' => 'Lot démo', 'type' => Lot::TYPE_HYBRID_LOCATION_CONTACT, 'service_id' => $test->service->id,
        'delegataire' => $delegataire->name, 'status' => Lot::STATUS_IN_PROGRESS, 'created_by' => $test->actor->id]);

    return $lot->appointments()->create(['customer_name' => 'Client démo', 'company_name' => 'Entreprise bénéficiaire',
        'site_name' => 'Bâtiment A', 'customer_phone' => '0600000000', 'customer_email' => 'client@example.test',
        'installer_name' => 'Installateur test', 'installer_siren' => '123456789', 'internal_reference' => 'INTERNE-TEST',
        'address' => '10 rue du Test', 'postal_code' => '75001', 'city' => 'Paris', 'department_code' => '75',
        'latitude' => 48.85, 'longitude' => 2.35, 'status' => LotAppointment::STATUS_PENDING]);
}

it('places a physical lot in the native registry with its identities and no Coffrac call', function () {
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $appointment = Appointment::sole();
    $dossier = Dossier::sole();
    expect($dossier->source)->toBe('lot')->and($dossier->source_reference)->toBe((string) $row->id)
        ->and($dossier->company_name)->toBe('Entreprise bénéficiaire')->and($dossier->site_name)->toBe('Bâtiment A')
        ->and($dossier->installer_siren)->toBe('123456789')->and($dossier->legacy_lot_name)->toBe('Lot démo')
        ->and($dossier->delegataire->name)->toBe('Délégataire démo')
        ->and($appointment->dossierOperation->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and($row->fresh()->appointment_id)->toBe($appointment->id)->and($row->fresh()->processing_mode)->toBe('physical');
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertNotFound();
    expect(Appointment::count())->toBe(1)->and(Dossier::count())->toBe(1);
    expect($dossier->fresh()->latestStatusChange->changed_fields['status'])->toBe('RDV ATTENTE VISITE');
    Http::assertNothingSent();
});

it('rejects archived excluded and do not place lot rows without partial records', function (string $state) {
    $row = demoLotRow($this);
    match ($state) {
        'archived' => $row->lot->update(['status' => Lot::STATUS_ARCHIVED]),
        'excluded' => $row->update(['excluded_from_lot_stats' => true]),
        'not_placed' => $row->update(['status' => LotAppointment::STATUS_NOT_PLACED]),
    };
    $response = $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id]);
    expect($response->status())->toBeIn([404, 422]);
    expect(Appointment::count())->toBe(0)->and(Dossier::count())->toBe(0);
    Http::assertNothingSent();
})->with(['archived', 'excluded', 'not_placed']);

it('creates a native dossier for a manual appointment and reuses it when rescheduled', function () {
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'manual_appointment' => [
        'first_name' => 'Camille', 'last_name' => 'Test', 'phone' => '0600000000', 'address' => '10 rue du Test, 75001 Paris',
        'department_code' => '75', 'latitude' => 48.85, 'longitude' => 2.35, 'service_id' => $this->service->id,
    ]])->assertCreated();
    $appointment = Appointment::sole();
    expect($appointment->dossierOperation)->not->toBeNull()->and(Dossier::sole()->postal_code)->toBe('75001');
    $this->postJson(route('planner.tracking.appointments.problem', $appointment), [
        'comment' => 'Client absent', 'problem_type' => 'Demande de rapl', 'recall_date' => '2026-11-01', 'recall_time' => '14:00',
    ])->assertOk();
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'replace_appointment_id' => $appointment->id,
        'starts_at' => now()->addWeeks(2)->setTime(10, 0)->toIso8601String()])->assertSuccessful();
    expect(Appointment::count())->toBe(1)->and(Dossier::count())->toBe(1)
        ->and($appointment->fresh()->status)->toBe('scheduled')
        ->and($appointment->fresh()->dossierOperation->legacy_status)->toBe('RDV ATTENTE VISITE');
    expect(Dossier::sole()->activities()->where('kind', 'status_changed')->first()->body)->toContain('2026-11-01', '14:00');
    expect(Dossier::sole()->latestStatusChange->changed_fields)->toMatchArray([
        'previous_status' => 'PROBLÈME RENDEZ-VOUS', 'status' => 'RDV ATTENTE VISITE',
    ]);
    Http::assertNothingSent();
});

it('reopens an unplaced problem with a reason and checks stale state and scope', function () {
    $dossier = Dossier::create(['reference' => 'DEMO-PROBLEM', 'address' => 'Paris', 'city' => 'Paris', 'last_name' => 'Test']);
    $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
    $operation->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $url = route('v2.dossiers.operations.reopen', [$dossier, $operation]);
    $this->postJson($url, ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson($url, ['version' => 99, 'comment' => 'Reprise'])->assertUnprocessable();
    $this->get(route('v2.dossiers.show', $dossier))->assertOk()->assertSee('Reprendre la prise de RDV');
    $this->post($url, ['version' => 1, 'comment' => 'Client de nouveau disponible'])->assertRedirect()->assertSessionHasNoErrors();
    expect($operation->fresh()->legacy_status)->toBe('PRISE DE RDV');
    $this->get(route('v2.dossiers.show', $dossier))->assertSee(route('planner.book', ['crm_appointment_id' => 'dossier-'.$operation->id]), false);
    $this->postJson($url, ['version' => 2, 'comment' => 'Reprise'])->assertUnprocessable();
    $other = Dossier::create(['reference' => 'OTHER-DEMO', 'address' => 'Paris', 'city' => 'Paris']);
    $this->postJson(route('v2.dossiers.operations.reopen', [$other, $operation]), ['version' => 1, 'comment' => 'Reprise'])->assertNotFound();
    $this->actingAs($this->tech)->postJson($url, ['version' => 2, 'comment' => 'Reprise'])->assertForbidden();
    Http::assertNothingSent();
});

it('does not overwrite a completed control when reporting a problem or rescheduling', function () {
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $appointment = Appointment::sole();
    $operation = $appointment->dossierOperation;
    $operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->postJson(route('planner.tracking.appointments.problem', $appointment), [
        'comment' => 'Erreur à refuser', 'problem_type' => 'Demande de rapl', 'recall_date' => '2026-11-01', 'recall_time' => '14:00',
    ])->assertUnprocessable();
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'replace_appointment_id' => $appointment->id,
        'starts_at' => now()->addWeeks(2)->toIso8601String()])->assertUnprocessable();
    expect($operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')->and($appointment->fresh()->status)->toBe('scheduled');
    Http::assertNothingSent();
});

it('searches by address phone and postcode and displays the history chronologically', function () {
    $dossier = Dossier::create(['reference' => 'DEMO-SEARCH', 'last_name' => 'Client', 'address' => '10 avenue des Tests', 'city' => 'Paris', 'postal_code' => '75012', 'phone' => '0601020304']);
    $dossier->activities()->create(['kind' => 'comment', 'body' => 'COMMENT RECENT'])->forceFill(['created_at' => now()])->save();
    $dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'COMMENT ANCIEN'])->forceFill(['created_at' => now()->subYear()])->save();
    foreach (['avenue des Tests', '75012', '0601020304'] as $q) {
        $this->getJson(route('v2.dossiers.index', ['q' => $q]))->assertOk()->assertJsonPath('total', 1);
    }
    $this->get(route('v2.dossiers.show', $dossier))->assertSeeInOrder(['COMMENT RECENT', 'COMMENT ANCIEN']);
});

it('lists native and linked lot documents without duplicates or obsolete remote URLs', function () {
    $row = demoLotRow($this);
    $file = $row->documents()->create(['name' => 'Plan du site', 'original_name' => 'plan.pdf', 'disk' => 'local',
        'path' => 'lot-appointment-documents/'.$row->id.'/plan.pdf', 'mime' => 'application/pdf', 'size' => 100,
        'is_private' => true, 'status' => 'pending']);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $appointment = Appointment::sole();
    $file->update(['appointment_id' => $appointment->id]);
    $dossier = Dossier::sole();
    $document = $dossier->documents()->create(['name' => 'Document natif', 'path' => 'private/test.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
    $appointment->update(['external_source' => 'coffrac', 'external_payload' => ['documents' => [['url' => 'https://coffrac.test/obsolete.pdf']]]]);
    $serializer = app(AppointmentDocumentSerializer::class);
    $documents = $serializer->forAppointments(collect([$appointment]))[$appointment->id];
    expect($documents)->toHaveCount(2)->and(array_column($documents, 'url'))->toBe([
        route('v2.documents.download', $document), route('v2.dossiers.lot-documents.download', [$dossier, $file->id]),
    ])->and(json_encode($documents))->not->toContain('https://coffrac.test', 'private/test.pdf');
    $this->actingAs($this->tech);
    expect($serializer->forAppointment($appointment))->toBe([]);
    $this->get(route('v2.documents.download', $document))->assertForbidden();
    $this->get(route('v2.dossiers.lot-documents.download', [$dossier, $file->id]))->assertForbidden();
    Http::assertNothingSent();
});

it('keeps appointment comments in the dossier history and calendar without repeating identical saves', function () {
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $appointment = Appointment::sole();
    $dossier = Dossier::sole();
    $url = route('planner.tracking.appointments.comment', $appointment);
    $this->patchJson($url, ['comment' => 'Nouveau commentaire RDV'])->assertOk();
    $this->patchJson($url, ['comment' => 'Nouveau commentaire RDV'])->assertOk();
    expect($dossier->comments()->count())->toBe(1);
    $result = app(AppointmentComments::class)->forAppointments(collect([$appointment]));
    expect($result[$appointment->id][0]['body'])->toBe('Nouveau commentaire RDV');
    $this->postJson(route('planner.tracking.search'), ['q' => 'Entreprise bénéficiaire'])->assertOk()
        ->assertJsonPath('appointments.0.extendedProps.comments.0.body', 'Nouveau commentaire RDV');
    $this->patchJson($url, ['comment' => null])->assertOk();
    expect($dossier->comments()->count())->toBe(1)->and($appointment->fresh()->comment)->toBeNull();
    Http::assertNothingSent();
});

it('refuses mixed booking sources and reopens no linked appointment as a new request', function () {
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot,
        'lot_appointment_id' => $row->id, 'crm_appointment_id' => 'dossier-1'])->assertUnprocessable();
    expect(Appointment::count())->toBe(0);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $dossier = Dossier::sole();
    $operation = $dossier->operations()->sole();
    $operation->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $this->postJson(route('v2.dossiers.operations.reopen', [$dossier, $operation]), [
        'version' => $dossier->version, 'comment' => 'Ne pas créer de doublon',
    ])->assertUnprocessable();
    expect(Appointment::count())->toBe(1);
});

it('reschedules imported appointments on their native operation without recreating a dossier', function () {
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $appointment = Appointment::sole();
    $appointment->update(['external_source' => 'coffrac', 'external_reference' => '1234']);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot,
        'replace_appointment_id' => $appointment->id, 'starts_at' => now()->addWeeks(2)->setTime(10, 0)->toIso8601String(),
    ])->assertOk();
    expect(Dossier::count())->toBe(1)->and(Appointment::count())->toBe(1)
        ->and($appointment->fresh()->dossier_operation_id)->toBe($appointment->dossier_operation_id);
    $appointment->forceFill(['dossier_operation_id' => null])->save();
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot,
        'replace_appointment_id' => $appointment->id, 'starts_at' => now()->addWeeks(3)->setTime(10, 0)->toIso8601String(),
    ])->assertUnprocessable();
    expect(Dossier::count())->toBe(1);
    Http::assertNothingSent();
});

it('stores lot documents locally and retires old queued Coffrac uploads in V2', function () {
    Storage::fake('local');
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    Appointment::sole()->update(['external_source' => 'coffrac', 'external_reference' => '1234']);
    $this->actingAs(User::factory()->create(['role' => 0, 'admin' => true]));
    $this->postJson(route('manager.lots.appointments.documents.store', $row), [
        'name' => 'Plan', 'document' => UploadedFile::fake()->image('photo.png'), 'is_private' => true,
    ])->assertCreated();
    Queue::assertNotPushed(PushLotAppointmentDocumentToCoffracJob::class);
    $document = $row->documents()->sole();
    $document->update(['status' => 'queued']);
    (new PushLotAppointmentDocumentToCoffracJob($document->id))->handle(app(CoffracAppointmentService::class));
    expect($document->fresh()->status)->toBe('pending');
    Http::assertNothingSent();
});

it('does not rewrite the service of an operation already in the control workflow', function () {
    $row = demoLotRow($this);
    $this->postJson(route('planner.book.appointments.store'), [...$this->slot, 'lot_appointment_id' => $row->id])->assertCreated();
    $appointment = Appointment::sole();
    $appointment->dossierOperation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $otherService = Service::create(['name' => 'Autre contrôle', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($otherService);
    $this->patchJson(route('planner.tracking.appointments.details', $appointment), [
        'starts_at' => $appointment->starts_at->toIso8601String(), 'duration_minutes' => 60,
        'address' => $appointment->address, 'service_id' => $otherService->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    expect($appointment->fresh()->service_id)->toBe($this->service->id)
        ->and($appointment->fresh()->dossierOperation->service_id)->toBe($this->service->id);
});
