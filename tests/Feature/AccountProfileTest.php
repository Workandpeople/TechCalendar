<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'profile-client',
        'kind' => 'client', 'name' => 'Organisation profil', 'is_active' => true, 'portal_categories' => ['pending', 'visited', 'report']]);
    $this->account = User::factory()->create(['first_name' => 'Camille', 'last_name' => 'MARTIN',
        'role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $this->organisation->id,
        'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client'), 'password' => 'Ancien-Secret-123']);
    $this->other = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('shows the profile entry above logout and only the authenticated account information', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $response = $this->actingAs($this->account)->get(route('profile'))->assertOk()
        ->assertSee('Mon profil')->assertSee('Changer mon mot de passe')->assertSee('Mes notifications par email')
        ->assertSee($this->account->email)->assertSee('Organisation profil')->assertDontSee($this->other->email)
        ->assertDontSee('Page profil en cours de construction')->assertDontSee('Gestion des données');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@id="header-user-menu-dropdown"]/a[@href="'.route('profile').'"][following-sibling::form]')->length)->toBe(1)
        ->and($xpath->query('//input[@id="profile-email" and @readonly and not(@name)]')->length)->toBe(1);
})->with(['client', 'delegataire']);

it('updates only personal names and phone without changing email permissions or credentials', function () {
    $before = $this->account->fresh()->getAttributes();
    $this->actingAs($this->account)->put(route('profile.update'), [
        'first_name' => '  alice  ', 'last_name' => 'durand', 'phone' => '06 01 02 03 04',
        'id' => $this->other->id, 'email' => 'changed@example.invalid', 'admin' => true, 'role' => 0,
        'external_client_id' => null, 'portal_enabled' => false, 'portal_permissions' => [],
        'must_change_password' => false, 'password' => 'Injected-Password-123',
    ])->assertRedirect(route('profile'))->assertSessionHasNoErrors();
    $after = $this->account->fresh();
    expect($after->first_name)->toBe('Alice')->and($after->last_name)->toBe('DURAND')->and($after->phone)->toBe('06 01 02 03 04');
    foreach (['email', 'password', 'admin', 'role', 'external_client_id', 'portal_enabled', 'portal_permissions', 'must_change_password'] as $column) {
        expect($after->getRawOriginal($column))->toBe($before[$column]);
    }
});

it('validates profile input and isolates its errors', function () {
    $this->actingAs($this->account)->from(route('profile'))->put(route('profile.update'), ['last_name' => '', 'phone' => ['invalid']])
        ->assertRedirect(route('profile'))->assertSessionHasErrors(['last_name', 'phone'], null, 'profile');
    $this->get(route('profile'))->assertOk()->assertSee('Mes informations');
    expect($this->account->fresh()->last_name)->toBe('MARTIN');
});

it('requires the current password before changing it', function () {
    $this->actingAs($this->account)->put(route('profile.password'), ['current_password' => 'wrong',
        'password' => 'Nouveau-Secret-456', 'password_confirmation' => 'Nouveau-Secret-456'])
        ->assertSessionHasErrors('current_password', null, 'password');
    expect(Hash::check('Ancien-Secret-123', $this->account->fresh()->password))->toBeTrue();
});

it('rejects weak or unconfirmed new passwords', function (array $passwords) {
    $this->actingAs($this->account)->put(route('profile.password'), ['current_password' => 'Ancien-Secret-123', ...$passwords])
        ->assertSessionHasErrors('password', null, 'password');
    expect(Hash::check('Ancien-Secret-123', $this->account->fresh()->password))->toBeTrue();
})->with([
    [['password' => 'short', 'password_confirmation' => 'short']],
    [['password' => 'Nouveau-Secret-456', 'password_confirmation' => 'different']],
    [['password' => 'Ancien-Secret-123', 'password_confirmation' => 'Ancien-Secret-123']],
]);

it('changes the password rotates remember tokens and removes other database sessions', function () {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'other-device-fixture', 'user_id' => $this->account->id, 'payload' => '', 'last_activity' => time()]);
    $token = $this->account->remember_token;
    $this->actingAs($this->account)->put(route('profile.password'), ['current_password' => 'Ancien-Secret-123',
        'password' => 'Nouveau-Secret-456', 'password_confirmation' => 'Nouveau-Secret-456', 'admin' => true])
        ->assertRedirect(route('profile').'#securite')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->account);
    expect(Hash::check('Nouveau-Secret-456', $this->account->fresh()->password))->toBeTrue()
        ->and($this->account->fresh()->remember_token)->not->toBe($token)
        ->and($this->account->fresh()->admin)->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'other-device-fixture')->exists())->toBeFalse();
});

