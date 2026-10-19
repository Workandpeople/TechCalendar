<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\PostVisitWorkflow;
use App\Services\LotAutoCompletionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['name' => 'Contrôle natif', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->dossier = Dossier::create(['reference' => 'POST-VISIT', 'company_name' => 'Client test', 'address' => '10 rue du Test', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::create([
        'service_id' => $this->service->id, 'technician_id' => $this->tech->id, 'created_by' => $this->planner->id,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '0600000000',
        'address' => '10 rue du Test', 'latitude' => 48.8, 'longitude' => 2.3,
        'starts_at' => now()->subDay()->setTime(10, 0), 'ends_at' => now()->subDay()->setTime(11, 0),
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED,
    ]);
    $this->appointment->forceFill(['dossier_operation_id' => $this->operation->id])->save();
    $this->lot = Lot::create(['name' => 'Lot natif', 'type' => Lot::TYPE_FULL_CONTROL, 'service_id' => $this->service->id,
        'status' => Lot::STATUS_IN_PROGRESS, 'created_by' => $this->manager->id]);
    $this->row = $this->lot->appointments()->create(['customer_name' => 'Client', 'address' => '10 rue du Test',
        'appointment_id' => $this->appointment->id, 'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
        'status' => LotAppointment::STATUS_PLACED]);
    Storage::disk('local')->put('crm/rapport.pdf', "%PDF-1.4\n%%EOF");
    $this->document = $this->dossier->documents()->create(['name' => 'Rapport vérifié', 'path' => 'crm/rapport.pdf', 'mime_type' => 'application/pdf', 'size' => 14,
        'document_type' => 'report', 'dossier_operation_id' => $this->operation->id,
        'inspected_on' => now()->subDay()->toDateString(), 'control_outcome' => 'satisfactory']);
    Storage::disk('local')->put('crm/visit.pdf', '%PDF-1.4');
    $this->visitDocument = $this->dossier->documents()->create(['name' => 'Visite technique', 'path' => 'crm/visit.pdf', 'mime_type' => 'application/pdf', 'size' => 8,
        'document_type' => 'visit', 'dossier_operation_id' => $this->operation->id]);
    $this->url = route('v2.dossiers.operations.post-visit', [$this->dossier, $this->operation]);
    $client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'RECONTROL-CLIENT',
        'kind' => 'client', 'name' => 'Client recontrôle', 'is_active' => true]);
    $this->dossier->update(['delegataire_id' => $client->id]);
    $this->client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $client->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client'), 'must_change_password' => false]);
    $this->responseUrl = route('client.dossiers.recontrol', [$this->dossier, $this->operation]);
    $this->actingAs($this->manager);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function postVisitAction($test, string $action, array $data = [])
{
    return $test->postJson($test->url, array_replace([
        'version' => $test->dossier->fresh()->version, 'action' => $action, 'comment' => 'Motif de test',
        ...($action === 'record_visit' ? ['visit_document_id' => $test->visitDocument->id] : []),
    ], $data));
}

function submitPostVisit($test, string $outcome = 'satisfactory'): void
{
    postVisitAction($test, 'record_visit', ['appointment_id' => $test->appointment->id])->assertRedirect();
    $test->document->update(['control_outcome' => $outcome]);
    app(PostVisitWorkflow::class)->recordReport(auth()->user(), $test->dossier, $test->operation, $test->document,
        ['version' => $test->dossier->fresh()->version, 'comment' => 'Rapport déposé']);

}

it('runs the native post visit cycle and projects the uploaded report result independently of validation without new records', function (string $outcome, ?bool $expected) {
    $dates = $this->appointment->only('starts_at', 'ends_at', 'status');
    $this->actingAs($this->planner);
    submitPostVisit($this, $outcome);
    expect($this->row->fresh()->physical_satisfaction)->toBe($expected)
        ->and($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($this->lot->fresh()->status)->toBe($expected === null ? Lot::STATUS_IN_PROGRESS : Lot::STATUS_TO_INVOICE);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Statut et fiches')
        ->assertDontSee('Valider le rapport et mettre à jour le lot');
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertForbidden();
    $this->actingAs($this->manager);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')
        ->and($this->operation->fresh()->control_validated_by)->toBe($this->manager->id)
        ->and($this->row->fresh()->physical_satisfaction)->toBe($expected)
        ->and($this->lot->fresh()->status)->toBe($expected === null ? Lot::STATUS_IN_PROGRESS : Lot::STATUS_TO_INVOICE)
        ->and($this->appointment->fresh()->only('starts_at', 'ends_at', 'status'))->toEqual($dates)
        ->and(Dossier::count())->toBe(1)->and(Appointment::count())->toBe(1)
        ->and($this->dossier->operations()->count())->toBe(1)
        ->and($this->dossier->activities()->where('kind', 'post_visit')->count())->toBe(3)
        ->and($this->dossier->comments()->count())->toBe(3);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Avis du dernier rapport')->assertSee('Rapport vérifié');
})->with([['satisfactory', true], ['unsatisfactory', false], ['unverifiable', null]]);

it('preserves satisfaction during correction and preserves the previous review in history', function () {
    submitPostVisit($this);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    $oldVersion = $this->dossier->fresh()->version;
    postVisitAction($this, 'reopen_control')->assertRedirect();
    expect($this->row->fresh()->physical_satisfaction)->toBeTrue()
        ->and($this->lot->fresh()->status)->toBe(Lot::STATUS_TO_INVOICE)
        ->and($this->operation->fresh()->report_document_id)->toBe($this->document->id);
    postVisitAction($this, 'reopen_control', ['version' => $oldVersion])->assertUnprocessable()->assertJsonValidationErrors('version');
    postVisitAction($this, 'request_correction', ['comment' => 'Pièce contradictoire'])->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES');
    postVisitAction($this, 'submit_control', ['outcome' => 'unsatisfactory', 'inspected_on' => now()->subDay()->toDateString()])->assertUnprocessable();
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => $this->dossier->fresh()->version, 'comment' => 'Correction effectuée'])->assertRedirect();
    $this->actingAs($this->manager);
    $this->document->update(['control_outcome' => 'unsatisfactory']);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    expect($this->row->fresh()->physical_satisfaction)->toBeFalse()
        ->and($this->lot->fresh()->status)->toBe(Lot::STATUS_TO_INVOICE);
    $audit = $this->dossier->activities()->where('kind', 'post_visit')->get()->firstWhere('changed_fields.action', 'reopen_control');
    expect($audit->changed_fields['before']['control_outcome'])->toBe('satisfactory')
        ->and($audit->changed_fields['before']['control_validated_by'])->toBe($this->manager->id);
});

