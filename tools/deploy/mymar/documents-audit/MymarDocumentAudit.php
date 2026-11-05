<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

final class MymarDocumentAudit
{
    private const FILES = [
        'app/Http/Controllers/DossierController.php', 'app/Http/Controllers/ApiController.php',
        'app/Models/Dossier.php', 'app/Models/DossierDocument.php', 'app/Models/DossierHistorique.php',
        'app/Models/Status.php', 'config/filesystems.php', 'routes/web.php', 'routes/api.php',
    ];

    private const METHODS = [
        '__construct', 'show', 'update', 'store', 'uploadDocument', 'addDocument', 'deleteDoc',
        'changeStatus', 'changeStatusPost', 'updateDocument', 'validateDocument', 'changeDocument',
    ];

    private const STRINGS = [
        'documents', 'document', 'dossier_documents', 'file', 'files', 'path', 'nom', 'nomDoc', 'second_name',
        'description', 'is_public', 'is_validated', 'request_data', 'facture', 'devis', 'vt2', 'VT2', 'VT 2',
        'Facture', 'Devis', 'Dépôt', 'Depot', 'DEPOT', 'depot', 'fileToUpload', 'mimes', 'mimetypes',
        'pdf', 'required', 'nullable', 'file', 'max', 'local', 'public', 'private', 'driver', 'root', 'url',
        'visibility', 'throw', 'default', 'disks', 'links', 'app', 'app/public', 'public/storage', 'storage',
        'dossier', 'status', 'champs', 'roles', 'validation', 'validation_text', 'partenair_ids_notif',
    ];

    public const TABLES = ['dossiers', 'dossier_documents', 'dossier_historiques', 'status'];

    public static function sources(string $root): array
    {
        if (realpath($root) !== $root || is_link($root)) {
            throw new RuntimeException('Indirect root refused.');
        }
        $files = $proof = [];
        $bytes = 0;
        foreach (self::FILES as $path) {
            $source = MymarReadOnlyAudit::read($root, $path);
            if ($source === null || ($bytes += strlen($source)) > 4194304) {
                throw new RuntimeException('Required bounded source missing.');
            }
            $proof[$path] = hash('sha256', $source);
            $file = ['sha256' => $proof[$path], 'bytes' => strlen($source),
                'business_facts' => MymarReadOnlyAudit::facts($source),
                ...MymarWorkflowAudit::methods($source, str_starts_with($path, 'app/Models/'), self::METHODS, self::STRINGS)];
            if (! str_contains($path, '/Controllers/')) {
                $file['structure_redacted'] = MymarWorkflowAudit::redact($source, self::STRINGS);
            }
            $files[$path] = $file;
        }

        return ['files' => $files, 'proof' => $proof, 'source_bytes_read' => $bytes];
    }

    public static function metadataSql(): string
    {
        return self::readOnlyPrefix().<<<'SQL'
SELECT JSON_OBJECT('section','table','table',TABLE_NAME,'engine',ENGINE)
 FROM information_schema.TABLES WHERE TABLE_SCHEMA='MYMAR' AND TABLE_TYPE='BASE TABLE'
 AND TABLE_NAME IN ('dossiers','dossier_documents','dossier_historiques','status') ORDER BY TABLE_NAME LIMIT 5;
SELECT JSON_OBJECT('section','column','table',TABLE_NAME,'column',COLUMN_NAME,'type',DATA_TYPE,'nullable',IS_NULLABLE)
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR'
 AND TABLE_NAME IN ('dossiers','dossier_documents','dossier_historiques','status') ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 601;
SELECT JSON_OBJECT('section','trigger','table',EVENT_OBJECT_TABLE,'event',EVENT_MANIPULATION,'timing',ACTION_TIMING)
 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='MYMAR'
 AND EVENT_OBJECT_TABLE IN ('dossiers','dossier_documents','dossier_historiques','status') LIMIT 51;
ROLLBACK;
SQL;
    }

    public static function validateMetadata(array $rows): void
    {
        $tables = $columns = [];
        $counts = ['table' => 0, 'column' => 0, 'trigger' => 0];
        foreach ($rows as $row) {
            $section = $row['section'] ?? '';
            if (! isset($counts[$section]) || ! in_array($row['table'] ?? '', self::TABLES, true)) {
                throw new RuntimeException('Unexpected metadata.');
            }
            $counts[$section]++;
            if ($section === 'table') {
                if (($row['engine'] ?? null) !== 'InnoDB' || isset($tables[$row['table']])) {
                    throw new RuntimeException('Unexpected table engine or duplicate.');
                }
                $tables[$row['table']] = true;
            } elseif ($section === 'column') {
                $columns[$row['table']][] = $row['column'] ?? '';
            }
        }
        if (array_diff(self::TABLES, array_keys($tables)) || $counts['column'] >= 601 || $counts['trigger'] >= 51) {
            throw new RuntimeException('Incomplete metadata.');
        }
        foreach (['status' => ['id', 'name', 'deleted_at'], 'dossier_documents' => ['id', 'dossier_id', 'path', 'nom']] as $table => $required) {
            if (array_diff($required, $columns[$table] ?? [])) {
                throw new RuntimeException('Required reference fields missing.');
            }
        }
    }

    public static function statusSql(): string
    {
        return self::readOnlyPrefix().<<<'SQL'
SELECT JSON_OBJECT('section','status','id',id,'name',name,'deleted',CAST(deleted_at IS NOT NULL AS UNSIGNED))
 FROM MYMAR.status ORDER BY id LIMIT 201;
ROLLBACK;
SQL;
    }

    public static function statuses(array $rows): array
    {
        if ($rows === [] || count($rows) >= 201) {
            throw new RuntimeException('Incomplete reference catalogue.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (array_diff(array_keys($row), ['section', 'id', 'name', 'deleted']) || ($row['section'] ?? null) !== 'status'
                || ! is_int($row['id'] ?? null) || $row['id'] < 1 || isset($result[$row['id']])
                || ! is_string($row['name'] ?? null) || strlen($row['name']) > 512
                || ! in_array($row['deleted'] ?? null, [0, 1, false, true], true)) {
                throw new RuntimeException('Unexpected reference row.');
            }
            $result[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'deleted' => (bool) $row['deleted']];
        }

        return array_values($result);
    }

    private static function readOnlyPrefix(): string
    {
        return <<<'SQL'
SET SESSION max_execution_time=1500;
SET SESSION lock_wait_timeout=2;
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;

SQL;
    }
}
