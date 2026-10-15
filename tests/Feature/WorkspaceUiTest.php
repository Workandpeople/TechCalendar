<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
});

it('renders contextual help and functional colours without changing navigation permissions', function () {
    $planner = User::factory()->create(['admin' => false, 'role' => 1]);
    $response = $this->actingAs($planner)->get(route('v2.dossiers.index'))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//body[@data-workspace-tone="blue"]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-workspace-help]/summary[@aria-label="Mode d’emploi"]')->length)->toBe(1)
        ->and($xpath->query('//main[@id="workspace-main"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="app-sidebar"]//a[@data-workspace-tone="teal"]')->length)->toBe(2)
        ->and($xpath->query('//*[@id="app-sidebar"]//section[@aria-label="Admin"]')->length)->toBe(0);

    $response->assertSee('Réponse du client attendue')->assertSee('Éléments à revérifier');
});

it('escapes heading content and keeps action slots usable', function () {
    $html = Blade::render('<x-workspace-heading :title="$title" :description="$description"><button type="button">Modifier</button></x-workspace-heading>', [
        'title' => '<img src=x onerror=alert(1)>',
        'description' => '<script>alert(1)</script>',
    ]);
    expect($html)->toContain('&lt;img', '&lt;script&gt;', '<button type="button">Modifier</button>')
        ->not->toContain('<script>', '<img src=x');
});

it('keeps the initial booking path visible without exposing hidden confirmation actions', function () {
    $planner = User::factory()->create(['admin' => false, 'role' => 1]);
    $this->actingAs($planner)->get(route('planner.book'))->assertOk()
        ->assertSee('Parcours de prise de rendez-vous')
        ->assertSee('Choisir un dossier')
        ->assertSee('Trouver un créneau')
        ->assertSee('Confirmer le RDV')
        ->assertSee('id="booking-placement-confirmation" class="gc-card hidden', false);
});