it('rejects invalid states scope stale forms and incomplete or future data atomically', function () {
    postVisitAction($this, 'record_visit', ['appointment_id' => $this->appointment->id, 'version' => 999])->assertUnprocessable();
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertUnprocessable();
    postVisitAction($this, 'record_visit', ['appointment_id' => $this->appointment->id, 'comment' => '  '])->assertUnprocessable();
    postVisitAction($this, 'record_visit', ['appointment_id' => 987])->assertUnprocessable();
    $this->appointment->update(['ends_at' => now()->addDay()]);
    postVisitAction($this, 'record_visit', ['appointment_id' => $this->appointment->id])->assertUnprocessable();
    expect($this->dossier->fresh()->version)->toBe(1)->and($this->dossier->activities()->count())->toBe(0);
    $other = Dossier::create(['reference' => 'OTHER', 'address' => 'Paris', 'city' => 'Paris']);
    $this->postJson(route('v2.dossiers.operations.post-visit', [$other, $this->operation]), [
        'version' => 1, 'action' => 'record_visit', 'comment' => 'Test', 'appointment_id' => $this->appointment->id, 'visit_document_id' => $this->visitDocument->id,
    ])->assertNotFound();
    $this->appointment->update(['ends_at' => now()->subDay()->setTime(11, 0)]);
    postVisitAction($this, 'record_visit', ['appointment_id' => $this->appointment->id])->assertRedirect();
    postVisitAction($this, 'submit_control', ['outcome' => 'satisfactory', 'inspected_on' => now()->addDay()->toDateString()])->assertUnprocessable();
    postVisitAction($this, 'submit_control', ['outcome' => 'unknown', 'inspected_on' => now()->subDay()->toDateString()])->assertUnprocessable();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV VISITE')->and($this->row->fresh()->physical_satisfaction)->toBeNull();
});

