<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\UserAccountConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Mail::fake();
});

dataset('user email conflict areas', [
    'admin' => ['admin', true],
    'manager' => ['manager', false],
    'admin in manager area' => ['manager', true],
]);

function conflictingUserPayload(string $email): array
{
    return ['first_name' => 'Camille', 'last_name' => 'Test', 'email' => $email, 'role' => 1, 'admin' => false];
}

function emailConflictExternal(string $kind): User
{
    $client = ExternalDelegataire::query()->create([
        'source' => 'native', 'external_id' => 'email-conflict-'.$kind, 'kind' => $kind,
        'name' => 'Email conflict '.$kind, 'is_active' => false,
    ]);

    return User::factory()->create([
        'role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $client->id, 'portal_enabled' => false,
    ]);
}

it('explains an existing internal email without recreating or restoring its account', function (string $area, bool $admin, bool $disabled) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $existing = User::factory()->create(['role' => 1, 'admin' => false]);
    if ($disabled) {
        $existing->delete();
    }
    $before = $existing->refresh()->getRawOriginal();

    $this->actingAs($actor)->from(route("{$area}.users"))
        ->post(route("{$area}.users.store"), conflictingUserPayload($existing->email))
        ->assertRedirect(route("{$area}.users"));

    $this->get(route("{$area}.users"))->assertOk()
        ->assertViewHas('emailConflict', fn ($conflict) => $conflict !== null)
        ->assertSee($disabled ? 'compte interne désactivé' : 'compte interne actif')
        ->assertSee('Retrouver le compte existant')
        ->assertSee(route($admin ? 'admin.users' : 'manager.users', ['q' => $existing->email, 'status' => 'all']));

    expect(User::withTrashed()->count())->toBe(2)
        ->and($existing->fresh()->getRawOriginal())->toBe($before);
    Mail::assertNothingSent();
})->with('user email conflict areas')->with([false, true]);

it('finds the disabled account hidden by the active status filter without silently including it', function (string $area, bool $admin) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $existing = User::factory()->create(['role' => 1, 'admin' => false]);
    $existing->delete();

    $this->actingAs($actor)->get(route("{$area}.users", ['q' => $existing->email]))
        ->assertOk()->assertSee('compte interne désactivé')
        ->assertSee('Retrouver le compte existant')
        ->assertViewHas('users', fn ($users) => $users->isEmpty());

    $this->get(route($admin ? 'admin.users' : 'manager.users', ['q' => $existing->email, 'status' => 'all']))
        ->assertOk()->assertSee('Restaurer')
        ->assertViewHas('users', fn ($users) => $users->pluck('id')->all() === [$existing->id]);
    expect($existing->fresh()->trashed())->toBeTrue();
})->with('user email conflict areas');

it('explains external emails and links to their separate management without granting access', function (string $area, bool $admin, string $kind) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $existing = emailConflictExternal($kind);
    $before = $existing->refresh()->getRawOriginal();

    $response = $this->actingAs($actor)->postJson(route("{$area}.users.store"), conflictingUserPayload($existing->email))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($response->json('errors.email.0'))->toContain($kind === 'client' ? 'compte client' : 'compte délégataire');

    $this->get(route("{$area}.users", ['q' => $existing->email]))
        ->assertOk()->assertSee('Gérer les accès externes')
        ->assertSee(route('manager.clients.edit', $existing->external_client_id))
        ->assertViewHas('users', fn ($users) => $users->isEmpty());
    expect($existing->fresh()->getRawOriginal())->toBe($before)
        ->and(User::withTrashed()->count())->toBe(2);
    Mail::assertNothingSent();
})->with('user email conflict areas')->with(['client', 'delegataire']);

it('keeps administrators hidden from managers while explaining the email reservation', function () {
    $actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $existing = User::factory()->create(['role' => 0, 'admin' => true]);

    $this->actingAs($actor)->get(route('manager.users', ['q' => $existing->email]))
        ->assertOk()->assertSee('Seul un administrateur peut gérer ce compte.')
        ->assertDontSee('Retrouver le compte existant')
        ->assertViewHas('users', fn ($users) => $users->isEmpty());

    $this->postJson(route('manager.users.store'), conflictingUserPayload($existing->email))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($existing->fresh()->admin)->toBeTrue();
});

it('can find a valid email longer than the old search limit', function (string $area, bool $admin) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $existing = User::factory()->create([
        'email' => str_repeat('a', 64).'@'.str_repeat('b', 63).'.example.test', 'role' => 1, 'admin' => false,
    ]);
    $existing->delete();

    $this->actingAs($actor)->get(route("{$area}.users", ['q' => $existing->email]))
        ->assertOk()->assertSee('Retrouver le compte existant');
    $this->get(route($admin ? 'admin.users' : 'manager.users', ['q' => $existing->email, 'status' => 'all']))
        ->assertOk()->assertViewHas('users', fn ($users) => $users->pluck('id')->all() === [$existing->id]);
})->with('user email conflict areas');

it('allows a new address and preserves the current address on update', function (string $area, bool $admin) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);

    $this->actingAs($actor)->post(route("{$area}.users.store"), conflictingUserPayload('new-user@example.test'))
        ->assertRedirect(route("{$area}.users"))->assertSessionHasNoErrors();
    $created = User::query()->where('email', 'new-user@example.test')->firstOrFail();

    $this->put(route("{$area}.users.update", $created), conflictingUserPayload($created->email))
        ->assertRedirect(route("{$area}.users"))->assertSessionHasNoErrors();
    expect(User::query()->where('email', $created->email)->count())->toBe(1);
})->with('user email conflict areas');

it('still reserves a disabled account email on updates', function (string $area, bool $admin) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $target = User::factory()->create(['role' => 1, 'admin' => false]);
    $existing = User::factory()->create(['role' => 1, 'admin' => false]);
    $existing->delete();

    $this->actingAs($actor)->putJson(route("{$area}.users.update", $target), conflictingUserPayload($existing->email))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($target->fresh()->email)->toBe($target->email)
        ->and($existing->fresh()->trashed())->toBeTrue();
})->with('user email conflict areas');

it('does not expose the lookup to external users or technicians', function (int $role) {
    $actor = User::factory()->create(['role' => $role, 'admin' => false]);
    $existing = User::factory()->create();

    app(UserAccountConflict::class)->find($actor, $existing->email);
})->with([2, User::ROLE_EXTERNAL])->throws(HttpException::class);
