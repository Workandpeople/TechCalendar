<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Explicit, operator-approved IDs only. Produces a dormant mapping, never changes accounts or planning. */
final class MymarApprovedTechnicianMap
{
    public static function bindings(array $approval): array
    {
        if (array_diff(array_keys($approval), ['format', 'version', 'bindings'])
            || ($approval['format'] ?? null) !== 'cofrac.mymar-approved-identities' || ($approval['version'] ?? null) !== 1
            || ! is_array($approval['bindings'] ?? null) || ! array_is_list($approval['bindings'])
            || $approval['bindings'] === [] || count($approval['bindings']) > 200) {
            throw new RuntimeException('mymar.invalid_identity_approval');
        }
        $sources = $targets = [];
        foreach ($approval['bindings'] as $binding) {
            if (! is_array($binding) || count($binding) !== 4
                || array_diff(array_keys($binding), ['source_technician_id', 'source_name', 'cofrac_user_id', 'cofrac_name'])
                || ! is_int($binding['source_technician_id'] ?? null) || $binding['source_technician_id'] < 1
                || ! is_int($binding['cofrac_user_id'] ?? null) || $binding['cofrac_user_id'] < 1
                || isset($sources[$binding['source_technician_id']]) || isset($targets[$binding['cofrac_user_id']])) {
                throw new RuntimeException('mymar.invalid_or_duplicate_approved_binding');
            }
            self::identity($binding['source_name'] ?? null);
            self::identity($binding['cofrac_name'] ?? null);
            $sources[$binding['source_technician_id']] = true;
            $targets[$binding['cofrac_user_id']] = true;
        }
        $bindings = $approval['bindings'];
        usort($bindings, static fn ($a, $b) => $a['source_technician_id'] <=> $b['source_technician_id']);

        return $bindings;
    }

    public static function sql(array $approval): string
    {
        $bindings = self::bindings($approval);
        $sources = implode(',', array_column($bindings, 'source_technician_id'));
        $targets = implode(',', array_column($bindings, 'cofrac_user_id'));

        return <<<'SQL'
SET SESSION max_execution_time=1500;
SET SESSION lock_wait_timeout=2;
SET SESSION innodb_lock_wait_timeout=2;
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT JSON_OBJECT('section','server','version',@@version,'port',@@port,'socket',@@socket,'read_only',@@transaction_read_only);
SELECT JSON_OBJECT('section','table','schema',TABLE_SCHEMA,'table',TABLE_NAME,'engine',ENGINE)
FROM information_schema.TABLES
WHERE (TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('users','roles','model_has_roles'))
   OR (TABLE_SCHEMA='cofrac2production' AND TABLE_NAME='users') LIMIT 5;
SELECT JSON_OBJECT('section','column','schema',TABLE_SCHEMA,'table',TABLE_NAME,'column',COLUMN_NAME)
FROM information_schema.COLUMNS
WHERE (TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('users','roles','model_has_roles'))
   OR (TABLE_SCHEMA='cofrac2production' AND TABLE_NAME='users') LIMIT 401;
SQL
            ."\nSELECT JSON_OBJECT('section','source','id',u.id,'name',u.name,'enabled',u.enabled,'terrain',EXISTS ("
            .'SELECT 1 FROM MYMAR.model_has_roles b JOIN MYMAR.roles role ON role.id=b.role_id WHERE b.model_id=u.id'
            ." AND role.name='Technicien Terrain' AND b.model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User'))))"
            ." FROM MYMAR.users u WHERE u.id IN ($sources) ORDER BY u.id LIMIT 201;\n"
            ."SELECT JSON_OBJECT('section','target','id',id,'name',CONCAT_WS(' ',first_name,last_name),'role',role,"
            ."'disabled',deleted_at IS NOT NULL,'external',external_client_id IS NOT NULL)"
            ." FROM cofrac2production.users WHERE id IN ($targets) ORDER BY id LIMIT 201;\n"
            ."SELECT JSON_OBJECT('section','clock','utc',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'read_only',@@transaction_read_only);\n"
            ."ROLLBACK;\nSELECT JSON_OBJECT('section','complete','complete',true);\n";
    }

