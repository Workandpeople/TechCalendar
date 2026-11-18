<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function runProductionPortalPreflight(): array
{
    // Execute the actual pinned checker block without its production-only host/filesystem setup.
    $source = file_get_contents(dirname(__DIR__, 2).'/tools/deploy/production-runtime.php');
    $from = strpos($source, '    $portalAccounts = 0;');
    $to = strpos($source, '    // Active templates are editable content');
    if ($from === false || $to === false || $to <= $from) {
        throw new LogicException('Production portal preflight block not found.');
    }

    return eval('use App\\Models\\User; use App\\Models\\ExternalDelegataire; use App\\Services\\Crm\\ClientAccess;'
        .substr($source, $from, $to - $from)
        .'return ["active" => $portalAccounts, "inactive_organisation" => $inactiveOrganisationPortalAccounts];');
}

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'preflight-inactive',
        'kind' => 'delegataire', 'name' => 'Organisation inactive fictive', 'is_active' => false]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('delegataire')]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('accepts an inactive client or delegate as access-denied without mutating accounts permissions or passwords', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $userBefore = $this->external->fresh()->getAttributes();
    $organisationBefore = $this->organisation->fresh()->getAttributes();
    DB::enableQueryLog();
    expect(runProductionPortalPreflight())->toBe(['active' => 0, 'inactive_organisation' => 1]);
    foreach (DB::getQueryLog() as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    DB::disableQueryLog();
    expect($this->external->fresh()->getAttributes())->toBe($userBefore)
        ->and($this->organisation->fresh()->getAttributes())->toBe($organisationBefore)
        ->and(app(ClientAccess::class)->active($this->external->fresh()))->toBeFalse();
    $this->actingAs($this->external->fresh())->get(route('client.dossiers'))->assertForbidden();
})->with(['client', 'delegataire']);

it('still counts valid active external accounts separately from inactive organisations', function () {
    $active = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'preflight-active',
        'kind' => 'client', 'name' => 'Active fixture', 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $active->id, 'portal_enabled' => true]);
    expect(runProductionPortalPreflight())->toBe(['active' => 1, 'inactive_organisation' => 1]);
});

it('never exempts an invalid account just because its organisation is inactive', function (string $case) {
    match ($case) {
        'internal role' => $this->external->forceFill(['role' => 2])->save(),
        'unknown role' => $this->external->forceFill(['role' => 99])->save(),
        'admin' => $this->external->forceFill(['admin' => true])->save(),
        'no organisation' => $this->external->forceFill(['external_client_id' => null])->save(),
        'invalid kind' => $this->organisation->update(['kind' => 'unknown']),
    };
    expect(fn () => runProductionPortalPreflight())->toThrow(RuntimeException::class,
        'Compte de portail actif sans role externe ou organisation valide. Aucun acces modifie.');
})->with(['internal role', 'unknown role', 'admin', 'no organisation', 'invalid kind']);

it('still blocks an invalid enabled account when its organisation is active', function () {
    $this->organisation->update(['is_active' => true]);
    $this->external->forceFill(['role' => 1])->save();
    expect(fn () => runProductionPortalPreflight())->toThrow(RuntimeException::class);
});

it('does not grant access when the application portal is disabled', function () {
    config(['crm.v2_enabled' => false]);
    $this->organisation->update(['is_active' => true]);
    expect(fn () => runProductionPortalPreflight())->toThrow(RuntimeException::class);
});

it('keeps intentionally disabled and deleted accounts out of the active portal audit', function (string $case) {
    if ($case === 'disabled') {
        $this->external->forceFill(['portal_enabled' => false, 'role' => 2])->save();
    } else {
        $this->external->delete();
    }
    expect(runProductionPortalPreflight())->toBe(['active' => 0, 'inactive_organisation' => 0]);
})->with(['disabled', 'deleted']);

it('keeps eager loading bounded when many accounts share an inactive organisation', function () {
    User::factory()->count(105)->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true]);
    DB::enableQueryLog();
    expect(runProductionPortalPreflight())->toBe(['active' => 0, 'inactive_organisation' => 106])
        ->and(DB::getQueryLog())->toHaveCount(4);
    DB::disableQueryLog();
});
