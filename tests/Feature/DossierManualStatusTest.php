<?php

use App\Models\Appointment;
use App\Models\CapceeAutomaticSubmission;
use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\DossierOperation;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierBillingService;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\DossierTable;
use App\Services\Crm\DossierWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->service = Service::create(['name' => 'Inspection test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'MANUAL-STATE', 'company_name' => 'Client test', 'address' => 'Rue Test', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id,
        'service_id' => $this->service->id, 'technician_id' => $this->actor->id, 'created_by' => $this->actor->id,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => 'Rue Test',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
        'status' => Appointment::STATUS_SCHEDULED]);
    $this->lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_FULL_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS]);
    $this->row = $this->lot->appointments()->create(['customer_name' => 'Client', 'appointment_id' => $this->appointment->id,
        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL, 'status' => LotAppointment::STATUS_PLACED]);
    $this->url = route('v2.dossiers.operations.status', [$this->dossier, $this->operation]);
    $this->data = ['version' => 1, 'status' => 'visited'];
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('allows every manual business state from every business state for managers and planners', function (string $from, int $role) {
    $this->actor->forceFill(['role' => $role])->save();
    $this->actingAs($this->actor);
    foreach (DossierStatusCatalog::manualStates() as $to => $status) {
        $this->operation->refresh()->forceFill(['legacy_status' => DossierStatusCatalog::STATUSES[$from]['values'][0]])->save();
        $version = $this->dossier->fresh()->version;
        $count = $this->dossier->activities()->count();
        $this->patch($this->url, [...$this->data, 'version' => $version, 'status' => $to])->assertSessionHasNoErrors()->assertRedirect();
        expect($this->operation->fresh()->legacy_status)->toBe($status['values'][0])
            ->and($this->dossier->fresh()->version)->toBe($version + (int) ($from !== $to))
            ->and($this->dossier->activities()->count())->toBe($count + (int) ($from !== $to));
    }
    expect($this->dossier->documents()->count())->toBe(0)->and(DossierBilling::count())->toBe(0);
})->with(array_keys(DossierStatusCatalog::manualStates()))->with([0, 1]);

it('offers the complete state selector without a reason or proof fields even while awaiting a future visit', function () {
    $response = $this->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    $form = '//form[@action="'.$this->url.'"]';
    $values = [];
    foreach ($xpath->query($form.'//select[@name="status"]/option') as $option) {
        $values[] = $option->getAttribute('value');
    }
    expect($values)->toBe(array_keys(DossierStatusCatalog::manualStates()))
        ->and($xpath->query($form.'//*[@name="comment" or @name="report_document_id" or @name="visit_document_id" or @name="appointment_id" or @name="outcome" or @name="audience" or @name="client_visible" or @name="delegataire_visible"]')->length)->toBe(0)
        ->and($xpath->query($form.'//p | '.$form.'//fieldset')->length)->toBe(0)
        ->and($xpath->query($form.'//button[@type="submit"]')->length)->toBe(1);
    $response->assertSee('Changer l’état')->assertDontSee('Choix manuel libre')->assertDontSee('Changer l’état ne crée pas');
});

it('preserves appointments documents technical validation satisfaction archived lots and billing', function () {
    Storage::disk('local')->put('crm/existing.pdf', '%PDF-1.4');
    $document = $this->dossier->documents()->create(['name' => 'Rapport existant', 'path' => 'crm/existing.pdf',
        'document_type' => 'report', 'dossier_operation_id' => $this->operation->id, 'client_visible' => false,
        'mime_type' => 'application/pdf', 'size' => 8]);
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS', 'report_document_id' => $document->id,
        'visit_appointment_id' => $this->appointment->id, 'inspected_on' => now()->subDay(), 'control_outcome' => 'unsatisfactory',
        'control_submitted_at' => now(), 'control_validated_at' => now(), 'control_validated_by' => $this->actor->id])->save();
    $this->lot->update(['status' => Lot::STATUS_ARCHIVED]);
    $this->row->update(['physical_satisfaction' => false, 'physical_satisfaction_synced_at' => now(), 'contact_satisfaction' => true]);
    $billing = DossierBilling::create(['dossier_id' => $this->dossier->id, 'dossier_operation_id' => $this->operation->id,
        'source' => 'native', 'marked_at' => now(), 'marked_by' => $this->actor->id, 'invoice_reference' => 'INVOICE-EXISTING']);
    $preserved = [$this->appointment->fresh()->getAttributes(), $document->fresh()->getAttributes(),
        $this->row->fresh()->getAttributes(), $this->lot->fresh()->getAttributes(), $billing->fresh()->getAttributes(),
        $this->operation->fresh()->except(['legacy_status', 'updated_at'])];
    $this->patch($this->url, [...$this->data, 'status' => 'pending'])->assertSessionHasNoErrors();
    expect([$this->appointment->fresh()->getAttributes(), $document->fresh()->getAttributes(),
        $this->row->fresh()->getAttributes(), $this->lot->fresh()->getAttributes(), $billing->fresh()->getAttributes(),
        $this->operation->fresh()->except(['legacy_status', 'updated_at'])])->toEqual($preserved)
        ->and(DossierBillingService::summary($this->dossier->fresh())['label'])->toBe('Facturé')
        ->and(CapceeAutomaticSubmission::count())->toBe(0);
    $this->postJson(route('v2.dossiers.billing.mark', $this->dossier), ['version' => 2, 'confirmed' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('billing');
    Storage::disk('local')->assertExists($document->path);
});

