<?php

namespace App\Services\Simulation;

use Generator;
use RuntimeException;

class SnapshotReader
{
    public const TABLES = ['clients', 'dossier_comment', 'dossier_document', 'dossier_fiche_facturation', 'dossier_fiche_rdv', 'dossier_fiches', 'dossier_historique', 'dossier_letige', 'dossiers', 'entreprise', 'fiche_document', 'fiche_type', 'lot_dossier', 'mail_templates', 'media', 'planings', 'rdv_extern', 'sms_tempalte', 'status', 'user_type', 'users'];

    public array $references;

    public array $manifest;

    public array $overrides;

    public string $fingerprint;

    public function __construct(public readonly string $directory)
    {
        $descriptor = $this->json($directory.'/dataset.json');
        $this->verify($directory.'/references.json.gz', $descriptor['references_sha256'] ?? '');
        $this->verify($directory.'/coffrac/manifest.json', $descriptor['coffrac_manifest_sha256'] ?? '');
        $this->verify($directory.'/matching.json', $descriptor['matching_sha256'] ?? '');
        $this->overrides = $this->json($directory.'/matching.json');
        $this->references = json_decode(gzdecode(file_get_contents($directory.'/references.json.gz'), 32 * 1024 * 1024), true, 512, JSON_THROW_ON_ERROR);
        $this->manifest = $this->json($directory.'/coffrac/manifest.json');
        if (($this->references['format'] ?? '') !== 'techcalendar.reference-snapshot'
            || ($this->references['version'] ?? null) !== 1 || ($this->references['complete'] ?? false) !== true
            || ($this->manifest['format'] ?? '') !== 'techcalendar.coffrac-snapshot'
            || ($this->manifest['version'] ?? null) !== 1 || ($this->manifest['complete'] ?? false) !== true
            || count($this->manifest['tables'] ?? []) !== count(self::TABLES)) {
            throw new RuntimeException('Format de snapshot invalide ou incomplet.');
        }
        foreach ($this->references['tables'] as $table) {
            if (count($table['rows']) !== $table['count']) {
                throw new RuntimeException('Effectif de references incoherent.');
            }
        }
        foreach (self::TABLES as $table) {
            $this->verify($directory.'/coffrac/'.$table.'.jsonl', $this->manifest['tables'][$table]['sha256'] ?? '');
        }
        $this->fingerprint = hash_file('sha256', $directory.'/dataset.json');
    }

    public function rows(string $table): Generator
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Table non autorisee.');
        }
        $handle = fopen($this->directory.'/coffrac/'.$table.'.jsonl', 'rb');
        $count = 0;
        $previousId = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! isset($row['id']) || (int) $row['id'] <= $previousId
                    || array_intersect(['password', 'remember_token', 'api_token', 'access_token'], array_keys($row))) {
                    throw new RuntimeException('Identifiant duplique/non trie ou secret dans '.$table.'.');
                }
                $previousId = (int) $row['id'];
                $count++;
                yield $row;
            }
            if ($count !== $this->manifest['tables'][$table]['count']) {
                throw new RuntimeException('Effectif incoherent pour '.$table.'.');
            }
        } finally {
            fclose($handle);
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

    private function json(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Fichier du snapshot manquant : '.basename($path));
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function verify(string $path, string $hash): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || ! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('Empreinte invalide : '.basename($path));
        }
    }
}
