<?php

use Illuminate\Support\Facades\Http;

it('keeps recovered sms credentials out of the test runtime and does not call the provider', function () {
    Http::preventStrayRequests();

    expect(config('services.smsfactor.enabled'))->toBeFalse()
        ->and(config('services.smsfactor.token'))->toBe('')
        ->and(config('services.smsfactor.sender'))->toBe('')
        ->and(config('services.smsfactor.base_url'))->toBe('https://api.smsfactor.com')
        ->and(config('services.smsfactor.timeout'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('services.smsfactor.connect_timeout'))->toBeInt()->toBeGreaterThan(0);

    Http::assertNothingSent();
});
