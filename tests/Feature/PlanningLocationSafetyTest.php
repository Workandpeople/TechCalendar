<?php

use App\Models\Department;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.mapbox.token' => 'test-mapbox', 'cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function planningAddressFeature(array $override = []): array
{
    return array_replace([
        'id' => 'address.safe', 'place_type' => ['address'], 'relevance' => 1,
        'text' => 'Les Rivieres', 'place_name' => 'Les Rivieres, 41110 Testville, France',
        'center' => [1.3, 47.3], 'properties' => ['accuracy' => 'point'],
        'context' => [['id' => 'postcode.1', 'text' => '41110'], ['id' => 'place.1', 'text' => 'Testville']],
    ], $override);
}

it('does not turn a city poor match or inconsistent postcode into an exact planning location', function (array $override) {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [planningAddressFeature($override)]])]);
    $result = app(MapboxAddressGeocoder::class)->geocode('Les Rivieres, 41110 Testville');
    expect($result['latitude'])->toBeNull()->and($result['longitude'])->toBeNull()->and($result['warnings'])->not->toBeEmpty();
})->with([
    [['place_type' => ['place']]],
    [['relevance' => 0.4]],
    [['properties' => ['accuracy' => 'approximate']]],
    [['context' => [['id' => 'postcode.1', 'text' => '81110'], ['id' => 'place.1', 'text' => 'Testville']]]],
    [['context' => [['id' => 'postcode.1', 'text' => '41110'], ['id' => 'place.1', 'text' => 'Autreville']]]],
    [['center' => [181, 47]]],
]);

it('selects a precise consistent rural address instead of the first approximate result', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [
        planningAddressFeature(['place_type' => ['place']]), planningAddressFeature(['center' => [181, 47]]), planningAddressFeature(),
    ]])]);
    $result = app(MapboxAddressGeocoder::class)->geocode('Les Rivieres, 41110 Testville');
    expect($result['latitude'])->toBe(47.3)->and($result['longitude'])->toBe(1.3)
        ->and($result['postal_code'])->toBe('41110')->and($result['warnings'])->toBe([]);
});

it('checks the selected imported dossier rather than trusting historical GPS and caches only that check', function (bool $matches) {
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $dossier = Dossier::create(['source' => 'coffrac', 'reference' => 'LOCATION', 'address' => 'Les Rivieres', 'postal_code' => '41110', 'city' => 'Testville']);
    $operation = $dossier->operations()->create([]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    DB::table('legacy_records')->insert(['source_table' => 'dossiers', 'source_id' => 1, 'dossier_id' => $dossier->id,
        'payload' => json_encode(['adress' => 'Les Rivieres', 'zip' => '41110', 'ville' => 'Testville', 'latitude' => 43, 'longitude' => 2])]);
    $before = $dossier->fresh()->getAttributes();
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$matches ? planningAddressFeature() : planningAddressFeature(['place_type' => ['place']])]]),
        'geo.api.gouv.fr/communes*' => Http::response([])]);
    $booking = app(DossierBooking::class);
    expect($booking->pending($actor))->toHaveCount(1);
    Http::assertNothingSent();
    foreach (range(1, 2) as $attempt) {
        $selected = $booking->find($actor, 'dossier-'.$operation->id);
        expect($selected['latitude'])->toBe($matches ? 47.3 : null)
            ->and($selected['longitude'])->toBe($matches ? 1.3 : null);
    }
    Http::assertSentCount($matches ? 1 : 2);
    expect($dossier->fresh()->getAttributes())->toBe($before);
})->with([true, false]);

it('includes the nearest qualified technician even when three other technicians cover the sector', function () {
    config(['services.mapbox.token' => null]);
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $service = Service::create(['name' => 'Qualification', 'type' => 'COFRAC', 'average_duration_minutes' => 40]);
    Department::firstOrCreate(['code' => '41'], ['name' => 'Secteur']);
    foreach ([44, 44.1, 44.2] as $latitude) {
        $far = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => $latitude, 'longitude' => 2, 'day_start_time' => '08:00', 'day_end_time' => '18:00']);
        $far->services()->attach($service);
        $far->departments()->attach('41');
    }
    $near = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 47.31, 'longitude' => 1.3, 'day_start_time' => '08:00', 'day_end_time' => '18:00']);
    $near->services()->attach($service);
    $response = $this->actingAs($actor)->postJson(route('planner.book.analyze'), ['manual_appointment' => [
        'first_name' => 'Test', 'last_name' => 'Local', 'phone' => '0600000000', 'address' => 'Les Rivieres, 41110 Testville',
        'department_code' => '41', 'latitude' => 47.3, 'longitude' => 1.3, 'service_id' => $service->id,
    ]])->assertOk()->assertJsonCount(4, 'technicians');
    expect($response->json('technicians.0.id'))->toBe($near->id)
        ->and($response->json('technicians.0.covers_requested_department'))->toBeFalse();
    Http::assertNothingSent();
});
