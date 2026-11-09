<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\ClientPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'SERVICE-FILTER',
        'kind' => 'client', 'name' => 'Organisation test', 'is_active' => true,
        'portal_categories' => ['pending', 'visited', 'report']]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client')]);
    [$this->a, $this->b, $this->c, $this->hidden, $this->unused] = collect([
        'BAT EQ 127', 'BAR TH 171', 'AGRI EQ 108', 'FICHE CONFIDENTIELLE', 'FICHE INUTILISEE',
    ])->map(fn ($name) => Service::create(['name' => $name, 'type' => Service::TYPE_COFFRAC,
        'average_duration_minutes' => 60]))->all();
    $this->makeDossier = function (string $name, array $operations, array $extra = []): Dossier {
        $dossier = Dossier::create(['reference' => $name, 'company_name' => $name, 'address' => 'Rue Test', 'city' => 'Paris',
            'client_id' => $this->organisation->id, 'delegataire_id' => $this->organisation->id, ...$extra]);
        foreach ($operations as [$service, $state]) {
            $dossier->operations()->create(['service_id' => $service->id])->forceFill(['legacy_status' => $state])->save();
        }

        return $dossier;
    };
    $this->first = ($this->makeDossier)('Beneficiaire Alpha', [[$this->a, 'PRISE DE RDV']]);
    $this->second = ($this->makeDossier)('Beneficiaire Bravo', [[$this->b, 'RAPPORT GENIUS']]);
    $this->mixed = ($this->makeDossier)('Beneficiaire Mixte', [[$this->a, 'PRISE DE RDV'], [$this->b, 'RAPPORT GENIUS'], [$this->c, 'RDV VISITE']]);
    $this->excluded = ($this->makeDossier)('Beneficiaire Exclu', [[$this->c, 'PRISE DE RDV']]);
    ($this->makeDossier)('Autre organisation', [[$this->hidden, 'RAPPORT GENIUS']], ['client_id' => null, 'delegataire_id' => null]);
    ($this->makeDossier)('Etat interdit', [[$this->hidden, 'CONTROLE RT']]);
    ($this->makeDossier)('Dossier supprime', [[$this->hidden, 'PRISE DE RDV']])->delete();
    $this->filters = ['service_ids' => [$this->a->id, $this->b->id]];
    $this->actingAs($this->external);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('filters by one or several CEE sheets in both external spaces without duplicating dossiers', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $this->get(route('client.dossiers', $this->filters))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->unique()->count() === 3)
        ->assertViewHas('categories', fn ($counts) => $counts['all']['count'] === 3 && $counts['pending']['count'] === 2
            && $counts['report']['count'] === 2 && $counts['visited']['count'] === 0)
        ->assertDontSee('Beneficiaire Exclu')->assertDontSee('Autre organisation')->assertDontSee('Etat interdit')->assertDontSee('Dossier supprime');
    $this->get(route('client.dossiers', ['service_ids' => [$this->a->id]]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2);
})->with(['client', 'delegataire']);

