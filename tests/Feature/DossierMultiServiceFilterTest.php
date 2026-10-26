<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->services = collect(['A', 'B', 'C'])->map(fn ($name) => Service::create(['name' => 'Fiche '.$name,
        'type' => 'COFFRAC', 'average_duration_minutes' => 60]));
    [$this->a, $this->b, $this->c] = $this->services->all();
    $this->makeDossier = function (string $name, array $operations): Dossier {
        $dossier = Dossier::create(['reference' => $name, 'company_name' => $name, 'address' => 'Rue Test', 'city' => 'Paris']);
        foreach ($operations as [$service, $state]) {
            $dossier->operations()->create(['service_id' => $service->id])->forceFill(['legacy_status' => $state])->save();
        }

        return $dossier;
    };
    $this->first = ($this->makeDossier)('Bénéficiaire Alpha', [[$this->a, 'PRISE DE RDV']]);
    $this->second = ($this->makeDossier)('Bénéficiaire Bravo', [[$this->b, 'RAPPORT GENIUS']]);
    $this->mixed = ($this->makeDossier)('Bénéficiaire Mixte', [[$this->a, 'RDV ATTENTE VISITE'], [$this->b, 'RDV VISITE'], [$this->c, 'DOSSIER ANNULE']]);
    $this->excluded = ($this->makeDossier)('Bénéficiaire Exclu', [[$this->c, 'PRISE DE RDV']]);
    $this->filters = ['service_ids' => [$this->a->id, $this->b->id]];
    $this->actingAs($this->actor);
});

it('matches any chosen service without duplicate dossiers and counts only selected operations', function () {
    $this->get(route('v2.dossiers.index', $this->filters))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->unique()->count() === 3)
        ->assertViewHas('categories', fn ($counts) => $counts['all']['count'] === 3 && $counts['pending']['count'] === 1
            && $counts['visited']['count'] === 1 && $counts['report']['count'] === 1 && $counts['cancelled']['count'] === 0);
    $this->getJson(route('v2.dossiers.index', $this->filters))->assertOk()->assertJsonPath('total', 3);
});

it('renders a searchable native multi select and preserves both choices in category links', function () {
    $response = $this->get(route('v2.dossiers.index', $this->filters))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//select[@name="service_ids[]" and @multiple and @data-multi-select]')->length)->toBe(1)
        ->and($xpath->query('//select[@name="service_ids[]"]/option[@selected]')->length)->toBe(2);
    $link = $xpath->query('//a[@data-category="report"]')->item(0)->getAttribute('href');
    parse_str(parse_url($link, PHP_URL_QUERY), $filters);
    expect($filters['service_ids'])->toBe(array_map(strval(...), $this->filters['service_ids']))->and($filters['status'])->toBe('report');
});

it('matches service and state on the same operation', function () {
    $filters = ['service_ids' => [$this->a->id, $this->c->id], 'status' => 'visited'];
    $this->get(route('v2.dossiers.index', $filters))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0)
        ->assertViewHas('categories', fn ($counts) => $counts['visited']['count'] === 0);
});

it('keeps legacy single service URLs compatible and gives the explicit multi selection priority', function () {
    $registry = app(DossierRegistry::class);
    expect($registry->matchingQuery($this->actor, ['service_id' => $this->a->id])->count())->toBe(2)
        ->and($registry->matchingQuery($this->actor, ['service_id' => $this->c->id, ...$this->filters])->count())->toBe(3)
        ->and($registry->matchingQuery($this->actor, ['service_id' => $this->c->id, 'service_ids' => []])->count())->toBe(4);
    $this->get(route('v2.dossiers.index', ['service_id' => $this->a->id]))->assertOk()->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2);
});

it('matches appointment dates and the displayed booking to a chosen service', function () {
    $bookings = [];
    foreach ($this->mixed->operations()->orderBy('id')->get() as $index => $operation) {
        $bookings[] = Appointment::forceCreate(['dossier_operation_id' => $operation->id, 'service_id' => $operation->service_id,
            'technician_id' => $this->actor->id, 'created_by' => $this->actor->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Test',
            'customer_phone' => '', 'address' => 'Rue Test', 'starts_at' => '2026-11-0'.($index + 1).' 10:00:00',
            'ends_at' => '2026-11-0'.($index + 1).' 11:00:00', 'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
    }
    $filters = [...$this->filters, 'date_field' => 'appointment', 'date_from' => '2026-11-02', 'date_to' => '2026-11-02'];
    $this->get(route('v2.dossiers.index', $filters))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->listingAppointment->id === $bookings[1]->id);
    $this->get(route('v2.dossiers.index', [...$filters, 'status' => 'awaiting_visit']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0);
});

it('preserves all chosen services in pagination and exports the same filtered results', function () {
    for ($index = 0; $index < 26; $index++) {
        ($this->makeDossier)('Pagination '.$index, [[$index % 2 === 0 ? $this->a : $this->b, 'PRISE DE RDV']]);
    }
    $filters = [...$this->filters, 'status' => 'pending', 'per_page' => 25, 'page' => 2];
    $this->get(route('v2.dossiers.index', $filters))->assertOk()->assertViewHas('dossiers', function ($rows) {
        parse_str(parse_url($rows->url(1), PHP_URL_QUERY), $query);

        return $rows->total() === 27 && $rows->count() === 2 && $query['service_ids'] === array_map(strval(...), $this->filters['service_ids']);
    });
    $response = $this->get(route('v2.dossiers.export', $this->filters))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'multi-services-');
    try {
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Bénéficiaire Alpha', 'Bénéficiaire Bravo', 'Bénéficiaire Mixte')
            ->not->toContain('Bénéficiaire Exclu');
        $zip->close();
    } finally {
        unlink($path);
    }
});

it('applies the service selection to deleted and unknown category counts', function () {
    $this->mixed->delete();
    $this->excluded->delete();
    $this->get(route('v2.dossiers.index', [...$this->filters, 'status' => 'unknown']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->is($this->mixed))
        ->assertViewHas('categories', fn ($counts) => $counts['unknown']['count'] === 1);
});

it('rejects malformed duplicate nonexistent or excessive selections before listing or exporting', function (mixed $ids) {
    foreach (['v2.dossiers.index', 'v2.dossiers.export'] as $route) {
        $this->getJson(route($route, ['service_ids' => $ids]))->assertUnprocessable();
    }
})->with(['scalar' => '1', 'duplicate' => [[1, 1]], 'missing' => [[999999]], 'nested' => [[[1]]],
    'unknown key' => [['bad' => 1]], 'excessive' => [range(1, 251)]]);