it('requires an available PDF belonging to the dossier and refuses archived lots', function () {
    submitPostVisit($this);
    $other = Dossier::create(['reference' => 'OTHER-PDF', 'address' => 'Paris', 'city' => 'Paris']);
    $otherPdf = $other->documents()->create(['name' => 'Autre', 'path' => 'other.pdf', 'mime_type' => 'application/pdf', 'size' => 12]);
    Storage::disk('local')->put('other.pdf', '%PDF-1.4');
    postVisitAction($this, 'validate_report', ['report_document_id' => $otherPdf->id])->assertUnprocessable()->assertJsonValidationErrors('report_document_id');
    Storage::disk('local')->delete($this->document->path);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertUnprocessable();
    Storage::disk('local')->put($this->document->path, '%PDF-1.4');
    $this->lot->update(['status' => Lot::STATUS_ARCHIVED]);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertUnprocessable();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($this->row->fresh()->physical_satisfaction)->toBeTrue()
        ->and($this->lot->fresh()->status)->toBe(Lot::STATUS_ARCHIVED);
});

it('keeps physical outcomes separate from contacts and ignores excluded rows in lot targets', function () {
    $this->lot->update(['type' => Lot::TYPE_HYBRID_LOCATION_CONTACT, 'physical_sampling_percentage' => 50, 'contact_sampling_percentage' => 50]);
    $contact = $this->lot->appointments()->create(['customer_name' => 'Contact', 'address' => 'Paris',
        'appointment_id' => $this->appointment->id, 'processing_mode' => 'contact', 'status' => 'contact_processed', 'contact_satisfaction' => false, 'contact_comment' => 'Défavorable']);
    $excluded = $this->lot->appointments()->create(['customer_name' => 'Exclu', 'address' => 'Paris',
        'processing_mode' => 'physical', 'status' => 'placed', 'appointment_id' => $this->appointment->id, 'excluded_from_lot_stats' => true]);
    submitPostVisit($this);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    expect($contact->fresh()->physical_satisfaction)->toBeNull()->and($contact->fresh()->contact_satisfaction)->toBeFalse()
        ->and($this->row->fresh()->physical_satisfaction)->toBeTrue()->and($excluded->fresh()->excluded_from_lot_stats)->toBeTrue()
        ->and($this->lot->fresh()->status)->toBe(Lot::STATUS_TO_INVOICE);
    $stats = app(LotAutoCompletionCalculator::class)->calculate($this->lot->fresh(), $this->lot->appointments()->get());
    expect($stats['target_count'])->toBe(2)->and($stats['satisfaction_remaining_count'])->toBe(0);
});

it('protects the reviewed visit from unlinking editing and resetting in a lot', function () {
    submitPostVisit($this);
    $this->deleteJson(route('v2.dossiers.appointments.destroy', [$this->dossier, $this->operation, $this->appointment]), [
        'version' => $this->dossier->fresh()->version,
    ])->assertUnprocessable();
    $this->patchJson(route('planner.tracking.appointments.details', $this->appointment), [
        'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'duration_minutes' => 60,
        'address' => $this->appointment->address,
    ])->assertUnprocessable();
    $this->patchJson(route('manager.lots.appointments.reset-processing', $this->row))->assertUnprocessable();
    expect($this->appointment->fresh()->dossier_operation_id)->toBe($this->operation->id)
        ->and($this->appointment->fresh()->trashed())->toBeFalse()
        ->and($this->row->fresh()->appointment_id)->toBe($this->appointment->id);
});

it('requires a typed operation report and prevents changing an opinion from the status form', function () {
    $this->operation->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->document->update(['document_type' => 'free']);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertUnprocessable()->assertJsonValidationErrors('report_document_id');
    postVisitAction($this, 'submit_control', ['outcome' => 'satisfactory'])->assertUnprocessable()->assertJsonValidationErrors('outcome');
    $this->actingAs($this->planner);
    postVisitAction($this, 'request_correction')->assertForbidden();
    $this->actingAs($this->tech);
    postVisitAction($this, 'request_correction')->assertForbidden();
    $this->actingAs($this->manager);
    config(['crm.v2_enabled' => false]);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertNotFound();
});

