<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierLocation;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'cache.default' => 'array', 'services.mapbox.token' => 'fixture-mapbox-token']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Cache::flush();
    $this->actor = User::factory()->create(['admin' => false, 'role' => 0]);
    $this->dossier = Dossier::create(['source' => 'coffrac', 'reference' => 'ADDRESS-TEST', 'company_name' => 'Entreprise Test',
        'address' => '1 rue Exemple', 'postal_code' => '75001', 'city' => 'Paris']);
    $this->service = Service::create(['name' => 'Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    DB::table('legacy_records')->insert(['source_table' => 'dossiers', 'source_id' => 42, 'dossier_id' => $this->dossier->id,
        'payload' => json_encode(['adress' => '1 rue Exemple', 'zip' => '75001', 'ville' => 'Paris', 'latitude' => 43.5, 'longitude' => 4.5])]);
    $this->feature = ['id' => 'address.123', 'place_type' => ['address'], 'text' => 'rue Corrigée', 'address' => '8',
        'place_name' => '8 rue Corrigée, 69001 Lyon, France', 'center' => [4.83, 45.76], 'relevance' => 1,
        'context' => [['id' => 'postcode.1', 'text' => '69001'], ['id' => 'place.1', 'text' => 'Lyon'], ['id' => 'country.1', 'text' => 'France']]];
    $this->data = ['_form' => 'address', 'version' => 1, 'address_query' => $this->feature['place_name'], 'mapbox_id' => 'address.123'];
    $this->url = route('v2.dossiers.address.update', $this->dossier);
    $this->suggestionsUrl = route('v2.dossiers.address.suggestions', $this->dossier);
    $this->actingAs($this->actor);
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('offers a small address dialog directly on the intervention address with a Valider button', function () {
    $response = $this->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@id="synthese"]//dd//button[@data-dialog-target="corriger-adresse"]')->length)->toBe(1)
        ->and($xpath->query('//dialog[@id="corriger-adresse" and contains(@class,"gc-address-dialog")]')->length)->toBe(1)
        ->and($xpath->query('//dialog[@id="corriger-adresse"]//input[@role="combobox" and @aria-autocomplete="list"]')->length)->toBe(1)
        ->and($xpath->query('//dialog[@id="corriger-adresse"]//button[@type="submit"]')->item(0)->textContent)->toBe('Valider');
    Http::assertNothingSent();
});

it('queries Mapbox for suggestions without exposing the token or mutating the dossier', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->feature, ['id' => '../unsafe', 'place_name' => 'Bad'], 'bad']])]);
    $before = $this->dossier->fresh()->getAttributes();
    $this->getJson($this->suggestionsUrl.'?'.http_build_query(['q' => '8 rue Corrigée']))->assertOk()
        ->assertJsonPath('suggestions.0.id', 'address.123')->assertJsonCount(1, 'suggestions')
        ->assertDontSee('fixture-mapbox-token')->assertHeader('Cache-Control', 'no-store, private');
    Http::assertSent(fn ($request) => $request['autocomplete'] === 'true' && $request['types'] === 'address,postcode,place,locality'
        && $request['access_token'] === 'fixture-mapbox-token');
    expect($this->dossier->fresh()->getAttributes())->toBe($before);
});

it('stores the server resolved address and durable point with private audit and no status or appointment change', function () {
    $technician = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => null, 'longitude' => null]);
    $appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id, 'service_id' => $this->service->id,
        'technician_id' => $technician->id, 'created_by' => $this->actor->id, 'customer_first_name' => 'Test',
        'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => '1 rue Exemple, 75001 Paris',
        'latitude' => 43.5, 'longitude' => 4.5, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
    $appointmentBefore = $appointment->fresh()->getAttributes();
    $sourceBefore = DB::table('legacy_records')->value('payload');
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->feature]])]);
    $this->patch($this->url, [...$this->data, 'latitude' => 0, 'longitude' => 0, 'address' => 'FORGED'])
        ->assertSessionHasNoErrors()->assertRedirect(route('v2.dossiers.show', $this->dossier).'#synthese');
    $dossier = $this->dossier->fresh();
    expect($dossier->address)->toBe('8 rue Corrigée')->and($dossier->postal_code)->toBe('69001')->and($dossier->city)->toBe('Lyon')
        ->and($dossier->version)->toBe(2)->and(DossierLocation::coordinates($dossier))->toBe(['latitude' => 45.76, 'longitude' => 4.83])
        ->and($appointment->fresh()->getAttributes())->toBe($appointmentBefore)
        ->and($this->operation->fresh()->legacy_status)->toBe('PRISE DE RDV')
        ->and(DB::table('legacy_records')->value('payload'))->toBe($sourceBefore)
        ->and($dossier->statusChanges()->count())->toBe(0);
    $audit = $dossier->activities()->sole();
    expect($audit->kind)->toBe('address_corrected')->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/'.rawurlencode($this->data['address_query']).'.json') && $request['autocomplete'] === 'false');
    Cache::flush();
    $this->getJson(route('v2.dossiers.map', $dossier))->assertOk()->assertJsonPath('coordinates.latitude', 45.76);
    $this->postJson(route('planner.book.analyze'), ['replace_appointment_id' => $appointment->id])->assertOk()
        ->assertJsonPath('crm_appointment.latitude', 45.76)->assertJsonPath('crm_appointment.address', '8 rue Corrigée, 69001 Lyon');
    Http::assertSentCount(1);
});

