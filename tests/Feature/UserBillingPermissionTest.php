<?php

use App\Mail\UserCreatedCredentialsMail;
use App\Models\Department;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\MobileAccessToken;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Mail::fake();
    Notification::fake();
    Http::preventStrayRequests();
});

dataset('billing user managers', [
    'admin endpoint' => ['admin', true],
    'manager endpoint' => ['manager', false],
    'admin via manager endpoint' => ['manager', true],
]);

function billingUserPayload(?User $user = null, int $role = 1): array
{
    $payload = [
        'first_name' => $user?->first_name ?? 'Camille',
        'last_name' => $user?->last_name ?? 'Comptabilite',
        'email' => $user?->email ?? 'billing-user@example.test',
        'role' => $role,
        'admin' => false,
    ];

    if ($role === 2) {
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT, 'name' => 'Billing permission test', 'average_duration_minutes' => 90,
        ]);
        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhone']);
        $payload += [
            'phone' => '0601020304', 'address' => '1 place Bellecour, 69002 Lyon',
            'department_code' => '69', 'latitude' => 45.7578, 'longitude' => 4.832,
            'day_start_time' => '08:00', 'day_end_time' => '18:00', 'break_duration_minutes' => 45,
            'service_ids' => [$service->id], 'department_codes' => ['69'],
        ];
    }

    return $payload;
}

function billingExternalClient(): ExternalDelegataire
{
    return ExternalDelegataire::query()->create([
        'source' => 'native', 'external_id' => 'billing-client',
        'kind' => 'client', 'name' => 'Billing client', 'is_active' => true,
    ]);
}

it('defaults billing permission to false and casts explicitly assigned values', function () {
    $user = User::factory()->create(['role' => 1])->refresh();

    expect($user->can_manage_billing)->toBeFalse()
        ->and($user->isFillable('can_manage_billing'))->toBeTrue();

    $user->fill(['can_manage_billing' => 1])->save();
    expect($user->refresh()->can_manage_billing)->toBeTrue();
    $user->fill(['can_manage_billing' => '0'])->save();
    expect($user->refresh()->can_manage_billing)->toBeFalse();
});

it('stores only explicit Planning billing grants', function (string $area, bool $admin, int $role, array $permission, bool $expected) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);

    $this->actingAs($actor)->post(route("{$area}.users.store"), [
        ...billingUserPayload(role: $role), ...$permission,
    ])->assertRedirect(route("{$area}.users"))->assertSessionHasNoErrors();

    $created = User::query()->where('email', 'billing-user@example.test')->firstOrFail();
    expect($created->can_manage_billing)->toBe($expected)
        ->and($created->role)->toBe($role)
        ->and($created->admin)->toBeFalse()
        ->and($created->must_change_password)->toBeTrue();
    Mail::assertSent(UserCreatedCredentialsMail::class, 1);
})->with('billing user managers')->with([
    'Planning omitted' => [1, [], false],
    'Planning false' => [1, ['can_manage_billing' => false], false],
    'Planning zero' => [1, ['can_manage_billing' => '0'], false],
    'Planning true' => [1, ['can_manage_billing' => true], true],
    'Planning one' => [1, ['can_manage_billing' => '1'], true],
    'manager implicit' => [0, [], false],
    'manager crafted' => [0, ['can_manage_billing' => true], false],
    'technician omitted' => [2, [], false],
    'technician crafted' => [2, ['can_manage_billing' => true], false],
]);

it('preserves revokes and clears billing grants without implicit promotion', function (string $area, bool $admin, int $oldRole, bool $existing, int $role, array $permission, bool $expected) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $target = User::factory()->create(['role' => $oldRole, 'can_manage_billing' => $existing]);

    $this->actingAs($actor)->put(route("{$area}.users.update", $target), [
        ...billingUserPayload($target, $role), ...$permission,
    ])->assertRedirect(route("{$area}.users"))->assertSessionHasNoErrors();

    expect($target->refresh()->can_manage_billing)->toBe($expected)
        ->and($target->role)->toBe($role);
    Mail::assertNothingSent();
})->with('billing user managers')->with([
    'preserve Planning grant' => [1, true, 1, [], true],
    'preserve Planning refusal' => [1, false, 1, [], false],
    'grant Planning' => [1, false, 1, ['can_manage_billing' => '1'], true],
    'revoke Planning' => [1, true, 1, ['can_manage_billing' => '0'], false],
    'Planning to technician' => [1, true, 2, [], false],
    'crafted technician grant' => [1, true, 2, ['can_manage_billing' => true], false],
    'clear stale technician grant' => [2, true, 2, [], false],
    'Planning to manager' => [1, true, 0, [], false],
    'manager to Planning' => [0, false, 1, [], false],
    'stale manager to Planning' => [0, true, 1, [], false],
    'stale technician to Planning' => [2, true, 1, [], false],
    'explicit grant on role change' => [0, false, 1, ['can_manage_billing' => true], true],
]);

