<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs coffrac delegataires into the local read only manager page', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/delegataires' => Http::response([
            'result' => true,
            'data' => [
                [
                    'id' => 7,
                    'company_name' => 'Oblige Contrôle',
                    'name' => 'Lucas Admin',
                    'email' => 'delegataire@example.test',
                    'phone' => '0601020304',
                    'is_active' => true,
                ],
            ],
        ]),
    ]);

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.delegataires.sync'))
        ->assertRedirect(route('manager.delegataires'))
        ->assertSessionHas('status');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://coffrac.test/api/techcalendar/delegataires'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));

    $this->assertDatabaseHas('external_delegataires', [
        'source' => CoffracAppointmentService::SOURCE,
        'external_id' => '7',
        'name' => 'Oblige Contrôle',
        'company_name' => 'Oblige Contrôle',
        'email' => 'delegataire@example.test',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.delegataires'))
        ->assertOk()
        ->assertSee('Gestion des délégataires')
        ->assertSee('Oblige Contrôle')
        ->assertSee('delegataire@example.test')
        ->assertSee('Récupérer depuis Coffrac')
        ->assertDontSee('Créer un délégataire')
        ->assertDontSee('Supprimer');
});

it('disables missing coffrac delegataires during sync', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    ExternalDelegataire::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_id' => 'old-1',
        'name' => 'Ancien délégataire',
        'is_active' => true,
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/delegataires' => Http::response([
            'result' => true,
            'data' => [],
        ]),
    ]);

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.delegataires.sync'))
        ->assertRedirect(route('manager.delegataires'));

    $this->assertDatabaseHas('external_delegataires', [
        'source' => CoffracAppointmentService::SOURCE,
        'external_id' => 'old-1',
        'is_active' => false,
    ]);
});
