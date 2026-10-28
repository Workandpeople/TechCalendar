<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\DossierTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->actingAs($this->actor);
    $this->clientA = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CLIENT-A', 'kind' => 'client',
        'name' => 'Client Alpha', 'company_name' => 'Alpha Isolation', 'is_active' => true]);
    $this->clientB = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CLIENT-B', 'kind' => 'client',
        'name' => 'Client Bravo', 'company_name' => 'Bravo Travaux', 'is_active' => true]);
    $this->delegate = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'DELEGATE', 'kind' => 'delegataire',
        'name' => 'Délégataire commun', 'company_name' => 'Groupe Délégataire', 'is_active' => true]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Fiche Test', 'average_duration_minutes' => 60]);
    $this->makeDossier = function (string $name, ?int $client, ?int $delegate, string $status = 'RDV VISITE'): Dossier {
        $dossier = Dossier::create(['reference' => $name, 'company_name' => $name, 'address' => '1 rue fictive', 'city' => 'Paris',
            'client_id' => $client, 'delegataire_id' => $delegate]);
        $dossier->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => $status])->save();

        return $dossier;
    };
    $this->first = ($this->makeDossier)('Bénéficiaire premier', $this->clientA->id, $this->delegate->id);
    $this->second = ($this->makeDossier)('Bénéficiaire second', $this->clientB->id, $this->delegate->id, 'RAPPORT GENIUS');
    $this->unrelated = ($this->makeDossier)('Bénéficiaire exclu', null, null);
    $this->filters = ['company_ids' => [$this->clientA->id, $this->clientB->id]];
});

afterEach(fn () => Http::assertNothingSent());

it('matches any selected client or delegate with accurate counts and no duplicate dossiers', function () {
    $filters = ['company_ids' => [$this->clientA->id, $this->delegate->id]];
    $this->get(route('v2.dossiers.index', $filters))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->unique()->count() === 2)
        ->assertViewHas('categories', fn ($counts) => $counts['all']['count'] === 2 && $counts['visited']['count'] === 1 && $counts['report']['count'] === 1);
    $this->getJson(route('v2.dossiers.index', $filters))->assertOk()->assertJsonPath('total', 2);
    $this->getJson(route('v2.dossiers.index', [...$filters, 'status' => 'visited', 'service_ids' => [$this->service->id]]))
        ->assertOk()->assertJsonPath('total', 1);
    $this->getJson(route('v2.dossiers.index', [...$filters, 'q' => 'exclu']))->assertOk()->assertJsonPath('total', 0);
});

it('renders a searchable multi selection and retains both companies across category links and pagination', function () {
    for ($index = 0; $index < 11; $index++) {
        ($this->makeDossier)('Pagination '.$index, $index % 2 ? $this->clientA->id : $this->clientB->id, null);
    }
    $response = $this->get(route('v2.dossiers.index', [...$this->filters, 'page' => 2]))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//select[@name="company_ids[]" and @multiple and @data-multi-select]')->length)->toBe(1)
        ->and($xpath->query('//select[@name="company_ids[]"]//option[@selected]')->length)->toBe(2);
    $link = $xpath->query('//a[@data-category="report"]')->item(0)->getAttribute('href');
    parse_str(parse_url($link, PHP_URL_QUERY), $filters);
    expect($filters['company_ids'])->toBe(array_map(strval(...), $this->filters['company_ids']));
    $response->assertViewHas('dossiers', function ($rows) {
        parse_str(parse_url($rows->url(1), PHP_URL_QUERY), $query);

        return $rows->total() === 13 && $rows->count() === 3 && $query['company_ids'] === array_map(strval(...), $this->filters['company_ids']);
    });
});