it('accepts a client document atomically and permits another non conformity cycle before validation', function () {
    submitPostVisit($this, 'unsatisfactory');
    postVisitAction($this, 'request_correction', ['comment' => 'Joindre la pièce corrigée', 'client_visible' => true])->assertRedirect();
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->actingAs($this->client)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('Transmettre pour recontrôle')->assertSee('Joindre la pièce corrigée')->assertDontSee('Valider le rapport');
    $version = $this->dossier->fresh()->version;
    $this->postJson($this->responseUrl, ['version' => $version, 'document_name' => 'Justificatif corrigé',
        'document' => UploadedFile::fake()->image('preuve.jpg'), 'client_visible' => false,
        'action' => 'validate_report', 'outcome' => 'satisfactory'])->assertRedirect();
    $document = $this->dossier->documents()->where('name', 'Justificatif corrigé')->sole();
    Storage::disk('local')->assertExists($document->path);
    expect($document->client_visible)->toBeTrue()->and($document->author_id)->toBe($this->client->id)
        ->and($this->operation->fresh()->legacy_status)->toBe('RECONTROLE')
        ->and($this->operation->fresh()->control_outcome)->toBe('unsatisfactory')
        ->and($this->operation->fresh()->control_validated_at)->toBeNull()
        ->and($this->row->fresh()->physical_satisfaction)->toBeFalse()
        ->and($other->fresh()->legacy_status)->toBe('NON CONFORMITES');
    $response = $this->dossier->activities()->where('kind', 'post_visit')->latest('id')->first();
    expect($response->changed_fields['response_document_id'])->toBe($document->id)
        ->and($response->client_visible)->toBeTrue()->and($response->body)->not->toContain('Non satisfaisant');
    $this->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertSee('En cours de recontrôle');
    $this->postJson($this->responseUrl, ['version' => $version, 'comment' => 'Doublon'])->assertUnprocessable();
    $this->postJson($this->responseUrl, ['version' => $this->dossier->fresh()->version, 'comment' => 'Doublon'])->assertUnprocessable();
    expect($this->dossier->documents()->count())->toBe(3);
    $this->actingAs($this->manager);
    postVisitAction($this, 'request_correction', ['comment' => 'Correction supplémentaire', 'client_visible' => true])->assertRedirect();
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => $this->dossier->fresh()->version, 'comment' => 'Deuxième correction'])->assertRedirect();
    $this->actingAs($this->manager);
    $this->document->update(['control_outcome' => 'satisfactory']);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    expect($this->row->fresh()->physical_satisfaction)->toBeTrue()
        ->and($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS');
});

it('does not transition on a generic upload or a comment and requires an explicit fresh response', function () {
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->actingAs($this->client);
    $this->postJson(route('client.dossiers.comments', $this->dossier), ['body' => 'Document à venir'])->assertRedirect();
    $this->postJson(route('client.dossiers.upload', $this->dossier), ['name' => 'Pièce', 'document' => UploadedFile::fake()->image('photo.jpg')])->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES');
    $this->postJson($this->responseUrl, ['version' => 999, 'comment' => ' '])->assertUnprocessable();
    $this->postJson($this->responseUrl, ['version' => 1, 'document' => UploadedFile::fake()->create('bad.php', 1, 'text/plain')])->assertUnprocessable();
    $this->postJson($this->responseUrl, ['version' => 1, 'document' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])->assertUnprocessable();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->documents()->count())->toBe(3);
});

