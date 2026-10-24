<?php

namespace App\Services\Coffrac;

use App\Services\Crm\CollectedDossierDocuments;
use Generator;
use RuntimeException;

/** Portable metadata proof, not permission to serve files or import production. */
final class DocumentReconciliationPlan
{
    private const FILES = ['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl', 'report.json'];

    private const STATUSES = ['linked', 'missing_in_verified_inventory', 'quarantined_file', 'empty_file',
        'attachment_scope_to_review', 'missing_dossier_owner', 'unsafe_or_missing_path', 'not_servable'];

    public readonly array $report;

    public readonly string $fingerprint;

    private array $hashes = [];

    public function __construct(private readonly string $directory, private readonly ProductionDataset $dataset)
    {
        $this->privateFile('SHA256SUMS');
        $this->fingerprint = hash_file('sha256', $directory.'/SHA256SUMS');
        $lines = file($directory.'/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        if (count($lines) !== count(self::FILES)) {
            throw new RuntimeException('Plan documentaire non scelle.');
        }
        foreach (self::FILES as $offset => $name) {
            if (! preg_match('/^([a-f0-9]{64})  '.preg_quote($name, '/').'$/D', $lines[$offset], $match)) {
                throw new RuntimeException('Empreinte de plan documentaire invalide.');
            }
            $this->hashes[$name] = $match[1];
        }
        $this->assertUnchanged();
        $this->report = json_decode(file_get_contents($directory.'/report.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($this->report['format'] ?? '') !== 'cofrac2.document-reconciliation' || ($this->report['version'] ?? null) !== 1
            || ($this->report['complete'] ?? false) !== true
            || ($this->report['dump_sha256'] ?? '') !== $dataset->manifest['dump_sha256']
            || ($this->report['dataset_sha256'] ?? '') !== $dataset->fingerprint
            || ($this->report['native_database_read'] ?? null) !== false || ($this->report['database_links_verified'] ?? null) !== false
            || ($this->report['serving_enabled'] ?? null) !== false || ($this->report['final_cutover_ready'] ?? null) !== false) {
            throw new RuntimeException('Plan documentaire incompatible avec la source metier.');
        }
        foreach (['inventory_sha256', 'verification_sha256', 'references_sha256'] as $key) {
            if (! self::digest($this->report[$key] ?? null)) {
                throw new RuntimeException('Preuve du plan documentaire invalide.');
            }
        }
        foreach ($this->rows() as $row) {
            // Validate the entire stream before a caller can use even the first record.
        }
    }

    public function rows(): Generator
    {
        $this->privateFile('links.jsonl');
        $handle = fopen($this->directory.'/links.jsonl', 'rb');
        $hash = hash_init('sha256');
        $count = $linked = 0;
        $issues = $paths = [];
        $audiences = ['internal' => 0, 'client' => 0, 'shared' => 0];
        try {
            foreach (DocumentReferenceExport::rows($this->dataset) as $expected) {
                $line = fgets($handle, 65536);
                if ($line === false || ! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Plan documentaire tronque.');
                }
                hash_update($hash, $line);
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (count($row) !== count($expected) + 3 || ! array_key_exists('verified_sha256', $row)
                    || ! array_key_exists('archive_path_base64', $row) || ! in_array($row['status'] ?? '', self::STATUSES, true)) {
                    throw new RuntimeException('Entree du plan documentaire invalide.');
                }
                foreach ($expected as $key => $value) {
                    if (! array_key_exists($key, $row) || $row[$key] !== $value) {
                        throw new RuntimeException('Identite, proprietaire ou audience documentaire different de la source.');
                    }
                }
                $path = $row['path_base64'] === null ? null : base64_decode($row['path_base64'], true);
                $scoped = $row['source_table'] === 'dossier_document' && $row['owner_exists'] && self::safeRelative($path);
                $archivePath = $scoped ? base64_encode('public/documents/'.$path) : null;
                if ($row['archive_path_base64'] !== $archivePath
                    || ($row['verified_sha256'] !== null && ! self::digest($row['verified_sha256']))) {
                    throw new RuntimeException('Chemin ou empreinte documentaire incompatible.');
                }
                if ($row['status'] === 'linked') {
                    if (! $scoped || ! self::digest($row['verified_sha256'])
                        || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), CollectedDossierDocuments::EXTENSIONS, true)) {
                        throw new RuntimeException('Document non sur marque disponible.');
                    }
                    $linked++;
                    $paths[$archivePath] = true;
                } else {
                    $issues[$row['status']] = ($issues[$row['status']] ?? 0) + 1;
                }
                if ($row['source_table'] === 'dossier_document') {
                    $audience = ! $row['client_visible'] ? 'internal' : ($row['delegataire_visible'] ? 'shared' : 'client');
                    $audiences[$audience]++;
                }
                $count++;
                yield $row;
            }
            $expectedIssues = $this->report['issues'] ?? [];
            ksort($issues);
            ksort($expectedIssues);
            if (fgetc($handle) !== false || ! feof($handle) || $count !== ($this->report['records'] ?? null)
                || $linked !== ($this->report['linked_records'] ?? null) || count($paths) !== ($this->report['unique_linked_files'] ?? null)
                || $linked - count($paths) !== ($this->report['shared_file_references'] ?? null)
                || $issues !== $expectedIssues || $audiences !== ($this->report['audiences'] ?? null)
                || ! hash_equals($this->hashes['links.jsonl'], hash_final($hash))) {
                throw new RuntimeException('Couverture du plan documentaire incomplete ou modifiee.');
            }
        } finally {
            fclose($handle);
        }
        $this->assertUnchanged();
    }

    private function assertUnchanged(): void
    {
        $this->privateFile('SHA256SUMS');
        if (! hash_equals($this->fingerprint, hash_file('sha256', $this->directory.'/SHA256SUMS'))) {
            throw new RuntimeException('Scellement du plan documentaire modifie.');
        }
        foreach ($this->hashes as $name => $digest) {
            $this->privateFile($name);
            if (! hash_equals($digest, hash_file('sha256', $this->directory.'/'.$name))) {
                throw new RuntimeException('Plan documentaire modifie.');
            }
        }
    }

    private function privateFile(string $name): void
    {
        $path = $this->directory.'/'.$name;
        clearstatcache(true, $path);
        if (! is_dir($this->directory) || is_link($this->directory) || realpath($this->directory) !== $this->directory
            || (fileperms($this->directory) & 0007) !== 0
            || ! is_file($path) || is_link($path) || ! is_readable($path)
            || fileowner($path) !== fileowner($this->directory) || (fileperms($path) & 0007) !== 0) {
            throw new RuntimeException('Plan documentaire absent, public ou symbolique.');
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function safeRelative(?string $path): bool
    {
        return $path !== null && $path !== '' && preg_match('//u', $path) && ! preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)
            && ! array_filter(explode('/', $path), fn ($part) => $part === '' || str_starts_with($part, '.'));
    }
}