it('keeps legacy links and gives an explicit company selection priority over the old single filter', function () {
    $registry = app(DossierRegistry::class);
    expect($registry->matchingQuery($this->actor, ['delegataire_id' => $this->delegate->id])->count())->toBe(2)
        ->and($registry->matchingQuery($this->actor, ['delegataire_id' => $this->clientA->id, 'company_ids' => [$this->clientB->id]])->pluck('id')->all())->toBe([$this->second->id])
        ->and($registry->matchingQuery($this->actor, ['delegataire_id' => $this->clientA->id, 'company_ids' => []])->count())->toBe(3);
    $this->get(route('v2.dossiers.index', ['delegataire_id' => $this->clientA->id]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
});

it('displays the client company separately from the delegate, including manually entered organisations', function () {
    $manual = ($this->makeDossier)('Bénéficiaire manuel', null, $this->delegate->id);
    $manual->update(['client_contact' => ['company_name' => '', 'name' => 'Société manuelle']]);
    $response = $this->get(route('v2.dossiers.index'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    $first = $xpath->query('//tr[.//a[contains(@href,"/v2/dossiers/'.$this->first->id.'")]]/td[@data-column="company"]')->item(0);
    expect(trim($xpath->query('.//*[@data-company-client]', $first)->item(0)->textContent))->toBe('Alpha Isolation')
        ->and(trim($xpath->query('.//*[@data-company-delegataire]', $first)->item(0)->textContent))->toBe('Délégataire : Groupe Délégataire');
    $response->assertSee('Société manuelle');
});

it('sorts by the displayed client company rather than the shared delegate, with a manual-name fallback', function () {
    $manual = ($this->makeDossier)('Bénéficiaire manuel', null, $this->delegate->id);
    $manual->update(['client_contact' => ['company_name' => '', 'name' => 'Aardvark Manuel']]);
    $registry = app(DossierRegistry::class);
    $ids = [$this->first->id, $this->second->id, $manual->id];
    $query = $registry->matchingQuery($this->actor, [])->whereIn('dossiers.id', $ids);
    $table = app(DossierTable::class);
    expect($table->apply(clone $query, ['sort' => 'company', 'direction' => 'asc'])->pluck('dossiers.id')->all())
        ->toBe([$manual->id, $this->first->id, $this->second->id])
        ->and($table->apply(clone $query, ['sort' => 'company', 'direction' => 'desc'])->pluck('dossiers.id')->all())
        ->toBe([$this->second->id, $this->first->id, $manual->id]);
});

it('exports exactly the selected companies and keeps client and delegate names in separate columns', function () {
    $response = $this->get(route('v2.dossiers.export', $this->filters))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'company-filter-');
    try {
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Bénéficiaire premier', 'Bénéficiaire second', 'Alpha Isolation', 'Bravo Travaux', 'Groupe Délégataire')
            ->not->toContain('Bénéficiaire exclu');
        $zip->close();
    } finally {
        unlink($path);
    }
});

it('does not expose deleted dossiers through the selected companies outside the deleted category', function () {
    $this->first->delete();
    $this->getJson(route('v2.dossiers.index', $this->filters))->assertOk()->assertJsonPath('total', 1);
    $this->getJson(route('v2.dossiers.index', [...$this->filters, 'status' => 'unknown']))->assertOk()->assertJsonPath('total', 1);
});

it('validates company selections before listing and exporting', function (mixed $ids) {
    foreach (['v2.dossiers.index', 'v2.dossiers.export'] as $route) {
        $this->getJson(route($route, ['company_ids' => $ids]))->assertUnprocessable();
    }
})->with(['scalar' => '1', 'duplicate' => [[1, 1]], 'missing' => [[999999]], 'nested' => [[[1]]],
    'invalid key' => [['bad' => 1]], 'excessive' => [range(1, 251)]]);

it('does not grant an external account access to internal lists or exports via company ids', function () {
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->clientA->id, 'portal_enabled' => true, 'must_change_password' => false]);
    foreach (['v2.dossiers.index', 'v2.dossiers.export'] as $route) {
        $this->actingAs($external)->getJson(route($route, $this->filters))->assertForbidden();
    }
});
