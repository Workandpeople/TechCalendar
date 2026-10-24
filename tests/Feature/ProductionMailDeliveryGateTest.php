<?php

use App\Providers\AppServiceProvider;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;

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
