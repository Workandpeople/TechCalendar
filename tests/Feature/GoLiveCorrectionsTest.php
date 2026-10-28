<?php

use App\Mail\MailTemplateMailable;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierSections;
use App\Services\Crm\PortalCategoryAccess;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
    $this->manager = User::factory()->create(['admin' => false, 'role' => 0]);
    $this->service = Service::create(['name' => 'BAR TH 171', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'GO-LIVE', 'company_name' => 'Bénéficiaire Test',
        'address' => 'Rue Test', 'postal_code' => '75001', 'city' => 'Paris', 'email' => 'beneficiary@example.invalid']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $this->actingAs($this->manager);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

function goLiveReport($test, string $audience, string $outcome): array
{
    return ['document_type' => 'report', 'version' => $test->dossier->fresh()->version,
        'document' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF"),
        'control_reference' => 'CONTROL-TEST', 'inspected_on' => now()->subDay()->toDateString(),
        'issued_on' => now()->toDateString(), 'outcome' => $outcome, 'audience' => $audience];
}

it('routes reports by audience and propagates opinion to the linked physical lot independently', function (string $audience, string $state, string $outcome, ?bool $satisfied) {
    $appointment = Appointment::forceCreate(['technician_id' => $this->manager->id, 'created_by' => $this->manager->id,
        'service_id' => $this->service->id, 'dossier_operation_id' => $this->operation->id,
        'customer_first_name' => 'Test', 'customer_last_name' => 'Client', 'customer_phone' => '', 'address' => 'Paris',
        'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'duration_minutes' => 60]);
    $lot = Lot::create(['name' => 'Lot test']);
    $row = $lot->appointments()->create(['appointment_id' => $appointment->id, 'customer_name' => 'Test',
        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL]);
    $this->post(route('v2.dossiers.documents.store', $this->dossier), goLiveReport($this, $audience, $outcome))
        ->assertSessionHasNoErrors()->assertRedirect();
    $document = $this->dossier->documents()->sole();
    expect($document->name)->toBe('Rapport de contrôle - CONTROL-TEST')
        ->and($document->dossier_operation_id)->toBe($this->operation->id)
        ->and($this->operation->fresh()->legacy_status)->toBe($state)
        ->and($row->fresh()->physical_satisfaction)->toBe($satisfied);
})->with([['internal', 'CONTROLE RT'], ['client', 'RAPPORT GENIUS'], ['shared', 'RAPPORT GENIUS']])
    ->with([['satisfactory', true], ['unsatisfactory', false], ['unverifiable', null]]);

it('names general documents and infers the single operation without an editable technical title', function () {
    $this->post(route('v2.dossiers.documents.store', $this->dossier), ['document_type' => 'free', 'name' => 'Plan',
        'document' => UploadedFile::fake()->image('plan.png'), 'audience' => 'client'])->assertSessionHasNoErrors();
    expect($this->dossier->documents()->sole()->name)->toBe('Plan - Bénéficiaire Test');
    $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), goLiveReport($this, 'client', 'satisfactory'))
        ->assertUnprocessable()->assertJsonValidationErrors('operation_id');
    expect($this->dossier->documents()->count())->toBe(1);
});

it('preserves the client suffix even for a document name at its maximum length', function () {
    $this->post(route('v2.dossiers.documents.store', $this->dossier), ['document_type' => 'free', 'name' => str_repeat('A', 190),
        'document' => UploadedFile::fake()->image('plan.png'), 'audience' => 'client'])->assertSessionHasNoErrors();
    $name = $this->dossier->documents()->sole()->name;
    expect(mb_strlen($name))->toBeLessThanOrEqual(190)->and($name)->toEndWith(' - Bénéficiaire Test');
});

it('names new reports from their control reference without changing old documents or general document naming', function (string $reference) {
    $old = $this->dossier->documents()->create(['name' => 'Ancien rapport - Bénéficiaire Test', 'path' => 'crm/old.pdf',
        'mime_type' => 'application/pdf', 'size' => 20, 'document_type' => 'report', 'control_reference' => 'OLD-REFERENCE']);
    $data = [...goLiveReport($this, 'internal', 'satisfactory'), 'control_reference' => $reference, 'name' => 'IGNORED-NAME'];
    $this->post(route('v2.dossiers.documents.store', $this->dossier), $data)->assertSessionHasNoErrors()->assertRedirect();
    $document = $this->dossier->documents()->whereKeyNot($old->id)->sole();
    expect($document->name)->toBe(mb_substr('Rapport de contrôle - '.trim($reference), 0, 190))
        ->and($document->control_reference)->toBe(trim($reference))
        ->and(mb_strlen($document->name))->toBeLessThanOrEqual(190)
        ->and($old->fresh()->name)->toBe('Ancien rapport - Bénéficiaire Test');
})->with(['CTRL-2026-123', '  RÉF-ÉTÉ-123  ', str_repeat('R', 190)]);

it('offers report access by default for delegates and non deleted categories for clients without forcing custom choices', function (string $kind) {
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => $kind, 'kind' => $kind,
        'name' => 'Organisation test', 'is_active' => true]);
    $keys = app(PortalCategoryAccess::class)->effective($organisation);
    expect($keys)->toContain('report');
    if ($kind === 'client') {
        expect($keys)->toContain('rt_control', 'recontrol', 'cancelled')->not->toContain('unknown');
    }
    $organisation->update(['portal_categories' => []]);
    expect(app(PortalCategoryAccess::class)->effective($organisation))->toBe([]);
})->with(['client', 'delegataire']);

