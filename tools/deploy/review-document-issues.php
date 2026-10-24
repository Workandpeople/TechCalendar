<?php

declare(strict_types=1);

require __DIR__.'/lib/CoffracDocumentArchive.php';
require __DIR__.'/lib/CoffracDocumentIssueReview.php';

use Cofrac2\Deploy\CoffracDocumentIssueReview;

try {
    if ($argc !== 6) {
        throw new RuntimeException('Arguments invalides.');
    }
    $report = CoffracDocumentIssueReview::review($argv[1], $argv[2], $argv[3], $argv[4], $argv[5]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    echo "Diagnostic termine. Aucun contenu original lu ou execute, aucun fichier supprime ou rattache automatiquement.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : diagnostic documentaire refuse ('.get_class($error).', '.basename($error->getFile()).':'.$error->getLine()."). Aucun original modifie, aucune bascule.\n");
    exit(1);
}