it('regeocodes the same address and overrides old imported points even after cache expiry', function () {
    $feature = [...$this->feature, 'text' => 'rue Exemple', 'address' => '1', 'place_name' => '1 rue Exemple, 75001 Paris, France',
        'context' => [['id' => 'postcode.1', 'text' => '75001'], ['id' => 'place.1', 'text' => 'Paris']]];
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$feature]])]);
    $this->patch($this->url, [...$this->data, 'address_query' => '1 rue Exemple, 75001 Paris', 'mapbox_id' => null])->assertSessionHasNoErrors();
    Cache::flush();
    $this->getJson(route('v2.dossiers.map', $this->dossier))->assertJsonPath('coordinates.latitude', 45.76);
    $pending = app(DossierBooking::class)->pending($this->actor)->sole();
    expect($pending['latitude'])->toBe(45.76)->and($pending['longitude'])->toBe(4.83);
    Http::assertSentCount(1);
});

it('discards the saved point when the address is subsequently edited through another form', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [$this->feature]])]);
    $this->patch($this->url, $this->data)->assertSessionHasNoErrors();
    $this->dossier->fresh()->update(['city' => 'Paris', 'postal_code' => '75001']);
    expect(DossierLocation::coordinates($this->dossier->fresh()))->toBeNull();
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->once()->andReturn(['latitude' => 48.85, 'longitude' => 2.35]);
    $this->getJson(route('v2.dossiers.map', $this->dossier))->assertJsonPath('coordinates.latitude', 48.85);
});

it('leaves the address unchanged when Mapbox is unavailable empty invalid or too imprecise', function (string $failure) {
    $before = $this->dossier->fresh()->getAttributes();
    Http::fake(['api.mapbox.com/*' => match ($failure) {
        'empty' => Http::response(['features' => []]),
        'invalid' => Http::response(['features' => [[...$this->feature, 'center' => ['invalid', 45.76]]]]),
        'city' => Http::response(['features' => [[...$this->feature, 'place_type' => ['place']]]]),
        'different_selection' => Http::response(['features' => [[...$this->feature, 'id' => 'address.456']]]),
        'timeout' => fn () => throw new ConnectionException('fixture timeout'),
        default => Http::response([], 503),
    }]);
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('address_query')->assertDontSee('fixture-mapbox-token');
    expect($this->dossier->fresh()->getAttributes())->toBe($before)->and($this->dossier->activities()->count())->toBe(0);
})->with(['empty', 'invalid', 'city', 'different_selection', 'timeout', 'unavailable']);

it('detects concurrent edits before and after the network request without overwriting them', function (bool $duringRequest) {
    if ($duringRequest) {
        Http::fake(['api.mapbox.com/*' => function () {
            Dossier::whereKey($this->dossier->id)->update(['version' => 2, 'city' => 'Modification concurrente']);

            return Http::response(['features' => [$this->feature]]);
        }]);
    } else {
        $this->dossier->forceFill(['version' => 2])->save();
    }
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('version');
    expect($this->dossier->fresh()->address)->toBe('1 rue Exemple')->and($this->dossier->fresh()->version)->toBe(2)
        ->and($this->dossier->activities()->count())->toBe(0);
    if (! $duringRequest) {
        Http::assertNothingSent();
    }
})->with([false, true]);

it('forbids the editor and search to external users technicians and disabled accounts', function (int $role, bool $disabled) {
    $user = User::factory()->create(['admin' => false, 'role' => $role]);
    if ($disabled) {
        $user->delete();
    }
    $this->actingAs($user)->patchJson($this->url, $this->data)->assertForbidden();
    $this->actingAs($user)->getJson($this->suggestionsUrl.'?q=Paris')->assertForbidden();
    Http::assertNothingSent();
})->with([[User::ROLE_EXTERNAL, false], [2, false], [0, true]]);

it('rejects unsafe identifiers and invalid searches before querying Mapbox', function () {
    $this->patchJson($this->url, [...$this->data, 'mapbox_id' => 'https://attacker.invalid/address.123'])->assertUnprocessable();
    $this->getJson($this->suggestionsUrl.'?q=ab')->assertUnprocessable();
    Http::assertNothingSent();
});
