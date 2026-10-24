<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use Generator;
use RuntimeException;

/** Diagnose a sealed reconciliation; never read or execute original file contents. */
final class CoffracDocumentIssueReview
{
    public static function review(string $inventory, string $verification, string $archive, string $reconciliation, string $output): array
    {
        $proof = CoffracDocumentArchive::verifiedIndex($inventory, $archive, $verification);
        $seal = self::checkSeal($reconciliation);
        $previous = json_decode(file_get_contents($reconciliation.'/report.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($previous['format'] ?? '') !== 'cofrac2.document-reconciliation' || ($previous['version'] ?? null) !== 1
            || ($previous['complete'] ?? false) !== true || ($previous['inventory_sha256'] ?? '') !== $proof['summary']['inventory_sha256']
            || ($previous['verification_sha256'] ?? '') !== $proof['verification_sha256']
            || ($previous['serving_enabled'] ?? null) !== false || ($previous['final_cutover_ready'] ?? null) !== false
            || ! is_array($previous['issues'] ?? null)) {
            throw new RuntimeException('Rapprochement non valide ou preuve differente.');
        }
        foreach (['dump_sha256', 'dataset_sha256', 'references_sha256'] as $key) {
            if (! is_string($previous[$key] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $previous[$key])) {
                throw new RuntimeException('Identite source du rapprochement invalide.');
            }
        }
        foreach ([$inventory, $verification, $archive, $reconciliation, $proof['summary']['source']] as $input) {
            $parent = realpath(dirname($output));
            $root = realpath($input);
            if ($parent === $root || str_starts_with($parent.'/', $root.'/')) {
                throw new RuntimeException('Sortie distincte des sources requise.');
            }
        }
        if (realpath(dirname($output)) !== dirname($output) || file_exists($output) || is_link($output) || ! mkdir($output, 0700)) {
            throw new RuntimeException('Nouvelle sortie privee requise.');
        }
        chmod($output, 0700);
        $exceptions = [];
        foreach (self::rows($inventory.'/exceptions.jsonl') as $row) {
            $exceptions[$row['path_base64']] = $row['reason'];
        }
        $report = ['format' => 'cofrac2.document-issue-review', 'version' => 1, 'complete' => false,
            'dump_sha256' => $previous['dump_sha256'], 'inventory_sha256' => $previous['inventory_sha256'],
            'verification_sha256' => $previous['verification_sha256'], 'reconciliation_sha256' => $seal,
            'started_at_utc' => gmdate(DATE_ATOM), 'records' => 0, 'linked_records' => 0, 'reviewed_records' => 0,
            'diagnoses' => [], 'current_source_states' => [], 'references_with_other_root_candidates' => 0,
            'unreferenced_files_retained_by_root' => [], 'original_contents_read' => false,
            'native_database_read' => false, 'copy_content_rehashed' => false, 'automatic_relinking' => false,
            'serving_enabled' => false, 'database_links_verified' => false, 'final_cutover_ready' => false];
        $issues = $seen = [];
        $details = self::open($output.'/issues-review.jsonl');
        try {
            foreach (self::rows($reconciliation.'/links.jsonl') as $row) {
                self::assertLink($row, $seen);
                $report['records']++;
                if ($row['status'] === 'linked') {
                    $report['linked_records']++;

                    continue;
                }
                if (! array_key_exists($row['status'], $previous['issues'])) {
                    throw new RuntimeException('Motif de rapprochement inconnu.');
                }
                $issues[$row['status']] = ($issues[$row['status']] ?? 0) + 1;
                $report['reviewed_records']++;
                $path = $row['path_base64'] === null ? null : base64_decode($row['path_base64'], true);
                $safe = self::safeRelative($path);
                $relative = $safe && $row['source_table'] === 'dossier_document' ? 'public/documents/'.$path : null;
                $excluded = $relative === null ? null : ($exceptions[base64_encode($relative)] ?? null);
                $state = $relative === null ? 'not_probed_unproven_scope_or_path' : self::sourceState($proof['summary']['source'], $relative);
                $diagnosis = match ($row['status']) {
                    'missing_in_verified_inventory' => match (true) {
                        $excluded === 'code_configuration_or_metadata_not_copied' => 'excluded_code_or_configuration',
                        in_array($excluded, ['symlink_not_followed', 'special_file_not_copied'], true) => 'excluded_symbolic_or_special_file',
                        $state === 'missing' => 'not_in_inventory_and_missing_on_source_now',
                        in_array($state, ['regular_nonempty', 'regular_empty'], true) => 'present_on_source_not_in_verified_inventory',
                        default => 'source_path_requires_review',
                    },
                    'quarantined_file' => 'quarantine_security_review_required',
                    'empty_file' => $state === 'regular_nonempty' ? 'previously_empty_source_now_nonempty' : 'empty_file_requires_review',
                    'attachment_scope_to_review' => 'attachment_scope_and_owner_unproven',
                    'missing_dossier_owner' => 'dossier_owner_unproven',
                    'unsafe_or_missing_path' => 'unsafe_or_missing_path',
                    default => 'unresolved_reference_requires_review',
                };
                $candidates = [];
                if ($safe && in_array($row['status'], ['missing_in_verified_inventory', 'attachment_scope_to_review'], true)) {
                    foreach (CoffracDocumentArchive::ROOTS as $root) {
                        $candidate = $root.'/'.$path;
                        if ($candidate !== $relative && isset($proof['files'][$candidate])) {
                            $candidates[] = base64_encode($candidate);
                        }
                    }
                }
                $report['diagnoses'][$diagnosis] = ($report['diagnoses'][$diagnosis] ?? 0) + 1;
                $report['current_source_states'][$state] = ($report['current_source_states'][$state] ?? 0) + 1;
                $report['references_with_other_root_candidates'] += $candidates !== [] ? 1 : 0;
                self::write($details, [...$row, 'diagnosis' => $diagnosis, 'inventory_exclusion' => $excluded,
                    'current_source_state' => $state, 'candidate_paths_base64' => $candidates, 'automatic_relinking' => false]);
            }
        } finally {
            fclose($details);
        }
        ksort($issues);
        $expectedIssues = $previous['issues'];
        ksort($expectedIssues);
        if ($report['records'] !== ($previous['records'] ?? null) || $report['linked_records'] !== ($previous['linked_records'] ?? null)
            || $issues !== $expectedIssues) {
            throw new RuntimeException('Comptages de rapprochement incoherents.');
        }
        $unreferenced = [];
        foreach (self::rows($reconciliation.'/unreferenced.jsonl') as $row) {
            $path = base64_decode($row['path_base64'] ?? '', true);
            if (! is_string($path) || ! isset($proof['files'][$path]) || isset($unreferenced[$path])) {
                throw new RuntimeException('Liste de fichiers sans reference incoherente.');
            }
            $unreferenced[$path] = true;
            foreach (CoffracDocumentArchive::ROOTS as $root) {
                if (str_starts_with($path, $root.'/')) {
                    $report['unreferenced_files_retained_by_root'][$root] = ($report['unreferenced_files_retained_by_root'][$root] ?? 0) + 1;
                    break;
                }
            }
        }
        if (count($unreferenced) !== ($previous['unreferenced_files_retained'] ?? null)
            || self::checkSeal($reconciliation) !== $seal
            || hash_file('sha256', $inventory.'/SHA256SUMS') !== $proof['summary']['inventory_sha256']
            || hash_file('sha256', $verification.'/SHA256SUMS') !== $proof['verification_sha256']) {
            throw new RuntimeException('Preuves modifiees pendant le diagnostic.');
        }
        $report['complete'] = true;
        $report['finished_at_utc'] = gmdate(DATE_ATOM);
        $handle = self::open($output.'/report.json');
        self::write($handle, $report);
        fclose($handle);
        $handle = self::open($output.'/SHA256SUMS');
        foreach (['issues-review.jsonl', 'report.json'] as $name) {
            $line = hash_file('sha256', $output.'/'.$name).'  '.$name."\n";
            if (fwrite($handle, $line) !== strlen($line)) {
                throw new RuntimeException('Scellement incomplet.');
            }
        }
        fclose($handle);

        return $report;
    }

    private static function assertLink(array $row, array &$seen): void
    {
        $keys = ['source_table', 'source_id', 'source_field', 'dossier_id', 'owner_exists', 'path_base64',
            'client_visible', 'delegataire_visible', 'payload_sha256', 'status', 'archive_path_base64', 'verified_sha256'];
        if (count($row) !== count($keys) || array_diff($keys, array_keys($row))
            || ! in_array($row['source_table'] ?? '', ['dossier_document', 'fiche_document', 'media', 'dossier_fiches'], true)
            || ! is_int($row['source_id'] ?? null) || $row['source_id'] <= 0
            || ! in_array($row['source_field'] ?? '', ['path', 'rapport_pdf', 'signature'], true)
            || ! is_string($row['status'] ?? null) || ! array_key_exists('path_base64', $row)
            || ($row['path_base64'] !== null && (! is_string($row['path_base64']) || base64_decode($row['path_base64'], true) === false))
            || ! is_bool($row['client_visible'] ?? null) || ! is_bool($row['delegataire_visible'] ?? null)
            || ($row['delegataire_visible'] && ! $row['client_visible'])) {
            throw new RuntimeException('Reference de rapprochement invalide.');
        }
        $key = $row['source_table'].':'.$row['source_id'].':'.$row['source_field'];
        if (isset($seen[$key])) {
            throw new RuntimeException('Reference de rapprochement dupliquee.');
        }
        $seen[$key] = true;
    }

    private static function safeRelative(?string $path): bool
    {
        return $path !== null && $path !== '' && preg_match('//u', $path) && ! preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)
            && ! array_filter(explode('/', $path), fn ($part) => $part === '' || str_starts_with($part, '.'));
    }

