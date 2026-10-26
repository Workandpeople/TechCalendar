<?php

declare(strict_types=1);

use Cofrac2\Deploy\DocumentUploadNginx;

require __DIR__.'/lib/DocumentUploadNginx.php';

try {
    if ($argc !== 3 || ! is_file($argv[1]) || filesize($argv[1]) > 1024 * 1024) {
        throw new RuntimeException('Usage : php document-upload-nginx.php VHOST_ENTREE NOUVEAU_FICHIER');
    }
    $prepared = DocumentUploadNginx::prepare(file_get_contents($argv[1]));
    $handle = fopen($argv[2], 'x');
    if ($handle === false) {
        throw new RuntimeException('La sortie doit etre un nouveau fichier.');
    }
    try {
        if (fwrite($handle, $prepared) !== strlen($prepared)) {
            throw new RuntimeException('Ecriture incomplete.');
        }
    } finally {
        fclose($handle);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
