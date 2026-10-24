<?php

namespace App\Services\Coffrac;

use Generator;
use RuntimeException;

/** A sealed business snapshot. Password hashes never enter the business archive. */
final class ProductionDataset implements CoffracDataset
{
    public readonly array $manifest;

    public readonly string $fingerprint;

    public function __construct(public readonly string $directory)
    {
        $this->assertPrivateFile($directory.'/manifest.json');
        $this->manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->fingerprint = hash_file('sha256', $directory.'/manifest.json');
        $tables = $this->manifest['tables'] ?? [];
        if (($this->manifest['format'] ?? '') !== 'techcalendar.coffrac-production'
            || ($this->manifest['version'] ?? null) !== 1 || ($this->manifest['complete'] ?? false) !== true
            || ($this->manifest['final_cutover_ready'] ?? null) !== false
            || ! preg_match('/^[a-f0-9]{64}$/D', $this->manifest['dump_sha256'] ?? '')
            || count($tables) !== count(self::TABLES) + 1
            || array_diff([...self::TABLES, 'credentials'], array_keys($tables))) {
            throw new RuntimeException('Format de collecte de production invalide.');
        }
        foreach ($tables as $table => $metadata) {
            if (! is_int($metadata['count'] ?? null) || $metadata['count'] < 0
                || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256'] ?? '')) {
                throw new RuntimeException('Comptage ou empreinte invalide.');
            }
            $path = $directory.'/'.$table.'.jsonl';
            $this->assertPrivateFile($path);
            if (! hash_equals($metadata['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Collecte modifiee : '.$table);
            }
        }
        if ($tables['credentials']['count'] !== $tables['users']['count']) {
            throw new RuntimeException('Couverture des comptes incomplete.');
        }
    }

    public function rows(string $table): Generator
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Table non autorisee.');
        }
        foreach ($this->read($table) as $row) {
            foreach (array_keys($row) as $column) {
                if (preg_match('/password|token|secret|api_key/i', $column)) {
                    throw new RuntimeException('Secret interdit dans les donnees metier : '.$table);
                }
            }
            yield $row;
        }
    }

    public function credentials(): Generator
    {
        foreach ($this->read('credentials') as $row) {
            if (count($row) !== 2 || ! isset($row['password_hash']) || ! is_string($row['password_hash'])
                || ! preg_match('/^\$2[aby]\$(0[4-9]|1[0-6])\$[.\/A-Za-z0-9]{53}$/D', $row['password_hash'])
                || password_get_info($row['password_hash'])['algoName'] !== 'bcrypt') {
                throw new RuntimeException('Hash de compte incompatible ; reinitialisation a preparer.');
            }
            yield $row;
        }
    }

    public function keyed(string $table): array
    {
        $result = [];
        foreach ($this->rows($table) as $row) {
            $result[$row['id']] = $row;
        }

        return $result;
    }

    private function read(string $table): Generator
    {
        $path = $this->directory.'/'.$table.'.jsonl';
        $this->assertPrivateFile($path);
        $handle = fopen($path, 'rb');
        $hash = hash_init('sha256');
        $count = $previousId = 0;
        try {
            while (($line = fgets($handle, 65536)) !== false) {
                // fgets allocates its length eagerly: keep ordinary rows small in memory.
                while (! str_ends_with($line, "\n") && ! feof($handle)) {
                    if (strlen($line) >= 64 * 1024 * 1024) {
                        throw new RuntimeException('Ligne source trop volumineuse : '.$table);
                    }
                    $part = fgets($handle, 65536);
                    if ($part === false) {
                        break;
                    }
                    $line .= $part;
                }
                hash_update($hash, $line);
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] <= $previousId || ! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Identifiant ou ligne invalide : '.$table);
                }
                $previousId = $row['id'];
                $count++;
                yield $row;
            }
            if (! feof($handle) || $count !== $this->manifest['tables'][$table]['count']
                || ! hash_equals($this->manifest['tables'][$table]['sha256'], hash_final($hash))) {
                throw new RuntimeException('Collecte incomplete ou modifiee pendant la lecture : '.$table);
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertPrivateFile(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($this->directory) || ! is_dir($this->directory) || (fileperms($this->directory) & 0007) !== 0
            || is_link($path) || ! is_file($path) || ! is_readable($path) || (fileperms($path) & 0007) !== 0) {
            throw new RuntimeException('Collecte absente, publique ou lien symbolique.');
        }
    }
}
