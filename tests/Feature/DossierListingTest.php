<?php

use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\DossierStatusCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    $this->manager = User::factory()->create(['role' => 0]);
    $this->service = Service::create(['name' => 'Inspection test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->dossierWithStatus = function (?string $status, string $reference = 'DOS-TEST'): Dossier {
        $dossier = Dossier::create(['reference' => $reference, 'company_name' => 'Bénéficiaire test', 'address' => '10 rue du Test', 'city' => 'Lyon']);
        $dossier->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => $status])->save();

        return $dossier;
    };
});

it('exposes and filters each historical Coffrac category', function (string $key, array $status) {
    $dossier = ($this->dossierWithStatus)($status['values'][0]);
    ($this->dossierWithStatus)(null, 'OTHER');

    $this->actingAs($this->manager)->get(route('v2.dossiers.index', ['status' => $key]))
        ->assertOk()->assertSee($status['label'])
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $dossier->id)
        ->assertViewHas('categories', fn ($categories) => $categories[$key]['count'] === 1 && $categories['all']['count'] === 2)
        ->assertSee('data-category="'.$key.'"', false)->assertSee('aria-current="page"', false);
})->with(collect(DossierStatusCatalog::STATUSES)->except(['unknown', 'invoiced'])->map(fn ($status, $key) => [$key, $status])->all());

it('counts each dossier once per category and matches service and state on the same operation', function () {
    $dossier = ($this->dossierWithStatus)('Problème rendez- vous');
    $dossier->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $other = Service::create(['name' => 'Autre prestation', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $dossier->operations()->create(['service_id' => $other->id])->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    ($this->dossierWithStatus)('RAPPORT GENIUS', 'REPORT');
    $registry = app(DossierRegistry::class);

    $counts = $registry->categories($this->manager, ['status' => 'problem']);
    expect($counts['all']['count'])->toBe(2)
        ->and($counts['problem']['count'])->toBe(1)
        ->and($counts['report']['count'])->toBe(2);
    $filters = ['service_id' => $other->id, 'status' => 'problem'];
    expect($registry->search($this->manager, $filters)->total())->toBe(0);
    $counts = $registry->categories($this->manager, $filters);
    expect($counts['all']['count'])->toBe(1)->and($counts['problem']['count'])->toBe(0)->and($counts['report']['count'])->toBe(1);
});

it('keeps unclassified dossiers visible including those without operations', function () {
    ($this->dossierWithStatus)(null, 'EMPTY-STATE');
    ($this->dossierWithStatus)('État historique inconnu', 'OTHER-STATE');
    Dossier::create(['reference' => 'NO-OPERATION', 'address' => '10 rue du Test', 'city' => 'Lyon']);
    ($this->dossierWithStatus)('RDV VISITE', 'KNOWN');
    $this->actingAs($this->manager)->get(route('v2.dossiers.index', ['status' => 'unknown']))
        ->assertOk()->assertSee('État historique inconnu')->assertSee('État non renseigné')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3)
        ->assertViewHas('categories', fn ($categories) => $categories['unknown']['count'] === 3);
    $this->get(route('v2.dossiers.index', ['status' => 'unknown', 'service_id' => $this->service->id]))
        ->assertOk()->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2)
        ->assertViewHas('categories', fn ($categories) => $categories['unknown']['count'] === 2);
});

