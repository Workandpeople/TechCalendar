<?php

namespace App\Services\Coffrac;

use RuntimeException;

/** Sealed inputs only: no executable SQL, .env, application secrets or physical documents. */
final class StagingImportPackage
{
    public readonly array $manifest;

    public readonly string $fingerprint;

    public function __construct(public readonly string $directory, string $expectedSha256)
    {
        $this->file('manifest.json');
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) || filesize($directory.'/manifest.json') > 65536
            || ! hash_equals($expectedSha256, hash_file('sha256', $directory.'/manifest.json'))) {
            throw new RuntimeException('Empreinte du paquet de preparation differente.');
        }
        $this->fingerprint = $expectedSha256;
        $this->manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $manifest = $this->manifest;
        if (($manifest['format'] ?? null) !== 'cofrac2.staging-import' || ($manifest['version'] ?? null) !== 1
            || ($manifest['purpose'] ?? null) !== 'isolated_production_preparation'
            || ($manifest['final_cutover_ready'] ?? null) !== false
            || ($manifest['access_policy_version'] ?? null) !== NativeExternalAccessReview::POLICY_VERSION
            || ! preg_match('/^[a-f0-9]{40}$/D', $manifest['revision'] ?? '')
            || ! is_array($manifest['files'] ?? null) || array_keys($manifest['files']) !== self::files()) {
            throw new RuntimeException('Contrat de paquet de preparation invalide.');
        }
        foreach (['dataset_sha256', 'planning_sha256', 'document_plan_sha256', 'dump_sha256'] as $key) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $manifest[$key] ?? '')) {
                throw new RuntimeException('Preuve de source absente du paquet.');
            }
        }
        foreach (['inventory' => 'documents-inventory-', 'verification' => 'documents-run-'] as $key => $prefix) {
            if (! preg_match('#^/var/lib/cofrac2-production/'.preg_quote($prefix, '#').'[A-Za-z0-9_-]+$#D', $manifest['server_documents'][$key] ?? '')) {
                throw new RuntimeException('Chemin de preuve serveur inattendu.');
            }
        }
        $allowed = array_fill_keys(['manifest.json', ...self::files()], true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $relative = substr($entry->getPathname(), strlen($directory) + 1);
            if ($entry->isLink() || ($entry->isDir() ? ! in_array($relative, ['dataset', 'planning', 'documents'], true)
                : (! $entry->isFile() || ! isset($allowed[$relative])))) {
                throw new RuntimeException('Entree supplementaire ou symbolique dans le paquet.');
            }
        }
        $this->assertUnchanged();
        $dataset = new ProductionDataset($directory.'/dataset');
        if ($dataset->fingerprint !== $manifest['dataset_sha256'] || $dataset->manifest['dump_sha256'] !== $manifest['dump_sha256']
            || hash_file('sha256', $directory.'/planning/references.json.gz') !== $manifest['planning_sha256']
            || hash_file('sha256', $directory.'/documents/SHA256SUMS') !== $manifest['document_plan_sha256']) {
            throw new RuntimeException('Sources du paquet incompatibles.');
        }
        $report = json_decode(file_get_contents($directory.'/rehearsal.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($report['source_dump_sha256'] ?? null) !== $manifest['dump_sha256']
            || ($report['document_verification']['dataset_sha256'] ?? null) !== $manifest['dataset_sha256']
            || ($report['document_verification']['plan_sha256'] ?? null) !== $manifest['document_plan_sha256']
            || ($report['document_verification']['native_database_links_match_plan'] ?? null) !== true
            || ($report['matching']['techcalendar_planning']['snapshot_sha256'] ?? null) !== $manifest['planning_sha256']
            || ($report['matching']['external_access']['policy_version'] ?? null) !== $manifest['access_policy_version']
            || ($report['verification']['single_seeded_super_admin'] ?? null) !== true
            || ($report['verification']['enabled_external_portals'] ?? null) !== 0
            || ($report['verification']['outbound_jobs_or_messages'] ?? null) !== 0
            || ($report['verification']['imported_auth_tokens_or_sessions'] ?? null) !== 0
            || ($report['verification']['matching_password_hashes'] ?? null) !== $dataset->manifest['tables']['users']['count']
            || ($report['native_import_ready'] ?? null) !== false || ($report['final_cutover_ready'] ?? null) !== false) {
            throw new RuntimeException('Repetition complete non prouvee pour ce paquet.');
        }
    }

    public static function files(): array
    {
        return ['dataset/manifest.json', ...array_map(fn ($table) => 'dataset/'.$table.'.jsonl', [...CoffracDataset::TABLES, 'credentials']),
            'planning/references.json.gz', 'planning/SHA256SUMS', 'documents/links.jsonl', 'documents/issues.jsonl',
            'documents/unreferenced.jsonl', 'documents/report.json', 'documents/SHA256SUMS', 'rehearsal.json', 'README.txt'];
    }

    public function assertUnchanged(): void
    {
        $this->file('manifest.json');
        if (! hash_equals($this->fingerprint, hash_file('sha256', $this->directory.'/manifest.json'))) {
            throw new RuntimeException('Manifeste de preparation modifie.');
        }
        foreach ($this->manifest['files'] as $name => $metadata) {
            $this->file($name);
            if (! is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 0 || $metadata['bytes'] > 2 * 1024 ** 3
                || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256'] ?? '')
                || filesize($this->directory.'/'.$name) !== $metadata['bytes']
                || ! hash_equals($metadata['sha256'], hash_file('sha256', $this->directory.'/'.$name))) {
                throw new RuntimeException('Contenu du paquet modifie : '.$name);
            }
        }
    }

    private function file(string $name): void
    {
        $path = $this->directory.'/'.$name;
        clearstatcache(true, $path);
        foreach (array_unique([$this->directory, dirname($path)]) as $parent) {
            if (! is_dir($parent) || is_link($parent) || realpath($parent) !== $parent || (fileperms($parent) & 0027) !== 0) {
                throw new RuntimeException('Repertoire de paquet public, inscriptible ou symbolique.');
            }
        }
        if (! is_file($path) || is_link($path) || ! is_readable($path) || (fileperms($path) & 0027) !== 0
            || fileowner($path) !== fileowner($this->directory)) {
            throw new RuntimeException('Entree de paquet absente, publique ou symbolique.');
        }
    }
}