it('does not convert an admin privilege into a Planning billing grant', function () {
    $actor = User::factory()->create(['admin' => true]);
    $target = User::factory()->create(['admin' => true, 'role' => 1, 'can_manage_billing' => false]);

    $this->actingAs($actor)->put(route('admin.users.update', $target), billingUserPayload($target))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($target->refresh()->admin)->toBeFalse()
        ->and($target->can_manage_billing)->toBeFalse();
});

it('rejects non boolean billing permission on create and update', function (string $area, bool $admin, mixed $invalid) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $target = User::factory()->create(['role' => 1, 'can_manage_billing' => false]);

    $this->actingAs($actor)->postJson(route("{$area}.users.store"), [
        ...billingUserPayload(), 'can_manage_billing' => $invalid,
    ])->assertUnprocessable()->assertJsonValidationErrors('can_manage_billing');
    $this->putJson(route("{$area}.users.update", $target), [
        ...billingUserPayload($target), 'can_manage_billing' => $invalid,
    ])->assertUnprocessable()->assertJsonValidationErrors('can_manage_billing');

    expect($target->refresh()->can_manage_billing)->toBeFalse();
    $this->assertDatabaseMissing('users', ['email' => 'billing-user@example.test']);
    Mail::assertNothingSent();
})->with('billing user managers')->with([
    'null' => [null], 'string' => ['yes'], 'array' => [[true]], 'out of range' => [2],
]);

it('forbids unauthorized actors from granting billing permission including to themselves', function (string $area, array $attributes) {
    if (isset($attributes['external_client_id'])) {
        $attributes['external_client_id'] = billingExternalClient()->id;
    }
    $actor = User::factory()->create($attributes);
    $target = $actor->isExternal() ? User::factory()->create(['role' => 1]) : $actor;

    $this->actingAs($actor)->postJson(route("{$area}.users.store"), [
        ...billingUserPayload(), 'can_manage_billing' => true,
    ])->assertForbidden();
    $this->actingAs($actor)->putJson(route("{$area}.users.update", $target), [
        ...billingUserPayload($target), 'can_manage_billing' => true,
    ])->assertForbidden();

    expect($target->refresh()->can_manage_billing)->toBe((bool) ($attributes['can_manage_billing'] ?? false));
    $this->assertDatabaseMissing('users', ['email' => 'billing-user@example.test']);
    Mail::assertNothingSent();
})->with([
    'manager cannot use admin endpoint' => ['admin', ['role' => 0]],
    'Planning admin endpoint' => ['admin', ['role' => 1]],
    'Planning manager endpoint' => ['manager', ['role' => 1]],
    'accounting Planning admin endpoint' => ['admin', ['role' => 1, 'can_manage_billing' => true]],
    'accounting Planning manager endpoint' => ['manager', ['role' => 1, 'can_manage_billing' => true]],
    'tech admin endpoint' => ['admin', ['role' => 2]],
    'tech manager endpoint' => ['manager', ['role' => 2]],
    'external admin endpoint' => ['admin', ['role' => 3, 'external_client_id' => 1, 'portal_enabled' => true]],
    'external manager endpoint' => ['manager', ['role' => 3, 'external_client_id' => 1, 'portal_enabled' => true]],
    'external forged admin' => ['admin', ['role' => 0, 'admin' => true, 'external_client_id' => 1]],
    'external forged manager' => ['manager', ['role' => 0, 'external_client_id' => 1]],
]);

