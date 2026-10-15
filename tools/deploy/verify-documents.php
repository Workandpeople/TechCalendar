<?php

// Read-only verification. Does not execute, decompress or modify collected files.
if (count($argv) !== 4) {
    fwrite(STDERR, "Usage: php verify-documents.php ROOT documents.list0 summary.json\n");
    exit(1);
}
try {
    $root = realpath($argv[1]);
    if (! $root || ! is_dir($root) || is_link($argv[1])) {
        throw new RuntimeException('Racine de documents invalide.');
    }
    $content = file_get_contents($argv[2]);
    $summary = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    if ($content === false || ! str_ends_with($content, "\0") || ($summary['complete'] ?? false) !== true) {
        throw new RuntimeException('Manifeste incomplet.');
    }
    $paths = explode("\0", substr($content, 0, -1));
    if (count($paths) !== ($summary['unique_files'] ?? null) || count(array_unique($paths)) !== count($paths)) {
        throw new RuntimeException('Nombre de chemins incoherent.');
    }
    $bytes = $missing = $empty = 0;
    foreach ($paths as $relative) {
        if (preg_match('/[\x00-\x1f\x7f\\\\:]/', $relative)) {
            throw new RuntimeException('Chemin invalide dans le manifeste.');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || str_starts_with($part, '.')) {
                throw new RuntimeException('Chemin invalide dans le manifeste.');
            }
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Lien symbolique interdit dans la collecte.');
            }
        }
        if (! is_file($path) || ! is_readable($path)) {
            $missing++;

            continue;
        }
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Taille de fichier illisible.');
        }
        $bytes += $size;
        $empty += $size === 0 ? 1 : 0;
    }
    echo json_encode(['files' => count($paths), 'bytes' => $bytes, 'missing_or_unreadable' => $missing, 'empty' => $empty], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if ($missing || $bytes !== ($summary['total_bytes'] ?? null)) {
        throw new RuntimeException('Collecte incomplete ou tailles differentes. Ne pas basculer.');
    }
    echo "Inventaire et taille totale conformes. Les fichiers vides ne seront pas servis.\n";
    echo "Ce controle ne remplace pas un controle du contenu par checksum.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception instanceof RuntimeException ? $exception->getMessage()."\n" : "Verification impossible.\n");
    exit(1);
}
