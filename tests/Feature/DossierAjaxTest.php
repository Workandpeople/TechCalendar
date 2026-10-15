<?php

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.mapbox.token' => 'not-in-json']);
    $this->manager = User::factory()->create(['role' => 0]);
});

it('returns only escaped listing fragments with the same filters sorting counts and pagination', function () {
    for ($index = 0; $index < 26; $index++) {
        $dossier = Dossier::create([
            'reference' => sprintf('MATCH-%02d', $index),
            'company_name' => sprintf('Company %02d', $index), 'address' => '1 rue du Test', 'city' => 'Lyon',
        ]);
        $dossier->operations()->create()->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    }
    $dossier->activities()->create(['kind' => 'comment', 'body' => '&lt;script&gt;alert(1)&lt;/script&gt;']);
    Dossier::create(['reference' => 'EXCLUDED', 'address' => '2 rue du Test', 'city' => 'Lyon']);

    $response = $this->actingAs($this->manager)->getJson(route('v2.dossiers.index', [
        'q' => 'MATCH-', 'status' => 'pending', 'per_page' => 25, 'page' => 2,
        'sort' => 'client', 'direction' => 'asc',
    ]))->assertOk()->assertJsonStructure(['categories', 'results', 'total'])->assertJsonPath('total', 26);

    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->json('results'))
        ->toContain('MATCH-25', 'page=1', 'q=MATCH-', 'status=pending', 'direction=asc', '&amp;lt;script&amp;gt;')
        ->not->toContain('MATCH-24', 'EXCLUDED', '<script>', '<html', 'not-in-json', 'mapbox-gl.js', 'data-dossier-map-config');
    expect($response->json('categories'))->toContain('26', 'data-category="pending"', 'aria-current="page"');
});

it('returns an empty result fragment without redirecting or rendering the full layout', function () {
    Dossier::create(['reference' => 'EXCLUDED', 'address' => '2 rue du Test', 'city' => 'Lyon']);
    $response = $this->actingAs($this->manager)->getJson(route('v2.dossiers.index', ['q' => 'NO-MATCH']))
        ->assertOk()->assertJsonPath('total', 0);
    expect($response->json('results'))->toContain('Aucun dossier ne correspond')->not->toContain('<html', '<form');
    expect($response->json('categories'))->toContain('data-category="all"');
});

it('keeps the full page and working GET form for non ajax navigation', function () {
    $this->actingAs($this->manager)->get(route('v2.dossiers.index'))->assertOk()
        ->assertViewIs('crm.dossiers.index')->assertSee('data-dossier-filters', false)
        ->assertSee('method="GET"', false)->assertSee('data-dossier-results', false)
        ->assertSee('data-dossier-map-config', false);
});

it('validates ajax filters instead of returning an html error page', function () {
    $this->actingAs($this->manager)->getJson(route('v2.dossiers.index', ['sort' => 'invalid', 'per_page' => 10000]))
        ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'per_page']);
});

it('preserves access restrictions for ajax requests', function () {
    $this->getJson(route('v2.dossiers.index'))->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 2, 'admin' => false]))
        ->getJson(route('v2.dossiers.index'))->assertForbidden();
    config(['crm.v2_enabled' => false]);
    $this->actingAs($this->manager)->getJson(route('v2.dossiers.index'))->assertNotFound();
});
