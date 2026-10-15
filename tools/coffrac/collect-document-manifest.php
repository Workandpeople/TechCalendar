<?php

declare(strict_types=1);

// Source access is read-only. Only a new private export directory is written.
return static function (PDO $connection, string $documentRoot, string $exportParent): array {
    $root = realpath($documentRoot);
    $parent = realpath($exportParent);
    if (! $root || ! is_dir($root) || ! $parent || ! is_dir($parent) || ! is_writable($parent)) {
        throw new RuntimeException('Repertoire source ou destination invalide.');
    }
    if ($root === $parent || str_starts_with($parent, $root.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('La collecte doit etre stockee hors du repertoire documentaire.');
    }

    $status = $connection->query('SELECT id, name FROM status WHERE id = 11')->fetch(PDO::FETCH_ASSOC);
    if (! $status || strtoupper(trim((string) $status['name'])) !== 'DOSSIERS FACTURER') {
        throw new RuntimeException('Statut facture inattendu : verifier la correspondance avant de collecter.');
    }

    // States belong to operations, not dossiers.status_id. Keep mixed and unclassified dossiers.
    $statement = $connection->prepare(<<<'SQL'
        SELECT doc.id, doc.dossier_id, doc.path
        FROM dossier_document doc
        INNER JOIN dossiers d ON d.id = doc.dossier_id
        WHERE NOT EXISTS (SELECT 1 FROM dossier_fiches f WHERE f.dossier_id = d.id)
           OR EXISTS (
               SELECT 1 FROM dossier_fiches f WHERE f.dossier_id = d.id
                 AND (f.status_id IS NULL OR f.status_id <> ?)
           )
        ORDER BY doc.id
        SQL);
    $statement->execute([(int) $status['id']]);

    $directory = $parent.'/coffrac-documents-selection-'.bin2hex(random_bytes(6));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Creation du dossier de collecte impossible.');
    }
    $open = static function (string $path) {
        $handle = fopen($path, 'xb');
        if ($handle === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Creation du fichier prive impossible.');
        }

        return $handle;
    };
    $write = static function ($handle, string $content): void {
        if (fwrite($handle, $content) !== strlen($content)) {
            throw new RuntimeException('Export incomplet : ne pas utiliser cette collecte.');
        }
    };
    $manifest = $open($directory.'/documents.list0');
    $skipped = $open($directory.'/skipped.jsonl');
    $summary = [
        'complete' => false,
        'generated_at' => date(DATE_ATOM),
        'source_root' => $root,
        'excluded_status_id' => (int) $status['id'],
        'rule' => 'Exclude only dossiers whose operations are all in status 11; include mixed and unclassified dossiers.',
        'selected_document_records' => 0,
        'selected_dossiers' => 0,
        'unique_files' => 0,
        'total_bytes' => 0,
        'duplicate_file_references' => 0,
        'skipped' => [],
    ];
    $files = [];
    $dossiers = [];
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp',
        'doc', 'docx', 'xls', 'xlsx', 'xltx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'txt', 'csv', 'zip', '7z', 'mp4', 'mov'];

    try {
        while ($document = $statement->fetch(PDO::FETCH_ASSOC)) {
            $summary['selected_document_records']++;
            $dossiers[(int) $document['dossier_id']] = true;
            $relative = (string) $document['path'];
            $segments = explode('/', $relative);
            $reason = null;
            if ($relative === '' || preg_match('/[\x00-\x1F\x7F\\\\:]/', $relative)
                || array_filter($segments, static fn ($part) => $part === '' || str_starts_with($part, '.'))) {
                $reason = 'unsafe_path';
            } elseif (! in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), $allowedExtensions, true)) {
                $reason = 'unsupported_extension';
            } else {
                $candidate = $root;
                foreach ($segments as $segment) {
                    $candidate .= '/'.$segment;
                    if (is_link($candidate)) {
                        $reason = 'symlink';
                        break;
                    }
                }
                if ($reason === null && (! is_file($candidate) || ! is_readable($candidate))) {
                    $reason = 'missing_or_unreadable';
                }
                if ($reason === null && realpath($candidate) !== $candidate) {
                    $reason = 'unsafe_path';
                }
                $size = $reason === null ? filesize($candidate) : false;
                if ($reason === null && $size === false) {
                    $reason = 'stat_failed';
                }
            }
            if ($reason !== null) {
                $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + 1;
                $write($skipped, json_encode([
                    'document_id' => (int) $document['id'], 'dossier_id' => (int) $document['dossier_id'],
                    'path' => $relative, 'reason' => $reason,
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES)."\n");

                continue;
            }
            if (isset($files[$relative])) {
                $summary['duplicate_file_references']++;

                continue;
            }
            $files[$relative] = true;
            $summary['unique_files']++;
            $summary['total_bytes'] += $size;
            // NUL-delimited paths preserve spaces and cannot inject additional rsync entries.
            $write($manifest, $relative."\0");
        }
    } finally {
        fclose($manifest);
        fclose($skipped);
        $statement->closeCursor();
    }

    $summary['selected_dossiers'] = count($dossiers);
    $summary['complete'] = true;
    $summary['total_gib'] = round($summary['total_bytes'] / 1073741824, 2);
    $output = $open($directory.'/summary.json');
    $write($output, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    fclose($output);
    $checksums = $open($directory.'/SHA256SUMS');
    foreach (['documents.list0', 'skipped.jsonl', 'summary.json'] as $name) {
        $write($checksums, hash_file('sha256', $directory.'/'.$name).'  '.$name."\n");
    }
    fclose($checksums);

    return ['export_directory' => $directory, ...$summary];
};