it('keeps notifications opt in and offers only authorised status choices', function () {
    $this->actingAs($this->account)->get(route('profile'))->assertOk()->assertSee('Emails désactivés')
        ->assertSee('value="visited"', false)->assertDontSee('value="rt_control"', false)->assertDontSee('value="unknown"', false);
    expect($this->account->fresh()->portal_notification_preferences)->toBeNull();
});

it('saves independent notification preferences for each client or delegataire account', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $permissions = $this->account->portal_permissions;
    $this->actingAs($this->account)->put(route('profile.notifications'), ['email_enabled' => '1', 'comment_added' => '1',
        'document_added' => '0', 'statuses' => ['visited', 'report'], 'user_id' => $this->other->id, 'portal_permissions' => []])
        ->assertRedirect(route('profile').'#notifications')->assertSessionHasNoErrors();
    expect($this->account->fresh()->portal_notification_preferences)->toBe(['email_enabled' => true,
        'comment_added' => true, 'document_added' => false, 'statuses' => ['visited', 'report']])
        ->and($this->account->fresh()->portal_permissions)->toBe($permissions)
        ->and($this->other->fresh()->portal_notification_preferences)->toBeNull();
    Queue::assertNothingPushed();
})->with(['client', 'delegataire']);

it('rejects unauthorised statuses and malformed notification values', function (array $data) {
    $this->actingAs($this->account)->put(route('profile.notifications'), ['email_enabled' => 1, 'comment_added' => 0, 'document_added' => 1, ...$data])
        ->assertSessionHasErrors([], null, 'notifications');
    expect($this->account->fresh()->portal_notification_preferences)->toBeNull();
    $this->get(route('profile'))->assertOk()->assertSee('Mes notifications par email');
})->with([
    [['statuses' => ['rt_control']]], [['statuses' => ['report', 'report']]], [['statuses' => 'report']], [['email_enabled' => 'yes']],
]);

it('can unsubscribe from everything without changing access rights', function () {
    $this->account->forceFill(['portal_notification_preferences' => ['email_enabled' => true, 'comment_added' => true,
        'document_added' => true, 'statuses' => ['report']]])->save();
    $this->actingAs($this->account)->put(route('profile.notifications'), ['email_enabled' => 0, 'comment_added' => 0, 'document_added' => 0])
        ->assertSessionHasNoErrors();
    expect($this->account->fresh()->portal_notification_preferences)->toBe(['email_enabled' => false,
        'comment_added' => false, 'document_added' => false, 'statuses' => []])->and($this->account->fresh()->portal_enabled)->toBeTrue();
});

it('asks for an event when enabling emails instead of silently subscribing to nothing', function () {
    $this->actingAs($this->account)->put(route('profile.notifications'), ['email_enabled' => 1, 'comment_added' => 0, 'document_added' => 0])
        ->assertSessionHasErrors('email_enabled', null, 'notifications');
    expect($this->account->fresh()->portal_notification_preferences)->toBeNull();
});

it('does not bypass a required first password change', function (string $route, array $data) {
    $this->account->forceFill(['must_change_password' => true])->save();
    $this->actingAs($this->account)->put(route($route), $data)->assertRedirect(route('profile'));
    expect($this->account->fresh()->must_change_password)->toBeTrue()->and($this->account->fresh()->last_name)->toBe('MARTIN');
})->with([
    ['profile.update', ['last_name' => 'Injected']],
    ['profile.password', ['current_password' => 'Ancien-Secret-123', 'password' => 'Nouveau-Secret-456', 'password_confirmation' => 'Nouveau-Secret-456']],
    ['profile.notifications', ['email_enabled' => 1, 'comment_added' => 1, 'document_added' => 1]],
]);

it('blocks disabled external accounts on the profile too', function () {
    $this->account->forceFill(['portal_enabled' => false])->save();
    $this->actingAs($this->account)->get(route('profile'))->assertForbidden();
    $this->assertGuest();
});

it('retains the profile for internal users without exposing portal notification settings', function () {
    $staff = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($staff)->get(route('profile'))->assertOk()->assertSee('Changer mon mot de passe')->assertDontSee('Mes notifications par email');
    $this->put(route('profile.notifications'), ['email_enabled' => 1, 'comment_added' => 1, 'document_added' => 1])->assertForbidden();
});

it('requires login to read or update a profile', function () {
    $this->get(route('profile'))->assertRedirect(route('login'));
    $this->put(route('profile.update'), ['last_name' => 'Other'])->assertRedirect(route('login'));
});
