<?php

require_once __DIR__.'/../../tools/deploy/production-runtime.php';

it('recognises both approved operational Global Plus endpoints without changing the setting', function (string $url, string $environment) {
    $before = $url;
    expect(CofracProductionRuntimePolicy::globalPlusEnvironment($url))->toBe($environment)
        ->and($url)->toBe($before);
})->with([
    ['https://cee-api.test.globalplus.fr', 'test'],
    ['https://cee-api.test.globalplus.fr/', 'test'],
    ['https://cee-api.globalplus.fr', 'production'],
    ['https://cee-api.globalplus.fr/', 'production'],
]);

it('rejects every non canonical Global Plus destination without exposing it', function (mixed $url) {
    expect(CofracProductionRuntimePolicy::globalPlusEnvironment($url))->toBeNull();
})->with([
    [null], [true], [1900], [[]], [''], [' '],
    ['http://cee-api.globalplus.fr'], ['http://cee-api.test.globalplus.fr'],
    ['//cee-api.globalplus.fr'], ['https://127.0.0.1'],
    ['https://cee-api.globalplus.fr.evil.test'], ['https://cee-api.test.globalplus.fr.evil.test'],
    ['https://cee-api.globalplus.fr@evil.test'], ['https://user:secret@cee-api.globalplus.fr'],
    ['https://cee-api.globalplus.fr:443'], ['https://cee-api.globalplus.fr/api'],
    ['https://cee-api.globalplus.fr?key=secret'], ['https://cee-api.globalplus.fr#fragment'],
    [' https://cee-api.globalplus.fr'], ["https://cee-api.globalplus.fr\n"],
]);

it('uses the operational policy in the deployment check while retaining independent demo and import protections', function () {
    $root = dirname(__DIR__, 2);
    $runtime = file_get_contents($root.'/tools/deploy/production-runtime.php');
    expect($runtime)->toContain("CofracProductionRuntimePolicy::globalPlusEnvironment(config('services.global_plus.api_url'))")
        ->toContain("'global_plus_environment' => \$globalPlusEnvironment")
        ->and(file_get_contents($root.'/tools/deploy/lib/ProductionRuntimeBundle.php'))
        ->toContain("\$this->apis['GLOBAL_PLUS_API_URL'] !== 'https://cee-api.test.globalplus.fr'")
        ->and(file_get_contents($root.'/app/Console/Commands/CheckDemo.php'))
        ->toContain("=== 'https://cee-api.test.globalplus.fr'");
});