it('retains unresolved legacy billing evidence while allowing a manual status change', function () {
    $this->operation->forceFill(['legacy_status' => 'DOSSIERS FACTURER'])->save();
    $this->patch($this->url, $this->data)->assertSessionHasNoErrors();
    $dossier = $this->dossier->fresh();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV VISITE')
        ->and($dossier->billing_review_required)->toBeTrue()
        ->and($dossier->billing_review_evidence['manual_status_changes'][0]['legacy_status'])->toBe('DOSSIERS FACTURER')
        ->and(DossierBilling::count())->toBe(0);
    $this->postJson(route('v2.dossiers.billing.mark', $dossier), ['version' => 2, 'confirmed' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('billing');
});

it('keeps status changes in internal history without accepting sharing settings from older forms', function (array $legacySharing) {
    $this->patch($this->url, [...$this->data, ...$legacySharing])->assertSessionHasNoErrors()->assertSessionHas('status', 'État mis à jour.');
    $activity = $this->dossier->statusChanges()->sole();
    expect($activity->changed_fields['action'])->toBe('manual_status')
        ->and($activity->changed_fields['before']['legacy_status'])->toBe('RDV ATTENTE VISITE')
        ->and($activity->changed_fields['after']['legacy_status'])->toBe('RDV VISITE')
        ->and($activity->client_visible)->toBeFalse()->and($activity->delegataire_visible)->toBeFalse()
        ->and($activity->kind)->toBe('status_changed')->and($this->dossier->comments()->count())->toBe(0)
        ->and($activity->author_id)->toBe($this->actor->id)
        ->and($activity->body)->toBe('État modifié manuellement : RDV en attente de visite → RDV visité')
        ->and(app(DossierTable::class)->modification($this->dossier->fresh())['label'])->toBe('RDV visité');
})->with([[[]], [['audience' => 'internal']], [['audience' => 'client']], [['audience' => 'shared']],
    [['audience' => 'obsolete']], [['client_visible' => true, 'delegataire_visible' => true]]]);

it('uses organisation status access independently of manual changes and preserves private contents', function (string $kind) {
    $organisation = ExternalDelegataire::create(['kind' => $kind, 'source' => 'native', 'external_id' => 'STATUS-'.$kind,
        'name' => 'Organisation test', 'is_active' => true, 'portal_categories' => ['awaiting_visit']]);
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $organisation->id,
        'portal_enabled' => true, 'must_change_password' => false, 'portal_permissions' => [...ClientAccess::defaults($kind), 'comments' => true]]);
    $this->dossier->update([$kind === 'client' ? 'client_id' : 'delegataire_id' => $organisation->id]);
    $private = $this->dossier->documents()->create(['name' => 'KEEP-PRIVATE', 'path' => 'private.pdf', 'mime_type' => 'application/pdf', 'size' => 8]);
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'KEEP-PRIVATE-NOTE', 'client_visible' => false]);
    $this->actingAs($external)->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertDontSee('KEEP-PRIVATE');
    $this->actingAs($this->actor)->patch($this->url, [...$this->data, 'audience' => 'shared'])->assertSessionHasNoErrors();
    $this->actingAs($external)->get(route('client.dossiers.show', $this->dossier))->assertForbidden();
    $organisation->update(['portal_categories' => ['visited']]);
    $this->actingAs($external->fresh())->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('RDV visité')->assertDontSee('KEEP-PRIVATE')->assertDontSee('KEEP-PRIVATE-NOTE')->assertDontSee('État modifié manuellement');
    expect($private->fresh()->client_visible)->toBeFalse()->and($private->fresh()->delegataire_visible)->toBeFalse()
        ->and($organisation->fresh()->portal_categories)->toBe(['visited']);
})->with(['client', 'delegataire']);

