<?php

use App\Models\Dossier;
use App\Models\User;
use App\Services\FrenchCommuneGeocoder;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'cache.default' => 'array', 'services.mapbox.token' => 'fake-mapbox-token']);
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->communes = json_decode(file_get_contents(__DIR__.'/../Fixtures/french-communes.json'), true, 512, JSON_THROW_ON_ERROR);
    $this->geocoder = app(MapboxAddressGeocoder::class);
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'COMMUNE-TEST', 'address' => 'Adresse initiale', 'postal_code' => '75001', 'city' => 'Paris']);
    $this->fakeCommunes = function (): void {
        Http::fake(['geo.api.gouv.fr/communes*' => fn ($request) => Http::response(array_values(array_filter($this->communes,
            fn ($row) => ! isset($request['codePostal']) || in_array($request['codePostal'], $row['codesPostaux'], true))))]);
    };
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('suggests the requested commune instead of another commune sharing its postal code', function (string $query, string $city, string $identifier) {
    ($this->fakeCommunes)();
    $result = $this->geocoder->suggestions($query);
    expect($result)->toHaveCount(1)->and($result[0]['city'])->toBe($city)->and($result[0]['id'])->toBe($identifier);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://geo.api.gouv.fr/communes?') && ! isset($request['access_token']));
})->with([
    ['01560 Lesch', 'Lescheroux', 'commune.01212_01560'], ['01560 LESCHEROUX', 'Lescheroux', 'commune.01212_01560'],
    ['32380 BIVE', 'Bivès', 'commune.32055_32380'], ['32380 Bivès', 'Bivès', 'commune.32055_32380'],
]);

it('validates the exact coordinates of the chosen commune after canonical suggestion selection', function (string $query, string $identifier, string $name, float $latitude, float $longitude) {
    ($this->fakeCommunes)();
    $this->actingAs($this->actor)->patch(route('v2.dossiers.address.update', $this->dossier),
        ['version' => $this->dossier->fresh()->version, 'address_query' => $query, 'mapbox_id' => $identifier])->assertSessionHasNoErrors()->assertRedirect();
    $saved = $this->dossier->fresh();
    expect($saved->city)->toBe($name)->and($saved->inspection_geocode)->toMatchArray([
        'latitude' => $latitude, 'longitude' => $longitude, 'provider' => 'geo_api_gouv_fr', 'precision' => 'city', 'mapbox_id' => $identifier,
    ])->and($saved->activities()->sole()->kind)->toBe('address_corrected');
})->with([
    ['01560 Lescheroux, Ain, France', 'commune.01212_01560', 'Lescheroux', 46.4089, 5.1573],
    ['Lescheroux, Ain, France', 'commune.01212_01560', 'Lescheroux', 46.4089, 5.1573],
    ['32380 Bivès, Gers, France', 'commune.32055_32380', 'Bivès', 43.8317, 0.8087],
]);

it('replaces Mapbox postal or place suggestions with independently verifiable commune choices', function () {
    ($this->fakeCommunes)();
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [
        ['id' => 'place.lescheroux', 'place_type' => ['place'], 'place_name' => 'Lescheroux, Ain, France'],
        ['id' => 'place.other', 'place_type' => ['place'], 'place_name' => 'Franchelins, Ain, France'],
    ]])]);
    $result = $this->geocoder->suggestions('Lescheroux, Ain, France');
    expect($result)->toHaveCount(1)->and($result[0]['id'])->toBe('commune.01212_01560');
    $resolved = $this->geocoder->geocodeCity($result[0]['label'], $result[0]['id']);
    expect($resolved['city'])->toBe('Lescheroux')->and($resolved['latitude'])->toBe(46.4089);
});

it('does not require a Mapbox token to resolve an explicitly requested commune', function () {
    config(['services.mapbox.token' => '']);
    ($this->fakeCommunes)();
    expect($this->geocoder->suggestions('01560 Lescheroux')[0]['city'])->toBe('Lescheroux')
        ->and($this->geocoder->geocodeCity('01560 Lescheroux')['latitude'])->toBe(46.4089);
    Http::assertSentCount(1);
});

it('reuses the public postal list across typing and validation without caching dossier data', function () {
    ($this->fakeCommunes)();
    foreach (['01560 Les', '01560 Lesch', '01560 Lescheroux'] as $query) {
        expect($this->geocoder->suggestions($query))->toHaveCount(1);
    }
    expect($this->geocoder->geocodeCity('01560 Lescheroux', 'commune.01212_01560')['city'])->toBe('Lescheroux');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => ! isset($request['nom']) && $request['codePostal'] === '01560');
});

it('does not geocode an ambiguous postal code or a different commune silently', function (string $query, ?string $identifier) {
    ($this->fakeCommunes)();
    $before = $this->dossier->fresh()->getAttributes();
    $this->actingAs($this->actor)->patchJson(route('v2.dossiers.address.update', $this->dossier),
        ['version' => $this->dossier->fresh()->version, 'address_query' => $query, 'mapbox_id' => $identifier])
        ->assertUnprocessable()->assertJsonValidationErrors('address_query');
    expect($this->dossier->fresh()->getAttributes())->toBe($before)->and($this->dossier->activities()->count())->toBe(0);
})->with([
    ['01560 Commune inexistante', null], ['01560 Lescheroux', 'commune.01388_01560'],
    ['32380 Bives', 'commune.01212_01560'], ['01560 Lescheroux', 'postcode.old'],
]);

it('lists all communes for a postal-only search but requires a choice before resolving it', function () {
    ($this->fakeCommunes)();
    expect($this->geocoder->suggestions('01560'))->toHaveCount(2);
    expect(fn () => app(FrenchCommuneGeocoder::class)->geocode('01560'))->toThrow(ValidationException::class);
    expect($this->geocoder->geocodeCity('01560', 'commune.01212_01560')['city'])->toBe('Lescheroux');
});

it('does not cache outages and leaves the old dossier location untouched', function () {
    $before = $this->dossier->fresh()->getAttributes();
    Http::fake(['geo.api.gouv.fr/communes*' => Http::sequence()->push([], 503)->push([$this->communes[0]])]);
    expect(fn () => $this->geocoder->suggestions('01560 Lescheroux'))->toThrow(ValidationException::class);
    expect($this->geocoder->suggestions('01560 Lescheroux'))->toHaveCount(1);
    Http::assertSentCount(2);
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
});

it('rejects malformed geometry identities and postal associations', function (array $changes) {
    $row = [...$this->communes[0], ...$changes];
    Http::fake(['geo.api.gouv.fr/communes*' => Http::response([$row])]);
    expect($this->geocoder->geocodeCity('01560 Lescheroux', 'commune.01212_01560')['latitude'])->toBeNull();
})->with([
    [['centre' => ['type' => 'Polygon', 'coordinates' => [5, 46]]]],
    [['centre' => ['type' => 'Point', 'coordinates' => [0, 0]]]],
    [['centre' => ['type' => 'Point', 'coordinates' => [5, 146]]]],
    [['centre' => ['type' => 'Point', 'coordinates' => ['lon' => 5, 'lat' => 46]]]],
    [['code' => '../123']], [['codesPostaux' => ['75001']]],
]);