it('allows client documents and authorized comments in every visible state', function () {
    $org = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CLIENT', 'kind' => 'client', 'name' => 'Client', 'is_active' => true]);
    $client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $org->id,
        'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => [...ClientAccess::defaults('client'), 'upload_documents' => true]]);
    $this->dossier->update(['client_id' => $org->id]);
    $this->actingAs($client);
    $comments = route('client.dossiers.comments', $this->dossier);
    $upload = route('client.dossiers.upload', $this->dossier);
    foreach (['RDV VISITE', 'CONTROLE RT', 'RAPPORT GENIUS', 'RECONTROLE'] as $state) {
        $this->operation->forceFill(['legacy_status' => $state])->save();
        $this->get(route('client.dossiers.show', $this->dossier))->assertOk();
        $this->postJson($comments, ['body' => 'Question client'])->assertRedirect();
        $this->postJson($upload, ['name' => 'Complément', 'document' => UploadedFile::fake()->image('doc.jpg')])->assertRedirect();
        expect($this->operation->fresh()->legacy_status)->toBe($state);
    }
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->post($comments, ['body' => 'Correction client'])->assertRedirect();
    $this->post($upload, ['name' => 'Preuve', 'document' => UploadedFile::fake()->image('proof.jpg')])->assertRedirect();
    expect($this->dossier->comments()->count())->toBe(5)
        ->and($this->dossier->comments()->where('delegataire_visible', true)->exists())->toBeFalse();
    $this->operation->forceFill(['legacy_status' => null])->save();
    $this->get(route('client.dossiers.show', $this->dossier))->assertForbidden();
});