    public static function validate(array $approval, array $records, DateTimeImmutable $now): array
    {
        $bindings = self::bindings($approval);
        $single = $tables = $columns = $sources = $targets = [];
        $columnCount = 0;
        if (! array_is_list($records) || count($records) > 810) {
            throw new RuntimeException('mymar.excessive_mapping_snapshot');
        }
        foreach ($records as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('mymar.invalid_mapping_snapshot');
            }
            $section = $row['section'] ?? null;
            if (in_array($section, ['server', 'clock', 'complete'], true) && ! isset($single[$section])) {
                $single[$section] = $row;
            } elseif ($section === 'table') {
                $key = ($row['schema'] ?? '').'.'.($row['table'] ?? '');
                if (isset($tables[$key])) {
                    throw new RuntimeException('mymar.duplicate_mapping_metadata');
                }
                $tables[$key] = $row['engine'] ?? null;
            } elseif ($section === 'column') {
                $key = ($row['schema'] ?? '').'.'.($row['table'] ?? '');
                $column = $row['column'] ?? null;
                if (! is_string($column) || ++$columnCount > 400 || in_array($column, $columns[$key] ?? [], true)) {
                    throw new RuntimeException('mymar.excessive_or_duplicate_mapping_columns');
                }
                $columns[$key][] = $column;
            } elseif (in_array($section, ['source', 'target'], true)) {
                $id = $row['id'] ?? null;
                if (! is_int($id) || $id < 1 || isset(($section === 'source' ? $sources : $targets)[$id])) {
                    throw new RuntimeException('mymar.duplicate_or_invalid_mapping_account');
                }
                if ($section === 'source') {
                    $sources[$id] = $row;
                } else {
                    $targets[$id] = $row;
                }
            } else {
                throw new RuntimeException('mymar.unexpected_mapping_record');
            }
        }
        $required = ['MYMAR.users' => ['id', 'name', 'enabled'], 'MYMAR.roles' => ['id', 'name'],
            'MYMAR.model_has_roles' => ['model_id', 'model_type', 'role_id'],
            'cofrac2production.users' => ['id', 'first_name', 'last_name', 'role', 'deleted_at', 'external_client_id']];
        if (count($tables) !== count($required) || count($columns) !== count($required)) {
            throw new RuntimeException('mymar.incomplete_mapping_schema');
        }
        foreach ($required as $table => $fields) {
            if (($tables[$table] ?? null) !== 'InnoDB' || array_diff($fields, $columns[$table] ?? [])) {
                throw new RuntimeException('mymar.unsafe_mapping_schema');
            }
        }
        if (($single['server']['port'] ?? null) !== 3306 || ! is_string($single['server']['version'] ?? null)
            || ! str_starts_with($single['server']['version'], '8.0.')
            || ! in_array($single['server']['socket'] ?? null, ['/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock'], true)
            || ($single['server']['read_only'] ?? null) !== 1 || ($single['clock']['read_only'] ?? null) !== 1
            || ($single['complete']['complete'] ?? null) !== true || count($sources) !== count($bindings) || count($targets) !== count($bindings)) {
            throw new RuntimeException('mymar.incomplete_readonly_mapping_snapshot');
        }
        $utc = $single['clock']['utc'] ?? null;
        $instant = is_string($utc) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $utc, new DateTimeZone('UTC')) : false;
        if ($instant === false || $instant->format('Y-m-d\TH:i:s\Z') !== $utc
            || $now->getTimestamp() - $instant->getTimestamp() > 300 || $instant->getTimestamp() - $now->getTimestamp() > 60) {
            throw new RuntimeException('mymar.stale_mapping_snapshot');
        }
        $validated = [];
        foreach ($bindings as $binding) {
            $source = $sources[$binding['source_technician_id']] ?? [];
            $target = $targets[$binding['cofrac_user_id']] ?? [];
            if (! in_array($source['enabled'] ?? null, [1, '1', true], true) || ! in_array($source['terrain'] ?? null, [1, true], true)
                || ($target['role'] ?? null) !== 2 || ! in_array($target['disabled'] ?? null, [0, false], true)
                || ! in_array($target['external'] ?? null, [0, false], true)
                || self::identity($source['name'] ?? null) !== self::identity($binding['source_name'])
                || self::identity($target['name'] ?? null) !== self::identity($binding['cofrac_name'])) {
                throw new RuntimeException('mymar.approved_identity_inactive_or_changed');
            }
            $validated[] = ['source_technician_id' => $binding['source_technician_id'], 'cofrac_user_id' => $binding['cofrac_user_id'],
                'source_identity_sha256' => hash('sha256', self::identity($source['name'])),
                'target_identity_sha256' => hash('sha256', self::identity($target['name']))];
        }

        return ['format' => 'cofrac.mymar-approved-technician-map', 'version' => 1, 'bindings' => $validated,
            'validated_at_utc' => $utc, 'requires_runtime_revalidation' => true, 'validation_basis' => 'operator_ids_and_fresh_active_profiles',
            'connector_enabled' => false, 'can_affect_planning' => false, 'account_writes' => false];
    }

    private static function identity(mixed $name): string
    {
        if (! is_string($name) || strlen($name) > 200 || str_contains($name, '@') || preg_match('/[\x00-\x1f\x7f]/', $name)
            || preg_match('//u', $name) !== 1) {
            throw new RuntimeException('mymar.invalid_approved_identity');
        }
        // Parenthesized departments may change without changing the explicitly approved numeric account ID.
        $identity = strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/\([^)]*\)/u', ' ', $name))));
        if ($identity === '' || strlen($identity) < 2) {
            throw new RuntimeException('mymar.invalid_approved_identity');
        }

        return $identity;
    }
}