it('protects response ownership roles permissions and stale forms without writing documents', function () {
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $data = ['version' => 1, 'comment' => 'Réponse'];
    $this->postJson($this->responseUrl, $data)->assertForbidden();
    $other = Dossier::create(['reference' => 'OTHER-RESPONSE', 'address' => 'Paris', 'city' => 'Paris']);
    $otherOperation = $other->operations()->create(['service_id' => $this->service->id]);
    $otherOperation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->actingAs($this->client);
    $this->postJson(route('client.dossiers.recontrol', [$other, $otherOperation]), $data)->assertForbidden();
    $this->postJson(route('client.dossiers.recontrol', [$this->dossier, $otherOperation]), $data)->assertNotFound();
    $this->postJson($this->url, ['version' => 1, 'action' => 'request_correction', 'comment' => 'Interdit'])->assertForbidden();
    $this->postJson($this->responseUrl, ['version' => 999, 'document' => UploadedFile::fake()->image('stale.jpg')])->assertUnprocessable();
    $this->client->forceFill(['portal_permissions' => array_replace(ClientAccess::defaults('client'), ['add_comments' => false])])->save();
    $this->postJson($this->responseUrl, $data)->assertForbidden();
    $this->client->forceFill(['portal_permissions' => array_replace(ClientAccess::defaults('client'), ['upload_documents' => false])])->save();
    $this->postJson($this->responseUrl, ['version' => 1, 'document' => UploadedFile::fake()->image('forbidden.jpg')])->assertForbidden();
    $this->client->forceFill(['portal_permissions' => ['documents' => true, 'comments' => true]])->save();
    $this->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertSee('Transmettre pour recontrôle');
    $this->postJson($this->responseUrl, $data)->assertForbidden();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->documents()->count())->toBe(2)
        ->and(Storage::disk('local')->allFiles())->toBe(['crm/rapport.pdf', 'crm/visit.pdf']);
});

it('rolls back status history and satisfaction when storing a response document fails', function () {
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $registry = Mockery::mock(DossierRegistry::class)->makePartial();
    $registry->shouldReceive('addDocument')->once()->andThrow(new RuntimeException('Storage unavailable'));
    $this->app->instance(DossierRegistry::class, $registry);
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => 1,
        'document' => UploadedFile::fake()->image('failed.jpg')])->assertServerError();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->fresh()->version)->toBe(1)
        ->and($this->dossier->activities()->count())->toBe(0)
        ->and($this->row->fresh()->physical_satisfaction_synced_at)->toBeNull();
});

it('allows document only responses without comments rights and keeps internal review history private', function () {
    submitPostVisit($this);
    postVisitAction($this, 'request_correction', ['comment' => 'PUBLIC-CORRECTION', 'client_visible' => true])->assertRedirect();
    $activity = $this->dossier->comments()->latest('id')->first();
    $this->actingAs($this->client)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('PUBLIC-CORRECTION')->assertDontSee('Motif de test')->assertDontSee('control_submitted_at');
    $this->actingAs($this->manager)->patch(route('v2.dossiers.comments.sharing', [$this->dossier, $activity->id]), ['client_visible' => false])->assertRedirect();
    $this->actingAs($this->client)->get(route('client.dossiers.show', $this->dossier))->assertDontSee('PUBLIC-CORRECTION');
    $this->client->forceFill(['portal_permissions' => array_replace(ClientAccess::defaults('client'), ['comments' => false])])->save();
    $this->postJson($this->responseUrl, ['version' => $this->dossier->fresh()->version,
        'document' => UploadedFile::fake()->image('proof.jpg')])->assertRedirect();
    $this->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('En cours de recontrôle')->assertDontSee('Échanges avec notre équipe');
    expect($this->operation->fresh()->legacy_status)->toBe('RECONTROLE');
});

it('removes the uploaded file too if the response transaction fails after storing it', function () {
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    DossierActivity::updating(function ($activity) {
        if (isset($activity->changed_fields['response_document_id'])) {
            throw new RuntimeException('Response audit failed');
        }
    });
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => 1,
        'document' => UploadedFile::fake()->image('orphan.jpg')])->assertServerError();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->fresh()->version)->toBe(1)
        ->and($this->dossier->documents()->count())->toBe(2)
        ->and($this->dossier->activities()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe(['crm/rapport.pdf', 'crm/visit.pdf']);
});

