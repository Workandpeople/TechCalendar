<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['admin' => false, 'role' => 0]);
    $this->actingAs($this->actor);
    $this->dossier = Dossier::create(['reference' => 'MAP-TEST', 'address' => '1 rue Exemple', 'postal_code' => '75001', 'city' => 'Paris']);
    $this->previewUrl = route('v2.dossiers.map', $this->dossier);
    $this->booking = function (array $attributes = []) {
        $service = Service::create(['type' => 'COFFRAC', 'name' => 'Test', 'average_duration_minutes' => 60]);
        $operation = $this->dossier->operations()->create(['service_id' => $service->id]);

        return Appointment::forceCreate([...[
            'dossier_operation_id' => $operation->id, 'service_id' => $service->id,
            'technician_id' => $this->actor->id, 'created_by' => $this->actor->id,
            'customer_first_name' => 'Test', 'customer_last_name' => 'Test', 'customer_phone' => '0100000000',
            'address' => '1 rue Exemple, 75001 Paris', 'latitude' => 48.85, 'longitude' => 2.35,
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'duration_minutes' => 60, 'status' => 'scheduled',
        ], ...$attributes]);
    };
});

it('reuses matching booking coordinates without any geocoding or database mutation', function () {
    ($this->booking)();
    $before = $this->dossier->fresh()->getAttributes();
    $this->mock(MapboxAddressGeocoder::class)->shouldNotReceive('geocode');
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates.latitude', 48.85)->assertJsonPath('coordinates.longitude', 2.35)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
});

it('uses imported coordinates only for the original complete address', function () {
    $this->dossier->update(['source' => 'coffrac']);
    DB::table('legacy_records')->insert(['source_table' => 'dossiers', 'source_id' => 42, 'dossier_id' => $this->dossier->id,
        'payload' => json_encode(['adress' => $this->dossier->address, 'zip' => '75001', 'ville' => 'Paris', 'latitude' => 48.85, 'longitude' => 2.35])]);
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->with('1 rue Exemple, 69001 Lyon')
        ->andReturn(['latitude' => 45.76, 'longitude' => 4.83]);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates.latitude', 48.85);
    $this->dossier->update(['postal_code' => '69001', 'city' => 'Lyon']);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates.latitude', 45.76);
});

it('caches geocoding per full address and does not reuse a booking from a different town', function () {
    ($this->booking)(['address' => '1 rue Exemple']);
    $before = $this->dossier->fresh()->getAttributes();
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->with('1 rue Exemple, 75001 Paris')
        ->andReturn(['latitude' => 48.86, 'longitude' => 2.36]);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates.latitude', 48.86);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates.latitude', 48.86);
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
});

it('handles missing addresses and failed geocoding without fabricating a point', function () {
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->andReturn(['latitude' => null, 'longitude' => null]);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates', null);
    $this->dossier->update(['address' => '']);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates', null)->assertJsonPath('message', 'Adresse non renseignée.');
});

it('ignores invalid coordinates and ordinary deleted bookings', function (array $attributes) {
    ($this->booking)($attributes);
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->andReturn(['latitude' => 45.76, 'longitude' => 4.83]);
    $this->getJson($this->previewUrl)->assertOk()->assertJsonPath('coordinates.latitude', 45.76);
})->with([
    [['latitude' => 0, 'longitude' => 0]],
    [['latitude' => 91, 'longitude' => 2]],
    [['deleted_at' => now()]],
]);

it('requires authentication and the dossier reading permission', function () {
    $this->mock(MapboxAddressGeocoder::class)->shouldNotReceive('geocode');
    $this->actingAs(User::factory()->create(['admin' => false, 'role' => 2]))->getJson($this->previewUrl)->assertForbidden();
    auth()->logout();
    $this->getJson($this->previewUrl)->assertUnauthorized();
    $this->actingAs($this->actor);
    config(['crm.v2_enabled' => false]);
    $this->getJson($this->previewUrl)->assertNotFound();
});
