<?php

use App\Jobs\PrepareAutomaticCapceeReport;
use App\Jobs\SendCapceeReport;
use App\Models\CapceeAutomaticSubmission;
use App\Models\CapceeDelivery;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Capcee\AutomaticCapceeReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'capcee.enabled' => false, 'capcee.environment' => 'uat2',
        'capcee.client_id' => 'test-client', 'capcee.client_secret' => 'test-secret', 'capcee.queue_connection' => 'database',
        'queue.connections.database.retry_after' => 1800]);
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->delegataire = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CAPCEE', 'kind' => 'delegataire',
        'name' => 'CAPCEE test', 'is_active' => true, 'capcee_enabled' => true]);
    $this->dossier = Dossier::create(['reference' => 'AUTO-CAPCEE', 'delegataire_id' => $this->delegataire->id,
        'address' => '10 rue Test', 'city' => 'Lyon']);
    $service = Service::create(['name' => 'Contrôle', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $this->operation->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    $this->target = ['capcee_emmy_reference' => 'EMMY-EXPLICIT-42', 'capcee_object_type' => 'Operation__c', 'capcee_inspection_type' => 'site'];
    $this->upload = function (array $changes = []) {
        return $this->actingAs($this->actor)->post(route('v2.dossiers.documents.store', $this->dossier), [
            'name' => 'Rapport validé', 'document_type' => 'report', 'operation_id' => $this->operation->id,
            'version' => $this->dossier->fresh()->version, 'control_reference' => 'NOT-AN-EMMY-REFERENCE',
            'inspected_on' => '2026-10-13', 'issued_on' => '2026-10-14', 'outcome' => 'satisfactory',
            'document' => UploadedFile::fake()->createWithContent('rapport.pdf', $this->pdf), ...$this->target, ...$changes,
        ]);
    };
});

afterEach(fn () => Http::assertNothingSent());