it('rejects responses outside non conformity and on archived lots', function (string $state) {
    $this->operation->forceFill(['legacy_status' => $state])->save();
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => 1, 'comment' => 'Réponse'])->assertUnprocessable();
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->lot->update(['status' => Lot::STATUS_ARCHIVED]);
    $this->postJson($this->responseUrl, ['version' => 1, 'document' => UploadedFile::fake()->image('archived.jpg')])->assertUnprocessable();
    expect($this->dossier->fresh()->version)->toBe(1)->and($this->dossier->activities()->count())->toBe(0)
        ->and($this->dossier->documents()->count())->toBe(2);
})->with(['RDV ATTENTE VISITE', 'CONTROLE RT', 'RAPPORT GENIUS', 'RECONTROLE']);

it('lets an authorized delegataire respond to an imported non conformity without inventing a result', function () {
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->client->externalClient->update(['kind' => 'delegataire']);
    $this->client->forceFill(['portal_permissions' => ClientAccess::defaults('delegataire')])->save();
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => 1, 'comment' => 'Interdit'])->assertForbidden();
    $this->client->forceFill(['portal_permissions' => array_replace(ClientAccess::defaults('delegataire'), ['upload_documents' => true])])->save();
    $this->postJson($this->responseUrl, ['version' => 1, 'document' => UploadedFile::fake()->image('delegataire.jpg')])->assertRedirect();
    expect($this->operation->fresh()->control_outcome)->toBeNull()->and($this->row->fresh()->physical_satisfaction)->toBeNull();
    $this->actingAs($this->planner);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertForbidden();
    $this->actingAs($this->manager)->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Statut et fiches');
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id, 'outcome' => 'satisfactory',
        'inspected_on' => now()->addDay()->toDateString()])->assertUnprocessable();
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')
        ->and($this->row->fresh()->physical_satisfaction)->toBeTrue();
});