it('combines search filters with category counts and preserves them in navigation and pagination', function () {
    for ($index = 0; $index < 26; $index++) {
        ($this->dossierWithStatus)('PRISE DE RDV', 'MATCH-'.$index);
    }
    ($this->dossierWithStatus)('PRISE DE RDV', 'EXCLUDED');
    $filters = ['status' => 'pending', 'q' => 'MATCH-', 'service_id' => $this->service->id, 'planning' => 'unlinked', 'per_page' => 25];
    $response = $this->actingAs($this->manager)->get(route('v2.dossiers.index', [...$filters, 'page' => 2]))
        ->assertOk()->assertViewHas('dossiers', fn ($rows) => $rows->count() === 1 && $rows->total() === 26)
        ->assertViewHas('categories', fn ($categories) => $categories['all']['count'] === 26)
        ->assertSee('name="status" value="pending"', false)
        ->assertSee('page=1', false);
    preg_match('/data-category="report"\s+href="([^"]+)"/', $response->getContent(), $match);
    parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $categoryFilters);
    expect($categoryFilters)->toEqual([...$filters, 'status' => 'report']);
    $this->getJson(route('v2.dossiers.index', ['status' => 'not-a-status']))->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('preloads only three latest comments per dossier without history events and escapes their content', function () {
    $first = ($this->dossierWithStatus)('PRISE DE RDV', 'FIRST');
    $second = ($this->dossierWithStatus)('RDV ATTENTE VISITE', 'SECOND');
    foreach ([$first, $second] as $dossier) {
        for ($index = 0; $index < 5; $index++) {
            $dossier->activities()->create([
                'kind' => $index % 2 === 0 ? 'legacy_comment' : 'comment',
                'body' => $dossier->reference.' comment '.$index,
                'author_id' => $this->manager->id,
                'changed_fields' => ['is_private' => $index === 4],
            ])->forceFill(['created_at' => now()->subMinutes(5 - $index)])->save();
        }
        $dossier->activities()->create(['kind' => 'legacy_history', 'body' => 'HISTORY-EXCLUDED']);
    }
    $first->activities()->create(['kind' => 'comment', 'body' => '&lt;img src=x onerror=alert(1)&gt;']);

    $this->actingAs($this->manager)->get(route('v2.dossiers.index'))
        ->assertOk()->assertDontSee('FIRST comment 1')->assertDontSee('SECOND comment 1')
        ->assertSee('FIRST comment 4')->assertSee('SECOND comment 2')->assertSee('Privé')
        ->assertSee('&amp;lt;img src=x onerror=alert(1)&amp;gt;', false)->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertViewHas('dossiers', function ($rows) use ($first, $second) {
            $items = $rows->keyBy('id');

            return $items[$first->id]->comments->count() === 3 && $items[$first->id]->comments_count === 6
                && $items[$second->id]->comments->count() === 3 && $items[$second->id]->comments_count === 5
                && $items->every(fn ($dossier) => $dossier->comments->every(fn ($comment) => $comment->body !== 'HISTORY-EXCLUDED'));
        });
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->actingAs($technician)->get(route('v2.dossiers.index'))->assertForbidden();
});

it('attaches the comment tooltip only to the client name link', function () {
    $dossier = ($this->dossierWithStatus)('PRISE DE RDV');
    $response = $this->actingAs($this->manager)->get(route('v2.dossiers.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
    $xpath = new DOMXPath($document);
    $triggers = $xpath->query('//*[@data-dossier-comments-trigger]');

    expect($triggers->length)->toBe(1);
    $trigger = $triggers->item(0);
    expect($trigger->nodeName)->toBe('a')
        ->and($trigger->textContent)->toBe($dossier->beneficiaryLabel())
        ->and($trigger->getAttribute('href'))->toBe(route('v2.dossiers.show', $dossier))
        ->and($trigger->getAttribute('class'))->toContain('gc-dossier-client');
});

it('keeps the number of list queries bounded as the page fills up', function () {
    $registry = app(DossierRegistry::class);
    $addDossier = function (int $number) {
        $dossier = ($this->dossierWithStatus)('PRISE DE RDV', 'DOS-'.$number);
        $dossier->activities()->create(['kind' => 'comment', 'body' => 'Note de suivi', 'author_id' => $this->manager->id]);
    };
    $addDossier(0);
    DB::enableQueryLog();
    $registry->search($this->manager, []);
    $firstCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($index = 1; $index < 25; $index++) {
        $addDossier($index);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = $registry->search($this->manager, []);
    expect($rows->count())->toBe(25)->and(count(DB::getQueryLog()))->toBe($firstCount);
    DB::disableQueryLog();
});

it('presents the old invoiced state as final report without losing its billing protection', function () {
    $dossier = ($this->dossierWithStatus)('DOSSIERS FACTURER');
    expect(DossierStatusCatalog::describe('DOSSIERS FACTURER')['key'])->toBe('report');
    $this->actingAs($this->manager)->get(route('v2.dossiers.index', ['status' => 'report']))->assertOk()
        ->assertSee('Facturé')->assertDontSee('data-category="invoiced"', false)
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('categories', fn ($categories) => ! isset($categories['invoiced']) && $categories['report']['count'] === 1);
    $this->getJson(route('v2.dossiers.index', ['billing' => 'unbilled']))->assertJsonPath('total', 0);
    $this->getJson(route('v2.dossiers.index', ['billing' => 'invoiced']))->assertJsonPath('total', 1);
    expect($dossier->operations()->sole()->legacy_status)->toBe('DOSSIERS FACTURER');
});
