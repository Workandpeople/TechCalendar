<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

final class SqlAuditDiagnostic
{
    private const COLUMNS = [
        'MYMAR.status' => ['id', 'name', 'deleted_at'],
        'MYMAR.roles' => ['id', 'name'],
        'MYMAR.model_has_roles' => ['role_id', 'model_id', 'model_type'],
        'MYMAR.users' => ['id', 'name', 'email', 'enabled', 'cofrac_id'],
        'MYMAR.dossiers' => ['id', 'status_id', 'technicien_id', 'auditeur_id', 'mar_type'],
        'MYMAR.dossier_rdv' => ['id', 'dossier_id', 'date_rdv', 'duree', 'is_old', 'updated_at'],
        'cofrac2production.users' => ['id', 'email', 'first_name', 'last_name', 'role', 'deleted_at', 'external_client_id'],
        'cofrac2production.coffrac_user_links' => ['source_user_id', 'user_id'],
    ];

    private static function read(string $path, int $limit = 262144): ?string
    {
        if (is_link($path)) {
            throw new RuntimeException('Diagnostic link refused.');
        }
        if (! file_exists($path)) {
            return null;
        }
        if (! is_file($path) || filesize($path) > $limit) {
            throw new RuntimeException('Diagnostic file refused.');
        }
        $text = file_get_contents($path, false, null, 0, $limit + 1);
        if (! is_string($text) || strlen($text) > $limit) {
            throw new RuntimeException('Diagnostic file incomplete.');
        }

        return $text;
    }

    public static function findRun(string $state, string $sourceHash): string
    {
        if (realpath($state) !== $state || is_link($state) || ! preg_match('/^[a-f0-9]{64}$/D', $sourceHash)) {
            throw new RuntimeException('Invalid diagnostic target.');
        }
        $directories = glob($state.'/mymar-readonly-*', GLOB_ONLYDIR) ?: [];
        if (count($directories) > 500) {
            throw new RuntimeException('Diagnostic directory limit.');
        }
        $matches = [];
        foreach ($directories as $directory) {
            if (is_link($directory) || realpath($directory) !== $directory) {
                continue;
            }
            $source = self::read($directory.'/source-facts.json.gz', 1048576);
            if ($source !== null && hash_equals($sourceHash, hash('sha256', $source)) && self::read($directory.'/summary.json') !== null) {
                $matches[] = $directory;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Exact completed audit not found uniquely.');
        }

        return $matches[0];
    }

    private static function rows(?string $text, ?bool &$valid): array
    {
        $valid = true;
        $rows = [];
        foreach (explode("\n", $text ?? '') as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($rows) >= 12000) {
                throw new RuntimeException('Diagnostic row limit.');
            }
            try {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $valid = false;

                continue;
            }
            if (! is_array($row) || ! is_string($row['section'] ?? null)) {
                $valid = false;

                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public static function inspect(string $run): array
    {
        if (realpath($run) !== $run || is_link($run)) {
            throw new RuntimeException('Invalid audit path.');
        }
        $files = [];
        foreach (['metadata.sql', 'metadata.jsonl', 'metadata-validation.log', 'metadata-private.stderr', 'data.sql',
            'database.partial', 'database.jsonl', 'database-private.stderr', 'explain.tsv', 'explain-private.stderr'] as $name) {
            $text = self::read($run.'/'.$name, 8388608);
            $files[$name] = ['present' => $text !== null, 'bytes' => $text === null ? null : strlen($text)];
        }
        $errors = [];
        foreach (['metadata', 'database', 'explain'] as $stage) {
            $text = self::read($run.'/'.$stage.'-private.stderr') ?? '';
            preg_match_all('/^ERROR ([0-9]{3,5}) \(([A-Z0-9]{5})\)(?: at line ([0-9]+))?/m', $text, $matches, PREG_SET_ORDER);
            $errors[$stage] = ['has_output' => trim($text) !== '', 'codes' => []];
            foreach (array_slice($matches, 0, 20) as $match) {
                $errors[$stage]['codes'][] = ['mysql_code' => (int) $match[1], 'sqlstate' => $match[2], 'statement_line' => isset($match[3]) ? (int) $match[3] : null];
            }
            $errors[$stage]['unclassified_output'] = trim($text) !== '' && $matches === [];
        }
        $tables = [];
        $columns = [];
        $server = null;
        $metadataValid = null;
        foreach (self::rows(self::read($run.'/metadata.jsonl'), $metadataValid) as $row) {
            if (($row['section'] ?? null) === 'server') {
                preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', (string) ($row['version'] ?? ''), $version);
                $server = ['version_prefix' => $version[0] ?? null, 'port' => (int) ($row['port'] ?? 0),
                    'socket_expected' => in_array($row['socket'] ?? null, ['/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock'], true)];
            }
            $key = ($row['schema'] ?? '').'.'.($row['table'] ?? '');
            if (! isset(self::COLUMNS[$key])) {
                continue;
            }
            if (($row['section'] ?? null) === 'table') {
                $tables[$key] = ['engine' => in_array($row['engine'] ?? null, ['InnoDB', 'MyISAM', 'MEMORY'], true) ? $row['engine'] : 'other',
                    'estimated_rows' => isset($row['estimated_rows']) ? (int) $row['estimated_rows'] : null];
            } elseif (($row['section'] ?? null) === 'column') {
                $columns[$key][] = $row['column'] ?? '';
            }
        }
        $schema = [];
        foreach (self::COLUMNS as $key => $required) {
            $schema[$key] = ['table_present' => isset($tables[$key]), ...($tables[$key] ?? []),
                'missing_required_columns' => array_values(array_diff($required, $columns[$key] ?? []))];
        }

        return ['format' => 'cofrac.mymar-existing-sql-diagnostic', 'version' => 1, 'files' => $files,
            'errors' => $errors, 'metadata_json_valid' => $metadataValid, 'server_metadata' => $server, 'schema_checks' => $schema,
            'original_exit_codes_recorded' => false, 'raw_errors_exported' => false, 'sql_executed' => false,
            'application_executed' => false, 'services_changed' => false, 'connector_enabled' => false];
    }
}