it('captures a validated report but creates no delivery or job while the API is disabled', function () {
    ($this->upload)()->assertSessionHasNoErrors();
    $submission = CapceeAutomaticSubmission::sole();
    expect($submission->block_reason)->toBe('configuration')->and($submission->document->control_reference)->toBe('NOT-AN-EMMY-REFERENCE')
        ->and($submission->document->capcee_emmy_reference)->toBe('EMMY-EXPLICIT-42');
    expect(CapceeDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    app(AutomaticCapceeReports::class)->prepare($submission->id);
    expect(CapceeDelivery::count())->toBe(0);
});

it('prepares the saved PDF through the existing outbox once, with beneficiary email explicitly disabled', function () {
    config(['capcee.enabled' => true]);
    ($this->upload)()->assertSessionHasNoErrors();
    $submission = CapceeAutomaticSubmission::sole();
    Queue::assertPushed(PrepareAutomaticCapceeReport::class, fn ($job) => $job->submissionId === $submission->id);
    app(AutomaticCapceeReports::class)->prepare($submission->id);
    app(AutomaticCapceeReports::class)->prepare($submission->id);
    $delivery = CapceeDelivery::sole();
    expect($submission->refresh()->capcee_delivery_id)->toBe($delivery->id)
        ->and($delivery->metadata['send_email'])->toBeFalse()->and($delivery->sha256)->toBe(hash('sha256', $this->pdf))
        ->and($delivery->report->emmy_reference)->toBe('EMMY-EXPLICIT-42');
    Queue::assertPushed(SendCapceeReport::class);
});

it('never guesses EMMY and lets a manager complete metadata without uploading the PDF again', function () {
    ($this->upload)(['capcee_emmy_reference' => null, 'capcee_object_type' => null, 'capcee_inspection_type' => null])->assertSessionHasNoErrors();
    $submission = CapceeAutomaticSubmission::sole();
    expect($submission->block_reason)->toBe('metadata')->and($submission->document->capcee_emmy_reference)->toBeNull();
    $file = $submission->document->path;
    config(['capcee.enabled' => true]);
    $this->patch(route('v2.capcee.automatic.update', [$this->dossier, $submission]), ['version' => 1, ...$this->target])->assertSessionHasNoErrors();
    expect($submission->refresh()->version)->toBe(2)->and($submission->document->path)->toBe($file);
    $this->patchJson(route('v2.capcee.automatic.update', [$this->dossier, $submission]), ['version' => 1, ...$this->target])->assertConflict();
    app(AutomaticCapceeReports::class)->prepare($submission->id);
    expect(CapceeDelivery::count())->toBe(1);
});

it('does not replay historical reports by enabling a delegataire or queue a first-stage RT report', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    ($this->upload)()->assertSessionHasNoErrors();
    expect(CapceeAutomaticSubmission::count())->toBe(0)->and($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT');
    $this->delegataire->update(['capcee_enabled' => false]);
    ($this->upload)()->assertSessionHasNoErrors();
    $this->delegataire->update(['capcee_enabled' => true]);
    expect(CapceeAutomaticSubmission::count())->toBe(0)->and(CapceeDelivery::count())->toBe(0);
});

it('rechecks ownership permission activation environment and current validation under locks', function (string $case, string $block) {
    ($this->upload)()->assertSessionHasNoErrors();
    $submission = CapceeAutomaticSubmission::sole();
    config(['capcee.enabled' => true]);
    match ($case) {
        'disabled' => $this->delegataire->update(['capcee_enabled' => false]),
        'inactive' => $this->delegataire->update(['is_active' => false]),
        'assignment' => $this->dossier->update(['delegataire_id' => null]),
        'actor' => $this->actor->update(['role' => 1]),
        'environment' => config(['capcee.environment' => 'production']),
        'deleted' => $this->dossier->delete(),
        'validation' => $this->operation->forceFill(['control_validated_at' => null])->save(),
    };
    app(AutomaticCapceeReports::class)->prepare($submission->id);
    expect($submission->refresh()->block_reason)->toBe($block)->and(CapceeDelivery::count())->toBe(0);
})->with([['disabled', 'delegataire'], ['inactive', 'delegataire'], ['assignment', 'delegataire'], ['actor', 'actor'],
    ['environment', 'environment'], ['deleted', 'document'], ['validation', 'document']]);

it('reuses a matching delivery for duplicate local PDFs rather than creating another report', function () {
    config(['capcee.enabled' => true]);
    ($this->upload)()->assertSessionHasNoErrors();
    app(AutomaticCapceeReports::class)->prepare(CapceeAutomaticSubmission::sole()->id);
    $this->operation->forceFill(['legacy_status' => 'RECONTROLE'])->save();
    ($this->upload)()->assertSessionHasNoErrors();
    $second = CapceeAutomaticSubmission::latest('id')->first();
    app(AutomaticCapceeReports::class)->prepare($second->id);
    expect(CapceeDelivery::count())->toBe(1)->and($second->refresh()->capcee_delivery_id)->toBe(CapceeDelivery::sole()->id);
});

it('keeps superseded reports with a CAPCEE trace instead of failing on a foreign key constraint', function () {
    ($this->upload)()->assertSessionHasNoErrors();
    $previous = CapceeAutomaticSubmission::sole()->document;
    $this->operation->forceFill(['legacy_status' => 'RECONTROLE'])->save();
    ($this->upload)()->assertSessionHasNoErrors();
    expect($this->operation->fresh()->report_document_id)->not->toBe($previous->id);
    $this->deleteJson(route('v2.documents.destroy', $previous))->assertUnprocessable()->assertJsonValidationErrors('document');
    $this->assertDatabaseHas('dossier_documents', ['id' => $previous->id]);
    Storage::disk('local')->assertExists($previous->path);
    expect(CapceeAutomaticSubmission::count())->toBe(2);
});
