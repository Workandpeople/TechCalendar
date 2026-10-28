<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'NAV-CLIENT',
        'kind' => 'client', 'name' => 'Société cliente fictive', 'is_active' => true]);
    $this->delegate = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'NAV-DELEGATE',
        'kind' => 'delegataire', 'name' => 'Délégataire fictif', 'is_active' => true]);
    $this->dossier = Dossier::create(['reference' => 'NAVIGATION-TEST', 'company_name' => 'Bénéficiaire fictif',
        'address' => '1 rue Test', 'city' => 'Paris', 'client_id' => $this->client->id, 'delegataire_id' => $this->delegate->id]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite fictive', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('opens native dossier eyes in the current tab for a first booking or a replacement', function (bool $replacement) {
    if ($replacement) {
        $tech = User::factory()->create(['role' => 2, 'admin' => false]);
        Appointment::forceCreate(['dossier_operation_id' => $this->operation->id, 'service_id' => $this->service->id,
            'technician_id' => $tech->id, 'created_by' => $this->actor->id, 'customer_first_name' => '',
            'customer_last_name' => 'Bénéficiaire fictif', 'customer_phone' => '', 'address' => '1 rue Test',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
            'status' => Appointment::STATUS_SCHEDULED]);
    }
    $html = $this->get(route('planner.book'))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    $eye = $xpath->query('//a[contains(@class,"crm-appointment-dossier-link")]')->item(0);
    expect($eye)->not->toBeNull()
        ->and($eye->getAttribute('href'))->toBe(route('v2.dossiers.show', $this->dossier, absolute: false))
        ->and($eye->hasAttribute('target'))->toBeFalse()
        ->and($eye->getAttribute('title'))->toBe('Voir le dossier');
    $summaryLink = $xpath->query('//a[@data-booking-summary-link]')->item(0);
    expect($summaryLink)->not->toBeNull()
        ->and($summaryLink->hasAttribute('target'))->toBeFalse();
})->with([false, true]);

it('identifies booking requests by their client company and keeps the delegate separate', function () {
    $booking = app(DossierBooking::class)->pending($this->actor)->sole();
    expect($booking['company_name'])->toBe('Société cliente fictive')
        ->and($booking['client_company_name'])->toBe('Société cliente fictive')
        ->and($booking['delegataire_company_name'])->toBe('Délégataire fictif');
    $this->dossier->update(['client_id' => null, 'client_contact' => ['company_name' => '', 'name' => 'Client manuel fictif']]);
    expect(app(DossierBooking::class)->pending($this->actor)->sole()['company_name'])->toBe('Client manuel fictif');
});
