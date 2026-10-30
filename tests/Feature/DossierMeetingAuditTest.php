<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use App\Services\Crm\DossierRegistry;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'INTERNAL-ONLY', 'company_name' => 'Entreprise Test', 'address' => 'Rue Test', 'city' => 'Paris']);
    $this->dossier->refresh();
    $this->operation = $this->dossier->operations()->create();
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->actingAs($this->actor);
});

function meetingXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);

    return new DOMXPath($dom);
}

it('places the eye first and hides the reference without removing it from search', function () {
    $response = $this->get(route('v2.dossiers.index', ['q' => 'INTERNAL-ONLY']))->assertOk();
    $xpath = meetingXPath($response->getContent());
    expect($xpath->query('//table[contains(@class,"gc-dossier-table")]/tbody/tr[1]/td[1]/a[contains(@class,"gc-dossier-open")]')->length)->toBe(1);
    expect($xpath->query('//table[contains(@class,"gc-dossier-table")]')->item(0)->textContent)->not->toContain('INTERNAL-ONLY');
    expect($xpath->query('//form[@data-dossier-filters]//button[not(ancestor::noscript)]')->length)->toBe(0);
    expect($xpath->query('//a[@data-dossier-export]')->item(0)->getAttribute('href'))->toContain('q=INTERNAL-ONLY');
    expect($xpath->query('//a[@class="gc-dossier-comments-link"]')->item(0)->getAttribute('href'))->toBe(route('v2.dossiers.show', $this->dossier).'#commentaires');
});

it('offers document comment and edit forms as unique dialogs with protected posts', function () {
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'Note']);
    $response = $this->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    $xpath = meetingXPath($response->getContent());
    foreach (['documents-dossier', 'ajouter-commentaire', 'modifier-dossier'] as $id) {
        expect($xpath->query('//dialog[@id="'.$id.'"]//form')->length)->toBe(1);
        expect($xpath->query('//dialog[@id="'.$id.'"]//input[@name="_token"]')->length)->toBe(1);
    }
    expect($xpath->query('//dialog[@id="ajouter-commentaire"]//input[@name="audience"][@checked]')->item(0)->getAttribute('value'))->toBe('client');
    expect($xpath->query('//input[@name="reference"]')->length)->toBe(0);
    expect($xpath->query('//*[contains(@class,"gc-dossier-detail")]')->item(0)->textContent)->not->toContain('INTERNAL-ONLY');
    $response->assertDontSee('Avis et contrôle après visite')->assertDontSee('Liaison existante');
});

it('prefills imported beneficiary labels and preserves manual contacts and the internal reference', function () {
    $this->dossier->forceFill(['company_name' => null, 'legacy_label' => 'Nom importé', 'client_contact' => ['company_name' => 'Contact manuel']])->save();
    $response = $this->get(route('v2.dossiers.edit', $this->dossier))->assertOk();
    $xpath = meetingXPath($response->getContent());
    expect($xpath->query('//input[@name="company_name"]')->item(0)->getAttribute('value'))->toBe('Nom importé');
    expect($xpath->query('//input[@name="client_contact[company_name]"]')->item(0)->getAttribute('value'))->toBe('Contact manuel');
    $this->put(route('v2.dossiers.update', $this->dossier), ['version' => $this->dossier->version, 'company_name' => 'Nom importé', 'address' => 'Rue Test', 'city' => 'Paris'])->assertRedirect();
    expect($this->dossier->fresh()->reference)->toBe('INTERNAL-ONLY');
});

it('generates unique internal references when creating without a reference or installer', function () {
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->andReturn(['latitude' => 48.85, 'longitude' => 2.35, 'mapbox_id' => 'address.fixture']);
    $service = Service::create(['name' => 'Prestation test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    for ($i = 0; $i < 2; $i++) {
        $this->post(route('v2.dossiers.store'), ['company_name' => 'Nouveau', 'address' => 'Rue Test', 'city' => 'Paris', 'mapbox_id' => 'address.fixture', 'service_ids' => [$service->id]])->assertSessionHasNoErrors()->assertRedirect();
    }
    $created = Dossier::where('company_name', 'Nouveau')->get();
    expect($created)->toHaveCount(2)->and($created->pluck('reference')->unique())->toHaveCount(2);
    expect($created->first()->reference)->toStartWith('DOS-');
    Http::assertNothingSent();
});

it('records an unplaced problem on the dossier without external side effects', function () {
    $type = app(CoffracAppointmentService::class)->problemTypes(false)[0]['value'];
    $this->post(route('v2.dossiers.operations.problem', [$this->dossier, $this->operation]), [
        'version' => $this->dossier->version, 'problem_type' => $type, 'comment' => 'Impossible de joindre',
        'recall_date' => '2026-12-01', 'recall_time' => '10:00', 'audience' => 'client',
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS');
    expect($this->dossier->comments()->sole()->client_visible)->toBeTrue();
    expect($this->dossier->comments()->sole()->delegataire_visible)->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('updates the linked appointment atomically and refuses stale duplicate or foreign changes', function () {
    $service = Service::create(['name' => 'Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $appointment = Appointment::forceCreate(['technician_id' => $this->actor->id, 'service_id' => $service->id, 'dossier_operation_id' => $this->operation->id, 'created_by' => $this->actor->id,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => Appointment::STATUS_SCHEDULED,
        'address' => 'Paris', 'customer_first_name' => 'Test', 'customer_last_name' => 'Exemple', 'customer_phone' => '',
        'latitude' => 48.85, 'longitude' => 2.35, 'duration_minutes' => 60]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $data = ['version' => $this->dossier->version, 'appointment_id' => $appointment->id,
        'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'comment' => 'Reporter le rendez-vous', 'recall_date' => '2026-12-01', 'recall_time' => '10:00'];
    $url = route('v2.dossiers.operations.problem', [$this->dossier, $this->operation]);
    $this->postJson($url, [...$data, 'appointment_id' => $appointment->id + 1])->assertUnprocessable();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_SCHEDULED);
    $this->post($url, $data)->assertSessionHasNoErrors()->assertRedirect();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_PROBLEM);
    $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('version');
    expect($this->dossier->comments()->count())->toBe(1);
    Http::assertNothingSent();
});

it('refuses problem changes after visit and from external users', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $data = ['version' => $this->dossier->version, 'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK,
        'comment' => 'Pas possible', 'recall_date' => '2026-12-01', 'recall_time' => '10:00'];
    $url = route('v2.dossiers.operations.problem', [$this->dossier, $this->operation]);
    $this->postJson($url, $data)->assertUnprocessable();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV VISITE');
    $this->actingAs(User::factory()->create(['role' => 3, 'admin' => false]))->postJson($url, $data)->assertForbidden();
});

it('does not invent a state for an imported dossier without a source operation', function () {
    $this->operation->delete();
    expect(app(DossierRegistry::class)->categories($this->actor, [])['unknown']['count'])->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Aucune fiche/prestation associée');
});