it('shows only the selected sheets and matches the category on the same operation', function () {
    $this->get(route('client.dossiers', ['service_ids' => [$this->a->id], 'status' => 'report']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0);
    $this->get(route('client.dossiers', ['service_ids' => [$this->b->id], 'status' => 'report']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2 && $rows->every(fn ($dossier) => $dossier->operations->every(fn ($operation) => $operation->service_id === $this->b->id)));
});

it('renders a searchable multiselect with only accessible sheets and preserves selected values in categories', function () {
    $response = $this->get(route('client.dossiers', $this->filters))->assertOk()
        ->assertSee('Fiches CEE')->assertSee('Rechercher une fiche CEE')
        ->assertDontSee('FICHE CONFIDENTIELLE')->assertDontSee('FICHE INUTILISEE')
        ->assertViewHas('services', fn ($services) => $services->modelKeys() === [$this->c->id, $this->b->id, $this->a->id]);
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//form[@data-ajax-filters]//select[@name="service_ids[]" and @multiple and @data-multi-select]')->length)->toBe(1)
        ->and($xpath->query('//select[@name="service_ids[]"]/option[@selected]')->length)->toBe(2);
    $link = $xpath->query('//a[@data-category="report"]')->item(0)->getAttribute('href');
    parse_str(parse_url($link, PHP_URL_QUERY), $filters);
    expect($filters['service_ids'])->toBe(array_map(strval(...), $this->filters['service_ids']))->and($filters['status'])->toBe('report');
});

it('retains retired sheets that still belong to accessible historical dossiers', function () {
    DB::table('services')->where('id', $this->a->id)->update(['retired_at' => now()]);
    $this->get(route('client.dossiers', ['service_ids' => [$this->a->id]]))->assertOk()->assertSee('BAT EQ 127')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2)
        ->assertViewHas('services', fn ($services) => $services->contains('id', $this->a->id));
});

it('combines sheets with beneficiary and lot searches and returns to all accessible dossiers when cleared', function () {
    $this->mixed->update(['legacy_lot_name' => 'LOT 299']);
    $this->get(route('client.dossiers', [...$this->filters, 'q' => 'Mixte', 'lot' => '299']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($this->mixed));
    $this->get(route('client.dossiers', ['service_ids' => []]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 4);
});

it('filters the entire dataset and preserves selected sheets across pagination and ajax updates', function () {
    for ($index = 0; $index < 27; $index++) {
        ($this->makeDossier)('Pagination '.$index, [[$this->a, 'PRISE DE RDV']]);
    }
    $response = $this->get(route('client.dossiers', [...$this->filters, 'page' => 2]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertViewHas('dossiers', function ($rows) {
            parse_str(parse_url($rows->url(1), PHP_URL_QUERY), $filters);

            return $rows->total() === 30 && $rows->count() === 5
                && $filters['service_ids'] === array_map(strval(...), $this->filters['service_ids']);
        });
    expect($response->getContent())->toContain('data-ajax-results="dossiers"', 'data-ajax-results="categories"');
});

it('keeps organisation and forbidden category restrictions even when the selected sheet is otherwise visible', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    ($this->makeDossier)('Same sheet outside', [[$this->a, 'PRISE DE RDV']], ['client_id' => null, 'delegataire_id' => null]);
    ($this->makeDossier)('Mixed forbidden category', [[$this->a, 'PRISE DE RDV'], [$this->b, 'CONTROLE RT']]);
    $this->get(route('client.dossiers', ['service_ids' => [$this->a->id]]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2)
        ->assertDontSee('Same sheet outside')->assertDontSee('Mixed forbidden category');
    $this->get(route('client.dossiers', ['service_ids' => [$this->a->id], 'status' => 'rt_control']))->assertForbidden();
    $this->getJson(route('client.dossiers', ['service_ids' => [$this->hidden->id]]))->assertUnprocessable();
})->with(['client', 'delegataire']);

it('never offers a sheet from another client assigned to the same delegataire', function () {
    $other = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'OTHER-CLIENT',
        'kind' => 'client', 'name' => 'Autre client', 'is_active' => true]);
    ($this->makeDossier)('Other client dossier', [[$this->hidden, 'PRISE DE RDV']], ['client_id' => $other->id]);
    $this->get(route('client.dossiers'))->assertOk()->assertDontSee('FICHE CONFIDENTIELLE');
    $this->getJson(route('client.dossiers', ['service_ids' => [$this->hidden->id]]))->assertUnprocessable();
});

it('rejects malformed duplicate excessive nonexistent and inaccessible selections', function (mixed $ids) {
    $this->getJson(route('client.dossiers', ['service_ids' => $ids]))->assertUnprocessable();
})->with(['scalar' => '1', 'duplicate' => [[1, 1]], 'missing' => [[999999]], 'nested' => [[[1]]],
    'unknown key' => [['bad' => 1]], 'excessive' => [range(1, 251)], 'noninteger' => [['bad']], 'zero' => [[0]]]);

it('uses the appointment for a selected sheet rather than the appointment for another operation', function () {
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);
    foreach ($this->mixed->operations()->orderBy('id')->get() as $index => $operation) {
        Appointment::forceCreate(['dossier_operation_id' => $operation->id, 'service_id' => $operation->service_id,
            'technician_id' => $technician->id, 'created_by' => $technician->id,
            'customer_first_name' => 'Test', 'customer_last_name' => 'Test', 'customer_phone' => '',
            'address' => 'Rue Test', 'starts_at' => '2027-01-0'.($index + 1).' 10:00:00',
            'ends_at' => '2027-01-0'.($index + 1).' 11:00:00', 'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
    }
    $this->get(route('client.dossiers', ['q' => 'Mixte', 'service_ids' => [$this->b->id]]))->assertOk()
        ->assertSee('02/01/2027')->assertDontSee('01/01/2027')->assertDontSee('03/01/2027');
});

it('uses a bounded number of queries and loads no dossiers to build sheet options', function () {
    $this->external->load('externalClient');
    $queries = function (): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(ClientPortal::class)->dossiers($this->external, $this->filters);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    };
    $initial = $queries();
    foreach (range(1, 27) as $index) {
        ($this->makeDossier)('More dossiers '.$index, [[$this->a, 'PRISE DE RDV']]);
    }
    $larger = $queries();
    expect(count($larger))->toBe(count($initial));
    $optionsQueries = array_values(array_filter($larger, fn ($query) => str_contains($query['query'], 'select "services"."id", "services"."name"')));
    expect($optionsQueries)->toHaveCount(1)->and($optionsQueries[0]['query'])->toContain(' in (select ', '"client_id" = ?');
});
