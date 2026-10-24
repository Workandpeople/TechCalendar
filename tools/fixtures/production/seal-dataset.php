<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
require dirname(__DIR__).'/seal-production-audit.php';

use App\Services\Coffrac\CoffracDataset;
use App\Services\Coffrac\ProductionDataset;

$createdManifest = null;
try {
    if ($argc !== 3) {
        throw new RuntimeException('Usage : SOURCE_COLLECTION NEW_DATASET_DIRECTORY');
    }
    $source = verifiedCollection($argv[1]);
    $directory = $argv[2];
    $audit = json_decode(file_get_contents($directory.'/audit-report.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($audit['audit_complete'] ?? false) !== true || ($audit['source']['dump_sha256'] ?? '') !== $source['dump_sha256']) {
        throw new RuntimeException('Audit source absent ou incompatible.');
    }
    $tables = [];
    foreach ([...CoffracDataset::TABLES, 'credentials'] as $table) {
        $handle = fopen($directory.'/'.$table.'.jsonl', 'rb');
        $count = 0;
        while (fgets($handle) !== false) {
            $count++;
        }
        if (! feof($handle)) {
            throw new RuntimeException('Lecture incomplete.');
        }
        fclose($handle);
        $expected = $audit['counts'][$table === 'credentials' ? 'users' : $table] ?? null;
        if (! is_int($expected) || $count !== $expected) {
            throw new RuntimeException('Comptage different de la source restauree.');
        }
        $tables[$table] = ['count' => $count, 'sha256' => hash_file('sha256', $directory.'/'.$table.'.jsonl')];
    }
    $manifest = ['format' => 'techcalendar.coffrac-production', 'version' => 1, 'complete' => true,
        'final_cutover_ready' => false, 'dump_sha256' => $source['dump_sha256'], 'source_collection' => $source,
        'tables' => $tables, 'excluded_runtime_tables' => ['failed_jobs', 'migrations', 'password_reset_tokens', 'password_resets', 'personal_access_tokens'],
        'credential_policy' => 'bcrypt_only_separate_private_file_no_tokens'];
    $handle = fopen($directory.'/manifest.json', 'xb');
    if ($handle === false) {
        throw new RuntimeException('Manifeste deja present.');
    }
    $createdManifest = $directory.'/manifest.json';
    chmod($directory.'/manifest.json', 0600);
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (fwrite($handle, $json) !== strlen($json)) {
        throw new RuntimeException('Manifeste incomplet.');
    }
    fclose($handle);
    $dataset = new ProductionDataset($directory);
    foreach (CoffracDataset::TABLES as $table) {
        foreach ($dataset->rows($table) as $row) {
            // Validate every row without printing personal information.
        }
    }
    foreach ($dataset->credentials() as $row) {
    }
    echo "Dataset valide : 21 tables metier, credentials separes, aucun jeton importe.\n";
} catch (Throwable) {
    if ($createdManifest !== null) {
        unlink($createdManifest);
    }
    fwrite(STDERR, "STOP : dataset incomplet ou incompatible. Ne pas importer ; aucun secret affiche.\n");
    exit(1);
}
