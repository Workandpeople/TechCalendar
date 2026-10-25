<?php

use Cofrac2\Deploy\ProductionAssetUpdate;
use Illuminate\Filesystem\Filesystem;

require_once __DIR__.'/../../tools/deploy/lib/ProductionAssetUpdate.php';

beforeEach(function () {
    $this->bundle = sys_get_temp_dir().'/cofrac2-assets-'.bin2hex(random_bytes(8));
    mkdir($this->bundle, 0700);
    mkdir($this->bundle.'/build', 0700);
    mkdir($this->bundle.'/build/assets', 0700);
    $this->bundle = realpath($this->bundle);
    $vite = [];
    foreach (['resources/css/app.css' => 'app-test.css', 'resources/js/app.js' => 'app-test.js', 'resources/js/chart.js' => 'chart-test.js'] as $entry => $name) {
        file_put_contents($this->bundle.'/build/assets/'.$name, 'fixture');
        chmod($this->bundle.'/build/assets/'.$name, 0600);
        $vite[$entry] = ['file' => 'assets/'.$name, 'src' => $entry];
    }
    file_put_contents($this->bundle.'/build/manifest.json', json_encode($vite));
    chmod($this->bundle.'/build/manifest.json', 0600);
    $this->digest = ProductionAssetUpdate::seal($this->bundle, str_repeat('b', 40), str_repeat('a', 40));
});

afterEach(fn () => (new Filesystem)->deleteDirectory($this->bundle));

it('binds compiled assets to the exact current and previous revisions without any secrets', function () {
    $bundle = new ProductionAssetUpdate($this->bundle, $this->digest);
    expect($bundle->assetProbe)->toBe('/build/assets/app-test.css')
        ->and($bundle->manifest['from_revision'])->toBe(str_repeat('a', 40))->and($bundle->manifest['files'])->toHaveCount(4);
});

it('rejects tampered assets symlinks public inputs and unexpected secret files', function (string $case) {
    match ($case) {
        'modified' => file_put_contents($this->bundle.'/build/assets/app-test.js', 'changed'),
        'public' => chmod($this->bundle.'/build/assets/app-test.js', 0644),
        'symlink' => symlink('/etc/hosts', $this->bundle.'/extra'),
        'secret' => file_put_contents($this->bundle.'/.env', 'PRIVATE'),
    };
    expect(fn () => new ProductionAssetUpdate($this->bundle, $this->digest))->toThrow(RuntimeException::class);
})->with(['modified', 'public', 'symlink', 'secret']);

it('rejects executable or missing assets even when resealed', function (string $case) {
    if ($case === 'executable') {
        file_put_contents($this->bundle.'/build/assets/run.php', 'not an asset');
        chmod($this->bundle.'/build/assets/run.php', 0600);
    } else {
        unlink($this->bundle.'/build/assets/app-test.js');
    }
    expect(fn () => ProductionAssetUpdate::seal($this->bundle, str_repeat('b', 40), str_repeat('a', 40)))->toThrow(RuntimeException::class);
})->with(['executable', 'missing']);