it('ignores a reason submitted by an older form when changing status', function (?string $comment) {
    $this->patch($this->url, [...$this->data, 'comment' => $comment])->assertSessionHasNoErrors()->assertRedirect();

    expect($this->operation->fresh()->legacy_status)->toBe('RDV VISITE')
        ->and($this->dossier->statusChanges()->sole()->body)->toBe('État modifié manuellement : RDV en attente de visite → RDV visité');
})->with([null, '', '   ', 'Ancien motif à ignorer']);

it('allows the workflow service to change status without a reason', function () {
    expect(app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier, $this->operation, $this->data))->toBeTrue()
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV VISITE')
        ->and($this->dossier->statusChanges()->sole()->author_id)->toBe($this->actor->id);
});

it('rejects concurrent changes foreign operations and deleted dossiers', function () {
    $this->dossier->forceFill(['version' => 2])->save();
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('version');
    $other = Dossier::create(['reference' => 'OTHER', 'address' => 'Rue Test', 'city' => 'Paris']);
    $this->patchJson(route('v2.dossiers.operations.status', [$other, $this->operation]), $this->data)->assertNotFound();
    expect($this->dossier->activities()->count())->toBe(0)->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
    $this->dossier->delete();
    $this->patchJson($this->url, [...$this->data, 'version' => 2])->assertNotFound();
    expect(fn () => app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier, $this->operation, $this->data))
        ->toThrow(AuthorizationException::class);
});

it('modifies only the chosen operation and cannot create duplicate bookings when returning to pending', function () {
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->patch($this->url, [...$this->data, 'status' => 'pending'])->assertSessionHasNoErrors();
    expect($other->fresh()->legacy_status)->toBe('CONTROLE RT')->and(Appointment::count())->toBe(1)
        ->and($this->operation->fresh()->legacy_status)->toBe('PRISE DE RDV')
        ->and(DossierOperation::query()->awaitingBooking()->whereKey($this->operation->id)->exists())->toBeFalse();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Replacer le RDV');
});

it('forbids changing statuses without internal editing rights', function (int $role, bool $disabled) {
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    if ($disabled) {
        $user->delete();
    }
    $this->actingAs($user)->patchJson($this->url, $this->data)->assertForbidden();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
})->with([[User::ROLE_EXTERNAL, false], [2, false], [0, true]]);

it('refuses accounting deletion unknown states and forged technical values', function (array $invalid, string $field) {
    $this->patchJson($this->url, [...$this->data, ...$invalid])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->dossier->activities()->count())->toBe(0)->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
})->with([[['status' => 'invoiced'], 'status'], [['status' => 'unknown'], 'status'], [['status' => 'bad'], 'status'],
    [['outcome' => 'satisfactory'], 'outcome'], [['report_document_id' => 42], 'report_document_id']]);

it('keeps document driven automation after a manual correction without creating proof automatically', function () {
    $this->patch($this->url, $this->data)->assertSessionHasNoErrors();
    expect($this->dossier->documents()->count())->toBe(0)->and($this->operation->fresh()->inspected_on)->toBeNull();
    $report = fn (string $audience) => ['document_type' => 'report', 'version' => $this->dossier->fresh()->version,
        'document' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF"), 'control_reference' => 'CONTROL-TEST',
        'inspected_on' => now()->subDay()->toDateString(), 'issued_on' => now()->toDateString(), 'outcome' => 'unsatisfactory', 'audience' => $audience];
    $upload = route('v2.dossiers.documents.store', $this->dossier);
    $this->post($upload, $report('client'))->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')->and($this->row->fresh()->physical_satisfaction)->toBeFalse();
    $this->patch($this->url, [...$this->data, 'status' => 'awaiting_visit', 'version' => $this->dossier->fresh()->version])->assertSessionHasNoErrors();
    $this->appointment->forceFill(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()])->save();
    $this->post($upload, ['document_type' => 'visit', 'version' => $this->dossier->fresh()->version,
        'appointment_id' => $this->appointment->id, 'document' => UploadedFile::fake()->createWithContent('visit.pdf', '%PDF-1.4'),
        'audience' => 'client'])->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV VISITE');
    $this->post($upload, $report('internal'))->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT');
});
