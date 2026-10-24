<?php

declare(strict_types=1);

require __DIR__.'/lib/CoffracDocumentArchive.php';
require __DIR__.'/lib/CoffracDocumentReconciliation.php';

use Cofrac2\Deploy\CoffracDocumentReconciliation;

try {
    if ($argc !== 6) {
        throw new RuntimeException('Arguments invalides.');
    }
    $report = CoffracDocumentReconciliation::reconcile($argv[1], $argv[2], $argv[3], $argv[4], $argv[5]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    echo "Rapprochement de preparation termine. Aucun import SQL, changement de droits ou acces HTTP active.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : rapprochement documentaire refuse ('.get_class($error).', '.basename($error->getFile()).':'.$error->getLine()."). Voir le diagnostic prive ; aucun original modifie.\n");
    exit(1);
}
