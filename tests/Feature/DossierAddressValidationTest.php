<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierLocation;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.mapbox.token' => 'fixture-token']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'ADDRESS-VALIDATION', 'kind' => 'client', 'name' => 'Client test', 'is_active' => true]);
    $this->client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $organisation->id,
        'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client')]);
    $service = Service::create(['name' => 'Test adresse', 'type' => 'COFRAC', 'average_duration_minutes' => 40]);
    $this->payload = ['company_name' => 'Test', 'address' => '8 rue Test', 'postal_code' => '75001', 'city' => 'Paris',
        'service_ids' => [$service->id], 'submission_id' => (string) Str::uuid(), 'address_precision' => 'address', 'mapbox_id' => 'address.selected'];
    $this->feature = ['id' => 'address.selected', 'place_type' => ['address'], 'relevance' => 1,
        'address' => '8', 'text' => 'rue Test', 'place_name' => '8 rue Test, 75001 Paris, France', 'center' => [2.35, 48.85],
        'context' => [['id' => 'postcode.1', 'text' => '75001'], ['id' => 'place.1', 'text' => 'Paris']]];
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('rejects unselected addresses on internal and external forms before any HTTP request', function (bool $external) {
    $this->actingAs($external ? $this->client : $this->staff)->postJson(route($external ? 'client.dossiers.store' : 'v2.dossiers.store'),
        [...$this->payload, 'mapbox_id' => null])->assertUnprocessable()->assertJsonValidationErrors('mapbox_id');
    expect(Dossier::count())->toBe(0);
    Http::assertNothingSent();
})->with([false, true]);

it('renders a required address and five digit postal validation on both creation forms', function (bool $external) {
    $response = $this->actingAs($external ? $this->client : $this->staff)
        ->withSession(['_old_input' => ['address_precision' => 'city']])
        ->get(route($external ? 'client.dossiers.create' : 'v2.dossiers.create'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//input[@name="address" and @required]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="postal_code" and @required and @pattern="[0-9]{5}"]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="city" and @required]')->length)->toBe(1);
    Http::assertNothingSent();
})->with([false, true]);

it('requires a selected address at the business service boundary too', function () {
    expect(fn () => app(DossierRegistry::class)->create($this->staff, [...$this->payload, 'mapbox_id' => null]))
        ->toThrow(ValidationException::class);
    expect(Dossier::count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts the chosen suggestion even when it is not Mapbox first result', function (bool $external) {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [[...$this->feature, 'id' => 'address.other'], $this->feature]])]);
    $this->actingAs($external ? $this->client : $this->staff)->postJson(route($external ? 'client.dossiers.store' : 'v2.dossiers.store'), $this->payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DossierLocation::coordinates(Dossier::sole()))->toBe(['latitude' => 48.85, 'longitude' => 2.35]);
    Http::assertSentCount(1);
})->with([false, true]);

it('never trusts a forged stale or unresolvable suggestion', function (string $failure) {
    $features = match ($failure) {
        'other_id' => [[...$this->feature, 'id' => 'address.other']],
        'other_city' => [[...$this->feature, 'context' => [['id' => 'postcode.1', 'text' => '69001'], ['id' => 'place.1', 'text' => 'Lyon']]]],
        default => [],
    };
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => $features])]);
    $this->actingAs($this->staff)->postJson(route('v2.dossiers.store'), $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('address');
    expect(Dossier::count())->toBe(0);
})->with(['other_id', 'other_city', 'empty']);

it('requires a five digit postcode and a city in free address mode without requiring a suggestion', function (array $changes, string $field) {
    $this->actingAs($this->client)->postJson(route('client.dossiers.store'), [...$this->payload, 'address_precision' => 'city', 'mapbox_id' => null, ...$changes])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Dossier::count())->toBe(0);
    Http::assertNothingSent();
})->with([[['postal_code' => '1234'], 'postal_code'], [['postal_code' => ''], 'postal_code'], [['city' => ''], 'city']]);

it('resolves manually entered postcode and city and retains the free display address', function (bool $external) {
    Http::fake(['geo.api.gouv.fr/communes*' => Http::response([['code' => '01212', 'nom' => 'Lescheroux', 'codesPostaux' => ['01560'],
        'centre' => ['type' => 'Point', 'coordinates' => [5.1573, 46.4089]]]])]);
    $this->actingAs($external ? $this->client : $this->staff)->postJson(route($external ? 'client.dossiers.store' : 'v2.dossiers.store'),
        [...$this->payload, 'address_precision' => 'city', 'mapbox_id' => null, 'postal_code' => '01560', 'city' => 'Lescheroux'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Dossier::sole()->address)->toBe('8 rue Test')->and(Dossier::sole()->inspection_geocode['precision'])->toBe('city');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['codePostal'] === '01560' && ! isset($request['access_token']));
})->with([false, true]);
