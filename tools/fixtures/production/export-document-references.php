<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/deploy/lib/CoffracDocumentReconciliation.php';

use App\Services\Coffrac\DocumentReferenceExport;
use App\Services\Coffrac\ProductionDataset;
use Cofrac2\Deploy\CoffracDocumentReconciliation;

try {
    if ($argc !== 3 || gethostname() === 'ns365601') {
        throw new RuntimeException('Export local depuis un dataset prive uniquement.');
    }
    $dataset = new ProductionDataset($argv[1]);
    $report = CoffracDocumentReconciliation::exportReferences(DocumentReferenceExport::rows($dataset), [
        'dump_sha256' => $dataset->manifest['dump_sha256'], 'dataset_sha256' => $dataset->fingerprint,
        'dossier_document_records' => $dataset->manifest['tables']['dossier_document']['count'],
    ], $argv[2]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : references documentaires non validees ('.get_class($error).', '.basename($error->getFile()).':'.$error->getLine()."). Aucun secret affiche.\n");
    exit(1);
}