    private static function sourceState(string $root, string $relative): string
    {
        $parts = explode('/', $relative);
        foreach ($parts as $offset => $part) {
            $root .= '/'.$part;
            clearstatcache(true, $root);
            if (is_link($root)) {
                return 'symlink_not_followed';
            }
            if (! file_exists($root)) {
                return 'missing';
            }
            $stat = @lstat($root);
            if ($stat === false) {
                return 'missing';
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0120000) {
                return 'symlink_not_followed';
            }
            if ($offset < count($parts) - 1) {
                if ($type !== 0040000) {
                    return 'non_directory_parent';
                }
            } else {
                return $type === 0100000 ? ($stat['size'] === 0 ? 'regular_empty' : 'regular_nonempty') : 'not_a_regular_file';
            }
        }
        throw new RuntimeException('Chemin source vide.');
    }

    private static function checkSeal(string $directory): string
    {
        $names = ['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl', 'report.json'];
        self::privatePath($directory, true);
        self::privatePath($directory.'/SHA256SUMS');
        $lines = file($directory.'/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        if (count($lines) !== count($names)) {
            throw new RuntimeException('Rapprochement non scelle.');
        }
        foreach ($names as $offset => $name) {
            self::privatePath($directory.'/'.$name);
            if (! preg_match('/^([a-f0-9]{64})  '.preg_quote($name, '/').'$/D', $lines[$offset], $match)
                || ! hash_equals($match[1], hash_file('sha256', $directory.'/'.$name))) {
                throw new RuntimeException('Rapprochement modifie.');
            }
        }

        return hash_file('sha256', $directory.'/SHA256SUMS');
    }

    private static function privatePath(string $path, bool $directory = false): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ($directory ? ! is_dir($path) : ! is_file($path)) || ! is_readable($path)
            || ($directory && realpath($path) !== $path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0007) !== 0) {
            throw new RuntimeException('Rapprochement absent, public ou symbolique.');
        }
    }

    private static function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        try {
            while (($line = fgets($handle, 65536)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Ligne de rapprochement tronquee.');
                }
                yield json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            }
            if (! feof($handle)) {
                throw new RuntimeException('Lecture de rapprochement incomplete.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function open(string $path)
    {
        $handle = fopen($path, 'xb');
        if ($handle === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Creation du diagnostic impossible.');
        }

        return $handle;
    }

    private static function write($handle, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Diagnostic incomplet.');
        }
    }
}
