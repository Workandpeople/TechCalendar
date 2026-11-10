<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierRegistry;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->mock(MapboxAddressGeocoder::class)->shouldReceive('geocode')->andReturn([
        'latitude' => 45.76, 'longitude' => 4.83, 'mapbox_id' => 'address.fixture',
    ]);
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->service = Service::create(['type' => 'COFRAC', 'name' => 'AGRI TH 117', 'average_duration_minutes' => 60]);
    $this->data = ['company_name' => 'Un autre bénéficiaire', 'phone' => '06 12 34 56 78',
        'address' => '10 rue de l’Église', 'postal_code' => '69001', 'city' => 'Lyon',
        'mapbox_id' => 'address.fixture', 'service_ids' => [$this->service->id]];
    $this->existing = Dossier::create(['reference' => 'PRIVATE-EXISTING', 'source' => 'coffrac',
        'company_name' => 'Société confidentielle', 'phone' => '+33 (0)6.12.34.56.78',
        'address' => '10 RUE DE L EGLISE, 69001 LYON, France', 'postal_code' => '69001', 'city' => 'Lyon']);
    $this->existing->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('blocks creation for the same exact fiche and either the phone or full address regardless of name or state', function (array $changes) {
    $this->actingAs($this->actor)->post(route('v2.dossiers.store'), [...$this->data, ...$changes])
        ->assertSessionHasErrors('service_ids');
    expect(Dossier::count())->toBe(1)->and($this->existing->fresh()->company_name)->toBe('Société confidentielle');
})->with([
    'both' => [[]],
    'phone only' => [['address' => '99 autre voie', 'city' => 'Paris', 'postal_code' => '75001']],
    'address only' => [['phone' => '0699887766']],
    'second contact' => [['phone' => '0100000001 / 0033 6 12 34 56 78', 'address' => 'Autre lieu']],
    'missing initial zero' => [['phone' => '612345678', 'address' => 'Autre lieu']],
]);

it('does not equate the SAV fiche with the normal fiche even when both contact fields match', function () {
    $sav = Service::create(['type' => 'COFRAC', 'name' => 'SAV AGRI TH 117', 'average_duration_minutes' => 60]);
    $this->actingAs($this->actor)->post(route('v2.dossiers.store'), [...$this->data, 'service_ids' => [$sav->id]])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Dossier::count())->toBe(2);
});

it('accepts a genuinely different site and phone or a previously deleted dossier', function (string $case) {
    $data = $this->data;
    if ($case === 'deleted') {
        $this->existing->delete();
    } else {
        $data = [...$data, 'phone' => '0611111111', 'address' => '12 rue de l’Église'];
    }
    $this->actingAs($this->actor)->post(route('v2.dossiers.store'), $data)->assertSessionHasNoErrors();
    expect(Dossier::withTrashed()->count())->toBe(2);
})->with(['different', 'deleted']);

it('uses current contacts and fiche assignments without a stale cache or derived backfill', function () {
    $this->existing->update(['phone' => null, 'address' => 'Un ancien site']);
    $created = app(DossierRegistry::class)->create($this->actor, $this->data);
    expect($created->id)->not->toBe($this->existing->id);
    $other = Service::create(['type' => 'COFRAC', 'name' => 'BAR EN 101', 'average_duration_minutes' => 60]);
    $created->operations()->update(['service_id' => $other->id]);
    $next = app(DossierRegistry::class)->create($this->actor, $this->data);
    expect($next->id)->not->toBe($created->id);
});

it('does not block two different unknown streets just because they share a town centroid', function () {
    $this->existing->update(['address' => '69001 Lyon', 'phone' => null]);
    $created = app(DossierRegistry::class)->create($this->actor, [...$this->data, 'address' => 'Lyon', 'phone' => null]);
    expect($created->exists)->toBeTrue()->and(Dossier::count())->toBe(2);
});

it('rejects client duplicates without leaking another organisations dossier identity', function () {
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'DUP-CLIENT',
        'kind' => 'client', 'name' => 'Client', 'is_active' => true]);
    $client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'external_client_id' => $organisation->id,
        'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client')]);
    $response = $this->actingAs($client)->postJson(route('client.dossiers.store'), [
        ...$this->data, 'submission_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('service_ids');
    $response->assertDontSee('PRIVATE-EXISTING')->assertDontSee('Société confidentielle');
    expect(Dossier::count())->toBe(1);
});

it('takes the fiche lock before reading existing dossiers and adds no listing queries or writes', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = str_replace(['"', '`'], '', $query->sql);
    });
    $this->actingAs($this->actor)->post(route('v2.dossiers.store'), $this->data)->assertSessionHasErrors('service_ids');
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'services') && str_contains($sql, 'id = ?'));
    $scan = collect($queries)->search(fn ($sql) => str_contains($sql, 'dossier_operations as o'));
    expect($lock)->not->toBeFalse()->and($scan)->not->toBeFalse()->and($lock)->toBeLessThan($scan);
    expect(collect($queries)->filter(fn ($sql) => str_starts_with(strtolower($sql), 'insert into dossiers')))->toBeEmpty();
});
