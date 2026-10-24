<?php

use App\Jobs\ProductionRuntimeProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('checks the dedicated worker without any outbound delivery', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'https://cofrac2.geniuscontrole.com', 'database.connections.mysql.database' => 'cofrac2production']);
    Http::preventStrayRequests();
    $nonce = bin2hex(random_bytes(16));
    try {
        (new ProductionRuntimeProbe($nonce))->handle();
        expect(Cache::pull('cofrac2.runtime-probe.'.$nonce))->toBe('processed');
        Http::assertNothingSent();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('rejects a probe outside the dedicated production target', function () {
    $nonce = bin2hex(random_bytes(16));
    expect(fn () => (new ProductionRuntimeProbe($nonce))->handle())->toThrow(RuntimeException::class)
        ->and(Cache::has('cofrac2.runtime-probe.'.$nonce))->toBeFalse();
});
