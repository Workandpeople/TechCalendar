<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use Generator;
use RuntimeException;

/** No SQL, application bootstrap, file serving, permission change or source write. */
final class CoffracDocumentReconciliation
{
    private const REFERENCE_FILES = ['references.jsonl', 'summary.json'];

    public static function exportReferences(iterable $references, array $source, string $output): array
    {
        if (array_keys($source) !== ['dump_sha256', 'dataset_sha256', 'dossier_document_records']
            || ! self::digest($source['dump_sha256']) || ! self::digest($source['dataset_sha256'])
            || ! is_int($source['dossier_document_records']) || $source['dossier_document_records'] < 0) {
            throw new RuntimeException('Source de references invalide.');
        }
        self::newDirectory($output);
        $handle = self::open($output.'/references.jsonl');
        $counts = [];
        $seen = [];
        $count = 0;
        try {
            foreach ($references as $row) {
                self::reference($row, $seen);
                self::write($handle, $row);
                $key = $row['source_table'].'.'.$row['source_field'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $count++;
            }
        } finally {
            fclose($handle);
        }
        if (($counts['dossier_document.path'] ?? 0) !== $source['dossier_document_records']) {
            throw new RuntimeException('References de dossiers incompletes.');
        }
        $summary = ['format' => 'cofrac2.document-references', 'version' => 1, 'complete' => true,
            ...$source, 'records' => $count, 'counts' => $counts, 'credentials_included' => false,
            'database_links_verified' => false, 'final_cutover_ready' => false];
        self::jsonFile($output.'/summary.json', $summary);
        self::seal($output, self::REFERENCE_FILES);

        return $summary;
    }

    public static function reconcile(string $references, string $inventory, string $verification, string $archive, string $output): array
    {
        $source = self::references($references);
        $proof = CoffracDocumentArchive::verifiedIndex($inventory, $archive, $verification);
        foreach ([$references, $inventory, $verification, $archive, $proof['summary']['source']] as $input) {
            $parent = realpath(dirname($output));
            $root = realpath($input);
            if ($parent === $root || str_starts_with($parent.'/', $root.'/')) {
                throw new RuntimeException('Sortie distincte des donnees source requise.');
            }
        }
        self::newDirectory($output);
        $handles = [];
        foreach (['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl'] as $name) {
            $handles[$name] = self::open($output.'/'.$name);
        }
        $report = ['format' => 'cofrac2.document-reconciliation', 'version' => 1, 'complete' => false,
            'dump_sha256' => $source['dump_sha256'], 'dataset_sha256' => $source['dataset_sha256'],
            'references_sha256' => hash_file('sha256', $references.'/SHA256SUMS'),
            'inventory_sha256' => $proof['summary']['inventory_sha256'], 'verification_sha256' => $proof['verification_sha256'],
            'verified_at_utc' => $proof['verification']['finished_at_utc'], 'started_at_utc' => gmdate(DATE_ATOM),
            'records' => 0, 'linked_records' => 0, 'unique_linked_files' => 0, 'shared_file_references' => 0,
            'unreferenced_files_retained' => 0, 'audiences' => ['internal' => 0, 'client' => 0, 'shared' => 0],
            'issues' => [], 'copy_content_rehashed' => false, 'native_database_read' => false,
            'database_links_verified' => false, 'serving_enabled' => false, 'final_cutover_ready' => false];
        $referenced = $linked = [];
        try {
            foreach (self::rows($references.'/references.jsonl') as $row) {
                $path = $row['path_base64'] === null ? null : base64_decode($row['path_base64'], true);
                $relative = null;
                $reason = null;
                if ($row['source_table'] !== 'dossier_document') {
                    $reason = 'attachment_scope_to_review';
                } elseif (! $row['owner_exists']) {
                    $reason = 'missing_dossier_owner';
                } elseif (! self::safeRelative($path)) {
                    $reason = 'unsafe_or_missing_path';
                } else {
                    $relative = 'public/documents/'.$path;
                    $file = $proof['files'][$relative] ?? null;
                    if ($file !== null) {
                        $referenced[$relative] = true;
                    }
                    $reason = match (true) {
                        $file === null => 'missing_in_verified_inventory',
                        $file['class'] === 'quarantine' => 'quarantined_file',
                        $file['size'] === 0 => 'empty_file',
                        ! $file['servable'] => 'not_servable',
                        default => null,
                    };
                }
                $key = ! $row['client_visible'] ? 'internal' : ($row['delegataire_visible'] ? 'shared' : 'client');
                $report['records']++;
                if ($row['source_table'] === 'dossier_document') {
                    $report['audiences'][$key]++;
                }
                if ($reason !== null) {
                    $report['issues'][$reason] = ($report['issues'][$reason] ?? 0) + 1;
                    self::write($handles['issues.jsonl'], ['source_table' => $row['source_table'],
                        'source_id' => $row['source_id'], 'source_field' => $row['source_field'], 'reason' => $reason]);
                } else {
                    $report['linked_records']++;
                    $linked[$relative] = true;
                }
                // Keep one entry per source record: two dossiers may share bytes, never their ACLs.
                self::write($handles['links.jsonl'], [...$row, 'status' => $reason ?? 'linked',
                    'archive_path_base64' => $relative === null ? null : base64_encode($relative),
                    'verified_sha256' => $relative !== null ? ($proof['files'][$relative]['sha256'] ?? null) : null]);
            }
            foreach ($proof['files'] as $path => $file) {
                if (! isset($referenced[$path])) {
                    $report['unreferenced_files_retained']++;
                    self::write($handles['unreferenced.jsonl'], ['path_base64' => base64_encode($path),
                        'class' => $file['class'], 'size' => $file['size'], 'reason' => 'no_proven_dossier_reference']);
                }
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
        if (self::references($references) !== $source || $report['records'] !== $source['records']
            || hash_file('sha256', $references.'/SHA256SUMS') !== $report['references_sha256']
            || hash_file('sha256', $verification.'/SHA256SUMS') !== $report['verification_sha256']
            || hash_file('sha256', $inventory.'/SHA256SUMS') !== $report['inventory_sha256']) {
            throw new RuntimeException('References modifiees pendant le rapprochement.');
        }
        $report['unique_linked_files'] = count($linked);
        $report['shared_file_references'] = $report['linked_records'] - count($linked);
        $report['complete'] = true;
        $report['all_references_resolved'] = $report['issues'] === [];
        $report['finished_at_utc'] = gmdate(DATE_ATOM);
        self::jsonFile($output.'/report.json', $report);
        self::seal($output, ['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl', 'report.json']);

        return $report;
    }

    private static function references(string $directory): array
    {
        self::privatePath($directory, true);
        self::privatePath($directory.'/SHA256SUMS');
        $lines = file($directory.'/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        if (count($lines) !== count(self::REFERENCE_FILES)) {
            throw new RuntimeException('References non scellees.');
        }
        foreach (self::REFERENCE_FILES as $offset => $name) {
            self::privatePath($directory.'/'.$name);
            if (! preg_match('/^([a-f0-9]{64})  '.preg_quote($name, '/').'$/D', $lines[$offset], $match)
                || ! hash_equals($match[1], hash_file('sha256', $directory.'/'.$name))) {
                throw new RuntimeException('References modifiees.');
            }
        }
        $summary = json_decode(file_get_contents($directory.'/summary.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($summary['format'] ?? '') !== 'cofrac2.document-references' || ($summary['version'] ?? null) !== 1
            || ($summary['complete'] ?? false) !== true || ! self::digest($summary['dump_sha256'] ?? null)
            || ! self::digest($summary['dataset_sha256'] ?? null) || ($summary['credentials_included'] ?? null) !== false
            || ($summary['database_links_verified'] ?? null) !== false || ($summary['final_cutover_ready'] ?? null) !== false) {
            throw new RuntimeException('Format de references invalide.');
        }
        $seen = $counts = [];
        $count = 0;
        foreach (self::rows($directory.'/references.jsonl') as $row) {
            self::reference($row, $seen);
            $key = $row['source_table'].'.'.$row['source_field'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $count++;
        }
        if ($count !== ($summary['records'] ?? null) || $counts !== ($summary['counts'] ?? null)
            || ($counts['dossier_document.path'] ?? 0) !== ($summary['dossier_document_records'] ?? null)) {
            throw new RuntimeException('Comptages de references incoherents.');
        }

        return $summary;
    }

    private static function reference(array $row, array &$seen): void
    {
        $keys = ['source_table', 'source_id', 'source_field', 'dossier_id', 'owner_exists', 'path_base64',
            'client_visible', 'delegataire_visible', 'payload_sha256'];
        $field = $row['source_table'] ?? '';
        $allowed = ['dossier_document' => ['path'], 'fiche_document' => ['path'], 'media' => ['path'], 'dossier_fiches' => ['rapport_pdf', 'signature']];
        if (count($row) !== count($keys) || array_diff($keys, array_keys($row))
            || ! in_array($row['source_field'] ?? '', $allowed[$field] ?? [], true)
            || ! is_int($row['source_id']) || $row['source_id'] <= 0
            || ($row['dossier_id'] !== null && (! is_int($row['dossier_id']) || $row['dossier_id'] <= 0))
            || ! is_bool($row['owner_exists']) || ($row['owner_exists'] && $row['dossier_id'] === null)
            || ! is_bool($row['client_visible']) || ! is_bool($row['delegataire_visible'])
            || ($row['delegataire_visible'] && ! $row['client_visible']) || ! self::digest($row['payload_sha256'])
            || ($row['path_base64'] !== null && (! is_string($row['path_base64']) || base64_decode($row['path_base64'], true) === false))) {
            throw new RuntimeException('Reference invalide ou secret inattendu.');
        }
        $key = $row['source_table'].':'.$row['source_id'].':'.$row['source_field'];
        if (isset($seen[$key])) {
            throw new RuntimeException('Identifiant source documentaire duplique.');
        }
        $seen[$key] = true;
    }

    private static function safeRelative(?string $path): bool
    {
        return $path !== null && $path !== '' && preg_match('//u', $path) && ! preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)
            && ! array_filter(explode('/', $path), fn ($part) => $part === '' || str_starts_with($part, '.'));
    }

    private static function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        try {
            while (($line = fgets($handle, 65536)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Reference tronquee ou trop volumineuse.');
                }
                yield json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            }
            if (! feof($handle)) {
                throw new RuntimeException('Lecture de references incomplete.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function newDirectory(string $path): void
    {
        if (realpath(dirname($path)) !== dirname($path) || file_exists($path) || is_link($path) || ! mkdir($path, 0700)) {
            throw new RuntimeException('Nouvelle sortie privee requise.');
        }
        chmod($path, 0700);
    }

    private static function privatePath(string $path, bool $directory = false): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ($directory ? ! is_dir($path) : ! is_file($path)) || ! is_readable($path)
            || ($directory && realpath($path) !== $path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0007) !== 0) {
            throw new RuntimeException('Reference absente, publique ou lien symbolique.');
        }
    }

    private static function open(string $path)
    {
        $handle = fopen($path, 'xb');
        if ($handle === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Creation de fichier prive impossible.');
        }

        return $handle;
    }

    private static function write($handle, array $row): void
    {
        $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Ecriture incomplete.');
        }
    }

    private static function jsonFile(string $path, array $value): void
    {
        $handle = self::open($path);
        try {
            self::write($handle, $value);
        } finally {
            fclose($handle);
        }
    }

    private static function seal(string $directory, array $names): void
    {
        $handle = self::open($directory.'/SHA256SUMS');
        try {
            foreach ($names as $name) {
                $line = hash_file('sha256', $directory.'/'.$name).'  '.$name."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Scellement incomplet.');
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
