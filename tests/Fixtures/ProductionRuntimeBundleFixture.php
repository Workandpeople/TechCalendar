<?php

function productionRuntimeBundleFixture(array $overrides = []): string
{
    $root = sys_get_temp_dir().'/cofrac2-runtime-fixture-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $root = realpath($root);
    mkdir($root.'/build', 0700);
    mkdir($root.'/build/assets', 0700);
    $vite = [];
    foreach (['resources/css/app.css' => 'app-test.css', 'resources/js/app.js' => 'app-test.js', 'resources/js/chart.js' => 'chart-test.js'] as $source => $file) {
        file_put_contents($root.'/build/assets/'.$file, str_ends_with($file, '.css') ? 'body{color:#123}' : '/* fixture */');
        chmod($root.'/build/assets/'.$file, 0600);
        $vite[$source] = ['file' => 'assets/'.$file, 'src' => $source, 'isEntry' => true];
    }
    file_put_contents($root.'/build/manifest.json', json_encode($vite, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/runtime-apis.env', implode("\n", [
        'MAPBOX_TOKEN="pk.fake-test-token"', 'OPENAI_API_KEY="sk.fake-test-token"', 'OPENAI_MODEL="gpt-4o-mini"',
        'GLOBAL_PLUS_API_KEY="fake-test-token"', 'GLOBAL_PLUS_API_URL="https://cee-api.test.globalplus.fr"', 'GLOBAL_PLUS_BUREAU_ID="1035"',
    ])."\n");
    chmod($root.'/runtime-apis.env', 0600);
    chmod($root.'/build/manifest.json', 0600);
    $manifest = ['format' => 'cofrac2.internal-runtime', 'version' => 1, 'revision' => str_repeat('a', 40),
        'import_package_sha256' => str_repeat('b', 64), 'documents' => [
            'inventory' => '/var/lib/cofrac2-production/documents-inventory-fixture', 'verification' => '/var/lib/cofrac2-production/documents-run-fixture',
            'plan_sha256' => str_repeat('c', 64), 'inventory_sha256' => str_repeat('d', 64), 'verification_sha256' => str_repeat('e', 64),
        ], 'files' => []];
    file_put_contents($root.'/manifest.json', json_encode(array_replace($manifest, $overrides), JSON_THROW_ON_ERROR));
    chmod($root.'/manifest.json', 0600);
    sealProductionRuntimeBundleFixture($root);

    return $root;
}

function sealProductionRuntimeBundleFixture(string $root): string
{
    $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
        $name = substr($entry->getPathname(), strlen($root) + 1);
        if ($name !== 'manifest.json') {
            $files[$name] = ['bytes' => $entry->getSize(), 'sha256' => hash_file('sha256', $entry->getPathname())];
        }
    }
    ksort($files);
    $manifest['files'] = $files;
    file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    return hash_file('sha256', $root.'/manifest.json');
}
