<?php

use App\Models\User;
use App\Services\PasswordResetLinkSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => true,
        'mail.default' => 'reset_failure', 'mail.mailers.reset_failure' => ['transport' => 'reset_failure']]);
    Http::preventStrayRequests();
    // Exercise the real notification/mail pipeline with a transport that never opens a socket.
    Mail::extend('reset_failure', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('535 5.7.3 username=private@example.invalid password=never-log-this', 535);
        }

        public function __toString(): string
        {
            return 'reset-failure-fixture';
        }
    });
    Log::spy();
});

afterEach(fn () => Http::assertNothingSent());

it('returns a safe form error instead of a 500 when SMTP rejects a public reset notification', function () {
    $user = User::factory()->create(['admin' => false]);
    $attributes = $user->fresh()->getAttributes();
    $this->followingRedirects()->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
        ->assertOk()->assertSeeText(__(PasswordResetLinkSender::DELIVERY_FAILED))
        ->assertDontSee('Si un compte actif correspond à cette adresse')
        ->assertDontSee('never-log-this')->assertDontSee('535 5.7.3');
    expect($user->fresh()->getAttributes())->toBe($attributes);
    Log::shouldHaveReceived('warning')->once()->with('Password reset email delivery failed.', [
        'reason' => 'authentication_failed', 'smtp_code' => 535,
    ]);
});

it('handles a reset transport failure in internal account administration without changing accounts', function (bool $admin, string $route) {
    $actor = User::factory()->create(['admin' => $admin, 'role' => 0]);
    $target = User::factory()->create(['admin' => false, 'role' => 2]);
    $attributes = $target->fresh()->getAttributes();
    $this->actingAs($actor)->from(route($admin ? 'admin.users' : 'manager.users'))
        ->post(route($route, $target))->assertRedirect()
        ->assertSessionHasErrors(['user' => __(PasswordResetLinkSender::DELIVERY_FAILED)])->assertSessionMissing('status');
    expect($target->fresh()->getAttributes())->toBe($attributes);
})->with([[true, 'admin.users.send-reset-link'], [false, 'manager.users.send-reset-link']]);

it('returns a safe temporary-unavailability response to mobile instead of a 500 or false delivery success', function () {
    $user = User::factory()->create(['admin' => false, 'role' => 2]);
    $this->postJson(route('api.mobile.password.email'), ['email' => $user->email])
        ->assertStatus(503)->assertExactJson(['message' => __(PasswordResetLinkSender::DELIVERY_FAILED)])
        ->assertDontSee('never-log-this')->assertDontSee($user->email);
});

it('preserves the broker throttle after a failed transport without deleting or bypassing reset tokens', function () {
    $user = User::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => __(PasswordResetLinkSender::DELIVERY_FAILED)]);
    $this->post(route('password.email'), ['email' => $user->email])->assertRedirect()
        ->assertSessionHasErrors(['email' => 'Un lien vient déjà d’être demandé. Réessaie dans quelques instants.']);
    expect(DB::table('password_reset_tokens')->count())->toBe(1);
    Log::shouldHaveReceived('warning')->once();
});

it('preserves non-enumerating responses for unknown and disabled public accounts without attempting delivery', function (bool $disabled) {
    $user = User::factory()->create();
    if ($disabled) {
        $user->delete();
    }
    $email = $disabled ? $user->email : 'unknown@example.invalid';
    $this->post(route('password.email'), ['email' => $email])->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Si un compte actif correspond à cette adresse, un lien de réinitialisation vient d’être envoyé.');
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Log::shouldNotHaveReceived('warning');
})->with([false, true]);

it('does not bypass internal reset authorization or reactivate disabled accounts', function () {
    $actor = User::factory()->create(['role' => 2, 'admin' => false]);
    $target = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->actingAs($actor)->post(route('admin.users.send-reset-link', $target))->assertForbidden();
    $this->post(route('manager.users.send-reset-link', $target))->assertForbidden();
    $target->delete();
    $admin = User::factory()->create(['admin' => true]);
    $this->actingAs($admin)->post(route('admin.users.send-reset-link', $target))->assertSessionHasErrors('user');
    expect($target->fresh()->trashed())->toBeTrue()->and(DB::table('password_reset_tokens')->count())->toBe(0);
    Log::shouldNotHaveReceived('warning');
});

it('classifies delivery rejections without leaking raw SMTP details', function (int $code, string $message, string $reason) {
    Password::shouldReceive('sendResetLink')->once()->with(['email' => 'fixture@example.invalid'])
        ->andThrow(new TransportException($message, $code));
    expect(app(PasswordResetLinkSender::class)->send('fixture@example.invalid'))->toBe(PasswordResetLinkSender::DELIVERY_FAILED);
    Log::shouldHaveReceived('warning')->once()->with('Password reset email delivery failed.', [
        'reason' => $reason, 'smtp_code' => $code ?: null,
    ]);
})->with([
    [535, '535 5.7.3 private user and secret', 'authentication_failed'],
    [550, '550 5.7.60 not permitted to send as private sender', 'sender_not_permitted'],
    [0, 'Connection failed with private hostname and secret', 'transport_unavailable'],
]);

it('does not hide application errors unrelated to SMTP', function () {
    Password::shouldReceive('sendResetLink')->once()->andThrow(new LogicException('Application error'));
    expect(fn () => app(PasswordResetLinkSender::class)->send('fixture@example.invalid'))->toThrow(LogicException::class);
    Log::shouldNotHaveReceived('warning');
});
