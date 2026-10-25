<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\DossierPlanningAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->planner = User::factory()->create(['role' => 0, 'first_name' => 'Alice', 'last_name' => 'Gérante']);
    $this->dossier = Dossier::create(['reference' => 'VISITED-1', 'company_name' => 'Inspection test', 'address' => '1 rue du Test', 'city' => 'Lyon']);
    $this->operation = $this->dossier->operations()->create();
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $this->url = route('v2.dossiers.planning-assignment', $this->dossier);
    $this->actingAs($this->actor);
});

it('assigns and reassigns visited dossiers with a private audit without changing status or client', function () {
    $response = $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])
        ->assertOk()->assertJsonPath('planning_user_id', $this->planner->id)->assertJsonPath('version', 2);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($this->dossier->refresh()->planning_user_id)->toBe($this->planner->id)
        ->and($this->dossier->delegataire_id)->toBeNull()
        ->and($this->operation->refresh()->legacy_status)->toBe('RDV VISITE')
        ->and($this->dossier->appointments()->count())->toBe(0);
    $activity = $this->dossier->activities()->sole();
    expect($activity->author_id)->toBe($this->actor->id)->and($activity->client_visible)->toBeFalse()
        ->and($activity->kind)->toBe('planning_assigned')
        ->and($activity->changed_fields)->toBe(['previous_planning_user_id' => null, 'planning_user_id' => $this->planner->id]);
    $other = User::factory()->create(['role' => 0]);
    $this->patchJson($this->url, ['planning_user_id' => $other->id, 'version' => 2])->assertOk()->assertJsonPath('version', 3);
    expect($this->dossier->refresh()->planning_user_id)->toBe($other->id)
        ->and($this->dossier->activities()->count())->toBe(2);
    Http::assertNothingSent();
});

it('allows planning staff to assign and to remove an assignment without duplicate history', function () {
    $this->actingAs(User::factory()->create(['role' => 1]));
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])->assertOk();
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 2])->assertOk()->assertJsonPath('version', 2);
    expect($this->dossier->activities()->count())->toBe(1);
    $this->patchJson($this->url, ['planning_user_id' => null, 'version' => 2])->assertOk()->assertJsonPath('version', 3);
    expect($this->dossier->refresh()->planning_user_id)->toBeNull()->and($this->dossier->activities()->count())->toBe(2);
});

it('rejects stale versions without overwriting a colleague assignment', function () {
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])->assertOk();
    $this->patchJson($this->url, ['planning_user_id' => null, 'version' => 1])->assertConflict();
    expect($this->dossier->refresh()->planning_user_id)->toBe($this->planner->id)
        ->and($this->dossier->activities()->count())->toBe(1);
});

it('requires a visited operation even when the listing was loaded before a status change', function (string $status) {
    $this->operation->forceFill(['legacy_status' => $status])->save();
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('planning_user_id');
    expect($this->dossier->refresh()->planning_user_id)->toBeNull()->and($this->dossier->version)->toBe(1);
})->with(['PRISE DE RDV', 'RDV ATTENTE VISITE', 'CONTROLE RT', 'RAPPORT GENIUS', 'DOSSIERS FACTURER']);

it('supports mixed dossiers as long as one operation is visited', function () {
    $this->dossier->operations()->create()->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])->assertOk();
    expect($this->dossier->operations()->pluck('legacy_status')->all())->toBe(['RDV VISITE', 'PRISE DE RDV']);
});

it('rejects non manager external and deleted targets including stale role assignments', function (string $kind) {
    $target = User::factory()->create(['role' => match ($kind) {
        'planner' => 1, 'technician' => 2, 'external' => 3, default => 0,
    }]);
    if ($kind === 'deleted') {
        $target->delete();
    }
    if ($kind === 'linked_external') {
        $client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CLIENT', 'name' => 'Client externe']);
        $target->forceFill(['external_client_id' => $client->id])->save();
    }
    $this->patchJson($this->url, ['planning_user_id' => $target->id, 'version' => 1])->assertUnprocessable();
    expect(app(DossierPlanningAssignment::class)->users()->modelKeys())->not->toContain($target->id)
        ->and($this->dossier->activities()->count())->toBe(0);
})->with(['planner', 'technician', 'external', 'linked_external', 'deleted']);

it('validates assignment and version inputs before any write', function (array $payload) {
    $this->patchJson($this->url, $payload)->assertUnprocessable();
    expect($this->dossier->refresh()->planning_user_id)->toBeNull()->and($this->dossier->activities()->count())->toBe(0);
})->with([
    [['version' => 1]], [['planning_user_id' => null]], [['planning_user_id' => 999999, 'version' => 1]],
    [['planning_user_id' => 'oops', 'version' => 1]], [['planning_user_id' => [], 'version' => 1]],
    [['planning_user_id' => null, 'version' => 0]],
]);

it('does not expose assignment to technicians external accounts or unauthenticated visitors', function (string $kind) {
    auth()->logout();
    if ($kind !== 'guest') {
        $user = User::factory()->create(['role' => $kind === 'external' ? 3 : 2, 'admin' => false]);
        $this->actingAs($user);
    }
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])
        ->assertStatus($kind === 'guest' ? 401 : 403);
    expect($this->dossier->refresh()->planning_user_id)->toBeNull();
})->with(['guest', 'technician', 'external']);

it('respects the disabled v2 feature flag', function () {
    config(['crm.v2_enabled' => false]);
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])->assertNotFound();
});

it('renders assignment in visited rows and ajax fragments but not unrelated categories', function () {
    $this->get(route('v2.dossiers.index', ['status' => 'visited']))->assertOk()
        ->assertSee('Suivi planning')->assertSee('data-planning-assignee', false)->assertSee($this->planner->full_name);
    $this->get(route('v2.dossiers.index', ['status' => 'pending']))->assertOk()->assertDontSee('data-planning-assignee', false);
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])->assertOk();
    $response = $this->getJson(route('v2.dossiers.index', ['status' => 'visited']))->assertOk();
    expect($response->json('results'))->toContain('data-version="2"', 'data-assigned-id="'.$this->planner->id.'"')
        ->not->toContain('Suivi planning attribué à');
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Suivi planning attribué à')->assertSee($this->planner->full_name);
});

it('preserves the name of a removed assignee and lets staff clear the assignment with no eligible users left', function () {
    $this->patchJson($this->url, ['planning_user_id' => $this->planner->id, 'version' => 1])->assertOk();
    $this->planner->delete();
    $response = $this->getJson(route('v2.dossiers.index', ['status' => 'visited']))->assertOk();
    expect($response->json('results'))->toContain($this->planner->full_name.' (indisponible)');
    $this->patchJson($this->url, ['planning_user_id' => null, 'version' => 2])->assertOk();
});
