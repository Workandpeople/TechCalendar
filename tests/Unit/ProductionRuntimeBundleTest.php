<?php

use Cofrac2\Deploy\ProductionRuntimeBundle;
use Illuminate\Filesystem\Filesystem;

require_once __DIR__.'/../../tools/deploy/lib/ProductionRuntimeBundle.php';
require_once __DIR__.'/../Fixtures/ProductionRuntimeBundleFixture.php';

beforeEach(function () {
    $this->runtimeBundle = productionRuntimeBundleFixture();
    $this->runtimeDigest = hash_file('sha256', $this->runtimeBundle.'/manifest.json');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->runtimeBundle);
});

it('accepts only sealed private assets and the approved API whitelist', function () {
    $bundle = new ProductionRuntimeBundle($this->runtimeBundle, $this->runtimeDigest);
    expect(array_keys($bundle->apis))->toBe(ProductionRuntimeBundle::API_KEYS)
        ->and($bundle->apis['GLOBAL_PLUS_API_URL'])->toBe('https://cee-api.test.globalplus.fr')
        ->and($bundle->manifest['documents']['inventory'])->toBe('/var/lib/cofrac2-production/documents-inventory-fixture');
});

it('refuses changed public symbolic or extra inputs', function (string $case) {
    $path = $this->runtimeBundle.'/runtime-apis.env';
    match ($case) {
        'changed' => file_put_contents($path, "\n", FILE_APPEND),
        'public' => chmod($path, 0644),
        'symlink' => (function () use ($path) {
            unlink($path);
            symlink('/etc/hosts', $path);
        })(),
        'extra' => file_put_contents($this->runtimeBundle.'/.env', 'private'),
    };
    expect(fn () => new ProductionRuntimeBundle($this->runtimeBundle, $this->runtimeDigest))->toThrow(RuntimeException::class);
})->with(['changed', 'public', 'symlink', 'extra']);

it('refuses production CRM endpoints executable assets missing entries and unintended secrets even when resealed', function (string $case) {
    $path = $this->runtimeBundle.'/runtime-apis.env';
    if ($case === 'endpoint') {
        file_put_contents($path, str_replace('cee-api.test.globalplus.fr', 'cee-api.globalplus.fr', file_get_contents($path)));
    } elseif ($case === 'secret') {
        file_put_contents($path, "DB_PASSWORD=forbidden\n", FILE_APPEND);
    } elseif ($case === 'interpolation') {
        file_put_contents($path, str_replace('pk.fake-test-token', 'pk.${UNDEFINED_KEY}', file_get_contents($path)));
    } elseif ($case === 'executable') {
        file_put_contents($this->runtimeBundle.'/build/assets/anything.php', 'forbidden');
        chmod($this->runtimeBundle.'/build/assets/anything.php', 0600);
    } else {
        unlink($this->runtimeBundle.'/build/assets/app-test.css');
    }
    $digest = sealProductionRuntimeBundleFixture($this->runtimeBundle);
    expect(fn () => new ProductionRuntimeBundle($this->runtimeBundle, $digest))->toThrow(RuntimeException::class);
})->with(['endpoint', 'secret', 'interpolation', 'executable', 'missing entry']);