it('excludes external targets from billing permission management', function (string $area, bool $admin, bool $linked) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $target = User::factory()->create([
        'role' => $linked ? 1 : User::ROLE_EXTERNAL,
        'external_client_id' => $linked ? billingExternalClient()->id : null,
    ]);

    $this->actingAs($actor)->get(route("{$area}.users"))->assertOk()->assertDontSee($target->email);
    $this->putJson(route("{$area}.users.update", $target), [
        ...billingUserPayload($target), 'can_manage_billing' => true,
    ])->assertNotFound();
    expect($target->refresh()->can_manage_billing)->toBeFalse();
})->with('billing user managers')->with([false, true]);

it('rejects creation of an external role through internal endpoints', function (string $area, bool $admin) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    $this->actingAs($actor)->postJson(route("{$area}.users.store"), [
        ...billingUserPayload(role: User::ROLE_EXTERNAL), 'can_manage_billing' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('role');
    Mail::assertNothingSent();
})->with('billing user managers');

it('keeps admin accounts outside manager edits and ignores crafted admin promotion', function () {
    $actor = User::factory()->create(['role' => 0]);
    $target = User::factory()->create(['admin' => true, 'role' => 1]);
    $this->actingAs($actor)->putJson(route('manager.users.update', $target), [
        ...billingUserPayload($target), 'can_manage_billing' => true,
    ])->assertNotFound();
    expect($target->refresh()->can_manage_billing)->toBeFalse();

    $this->post(route('manager.users.store'), [
        ...billingUserPayload(), 'admin' => true, 'can_manage_billing' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $created = User::query()->where('email', 'billing-user@example.test')->firstOrFail();
    expect($created->admin)->toBeFalse()->and($created->can_manage_billing)->toBeTrue();

    $this->put(route('manager.users.update', $created), [
        ...billingUserPayload($created), 'admin' => true, 'can_manage_billing' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($created->refresh()->admin)->toBeFalse()->and($created->can_manage_billing)->toBeFalse();
});

it('ignores crafted billing grants on client account creation and update', function () {
    $actor = User::factory()->create(['role' => 0]);
    $client = billingExternalClient();
    $payload = [
        ...billingUserPayload(), 'password' => 'Temporary-123', 'password_confirmation' => 'Temporary-123',
        'portal_enabled' => true, 'permissions' => ClientAccess::defaults('client'), 'can_manage_billing' => true,
    ];
    $this->actingAs($actor)->post(route('manager.clients.accounts.store', $client), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $account = User::query()->where('email', $payload['email'])->firstOrFail();
    expect($account->role)->toBe(User::ROLE_EXTERNAL)->and($account->can_manage_billing)->toBeFalse();

    $this->put(route('manager.clients.accounts.update', [$client, $account]), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($account->refresh()->can_manage_billing)->toBeFalse();
});

it('ignores billing privilege injection on the first password profile form', function (int $role) {
    $user = User::factory()->create(['role' => $role, 'must_change_password' => true]);
    $this->actingAs($user)->post(route('account.first-password.update'), [
        'password' => 'Updated-password-123', 'password_confirmation' => 'Updated-password-123',
        'can_manage_billing' => true, 'admin' => true, 'role' => 0,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($user->refresh()->can_manage_billing)->toBeFalse()
        ->and($user->role)->toBe($role)->and($user->admin)->toBeFalse()
        ->and($user->must_change_password)->toBeFalse();
})->with([1, 2]);

it('ignores billing privilege injection during public password reset', function () {
    $user = User::factory()->create(['role' => 1]);
    $token = Password::createToken($user);
    $this->post(route('password.store'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'Updated-password-123', 'password_confirmation' => 'Updated-password-123',
        'can_manage_billing' => true, 'admin' => true, 'role' => 0,
    ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
    expect($user->refresh()->can_manage_billing)->toBeFalse()
        ->and($user->role)->toBe(1)->and($user->admin)->toBeFalse();
});

it('ignores billing privilege injection on mobile profile endpoints', function () {
    $user = User::factory()->create(['role' => 2, 'must_change_password' => true]);
    MobileAccessToken::query()->create([
        'user_id' => $user->id, 'name' => 'Billing test', 'token_hash' => hash('sha256', 'billing-test-token'),
    ]);
    $injection = ['can_manage_billing' => true, 'admin' => true, 'role' => 1];
    $this->withToken('billing-test-token')->postJson(route('api.mobile.first-password.update'), [
        'password' => 'Updated-password-123', 'password_confirmation' => 'Updated-password-123', ...$injection,
    ])->assertOk();
    expect($user->refresh()->can_manage_billing)->toBeFalse()->and($user->role)->toBe(2)->and($user->admin)->toBeFalse();

    $this->patchJson(route('api.mobile.preferences.update'), [
        'notification_mail_enabled' => false, 'notification_push_enabled' => false, ...$injection,
    ])->assertOk();
    expect($user->refresh()->can_manage_billing)->toBeFalse()->and($user->role)->toBe(2)->and($user->admin)->toBeFalse();
});

it('renders the explicit accounting permission and per user hydration', function (string $area, bool $admin) {
    $actor = User::factory()->create(['role' => 0, 'admin' => $admin]);
    User::factory()->create(['role' => 1, 'can_manage_billing' => true]);
    User::factory()->create(['role' => 1, 'can_manage_billing' => false]);
    $this->actingAs($actor)->get(route("{$area}.users"))->assertOk()
        ->assertSee('Autoriser le suivi de facturation (comptabilité)')
        ->assertSee('Les administrateurs et les gérants disposent déjà de ce droit.')
        ->assertSee('id="create_can_manage_billing"', false)
        ->assertSee('id="edit_can_manage_billing"', false)
        ->assertSee('type="hidden" name="can_manage_billing" value="0"', false)
        ->assertSee('data-user-can-manage-billing="1"', false)
        ->assertSee('data-user-can-manage-billing="0"', false);
})->with('billing user managers');

it('authorizes billing only for eligible internal users through the dossier policy', function (array $attributes, bool $enabled, bool $expected) {
    config(['crm.v2_enabled' => $enabled]);
    $user = User::factory()->make($attributes);
    expect(Gate::forUser($user)->allows('manageBilling', new Dossier))->toBe($expected);
})->with([
    'manager implicit' => [['role' => 0, 'can_manage_billing' => false], true, true],
    'admin implicit' => [['role' => 1, 'admin' => true, 'can_manage_billing' => false], true, true],
    'admin with technical role' => [['role' => 2, 'admin' => true], true, true],
    'Planning default denied' => [['role' => 1, 'can_manage_billing' => false], true, false],
    'Planning explicit grant' => [['role' => 1, 'can_manage_billing' => true], true, true],
    'tech denied' => [['role' => 2, 'can_manage_billing' => false], true, false],
    'tech stale grant denied' => [['role' => 2, 'can_manage_billing' => true], true, false],
    'external stale grant denied' => [['role' => 3, 'can_manage_billing' => true], true, false],
    'external forged admin denied' => [['role' => 3, 'admin' => true, 'can_manage_billing' => true], true, false],
    'linked Planning denied' => [['role' => 1, 'external_client_id' => 1, 'can_manage_billing' => true], true, false],
    'linked manager denied' => [['role' => 0, 'external_client_id' => 1, 'can_manage_billing' => true], true, false],
    'linked admin denied' => [['role' => 0, 'admin' => true, 'external_client_id' => 1], true, false],
    'pending Planning password denied' => [['role' => 1, 'can_manage_billing' => true, 'must_change_password' => true], true, false],
    'pending manager password denied' => [['role' => 0, 'must_change_password' => true], true, false],
    'pending admin password denied' => [['role' => 0, 'admin' => true, 'must_change_password' => true], true, false],
    'deleted Planning denied' => [['role' => 1, 'can_manage_billing' => true, 'deleted_at' => '2026-10-18 12:00:00'], true, false],
    'deleted manager denied' => [['role' => 0, 'deleted_at' => '2026-10-18 12:00:00'], true, false],
    'deleted admin denied' => [['role' => 0, 'admin' => true, 'deleted_at' => '2026-10-18 12:00:00'], true, false],
    'v2 disabled manager' => [['role' => 0], false, false],
    'v2 disabled admin' => [['role' => 0, 'admin' => true], false, false],
    'v2 disabled Planning' => [['role' => 1, 'can_manage_billing' => true], false, false],
]);
