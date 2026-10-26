<?php

use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->managers = User::factory()->count(3)->create(['role' => 0, 'admin' => false]);
    $this->actingAs($this->managers[0]);
    $this->service = Service::create(['name' => 'Fiche de test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->makeAssignedDossier = function (string $name, ?User $manager, string $status = 'RDV VISITE'): Dossier {
        $dossier = Dossier::create(['reference' => $name, 'company_name' => $name, 'address' => 'Rue Test', 'city' => 'Paris']);
        $dossier->forceFill(['planning_user_id' => $manager?->id])->save();
        $dossier->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => $status])->save();

        return $dossier;
    };
    $this->first = ($this->makeAssignedDossier)('Suivi Alpha', $this->managers[0]);
    $this->second = ($this->makeAssignedDossier)('Suivi Bravo', $this->managers[1]);
    $this->third = ($this->makeAssignedDossier)('Suivi Charlie', $this->managers[2]);
    $this->unassigned = ($this->makeAssignedDossier)('Sans suivi', null);
    $this->pending = ($this->makeAssignedDossier)('En attente', $this->managers[2], 'PRISE DE RDV');
    $this->filters = ['status' => 'visited', 'planning_user_ids' => $this->managers->take(2)->pluck('id')->all()];
});

it('filters visited dossiers by any selected manager and keeps other category counts meaningful', function () {
    $this->get(route('v2.dossiers.index', $this->filters))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2 && $rows->pluck('id')->sort()->values()->all() === [$this->first->id, $this->second->id])
        ->assertViewHas('categories', fn ($counts) => $counts['visited']['count'] === 2 && $counts['pending']['count'] === 1 && $counts['all']['count'] === 5);
    $this->getJson(route('v2.dossiers.index', $this->filters))->assertOk()->assertJsonPath('total', 2);
    $this->get(route('v2.dossiers.index', [...$this->filters, 'service_ids' => [$this->service->id]]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2);
    Http::assertNothingSent();
});

it('ignores the assignment selection outside visited on listing export and direct registry calls', function () {
    foreach ([null, 'pending', 'report'] as $status) {
        $filters = [...$this->filters, 'status' => $status];
        $this->get(route('v2.dossiers.index', $filters))->assertOk()
            ->assertViewHas('filters', fn ($values) => ! array_key_exists('planning_user_ids', $values));
    }
    expect(app(DossierRegistry::class)->matchingQuery($this->managers[0], [...$this->filters, 'status' => 'pending'])->count())->toBe(1);
    $this->getJson(route('v2.dossiers.export', ['status' => 'pending', 'planning_user_ids' => 'not-an-array']))->assertOk();
});

it('renders only active internal managers and reveals the searchable multi selection only in visited', function () {
    $inactive = User::factory()->create(['role' => 0, 'admin' => false]);
    $inactive->delete();
    $planning = User::factory()->create(['role' => 1, 'admin' => false]);
    $html = $this->get(route('v2.dossiers.index', $this->filters))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    $select = '//select[@name="planning_user_ids[]" and @multiple and @data-multi-select]';
    expect($xpath->query($select.'[not(@disabled)]')->length)->toBe(1)
        ->and($xpath->query($select.'/option[@selected]')->length)->toBe(2)
        ->and($xpath->query($select.'/option')->length)->toBe(3)
        ->and($xpath->query($select.'/option[@value="'.$inactive->id.'" or @value="'.$planning->id.'"]')->length)->toBe(0);
    foreach (['all', 'pending', 'visited'] as $category) {
        $href = $xpath->query('//a[@data-category="'.$category.'"]')->item(0)->getAttribute('href');
        parse_str(parse_url($href, PHP_URL_QUERY) ?? '', $parameters);
        expect(isset($parameters['planning_user_ids']))->toBe($category === 'visited');
    }
    $other = $this->get(route('v2.dossiers.index', ['status' => 'pending']))->assertOk();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$other->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@data-dossier-planning-filter and @hidden]//select[@disabled]')->length)->toBe(1);
});

it('preserves the full selection in pagination and exports only the matching dossiers', function () {
    for ($index = 0; $index < 26; $index++) {
        ($this->makeAssignedDossier)('Page '.$index, $this->managers[$index % 2]);
    }
    $this->get(route('v2.dossiers.index', [...$this->filters, 'page' => 2]))->assertOk()->assertViewHas('dossiers', function ($rows) {
        parse_str(parse_url($rows->url(1), PHP_URL_QUERY), $query);

        return $rows->total() === 28 && $rows->count() === 3 && $query['planning_user_ids'] === array_map(strval(...), $this->filters['planning_user_ids']);
    });
    $response = $this->get(route('v2.dossiers.export', $this->filters))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'planning-filter-');
    try {
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Suivi Alpha', 'Suivi Bravo')->not->toContain('Suivi Charlie', 'Sans suivi', 'En attente');
        $zip->close();
    } finally {
        unlink($path);
    }
});

it('rejects invalid assignment arrays for both listing and export', function (mixed $ids) {
    foreach (['v2.dossiers.index', 'v2.dossiers.export'] as $route) {
        $this->getJson(route($route, ['status' => 'visited', 'planning_user_ids' => $ids]))->assertUnprocessable();
    }
})->with(['scalar' => '1', 'duplicate' => [[1, 1]], 'missing' => [[999999]], 'nested' => [[[1]]],
    'associative' => [['bad' => 1]], 'excessive' => [range(1, 251)]]);

it('does not accept technicians planning roles or inactive managers as filter choices', function () {
    foreach ([0, 1, 2] as $role) {
        $user = User::factory()->create(['role' => $role, 'admin' => false]);
        if ($role === 0) {
            $user->delete();
        }
        $this->getJson(route('v2.dossiers.index', ['status' => 'visited', 'planning_user_ids' => [$user->id]]))->assertUnprocessable();
    }
});