it('cannot withdraw a validation independently of an existing CAPCEE transmission', function () {
    submitPostVisit($this);
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    $this->operation->capceeReports()->create(['environment' => 'uat', 'reference' => 'TEST-REPORT', 'emmy_reference' => 'TEST', 'object_type' => 'INDIVIDUAL_HOUSE', 'inspection_type' => 'INITIAL']);
    postVisitAction($this, 'reopen_control')->assertUnprocessable();
    expect($this->row->fresh()->physical_satisfaction)->toBeTrue()
        ->and($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS');
});

it('isolates each operation of a dossier and rejects a repeated validation without duplicate history', function () {
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $otherVisit = $this->appointment->replicate();
    $otherVisit->forceFill(['dossier_operation_id' => $other->id])->save();
    $otherRow = $this->row->replicate();
    $otherRow->forceFill(['appointment_id' => $otherVisit->id])->save();
    submitPostVisit($this, 'unsatisfactory');
    $version = $this->dossier->fresh()->version;
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertRedirect();
    postVisitAction($this, 'validate_report', ['version' => $version, 'report_document_id' => $this->document->id])->assertUnprocessable();
    postVisitAction($this, 'validate_report', ['report_document_id' => $this->document->id])->assertUnprocessable();
    expect($this->row->fresh()->physical_satisfaction)->toBeFalse()
        ->and($otherRow->fresh()->physical_satisfaction)->toBeNull()
        ->and($other->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and($this->lot->fresh()->status)->toBe(Lot::STATUS_IN_PROGRESS)
        ->and($this->dossier->activities()->where('kind', 'post_visit')->count())->toBe(3);
});

it('changes a technician directly from the dossier using the shared availability checks', function () {
    $replacement = User::factory()->create(['role' => 2, 'admin' => false]);
    $replacement->services()->attach($this->service);
    $url = route('v2.dossiers.appointments.technician', [$this->dossier, $this->appointment->id]);
    $payload = ['version' => $this->dossier->fresh()->version, 'technician_id' => $replacement->id,
        'updated_at' => $this->appointment->fresh()->updated_at->toIso8601String()];
    $this->patch($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
    expect($this->appointment->fresh()->technician_id)->toBe($replacement->id);
    $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('version');

    $this->actingAs($this->client)->patch($url, $payload)->assertForbidden();
    $this->actingAs($this->manager);
    postVisitAction($this, 'record_visit', ['appointment_id' => $this->appointment->id])->assertRedirect();
    $this->patchJson($url, ['version' => $this->dossier->fresh()->version, 'technician_id' => $this->tech->id,
        'updated_at' => $this->appointment->fresh()->updated_at->toIso8601String()])->assertUnprocessable();
    expect($this->appointment->fresh()->technician_id)->toBe($replacement->id);
});

it('refuses unavailable or unqualified technician assignments and foreign appointments', function () {
    $replacement = User::factory()->create(['role' => 2, 'admin' => false]);
    $url = route('v2.dossiers.appointments.technician', [$this->dossier, $this->appointment->id]);
    $payload = ['version' => $this->dossier->fresh()->version, 'technician_id' => $replacement->id,
        'updated_at' => $this->appointment->fresh()->updated_at->toIso8601String()];
    $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    $replacement->services()->attach($this->service);
    $busy = $this->appointment->replicate();
    $busy->forceFill(['technician_id' => $replacement->id, 'dossier_operation_id' => null])->save();
    $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    $this->patchJson(route('v2.dossiers.appointments.technician', [$this->dossier, $busy->id]), $payload)->assertNotFound();
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id);
});

it('supports imported appointments without a modification date but still rejects stale tokens', function () {
    DB::table('appointments')->where('id', $this->appointment->id)->update(['updated_at' => null]);
    $replacement = User::factory()->create(['role' => 2, 'admin' => false]);
    $replacement->services()->attach($this->service);
    $url = route('v2.dossiers.appointments.technician', [$this->dossier, $this->appointment->id]);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    $this->patchJson($url, ['version' => 1, 'technician_id' => $replacement->id, 'updated_at' => null])->assertRedirect();
    expect($this->appointment->fresh()->technician_id)->toBe($replacement->id);
    $this->patchJson($url, ['version' => $this->dossier->fresh()->version, 'technician_id' => $this->tech->id, 'updated_at' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('appointment');
});

it('records a technical visit only after its document upload and blocks its removal', function () {
    $url = route('v2.dossiers.documents.store', $this->dossier);
    $payload = ['document_type' => 'visit', 'operation_id' => $this->operation->id,
        'appointment_id' => $this->appointment->id, 'version' => 1, 'name' => 'Compte rendu visite'];
    $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('document');
    postVisitAction($this, 'record_visit', ['appointment_id' => $this->appointment->id, 'visit_document_id' => $this->document->id])->assertUnprocessable();
    $this->postJson($url, [...$payload, 'document' => UploadedFile::fake()->image('visit.png')])->assertRedirect();
    $visit = $this->dossier->documents()->where('name', 'Compte rendu visite')->sole();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV VISITE')->and($visit->document_type)->toBe('visit')
        ->and($this->operation->fresh()->visit_appointment_id)->toBe($this->appointment->id)
        ->and($this->row->fresh()->physical_satisfaction)->toBeNull();
    $this->deleteJson(route('v2.documents.destroy', $visit))->assertUnprocessable();
    postVisitAction($this, 'submit_control')->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')->and($this->operation->fresh()->control_outcome)->toBeNull();
});

it('projects uploaded opinions immediately into linked lot results without touching phone outcomes', function (string $outcome, ?bool $expected) {
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $this->row->forceFill(['contact_satisfaction' => true])->save();
    $data = ['document_type' => 'report', 'operation_id' => $this->operation->id, 'version' => 1,
        'name' => 'Rapport', 'control_reference' => 'CONTROL', 'inspected_on' => now()->subDay()->toDateString(),
        'issued_on' => now()->toDateString(), 'outcome' => $outcome,
        'document' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF")];
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), $data)->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($this->operation->fresh()->control_outcome)->toBe($outcome)
        ->and($this->row->fresh()->physical_satisfaction)->toBe($expected)
        ->and($this->row->fresh()->contact_satisfaction)->toBeTrue();
    postVisitAction($this, 'request_correction')->assertRedirect();
    $this->actingAs($this->client)->postJson($this->responseUrl, ['version' => $this->dossier->fresh()->version])->assertRedirect();
    expect($this->row->fresh()->physical_satisfaction)->toBe($expected)
        ->and($this->operation->fresh()->legacy_status)->toBe('RECONTROLE');
})->with([['satisfactory', true], ['unsatisfactory', false], ['unverifiable', null]]);
