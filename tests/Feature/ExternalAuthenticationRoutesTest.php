<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->organisation = ExternalDelegataire::create([
        'source' => 'native', 'external_id' => 'auth-fixture', 'kind' => 'client',
        'name' => 'Organisation de test', 'is_active' => true,
    ]);
    $this->external = User::factory()->create([
        'role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $this->organisation->id,
        'portal_enabled' => true, 'must_change_password' => false, 'password' => 'External-Test-123',
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('redirects an already authenticated external login without processing stale form credentials', function (string $kind, bool $cached) {
    $this->organisation->update(['kind' => $kind]);
    $other = User::factory()->create(['role' => 0, 'password' => 'Other-Test-123']);
    if ($cached) {
        app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
    }

    $this->actingAs($this->external)->post('/login', [
        'email' => $other->email, 'password' => 'Other-Test-123',
    ])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->external);
    $this->get(route('dashboard'))->assertRedirect(route('client.dossiers'));
})->with(['client', 'delegataire'])->with([false, true]);

it('gives the POST login a stable name with compiled routes', function () {
    app('router')->setCompiledRoutes(app('router')->getRoutes()->compile());
    $route = app('router')->getRoutes()->match(Request::create('/login', 'POST'));

    expect($route->getName())->toBe('login.store');
});

it('lets the guest middleware redirect external sessions away from password forms', function (string $method, string $path) {
    $this->actingAs($this->external)->call($method, $path)->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->external);
})->with([
    ['GET', '/forgot-password'], ['POST', '/forgot-password'],
    ['GET', '/reset-password/unused-fixture-token'], ['POST', '/reset-password'],
]);

it('still blocks disabled external sessions and internal pages', function () {
    $this->actingAs($this->external)->get('/admin/users')->assertForbidden();
    $this->external->forceFill(['portal_enabled' => false])->save();
    $this->post('/login')->assertForbidden();
    $this->assertGuest();
});

it('keeps the required password change after redirecting an already authenticated login', function () {
    $this->external->forceFill(['must_change_password' => true])->save();
    $this->actingAs($this->external)->post('/login')->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('profile'));
    $this->get(route('client.dossiers'))->assertRedirect(route('profile'));
});

it('still authenticates anonymous external logins and rejects invalid credentials', function () {
    $this->post('/login', ['email' => $this->external->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post('/login', ['email' => $this->external->email, 'password' => 'External-Test-123'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->external);
});
