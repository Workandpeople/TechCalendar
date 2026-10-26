<?php

use App\Models\Department;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create(['role' => 0, 'admin' => false]));
});

function dossierFilterXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);

    return new DOMXPath($dom);
}

it('separates primary criteria complementary filters and table preferences without duplicate controls', function () {
    $response = $this->get(route('v2.dossiers.index'))->assertOk();
    $xpath = dossierFilterXPath($response->getContent());
    $form = '//form[@id="dossier-filters"]';
    expect($xpath->query($form)->length)->toBe(1)
        ->and($xpath->query($form.'//details[@data-dossier-advanced and not(@open)]')->length)->toBe(1)
        ->and($xpath->query($form.'//*[@data-filter-group]')->length)->toBe(3)
        ->and($xpath->query('//a[@data-dossier-section]')->length)->toBe(0);
    foreach (['q', 'delegataire_id', 'service_ids[]'] as $name) {
        expect($xpath->query($form.'//*[@name="'.$name.'" and not(ancestor::details)]')->length)->toBe(1);
    }
    foreach (['department', 'section', 'planning', 'billing', 'date_field', 'date_from', 'date_to'] as $name) {
        expect($xpath->query('//details[@data-dossier-advanced]//*[@name="'.$name.'"]')->length)->toBe(1);
    }
    expect($xpath->query('//input[@name="all_columns" and @form="dossier-filters" and not(ancestor::form)]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-dossier-page-size]')->length)->toBe(1)
        ->and($xpath->query($form.'//input[@name="per_page" and @type="hidden"]')->length)->toBe(1)
        ->and($xpath->query($form.'//noscript//button[@type="submit"]')->length)->toBe(1);
    Http::assertNothingSent();
});

it('opens complementary criteria from explicit links and preserves all selected values including multiple services', function () {
    Department::updateOrCreate(['code' => '75'], ['name' => 'Paris']);
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'FILTER-TEST', 'kind' => 'client', 'name' => 'Client test', 'is_active' => true]);
    $services = collect(['Fiche A', 'Fiche B'])->map(fn ($name) => Service::create(['type' => 'COFFRAC', 'name' => $name, 'average_duration_minutes' => 60]));
    $filters = ['q' => 'rue du Test', 'delegataire_id' => $organisation->id, 'service_ids' => $services->pluck('id')->all(),
        'department' => '75', 'section' => 'sav', 'planning' => 'problem', 'billing' => 'invoiced',
        'date_field' => 'appointment', 'date_from' => '2026-10-01', 'date_to' => '2026-10-26', 'per_page' => 50, 'all_columns' => 1];
    $response = $this->get(route('v2.dossiers.index', $filters))->assertOk();
    $xpath = dossierFilterXPath($response->getContent());
    expect($xpath->query('//details[@data-dossier-advanced and @open]')->length)->toBe(1)
        ->and(trim($xpath->query('//*[@data-dossier-advanced-count]')->item(0)->textContent))->toBe('5 actifs')
        ->and($xpath->query('//select[@name="service_ids[]"]/option[@selected]')->length)->toBe(2)
        ->and($xpath->query('//input[@name="all_columns" and @checked]')->length)->toBe(1);
    foreach (['department' => '75', 'section' => 'sav', 'planning' => 'problem', 'billing' => 'invoiced', 'date_field' => 'appointment'] as $name => $value) {
        expect($xpath->query('//select[@name="'.$name.'"]/option[@selected]')->item(0)->getAttribute('value'))->toBe($value);
    }
    $json = $this->getJson(route('v2.dossiers.index', $filters))->assertOk();
    $fragment = dossierFilterXPath($json->json('results'));
    expect($fragment->query('//input[@name="all_columns" and @form="dossier-filters" and @checked]')->length)->toBe(1)
        ->and($fragment->query('//*[@data-dossier-page-size]/option[@selected]')->item(0)->getAttribute('value'))->toBe('50');
    Http::assertNothingSent();
});
