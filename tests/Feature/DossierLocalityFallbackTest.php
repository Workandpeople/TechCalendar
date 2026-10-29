<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.mapbox.token' => 'fake-mapbox-token', 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'LOCALITY-TEST', 'kind' => 'client', 'name' => 'Client test', 'is_active' => true]);
    $this->client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $this->organisation->id,
        'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client')]);
    $this->service = Service::create(['name' => 'Test', 'type' => 'COFRAC', 'average_duration_minutes' => 45]);
    $this->postcode = ['id' => 'postcode.test', 'place_type' => ['postcode'], 'text' => '01560', 'place_name' => '01560 Lescheroux, France',
        'relevance' => 1, 'center' => [5.15, 46.4], 'context' => [['id' => 'place.test', 'text' => 'Lescheroux']]];
    $this->payload = ['company_name' => 'Bénéficiaire', 'address' => 'Lieu-dit introuvable', 'postal_code' => '01560', 'city' => 'Lescheroux',
        'address_precision' => 'city', 'mapbox_id' => 'postcode.test', 'service_ids' => [$this->service->id], 'submission_id' => (string) Str::uuid()];
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('offers Mapbox and the locality fallback on both creation forms without sending any request on load', function () {
    foreach ([[$this->staff, 'v2.dossiers.create'], [$this->client, 'client.dossiers.create']] as [$actor, $route]) {
        $this->actingAs($actor)->get(route($route))->assertOk()->assertSee('data-creation-address', false)
            ->assertSee('Adresse non trouvée')->assertSee('name="address_precision"', false)->assertSee('creation-city-options');
    }
    Http::assertNothingSent();
});

it('creates a dossier at an explicitly approximate locality without losing the original address', function (bool $external) {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->postcode]])]);
    $this->actingAs($external ? $this->client : $this->staff)->post(route($external ? 'client.dossiers.store' : 'v2.dossiers.store'), $this->payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $dossier = Dossier::sole();
    expect($dossier->address)->toBe('Lieu-dit introuvable')->and($dossier->postal_code)->toBe('01560')
        ->and($dossier->city)->toBe('Lescheroux')->and($dossier->inspection_geocode['precision'])->toBe('city')
        ->and(DossierLocation::coordinates($dossier))->toBe(['latitude' => 46.4, 'longitude' => 5.15]);
    $selected = app(DossierBooking::class)->find($this->staff, 'dossier-'.$dossier->operations()->sole()->id);
    expect($selected['latitude'])->toBe(46.4)->and($selected['location_precision'])->toBe('city')
        ->and($selected['location_warning'])->toContain('approximative');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['types'] === 'postcode' && str_contains($request->url(), rawurlencode('01560 Lescheroux')));
})->with([false, true]);

it('allows an absent street only when the postal code and city are explicitly supplied', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->postcode]])]);
    $this->actingAs($this->client)->post(route('client.dossiers.store'), [...$this->payload, 'address' => ''])->assertSessionHasNoErrors();
    expect(Dossier::sole()->address)->toBe('Lescheroux')->and(DossierLocation::address(Dossier::sole()))->toBe('01560 Lescheroux');
});

it('rejects mismatched postal codes communes low relevance unsafe coordinates and unavailable Mapbox', function (string $failure) {
    $feature = match ($failure) {
        'postal' => [...$this->postcode, 'text' => '75001'], 'city' => [...$this->postcode, 'context' => [['id' => 'place.test', 'text' => 'Paris']]],
        'point' => [...$this->postcode, 'center' => [0, 0]], 'relevance' => [...$this->postcode, 'relevance' => .3],
        default => $this->postcode,
    };
    Http::fake(['api.mapbox.com/*' => $failure === 'unavailable' ? Http::response([], 503) : Http::response(['features' => [$feature]])]);
    $this->actingAs($this->client)->postJson(route('client.dossiers.store'), $this->payload)->assertUnprocessable()->assertJsonValidationErrors('city');
    expect(Dossier::count())->toBe(0);
})->with(['postal', 'city', 'point', 'relevance', 'unavailable']);

it('offers locality suggestions without disclosing the token or permitting delegataires to create dossiers', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->postcode]])]);
    $url = route('client.dossiers.address.creation-suggestions', ['q' => '01560 Lescheroux', 'mode' => 'city']);
    $this->actingAs($this->client)->getJson($url)->assertOk()->assertJsonPath('suggestions.0.postal_code', '01560')
        ->assertJsonPath('suggestions.0.city', 'Lescheroux')->assertDontSee('fake-mapbox-token');
    $this->organisation->update(['kind' => 'delegataire']);
    $this->client->forceFill(['portal_permissions' => ClientAccess::defaults('delegataire')])->save();
    $this->getJson($url)->assertForbidden();
    Http::assertSentCount(1);
});

it('accepts postal code and city in the correction modal and preserves audit and existing appointments', function () {
    $dossier = Dossier::create(['reference' => 'CITY-CORRECTION', 'address' => 'Mauvaise adresse', 'city' => 'Paris']);
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->postcode]])]);
    $this->actingAs($this->staff)->patch(route('v2.dossiers.address.update', $dossier), ['version' => $dossier->fresh()->version,
        'address_query' => '01560 Lescheroux', 'mapbox_id' => 'postcode.test'])->assertRedirect()->assertSessionHasNoErrors();
    expect($dossier->fresh()->inspection_geocode['precision'])->toBe('city')->and($dossier->fresh()->city)->toBe('Lescheroux')
        ->and($dossier->activities()->sole()->body)->toContain('approximative');
});

it('never treats identical approximate city points as proof that simultaneous appointments are nearby', function () {
    $appointment = Appointment::make(['address' => '01560 Lescheroux', 'latitude' => 46.4, 'longitude' => 5.15]);
    $location = ['address' => '01560 Lescheroux', 'latitude' => 46.4, 'longitude' => 5.15, 'external_payload' => ['location_precision' => 'city']];
    expect(app(AppointmentSlotAvailability::class)->sameSite($location, $appointment))->toBeFalse();
    $appointment->external_payload = ['location_precision' => 'city'];
    unset($location['external_payload']);
    expect(app(AppointmentSlotAvailability::class)->sameSite($location, $appointment))->toBeFalse();
});
