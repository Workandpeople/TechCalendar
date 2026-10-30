<?php

use App\Models\Dossier;
use App\Models\User;
use App\Services\Crm\DossierAddressCorrection;
use App\Services\Crm\DossierLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'cache.default' => 'array']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => 0, 'admin' => false]));
    $this->dossier = Dossier::create(['reference' => 'RETAIN-STREET', 'address' => 'Lieu-dit Les Rivières, bâtiment B', 'postal_code' => '01560', 'city' => 'Lescheroux']);
    $this->commune = ['code' => '01212', 'nom' => 'Lescheroux', 'codesPostaux' => ['01560'],
        'centre' => ['type' => 'Point', 'coordinates' => [5.1573, 46.4089]], 'departement' => ['nom' => 'Ain']];
    Http::fake(['geo.api.gouv.fr/communes*' => Http::response([$this->commune])]);
    $this->data = ['version' => 1, 'address_query' => '01560 Lescheroux, Ain, France', 'mapbox_id' => 'commune.01212_01560'];
    $this->simulateOldCorrection = function (bool $coherent = true): void {
        $before = $this->dossier->only(['address', 'postal_code', 'city', 'inspection_geocode']);
        $this->dossier->update(['address' => 'Lescheroux']);
        $this->dossier->forceFill(['inspection_geocode' => DossierLocation::metadata($this->dossier,
            ['latitude' => 46.4089, 'longitude' => 5.1573, 'precision' => 'city', 'provider' => 'geo_api_gouv_fr'])])->save();
        $after = $this->dossier->only(array_keys($before));
        if (! $coherent) {
            $after['city'] = 'Autre commune';
        }
        $this->dossier->activities()->create(['kind' => 'address_corrected', 'body' => 'Ancienne correction', 'changed_fields' => compact('before', 'after')]);
    };
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('retains the full street while resolving and saving only the chosen commune', function () {
    $this->patch(route('v2.dossiers.address.update', $this->dossier), $this->data)->assertSessionHasNoErrors();
    $saved = $this->dossier->fresh();
    expect($saved->address)->toBe('Lieu-dit Les Rivières, bâtiment B')->and($saved->city)->toBe('Lescheroux')
        ->and(DossierLocation::coordinates($saved))->toBe(['latitude' => 46.4089, 'longitude' => 5.1573])
        ->and($saved->inspection_geocode['precision'])->toBe('city');
    $this->get(route('v2.dossiers.show', $saved))->assertOk()->assertSee('Lieu-dit Les Rivières, bâtiment B')
        ->assertSee('value="01560 Lescheroux"', false);
    $this->getJson(route('v2.dossiers.map', $saved))->assertOk()->assertJsonPath('precision', 'city')->assertJsonPath('coordinates.latitude', 46.4089);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['codePostal'] === '01560' && ! isset($r['nom']) && ! str_contains($r->url(), 'Rivi'));
});

it('allows completing the display street without ever passing it to the geocoder', function () {
    $street = 'Chemin inconnu <b>bâtiment C</b>';
    $this->patch(route('v2.dossiers.address.update', $this->dossier), [...$this->data, 'display_address' => $street])->assertSessionHasNoErrors();
    expect($this->dossier->fresh()->address)->toBe($street);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertSee($street)->assertDontSee($street, false);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['codePostal'] === '01560' && ! str_contains($r->url(), 'Chemin'));
});

it('prefills a proven historical street without mutating anything until the operator validates', function () {
    ($this->simulateOldCorrection)();
    $before = $this->dossier->fresh()->getAttributes();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('value="Lieu-dit Les Rivières, bâtiment B"', false);
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
    $this->patch(route('v2.dossiers.address.update', $this->dossier), $this->data)->assertSessionHasNoErrors();
    expect($this->dossier->fresh()->address)->toBe('Lieu-dit Les Rivières, bâtiment B');
});

it('never restores an unrelated history or replaces a newly edited street', function (bool $edited) {
    ($this->simulateOldCorrection)(false);
    if ($edited) {
        $this->dossier->update(['address' => 'Nouvelle voie choisie']);
    }
    expect(app(DossierAddressCorrection::class)->retainedAddress($this->dossier->fresh()))
        ->toBe($edited ? 'Nouvelle voie choisie' : 'Lescheroux');
    Http::assertNothingSent();
})->with([false, true]);

it('validates the retained street before any HTTP request or database write', function (mixed $value) {
    $before = $this->dossier->fresh()->getAttributes();
    $this->patchJson(route('v2.dossiers.address.update', $this->dossier), [...$this->data, 'display_address' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('display_address');
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
})->with(['too long' => str_repeat('a', 501), 'control' => "Rue\x01Test", 'array' => [['invalid']]]);
