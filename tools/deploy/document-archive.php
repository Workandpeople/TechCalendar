<?php

declare(strict_types=1);

require __DIR__.'/lib/CoffracDocumentArchive.php';

use Cofrac2\Deploy\CoffracDocumentArchive;

try {
    $action = $argv[1] ?? '';
    $result = match (true) {
        $action === 'inventory' && $argc === 4 => CoffracDocumentArchive::inventory($argv[2], $argv[3]),
        $action === 'validate' && $argc === 3 => CoffracDocumentArchive::validate($argv[2]),
        $action === 'prepare-copy' && $argc === 4 => CoffracDocumentArchive::prepareCopy($argv[2], $argv[3], 50 * 1073741824),
        $action === 'verify-copy' && $argc === 5 => CoffracDocumentArchive::verifyCopy($argv[2], $argv[3], $argv[4]),
        default => throw new RuntimeException('Arguments invalides.'),
    };
    // Do not print source file names, quarantined content or exception paths to the terminal.
    echo json_encode(array_diff_key($result, array_flip(['source', 'archive', 'roots'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if ($action === 'verify-copy' && $result['copy_verified'] !== true) {
        fwrite(STDERR, "STOP : copie non validee. Voir le rapport prive ; aucun fichier source modifie.\n");
        exit(2);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : operation documentaire refusee ('.get_class($error).', '.basename($error->getFile()).':'.$error->getLine()."). Aucun fichier source modifie, aucune bascule.\n");
    exit(1);
}
