<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::forget(ApplicationSettings::CACHE_KEY));

it('blocks the default mailer and custom sender transports when production delivery is disabled', function () {
    config(['mail.delivery_enabled' => false, 'mail.mailers.internal-test' => ['transport' => 'array']]);
    (new AppServiceProvider(app()))->boot();
    foreach (['array', 'internal-test'] as $name) {
        Mail::mailer($name)->raw('Fictional internal test.', fn ($message) => $message->to('fixture@example.invalid')->subject('Test'));
        $transport = Mail::mailer($name)->getSymfonyTransport();
        expect($transport)->toBeInstanceOf(ArrayTransport::class)->and($transport->messages())->toHaveCount(0);
    }
});

it('preserves ordinary delivery when the explicit gate is enabled', function () {
    config(['mail.delivery_enabled' => true]);
    Mail::mailer('array')->raw('Fictional internal test.', fn ($message) => $message->to('fixture@example.invalid')->subject('Test'));
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('keeps a configured SMTP default without delivering when the gate is disabled', function () {
    $admin = User::factory()->create(['admin' => true]);
    app(ApplicationSettings::class)->update(['mail.mailer' => 'smtp'], $admin->id);
    config(['mail.delivery_enabled' => false,
        'mail.mailers.smtp' => ['transport' => 'array']]);
    Mail::purge('smtp');
    (new AppServiceProvider(app()))->boot();

    Mail::raw('Fictional internal test.', fn ($message) => $message->to('fixture@example.invalid')->subject('Test'));

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.delivery_enabled'))->toBeFalse()
        ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});
