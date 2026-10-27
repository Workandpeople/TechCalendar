<?php

use App\Jobs\ProductionRuntimeProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('checks the dedicated worker without any outbound delivery', function (string $domain) {
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'https://'.$domain, 'database.connections.mysql.database' => 'cofrac2production']);
    Http::preventStrayRequests();
    $nonce = bin2hex(random_bytes(16));
    try {
        (new ProductionRuntimeProbe($nonce))->handle();
        expect(Cache::pull('cofrac2.runtime-probe.'.$nonce))->toBe('processed');
        Http::assertNothingSent();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
})->with(['cofrac2.geniuscontrole.com', 'cofrac.geniuscontrole.com']);

it('rejects a probe outside the dedicated production target', function () {
    $nonce = bin2hex(random_bytes(16));
    expect(fn () => (new ProductionRuntimeProbe($nonce))->handle())->toThrow(RuntimeException::class)
        ->and(Cache::has('cofrac2.runtime-probe.'.$nonce))->toBeFalse();
});
