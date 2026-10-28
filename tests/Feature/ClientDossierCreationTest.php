<?php

use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierOperation;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->organisation = ExternalDelegataire::create([
        'source' => 'native', 'external_id' => 'CLIENT-PORTAL', 'kind' => 'client',
        'name' => 'Client portail', 'email' => 'organisation@example.test', 'is_active' => true,
    ]);
    $this->client = User::factory()->create([
        'role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $this->organisation->id,
        'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client'),
    ]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'BAR EN 101', 'average_duration_minutes' => 60]);
    $this->data = [
        'submission_id' => (string) Str::uuid(), 'company_name' => 'Bénéficiaire de la visite',
        'last_name' => 'Dupont', 'first_name' => 'Marie', 'email' => 'beneficiaire@example.test',
        'phone' => '0600000000', 'address' => '10 rue de la Visite', 'postal_code' => '69001',
        'city' => 'Lyon', 'service_ids' => [$this->service->id],
    ];
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('offers a client-only form without organisation directories or internal controls', function () {
    $other = ExternalDelegataire::create([
        'source' => 'native', 'external_id' => 'SECRET-ORG', 'kind' => 'client',
        'name' => 'Organisation confidentielle', 'email' => 'secret@example.test', 'is_active' => true,
    ]);
    $this->actingAs($this->client)->get(route('client.dossiers'))->assertOk()
        ->assertSee(route('client.dossiers.create'), false)->assertSee('Nouveau dossier');
    $this->get(route('client.dossiers.create'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Prise de RDV')->assertSee($this->organisation->name)->assertSee($this->service->name)
        ->assertSee('name="submission_id"', false)->assertSee(route('client.dossiers.store'), false)
        ->assertDontSee('name="client_id"', false)->assertDontSee('name="delegataire_id"', false)
        ->assertDontSee('name="reference"', false)->assertDontSee($other->name)->assertDontSee($other->email)
        ->assertDontSee('Gestion des données');
});

it('creates a dossier and all selected operations in prise de RDV for its own client', function () {
    $second = Service::create(['type' => 'COFFRAC', 'name' => 'BAR EN 102', 'average_duration_minutes' => 60]);
    $this->actingAs($this->client)->post(route('client.dossiers.store'), [
        ...$this->data, 'service_ids' => [$this->service->id, $second->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('client.dossiers.show', 1));

    $dossier = Dossier::sole();
    expect($dossier->client_id)->toBe($this->organisation->id)
        ->and($dossier->client_contact['email'])->toBe('organisation@example.test')
        ->and($dossier->delegataire_id)->toBeNull()->and($dossier->delegataire_contact)->toBe([])
        ->and($dossier->created_by)->toBe($this->client->id)
        ->and($dossier->company_name)->toBe('Bénéficiaire de la visite')
        ->and($dossier->source)->toBe('native')->and($dossier->source_reference)->toBeNull()
        ->and($dossier->operations()->pluck('legacy_status')->all())->toBe(['PRISE DE RDV', 'PRISE DE RDV'])
        ->and($dossier->activities()->sole()->author_id)->toBe($this->client->id)
        ->and($dossier->appointments()->count())->toBe(0)->and($dossier->billings()->count())->toBe(0);

    $this->get(route('client.dossiers.show', $dossier))->assertOk()->assertSee('Bénéficiaire de la visite');
    $this->get(route('client.dossiers', ['status' => 'pending']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
    $manager = User::factory()->create(['role' => 0]);
    $this->actingAs($manager)->get(route('v2.dossiers.index', ['status' => 'pending']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $dossier->id);
});

it('cannot override assignment state authorship billing or private metadata with extra fields', function () {
    $manager = User::factory()->create(['role' => 0]);
    $dossier = app(DossierRegistry::class)->createForClient($this->client, [
        ...$this->data, 'client_id' => 999, 'delegataire_id' => 999, 'reference' => 'FORGED',
        'client_contact' => ['name' => 'Forged client'], 'delegataire_contact' => ['email' => 'forged@example.test'],
        'created_by' => $manager->id, 'planning_user_id' => $manager->id, 'legacy_status' => 'RAPPORT GENIUS',
        'source' => 'coffrac', 'source_reference' => '123', 'version' => 999, 'deleted_at' => now(),
        'billing_review_required' => true, 'client_visible' => true,
    ])->fresh();
    expect($dossier->client_id)->toBe($this->organisation->id)
        ->and($dossier->client_contact['name'])->toBe('Client portail')
        ->and($dossier->reference)->not->toBe('FORGED')
        ->and($dossier->created_by)->toBe($this->client->id)
        ->and($dossier->planning_user_id)->toBeNull()->and($dossier->version)->toBe(1)
        ->and($dossier->deleted_at)->toBeNull()->and($dossier->delegataire_id)->toBeNull()
        ->and($dossier->billing_review_required)->toBeFalse()
        ->and($dossier->operations()->sole()->legacy_status)->toBe('PRISE DE RDV');
});

it('ignores forged HTTP fields and does not expand access after creation', function () {
    $this->actingAs($this->client)->post(route('client.dossiers.store'), [
        ...$this->data, 'client_id' => 999, 'delegataire_id' => 999,
        'reference' => 'OVERRIDE', 'client_contact' => ['email' => 'forged@example.test'],
        'service_ids' => [$this->service->id], 'legacy_status' => 'RAPPORT GENIUS', 'created_by' => 999,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $dossier = Dossier::sole();
    expect($dossier->client_id)->toBe($this->organisation->id)
        ->and($dossier->client_contact['email'])->toBe('organisation@example.test');
    $this->put(route('v2.dossiers.update', $dossier), ['version' => 1, 'city' => 'Paris'])->assertForbidden();
    $this->post(route('client.dossiers.comments', $dossier), ['body' => 'Question sur ce dossier'])->assertRedirect();
    $this->post(route('v2.dossiers.store'), $this->data)->assertForbidden();
});

it('prevents double submissions of the same form and keeps unrelated clients isolated', function () {
    $this->actingAs($this->client)->post(route('client.dossiers.store'), $this->data)->assertSessionHasNoErrors();
    $this->post(route('client.dossiers.store'), $this->data)->assertSessionHasErrors('reference');
    expect(Dossier::count())->toBe(1)->and(DossierOperation::count())->toBe(1)->and(DossierActivity::count())->toBe(1);
    $other = ExternalDelegataire::create([
        'source' => 'native', 'external_id' => 'OTHER', 'kind' => 'client', 'name' => 'Other', 'is_active' => true,
    ]);
    $account = User::factory()->create([
        'role' => User::ROLE_EXTERNAL, 'external_client_id' => $other->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client'),
    ]);
    $this->actingAs($account)->get(route('client.dossiers.show', Dossier::sole()))->assertForbidden();
    $this->post(route('client.dossiers.store'), $this->data)->assertSessionHasNoErrors();
    expect(Dossier::count())->toBe(2)->and(Dossier::query()->where('client_id', $other->id)->count())->toBe(1);
});

it('rejects incomplete invalid duplicate or retired service submissions', function (array $overrides, string $error) {
    $this->actingAs($this->client)->post(route('client.dossiers.store'), [...$this->data, ...$overrides])
        ->assertSessionHasErrors($error);
    expect(Dossier::count())->toBe(0)->and(DossierOperation::count())->toBe(0);
})->with([
    [['company_name' => '', 'last_name' => ''], 'company_name'],
    [['address' => ''], 'address'],
    [['city' => ''], 'city'],
    [['submission_id' => ''], 'submission_id'],
    [['submission_id' => 'forged'], 'submission_id'],
    [['service_ids' => []], 'service_ids'],
    [['service_ids' => [999999]], 'service_ids.0'],
    [['service_ids' => [1, 1]], 'service_ids.0'],
    [['email' => 'invalid'], 'email'],
    [['installer_siren' => 'invalid'], 'installer_siren'],
]);

it('excludes retired services from the form and rejects their IDs', function () {
    $this->service->forceFill(['retired_at' => now()])->save();
    $this->actingAs($this->client)->get(route('client.dossiers.create'))->assertOk()
        ->assertDontSee($this->service->name)->assertSee('Aucune prestation disponible');
    $this->post(route('client.dossiers.store'), $this->data)->assertSessionHasErrors('service_ids.0');
    expect(Dossier::count())->toBe(0);
});

it('renders validation errors safely when submitted values have the wrong types', function () {
    $this->actingAs($this->client)->followingRedirects()->from(route('client.dossiers.create'))->post(route('client.dossiers.store'), [
        ...$this->data, 'submission_id' => ['invalid'], 'company_name' => ['invalid'], 'service_ids' => 'invalid',
    ])->assertOk()->assertSee('role="alert"', false)->assertSee('value="Dupont"', false);
    expect(Dossier::count())->toBe(0);
});

it('allows existing client accounts without the new key and respects an explicit revocation', function () {
    $permissions = ClientAccess::defaults('client');
    unset($permissions['create_dossiers']);
    $this->client->forceFill(['portal_permissions' => $permissions])->save();
    $this->actingAs($this->client)->get(route('client.dossiers.create'))->assertOk();
    $this->client->forceFill(['portal_permissions' => [...$permissions, 'create_dossiers' => false]])->save();
    $this->get(route('client.dossiers'))->assertOk()->assertDontSee(route('client.dossiers.create'), false);
    $this->get(route('client.dossiers.create'))->assertForbidden();
    $this->post(route('client.dossiers.store'), $this->data)->assertForbidden();
    expect(Dossier::count())->toBe(0);
});

it('lets managers administer the new client permission without altering document permissions', function () {
    $manager = User::factory()->create(['role' => 0]);
    $this->actingAs($manager)->get(route('manager.clients.edit', $this->organisation))->assertOk()
        ->assertSee('Créer des dossiers en prise de RDV')->assertSee('permissions[create_dossiers]', false);
    $permissions = [...ClientAccess::defaults('client'), 'create_dossiers' => false];
    $this->put(route('manager.clients.accounts.update', [$this->organisation, $this->client]), [
        'first_name' => $this->client->first_name, 'last_name' => $this->client->last_name,
        'email' => $this->client->email, 'portal_enabled' => true, 'permissions' => $permissions,
    ])->assertSessionHasNoErrors();
    expect($this->client->fresh()->portal_permissions)->toBe($permissions);
});

it('refuses delegataires even with a forged create permission', function () {
    $this->organisation->update(['kind' => 'delegataire']);
    $this->client->forceFill(['portal_permissions' => [...ClientAccess::defaults('delegataire'), 'create_dossiers' => true]])->save();
    $this->actingAs($this->client)->get(route('client.dossiers'))->assertOk()->assertDontSee(route('client.dossiers.create'), false);
    $this->get(route('client.dossiers.create'))->assertForbidden();
    $this->post(route('client.dossiers.store'), $this->data)->assertForbidden();
    expect(Dossier::count())->toBe(0);
});

it('enforces category restrictions for creation as well as viewing', function () {
    $this->organisation->update(['portal_categories' => ['non_compliant']]);
    $this->actingAs($this->client)->get(route('client.dossiers.create'))->assertForbidden();
    $this->post(route('client.dossiers.store'), $this->data)->assertForbidden();
    expect(Dossier::count())->toBe(0);
});

it('denies disabled inactive deleted password-pending and non-client accounts', function (string $case) {
    match ($case) {
        'portal_disabled' => $this->client->forceFill(['portal_enabled' => false])->save(),
        'organisation_inactive' => $this->organisation->update(['is_active' => false]),
        'deleted_user' => $this->client->delete(),
        'password_pending' => $this->client->forceFill(['must_change_password' => true])->save(),
        'internal' => $this->client->forceFill(['role' => 0])->save(),
        'v2_disabled' => config(['crm.v2_enabled' => false]),
    };
    if ($case === 'password_pending') {
        $this->actingAs($this->client)->get(route('client.dossiers.create'))->assertRedirect(route('profile'));
        $this->post(route('client.dossiers.store'), $this->data)->assertRedirect(route('profile'));
    } else {
        $this->actingAs($this->client)->get(route('client.dossiers.create'))->assertForbidden();
        $this->actingAs($this->client)->post(route('client.dossiers.store'), $this->data)->assertForbidden();
    }
    expect(Dossier::count())->toBe(0);
})->with(['portal_disabled', 'organisation_inactive', 'deleted_user', 'password_pending', 'internal', 'v2_disabled']);

it('requires authentication to submit a client dossier', function () {
    $this->get(route('client.dossiers.create'))->assertRedirect(route('login'));
    $this->post(route('client.dossiers.store'), $this->data)->assertRedirect(route('login'));
    expect(Dossier::count())->toBe(0);
});
