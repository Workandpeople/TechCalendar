<?php

use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use App\Services\PlanningAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'cache.default' => 'array', 'services.mapbox.token' => 'fixture-token']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Cache::flush();
    $this->communes = json_decode(file_get_contents(__DIR__.'/../Fixtures/french-communes.json'), true, 512, JSON_THROW_ON_ERROR);
    $this->fakeLocations = function (): void {
        Http::fake([
            'api.mapbox.com/*' => Http::response(['features' => []]),
            'geo.api.gouv.fr/communes*' => fn ($request) => Http::response(array_values(array_filter($this->communes,
                fn ($row) => in_array($request['codePostal'], $row['codesPostaux'], true)))),
        ]);
    };
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('recovers the commune from a full unrecognized address while ignoring earlier cadastral numbers', function (string $address) {
    ($this->fakeLocations)();
    $result = app(PlanningAddressGeocoder::class)->geocode($address);
    expect($result)->toMatchArray(['latitude' => 46.4089, 'longitude' => 5.1573, 'precision' => 'city', 'city' => 'Lescheroux'])
        ->and($result['warnings'])->not->toBeEmpty();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://geo.api.gouv.fr/') && $request['codePostal'] === '01560' && ! isset($request['nom']));
})->with([
    'Lieu-dit Les Rivieres, 01560 Lescheroux',
    'Lieu-dit Les Rivieres, 01560, Lescheroux, Ain, France',
    'Section 12345 AB, lieu dit inconnu, 01560 LESCHEROUX, France',
    'Lieu dit inconnu 01560 Lescheroux CEDEX 2',
]);

it('prefers explicit dossier locality fields over ambiguous numbers in the retained street', function () {
    ($this->fakeLocations)();
    $result = app(PlanningAddressGeocoder::class)->geocode('Parcelle 32380 AB, batiment inconnu', '01560', 'Lescheroux');
    expect($result['city'])->toBe('Lescheroux');
    Http::assertSentCount(2);
});

it('only accepts a postcode without a town when exactly one commune is associated', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => []]), 'geo.api.gouv.fr/communes*' => Http::response([$this->communes[0]])]);
    $result = app(PlanningAddressGeocoder::class)->geocode('Lieu dit introuvable, 01560');
    expect($result['city'])->toBe('Lescheroux')->and($result['precision'])->toBe('city');
    Http::assertSentCount(2);
});

it('does not guess a commune from an ambiguous postal code a bad city or a non-postal number', function (string $address, int $requests) {
    ($this->fakeLocations)();
    $result = app(PlanningAddressGeocoder::class)->geocode($address);
    expect($result['latitude'])->toBeNull()->and($result['longitude'])->toBeNull();
    Http::assertSentCount($requests);
})->with([
    ['Lieu dit introuvable, 01560', 2],
    ['Lieu dit introuvable, 01560 Ville inexistante', 2],
    ['Reference A01560B sans commune', 1],
    ['Telephone 0601560123', 1],
    ['Adresse sans code postal', 1],
]);

it('keeps a precise match without making a commune request', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [[
        'id' => 'address.valid', 'place_type' => ['address'], 'relevance' => 1, 'text' => 'Rue Test', 'address' => '8',
        'place_name' => '8 Rue Test, 01560 Lescheroux', 'center' => [5.15, 46.4],
        'context' => [['id' => 'postcode.1', 'text' => '01560'], ['id' => 'place.1', 'text' => 'Lescheroux']],
    ]]])]);
    $result = app(PlanningAddressGeocoder::class)->geocode('8 Rue Test, 01560 Lescheroux');
    expect($result['precision'])->toBe('address');
    Http::assertSentCount(1);
});

it('leaves the failure explicit if the commune service is unavailable', function (bool $timeout) {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => []]), 'geo.api.gouv.fr/communes*' => $timeout
        ? fn () => throw new ConnectionException('Fixture timeout') : Http::response([], 503)]);
    expect(app(PlanningAddressGeocoder::class)->geocode('Lieu dit, 01560 Lescheroux')['latitude'])->toBeNull();
    Http::assertSentCount($timeout ? 1 : 2);
})->with([false, true]);

it('recovers only the selected dossier caches the result and preserves the full address and business data', function () {
    ($this->fakeLocations)();
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $dossier = Dossier::create(['reference' => 'RECOVERY', 'address' => 'Lieu-dit à conserver', 'postal_code' => '01560', 'city' => 'Lescheroux']);
    $operation = $dossier->operations()->create([]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $before = $dossier->fresh()->getAttributes();
    $booking = app(DossierBooking::class);
    $booking->pending($actor);
    Http::assertNothingSent();
    foreach (range(1, 2) as $attempt) {
        $selected = $booking->find($actor, 'dossier-'.$operation->id);
        expect($selected['latitude'])->toBe(46.4089)->and($selected['location_precision'])->toBe('city')
            ->and($selected['external_payload']['location_precision'])->toBe('city')
            ->and($selected['location_warning'])->toContain('approximative');
    }
    expect($dossier->fresh()->getAttributes())->toBe($before)->and($dossier->activities()->count())->toBe(0);
    Http::assertSentCount(2);
});

it('analyzes a previously ungeocoded dossier without the missing GPS error and exposes the approximate location', function () {
    ($this->fakeLocations)();
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $service = Service::create(['name' => 'Visite test', 'type' => 'COFRAC', 'average_duration_minutes' => 40]);
    $dossier = Dossier::create(['reference' => 'RECOVERY-ANALYZE', 'address' => 'Lieu-dit inconnu, 01560 Lescheroux', 'city' => '']);
    $operation = $dossier->operations()->create(['service_id' => $service->id]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->actingAs($actor)->postJson(route('planner.book.analyze'), ['crm_appointment_id' => 'dossier-'.$operation->id])
        ->assertOk()->assertJsonPath('crm_appointment.latitude', 46.4089)->assertJsonPath('crm_appointment.longitude', 5.1573)
        ->assertJsonPath('filters.department_code', '01')->assertJsonPath('crm_appointment.postal_code', '01560')
        ->assertJsonPath('crm_appointment.location_precision', 'city')
        ->assertJsonPath('crm_appointment.external_payload.location_precision', 'city');
    Http::assertSentCount(2);
    expect($dossier->fresh()->address)->toBe('Lieu-dit inconnu, 01560 Lescheroux');
});