it('changes the service through ajax without changing appointment dates and rejects stale foreign or retired services', function () {
    $service = Service::create(['name' => 'SAV BAR TH 171', 'type' => 'COFFRAC', 'average_duration_minutes' => 90]);
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $technician->services()->attach($service->id);
    $appointment = Appointment::forceCreate(['technician_id' => $technician->id, 'created_by' => $this->manager->id,
        'service_id' => $this->service->id, 'dossier_operation_id' => $this->operation->id,
        'customer_first_name' => 'Test', 'customer_last_name' => 'Client', 'customer_phone' => '', 'address' => 'Paris',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
    $start = $appointment->starts_at->toIso8601String();
    $url = route('v2.dossiers.operations.service', [$this->dossier, $this->operation]);
    $this->patchJson($url, ['service_id' => $service->id, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
    expect($this->operation->fresh()->service_id)->toBe($service->id)
        ->and($appointment->fresh()->service_id)->toBe($service->id)
        ->and($appointment->fresh()->starts_at->toIso8601String())->toBe($start)
        ->and($appointment->fresh()->duration_minutes)->toBe(60)
        ->and(DossierSections::badges($this->dossier->fresh('operations.service')))->toContain('SAV');
    $this->patchJson($url, ['service_id' => $this->service->id, 'version' => 1])->assertConflict();
    $other = Dossier::create(['reference' => 'OTHER', 'address' => 'Test', 'city' => 'Paris']);
    $this->patchJson(route('v2.dossiers.operations.service', [$other, $this->operation]), ['service_id' => $service->id, 'version' => 1])->assertNotFound();
    $service->forceFill(['retired_at' => now()])->save();
    $this->patchJson($url, ['service_id' => $service->id, 'version' => 2])->assertNotFound();
});

it('permits the manager planning dashboard and booking without duplicating dossier or appointment links', function () {
    $this->get(route('planner.dashboard'))->assertOk();
    $response = $this->get(route('planner.book'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    foreach (['Les dossiers', 'Les RDV'] as $label) {
        expect($xpath->query('//*[@id="app-sidebar"]//a[normalize-space(.)="'.$label.'"]')->length)->toBe(1);
    }
});

it('replaces a known appointment with missing gps using the dossier address instead of returning a missing request', function () {
    $technician = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => null, 'longitude' => null]);
    $appointment = Appointment::forceCreate(['technician_id' => $technician->id, 'created_by' => $this->manager->id,
        'service_id' => $this->service->id, 'dossier_operation_id' => $this->operation->id,
        'customer_first_name' => 'Test', 'customer_last_name' => 'Client', 'customer_phone' => '', 'address' => '',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
        'latitude' => null, 'longitude' => null, 'status' => Appointment::STATUS_SCHEDULED]);
    $this->app->instance(MapboxAddressGeocoder::class, Mockery::mock(MapboxAddressGeocoder::class, function ($mock) {
        $mock->shouldReceive('geocode')->once()->with('Rue Test 75001 Paris')->andReturn(['latitude' => 48.85, 'longitude' => 2.35]);
    }));
    $this->postJson(route('planner.book.analyze'), ['replace_appointment_id' => $appointment->id])->assertOk()
        ->assertJsonPath('crm_appointment.replace_appointment_id', $appointment->id)
        ->assertJsonPath('crm_appointment.latitude', 48.85);
    expect($appointment->fresh()->latitude)->toBeNull();
});

it('filters departments sectors and date ranges identically in html and ajax listings', function () {
    Department::updateOrCreate(['code' => '2A'], ['name' => 'Corse du Sud']);
    $this->dossier->forceFill(['postal_code' => '20100', 'created_at' => '2026-01-02 23:59:59'])->save();
    $filters = ['department' => '2A', 'section' => 'corse', 'date_from' => '2026-01-02', 'date_to' => '2026-01-02'];
    $this->get(route('v2.dossiers.index', $filters))->assertOk()->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
    $this->getJson(route('v2.dossiers.index', $filters))->assertOk()->assertJsonPath('total', 1);
    $this->getJson(route('v2.dossiers.index', [...$filters, 'section' => 'domtom']))->assertJsonPath('total', 0);
    $this->getJson(route('v2.dossiers.index', ['date_to' => '2025-01-01']))->assertOk()->assertJsonPath('total', 0);
    $this->getJson(route('v2.dossiers.index', ['date_from' => '2026-01-03', 'date_to' => '2026-01-02']))->assertUnprocessable();
});

it('displays the appointment matching the date filter instead of a different future booking', function () {
    foreach (['2026-01-02 10:00:00', '2026-12-01 10:00:00'] as $date) {
        Appointment::forceCreate(['technician_id' => $this->manager->id, 'created_by' => $this->manager->id,
            'service_id' => $this->service->id, 'dossier_operation_id' => $this->operation->id,
            'customer_first_name' => 'Test', 'customer_last_name' => 'Client', 'customer_phone' => '', 'address' => 'Paris',
            'starts_at' => $date, 'ends_at' => Carbon::parse($date)->addHour(), 'duration_minutes' => 60]);
    }
    $this->get(route('v2.dossiers.index', ['date_field' => 'appointment', 'date_from' => '2026-01-02', 'date_to' => '2026-01-02']))
        ->assertOk()->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1
            && $rows->first()->listingAppointment->starts_at->toDateString() === '2026-01-02');
});

it('refuses service changes on a reported fiche or after an invoicing mark', function () {
    $service = Service::create(['name' => 'Autre fiche', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->operation->forceFill(['legacy_status' => 'DOSSIERS FACTURER'])->save();
    $this->patchJson(route('v2.dossiers.operations.service', [$this->dossier, $this->operation]), ['service_id' => $service->id, 'version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('service_id');
    expect($this->operation->fresh()->service_id)->toBe($this->service->id);
});

it('offers template or custom dossier mail with real line breaks and no delivery while disabled', function () {
    $template = MailTemplate::create(['name' => 'Modèle test', 'slug' => 'modele-test', 'subject' => 'Bonjour {{ client_name }}',
        'markdown_body' => "Première ligne\nSeconde ligne", 'is_active' => true]);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('data-dossier-mail', false)->assertSee('Modèle test');
    $data = ['mail_template_id' => $template->id, 'recipient_email' => 'beneficiary@example.invalid',
        'subject' => $template->subject, 'markdown_body' => $template->markdown_body];
    $this->postJson(route('v2.dossiers.mail.preview', $this->dossier), $data)->assertOk()
        ->assertJsonPath('subject', 'Bonjour Bénéficiaire Test')->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, '<br'))->etc());
    $this->post(route('v2.dossiers.mail.store', $this->dossier), $data)->assertRedirect()->assertSessionHas('status', 'Simulation enregistrée : aucun email envoyé.');
    Mail::assertNothingOutgoing();
    expect($this->dossier->activities()->where('kind', 'mail_simulated')->sole()->client_visible)->toBeFalse();
    config(['mail.delivery_enabled' => true, 'mail.default' => 'smtp', 'simulation.enabled' => false]);
    $this->post(route('v2.dossiers.mail.store', $this->dossier), [...$data, 'mail_template_id' => null])->assertRedirect();
    Mail::assertQueued(MailTemplateMailable::class, fn ($mail) => $mail->envelope()->from->address === config('mail.from.address'));
    $template->update(['is_active' => false]);
    $this->postJson(route('v2.dossiers.mail.preview', $this->dossier), $data)->assertUnprocessable();
});

it('hides unconfigured sms and scopes replacement pages without loading other pending dossiers', function () {
    config(['services.smsfactor.enabled' => false]);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertDontSee('id="composer-sms"', false);
    $this->get(route('planner.book', ['replace_appointment_id' => 10]))->assertOk()
        ->assertViewHas('bookingMode', 'replace')->assertViewHas('crmAppointments', fn ($rows) => $rows->isEmpty())
        ->assertDontSee('id="booking-replacement-search-section"', false);
});

it('activates existing non deleted mail templates without reviving removed ones or enabling delivery', function () {
    $template = MailTemplate::create(['name' => 'Inactive', 'slug' => 'inactive', 'subject' => 'Test', 'markdown_body' => 'Test', 'is_active' => false]);
    $deleted = MailTemplate::create(['name' => 'Deleted', 'slug' => 'deleted', 'subject' => 'Test', 'markdown_body' => 'Test', 'is_active' => false]);
    $deleted->delete();
    (require database_path('migrations/2026_10_26_100000_activate_existing_mail_templates.php'))->up();
    expect($template->fresh()->is_active)->toBeTrue()->and($deleted->fresh()->is_active)->toBeFalse()
        ->and(config('mail.delivery_enabled'))->toBeFalse();
});
