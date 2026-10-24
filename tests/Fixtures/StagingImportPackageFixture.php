<?php

use App\Services\Coffrac\NativeExternalAccessReview;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\StagingImportPackage;

/** Synthetic fixtures only; the production builder additionally validates all three source readers. */
function stagingImportPackageFixture(string $dataset, string $planning, string $documents, array $report, string $revision = ''): string
{
    $output = sys_get_temp_dir().'/cofrac2-staging-package-test-'.bin2hex(random_bytes(6));
    mkdir($output, 0700);
    $output = realpath($output);
    foreach (['dataset', 'planning', 'documents'] as $directory) {
        mkdir($output.'/'.$directory, 0700);
    }
    file_put_contents($planning.'/SHA256SUMS', hash_file('sha256', $planning.'/references.json.gz')."  references.json.gz\n");
    chmod($planning.'/SHA256SUMS', 0600);
    $files = [];
    foreach (StagingImportPackage::files() as $name) {
        if ($name === 'README.txt') {
            file_put_contents($output.'/'.$name, "Fixture uniquement. Donnees fictives.\n");
        } elseif ($name === 'rehearsal.json') {
            file_put_contents($output.'/'.$name, json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $parts = explode('/', $name, 2);
            $source = ['dataset' => $dataset, 'planning' => $planning, 'documents' => $documents][$parts[0]];
            copy($source.'/'.$parts[1], $output.'/'.$name);
        }
        chmod($output.'/'.$name, 0600);
        $files[$name] = ['bytes' => filesize($output.'/'.$name), 'sha256' => hash_file('sha256', $output.'/'.$name)];
    }
    $source = new ProductionDataset($dataset);
    file_put_contents($output.'/manifest.json', json_encode(['format' => 'cofrac2.staging-import', 'version' => 1,
        'purpose' => 'isolated_production_preparation', 'final_cutover_ready' => false, 'revision' => $revision ?: str_repeat('a', 40),
        'dataset_sha256' => $source->fingerprint, 'dump_sha256' => $source->manifest['dump_sha256'],
        'planning_sha256' => hash_file('sha256', $planning.'/references.json.gz'), 'document_plan_sha256' => hash_file('sha256', $documents.'/SHA256SUMS'),
        'access_policy_version' => NativeExternalAccessReview::POLICY_VERSION,
        'server_documents' => ['inventory' => '/var/lib/cofrac2-production/documents-inventory-fixture',
            'verification' => '/var/lib/cofrac2-production/documents-run-fixture'], 'files' => $files], JSON_THROW_ON_ERROR));
    chmod($output.'/manifest.json', 0600);

    return $output;
}

function resealStagingImportPackageFixture(string $directory, callable $mutate): string
{
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    foreach (StagingImportPackage::files() as $name) {
        $manifest['files'][$name] = ['bytes' => filesize($directory.'/'.$name), 'sha256' => hash_file('sha256', $directory.'/'.$name)];
    }
    $manifest = $mutate($manifest);
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    return hash_file('sha256', $directory.'/manifest.json');
}
