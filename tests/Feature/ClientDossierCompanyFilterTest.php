<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\ClientPortal;
use App\Services\Crm\DossierContacts;
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
    $this->delegate = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'PORTAL-DELEGATE',
        'kind' => 'delegataire', 'name' => 'Delegataire test', 'is_active' => true,
        'portal_categories' => ['pending', 'visited', 'report']]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->delegate->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('delegataire')]);
    [$this->electric, $this->sunny, $this->secret, $this->forbidden, $this->deleted, $this->unused] = collect([
        'ELECTRIC CONNEXION', 'SUNNYGO', 'SOCIETE AUTRE DELEGATAIRE', 'SOCIETE ETAT INTERDIT', 'SOCIETE DOSSIER SUPPRIME', 'SOCIETE SANS DOSSIER',
    ])->map(fn ($name, $index) => ExternalDelegataire::create(['source' => 'native', 'external_id' => 'PORTAL-COMPANY-'.$index,
        'kind' => 'client', 'name' => 'Contact '.$index, 'company_name' => $name, 'is_active' => true,
        'email' => 'private-'.$index.'@example.test', 'phone' => '0600000000']))->all();
    $this->sheet119 = Service::create(['name' => 'BAR TH 119', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->sheet171 = Service::create(['name' => 'BAR TH 171', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->makeDossier = function (string $reference, ?ExternalDelegataire $client, ?string $state = 'PRISE DE RDV', ?Service $service = null, array $extra = []): Dossier {
        $dossier = Dossier::create(['reference' => $reference, 'company_name' => 'Beneficiaire '.$reference,
            'address' => '1 rue Test', 'postal_code' => '75001', 'city' => 'Paris',
            'installer_name' => 'Installateur distinct', 'client_id' => $client?->id,
            'delegataire_id' => $this->delegate->id, ...$extra]);
        $dossier->operations()->create(['service_id' => ($service ?? $this->sheet119)->id])
            ->forceFill(['legacy_status' => $state])->save();

        return $dossier;
    };
    $this->first = ($this->makeDossier)('Electric-A', $this->electric);
    $this->second = ($this->makeDossier)('Electric-B', $this->electric, 'RAPPORT GENIUS');
    $this->third = ($this->makeDossier)('Sunny-A', $this->sunny, 'RAPPORT GENIUS');
    $this->fourth = ($this->makeDossier)('Electric-C', $this->electric, 'RAPPORT GENIUS', $this->sheet171);
    $this->unassigned = ($this->makeDossier)('Sans-client', null);
    ($this->makeDossier)('Dossier-externe', $this->secret, extra: ['delegataire_id' => null]);
    ($this->makeDossier)('Dossier-interdit', $this->forbidden, 'CONTROLE RT');
    ($this->makeDossier)('Dossier-supprime', $this->deleted)->delete();
    $this->filters = ['company_ids' => [$this->electric->id], 'service_ids' => [$this->sheet119->id]];
    $this->actingAs($this->external);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('shows the client company separately from the beneficiary and installer in delegate tables', function () {
    $response = $this->get(route('client.dossiers'))->assertOk()->assertSee('Société cliente');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    foreach ([[$this->first, 'ELECTRIC CONNEXION'], [$this->third, 'SUNNYGO'], [$this->unassigned, 'Non renseignée']] as [$dossier, $label]) {
        $cell = $xpath->query('//tr[.//a[@href="'.route('client.dossiers.show', $dossier).'"]]//td[@data-client-company]')->item(0);
        expect($cell)->not->toBeNull()->and(trim($cell->textContent))->toBe($label);
    }
    expect($xpath->query('//select[@name="company_ids[]" and @multiple and @data-multi-select]')->length)->toBe(1);
    $response->assertDontSee('Installateur distinct')->assertDontSee('private-0@example.test');
});

it('filters one or several client companies without matching the shared delegate or duplicating dossiers', function () {
    $this->get(route('client.dossiers', ['company_ids' => [$this->electric->id]]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3 && $rows->every(fn ($row) => $row->client_id === $this->electric->id))
        ->assertViewHas('categories', fn ($counts) => $counts['all']['count'] === 3 && $counts['pending']['count'] === 1 && $counts['report']['count'] === 2);
    $this->get(route('client.dossiers', ['company_ids' => [$this->electric->id, $this->sunny->id]]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 4 && $rows->pluck('id')->unique()->count() === 4);
    $this->getJson(route('client.dossiers', ['company_ids' => [$this->delegate->id]]))->assertUnprocessable();
});

it('combines the company with CEE sheets state lot and beneficiary without broadening access', function () {
    $this->second->update(['legacy_lot_name' => 'LOT 299']);
    $this->get(route('client.dossiers', [...$this->filters, 'status' => 'report']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($this->second))
        ->assertViewHas('categories', fn ($counts) => $counts['all']['count'] === 2 && $counts['pending']['count'] === 1 && $counts['report']['count'] === 1);
    $this->get(route('client.dossiers', [...$this->filters, 'status' => 'report', 'q' => 'Electric-B', 'lot' => '299']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($this->second));
    $this->get(route('client.dossiers', [...$this->filters, 'status' => 'report', 'q' => 'Sunny']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->isEmpty());
    $this->get(route('client.dossiers', ['company_ids' => []]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 5);
});

it('keeps the company filter and column in every authorized status table', function (?string $state, string $key) {
    $this->delegate->update(['portal_categories' => [$key]]);
    $dossier = ($this->makeDossier)('EtatUnique', $this->electric, $state);
    $this->get(route('client.dossiers', [...$this->filters, 'q' => 'EtatUnique', 'status' => $key]))->assertOk()
        ->assertSee('Société cliente')->assertSee('ELECTRIC CONNEXION')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($dossier))
        ->assertViewHas('categories', fn ($counts) => $counts[$key]['count'] === 1);
})->with([
    ['PRISE DE RDV', 'pending'], ['PROBLÈME RENDEZ-VOUS', 'problem'], ['RDV ATTENTE VISITE', 'awaiting_visit'],
    ['RDV VISITE', 'visited'], ['CONTROLE RT', 'rt_control'], ['NON CONFORMITES', 'non_compliant'],
    ['RECONTROLE', 'recontrol'], ['RAPPORT GENIUS', 'report'], ['DOSSIERS FACTURER', 'report'],
    ['DOSSIER ANNULE', 'cancelled'], [null, 'unknown'],
]);

it('offers only companies from accessible dossiers across authorized states regardless of current filters', function () {
    $this->get(route('client.dossiers', [...$this->filters, 'status' => 'pending']))->assertOk()
        ->assertViewHas('companies', fn ($companies) => $companies->modelKeys() === [$this->electric->id, $this->sunny->id])
        ->assertDontSee('SOCIETE AUTRE DELEGATAIRE')->assertDontSee('SOCIETE ETAT INTERDIT')
        ->assertDontSee('SOCIETE DOSSIER SUPPRIME')->assertDontSee('SOCIETE SANS DOSSIER');
    ($this->makeDossier)('Dossier-mixte-interdit', $this->forbidden)->operations()
        ->create(['service_id' => $this->sheet119->id])->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->get(route('client.dossiers'))->assertOk()->assertDontSee('SOCIETE ETAT INTERDIT');
    $this->getJson(route('client.dossiers', ['company_ids' => [$this->forbidden->id]]))->assertUnprocessable();
});

it('rejects inaccessible companies even alongside a visible company without leaking organisation details', function (string $company) {
    $this->getJson(route('client.dossiers', ['company_ids' => [$this->electric->id, $this->$company->id]]))
        ->assertUnprocessable()->assertJsonValidationErrors('company_ids.1')
        ->assertDontSee($this->$company->company_name)->assertDontSee($this->$company->email);
})->with(['secret', 'forbidden', 'deleted', 'unused']);

it('retains historical client companies even if their own portal account is inactive', function () {
    $this->electric->update(['is_active' => false]);
    $this->get(route('client.dossiers', $this->filters))->assertOk()->assertSee('ELECTRIC CONNEXION')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2)
        ->assertViewHas('companies', fn ($companies) => $companies->contains('id', $this->electric->id));
});

it('preserves both selections in category links pagination and AJAX responses for the full dataset', function () {
    foreach (range(1, 27) as $index) {
        ($this->makeDossier)('Pagination-'.$index, $this->electric);
    }
    $response = $this->get(route('client.dossiers', [...$this->filters, 'page' => 2]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertViewHas('dossiers', function ($rows) {
            parse_str(parse_url($rows->url(1), PHP_URL_QUERY), $query);

            return $rows->total() === 29 && $rows->count() === 4
                && $query['company_ids'] === [(string) $this->electric->id]
                && $query['service_ids'] === [(string) $this->sheet119->id];
        });
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//select[@name="company_ids[]"]/option[@selected]')->length)->toBe(1);
    $link = $xpath->query('//a[@data-category="report"]')->item(0)->getAttribute('href');
    parse_str(parse_url($link, PHP_URL_QUERY), $query);
    expect($query['company_ids'])->toBe([(string) $this->electric->id])
        ->and($query['service_ids'])->toBe([(string) $this->sheet119->id])
        ->and($query['status'])->toBe('report')->and($query)->not->toHaveKey('page');
});

it('escapes company labels and uses contact snapshots only as display fallbacks without relabelling them as the delegate', function () {
    $this->electric->update(['company_name' => '<img src=x onerror=alert(1)>']);
    $this->unassigned->update(['client_contact' => ['name' => 'Societe manuelle']]);
    $this->get(route('client.dossiers'))->assertOk()->assertSee('<img src=x onerror=alert(1)>')
        ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertSee('Societe manuelle');
    expect(DossierContacts::companyName(new ExternalDelegataire(['company_name' => '  ', 'name' => ' Nom commercial ']), ['name' => 'Ancien nom']))
        ->toBe('Nom commercial')->and(DossierContacts::companyName(null, 'invalid historical snapshot'))->toBe('');
});

it('does not offer another client company or add redundant controls in the client-only space', function () {
    $this->external->forceFill(['external_client_id' => $this->electric->id,
        'portal_permissions' => ClientAccess::defaults('client')])->save();
    $this->actingAs($this->external->fresh());
    $response = $this->get(route('client.dossiers'))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3)
        ->assertViewHas('showClientCompanies', false);
    expect($response->getContent())->not->toContain('name="company_ids[]"', 'data-client-company');
    $this->getJson(route('client.dossiers', ['company_ids' => [$this->sunny->id]]))->assertUnprocessable();
    $this->get(route('client.dossiers', ['company_ids' => [$this->electric->id]]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3);
});

it('rejects malformed duplicate excessive and nonexistent company selections', function (mixed $ids) {
    $this->getJson(route('client.dossiers', ['company_ids' => $ids]))->assertUnprocessable();
})->with(['scalar' => '1', 'duplicate' => [[1, 1]], 'missing' => [[999999]], 'nested' => [[[1]]],
    'unknown key' => [['bad' => 1]], 'excessive' => [range(1, 251)], 'noninteger' => [['bad']], 'zero' => [[0]]]);

it('eager loads only company labels for this page and builds options in one scoped query without an N plus one', function () {
    $this->external->load('externalClient');
    $queries = function (): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = app(ClientPortal::class)->dossiers($this->external, $this->filters)['dossiers'];
        $queries = DB::getQueryLog();
        DB::flushQueryLog();
        foreach ($page as $dossier) {
            expect($dossier->relationLoaded('client'))->toBeTrue();
            DossierContacts::companyName($dossier->client, $dossier->client_contact ?? []);
            expect(array_keys($dossier->client->getAttributes()))->toBe(['id', 'name', 'company_name']);
        }
        expect(DB::getQueryLog())->toBeEmpty();
        DB::disableQueryLog();

        return $queries;
    };
    $initial = $queries();
    foreach (range(1, 27) as $index) {
        ($this->makeDossier)('Budget-'.$index, $this->electric);
    }
    $larger = $queries();
    expect(count($larger))->toBe(count($initial));
    $options = collect($larger)->filter(fn ($query) => str_contains($query['query'], 'from "external_delegataires"')
        && str_contains($query['query'], 'in (select "dossiers"."client_id"'));
    expect($options)->toHaveCount(1)->and($options->first()['query'])->toContain('"delegataire_id" = ?');
});
