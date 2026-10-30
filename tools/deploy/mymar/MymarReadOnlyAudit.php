<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

final class MymarReadOnlyAudit
{
    private const FIELDS = ['enabled', 'is_old', 'mar_type', 'status_id', 'dossiers.status_id', 'dossier_rdv.is_old', 'duree', 'date_rdv', 'dossier_rdv.date_rdv', 'dossier_id'];

    public static function read(string $root, string $relative, int $limit = 2097152): ?string
    {
        $root = realpath($root);
        if ($root === false) {
            return null;
        }
        $path = $root.'/'.$relative;
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, $root.'/') || is_link($path) || ! is_file($path)
            || filesize($path) > $limit) {
            return null;
        }
        $data = file_get_contents($path, false, null, 0, $limit + 1);

        return is_string($data) && strlen($data) <= $limit ? $data : null;
    }

    private static function literal(string $token): string
    {
        $value = substr($token, 1, -1);

        return $token[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $value) : stripcslashes($value);
    }

    /** Optional exported-object syntax becomes an inert marker, never a class lookup or method call. */
    public static function literalArray(string $php, ?array &$diagnostic = null, bool $ignoreExportedObjects = false): array
    {
        $diagnostic = null;
        if (strlen($php) > 2097152) {
            $diagnostic = ['reason' => 'input_too_large'];
            throw new RuntimeException('Literal configuration too large.');
        }
        $tokens = [];
        $lines = [];
        $line = 1;
        foreach (token_get_all($php) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
                $lines[] = $line;
            }
            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }
        $index = 0;
        $steps = 0;
        $ignoredObjects = 0;
        $reject = static function (string $reason) use ($tokens, $lines, &$index, &$diagnostic): never {
            $kind = static fn ($t) => is_array($t) ? token_name($t[0]) : (in_array($t, ['[', ']', '(', ')', ';', ',', '-', '+', ':', '='], true) ? $t : 'punctuation');
            $diagnostic = ['reason' => $reason, 'line' => $lines[$index] ?? null,
                'token_type' => isset($tokens[$index]) ? $kind($tokens[$index]) : 'end_of_file',
                'next_token_types' => array_map($kind, array_slice($tokens, $index, 8))];
            throw new RuntimeException('Unsupported literal configuration.');
        };
        $is = static function ($type) use ($tokens, &$index): bool {
            return isset($tokens[$index]) && (is_int($type) ? is_array($tokens[$index]) && $tokens[$index][0] === $type : $tokens[$index] === $type);
        };
        $expect = static function ($type) use (&$index, $is, $reject): void {
            if (! $is($type)) {
                $reject('unexpected_token');
            }
            $index++;
        };
        $value = function (int $depth = 0) use (&$value, &$index, &$steps, &$ignoredObjects, $ignoreExportedObjects, $tokens, $is, $expect, $reject): mixed {
            if ($depth > 64 || ++$steps > 100000) {
                $reject('complexity_limit');
            }
            if ($ignoreExportedObjects && $is(T_NAME_FULLY_QUALIFIED)) {
                $index++;
                $expect(T_DOUBLE_COLON);
                if (! $is(T_STRING) || $tokens[$index][1] !== '__set_state') {
                    $reject('unsupported_static_expression');
                }
                $index++;
                $expect('(');
                if (! $is(T_ARRAY) && ! $is('[')) {
                    $reject('exported_object_state_not_literal_array');
                }
                $value($depth + 1);
                if ($is(',')) {
                    $index++;
                }
                $expect(')');
                $ignoredObjects++;

                // Deliberately discard the state and class name; no application object is created.
                return new \stdClass;
            }
            if ($is(T_ARRAY) || $is('[')) {
                $short = $is('[');
                $index++;
                if (! $short) {
                    $expect('(');
                }
                $end = $short ? ']' : ')';
                $result = [];
                while (! $is($end)) {
                    $key = $value($depth + 1);
                    if ($is(T_DOUBLE_ARROW)) {
                        $index++;
                        if (! is_int($key) && ! is_string($key)) {
                            $reject('invalid_key');
                        }
                        $result[$key] = $value($depth + 1);
                    } else {
                        $result[] = $key;
                    }
                    if ($is($end)) {
                        break;
                    }
                    $expect(',');
                }
                $expect($end);

                return $result;
            }
            if ($is(T_CONSTANT_ENCAPSED_STRING)) {
                return self::literal($tokens[$index++][1]);
            }
            $negative = $is('-');
            if ($negative) {
                $index++;
            }
            if ($is(T_LNUMBER) || $is(T_DNUMBER)) {
                $number = $tokens[$index++][1];
                if (! preg_match('/^[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $number)) {
                    $reject('unsupported_number');
                }

                return ($negative ? -1 : 1) * (strpbrk($number, '.eE') === false ? (int) $number : (float) $number);
            }
            if (! $negative && $is(T_STRING)) {
                $literal = match (strtolower($tokens[$index][1])) {
                    'null' => null, 'true' => true, 'false' => false,
                    default => $reject('expression_not_literal'),
                };
                $index++;

                return $literal;
            }
            $reject('expression_not_literal');
        };
        if ($ignoreExportedObjects && ! $is(T_RETURN)) {
            $reject('configuration_return_array_required');
        }
        if ($is(T_RETURN)) {
            $index++;
            $result = $value();
        } else {
            $expect(T_STRING);
            if (strtolower($tokens[0][1]) !== 'app') {
                $reject('unknown_wrapper');
            }
            $expect('(');
            $expect(T_CONSTANT_ENCAPSED_STRING);
            if (self::literal($tokens[$index - 1][1]) !== 'router') {
                $reject('unknown_wrapper');
            }
            $expect(')');
            $expect(T_OBJECT_OPERATOR);
            $expect(T_STRING);
            if ($tokens[$index - 1][1] !== 'setCompiledRoutes') {
                $reject('unknown_wrapper');
            }
            $expect('(');
            $result = $value();
            $expect(')');
        }
        $expect(';');
        if ($is(T_CLOSE_TAG)) {
            $index++;
        }
        if ($index !== count($tokens) || ! is_array($result)) {
            $reject('unknown_tail');
        }
        if ($ignoredObjects > 0) {
            $diagnostic = ['opaque_exported_objects' => $ignoredObjects];
        }

        return $result;
    }

    private static function containsOpaqueValue(mixed $value): bool
    {
        if (is_object($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsOpaqueValue($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Report syntax kinds only: neither values, identifiers nor excerpts leave this method. */
    public static function cacheDiagnostic(string $root): array
    {
        $source = self::read($root, 'bootstrap/cache/config.php');
        if ($source === null) {
            return ['readable_within_limits' => false, 'literal_decode' => false];
        }
        $diagnostic = null;
        try {
            self::literalArray($source, $diagnostic);

            return ['readable_within_limits' => true, 'bytes' => strlen($source), 'literal_decode' => true];
        } catch (\Throwable) {
            return ['readable_within_limits' => true, 'bytes' => strlen($source), 'literal_decode' => false,
                'syntax' => $diagnostic ?? ['reason' => 'parser_failure']];
        }
    }

    public static function identity(string $root, string $expectedDatabase): array
    {
        $env = self::read($root, '.env', 131072);
        $allowed = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_SOCKET', 'DB_URL', 'DATABASE_URL', 'APP_TIMEZONE'];
        $values = [];
        foreach (explode("\n", $env ?? '') as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Z_]+)\s*=\s*(.*?)\s*$/D', rtrim($line, "\r"), $m) || ! in_array($m[1], $allowed, true)) {
                continue;
            }
            if (array_key_exists($m[1], $values)) {
                return ['sql_eligible' => false, 'reason' => 'duplicate_database_declaration'];
            }
            $raw = $m[2];
            $values[$m[1]] = preg_match('/^([\x22\x27])(.*)\1$/D', $raw, $quoted) ? $quoted[2] : trim(explode(' #', $raw, 2)[0]);
        }
        $local = static fn ($host, $port, $socket) => in_array($host, ['127.0.0.1', 'localhost'], true)
            && in_array((string) ($port ?? ''), ['', '3306'], true)
            && in_array($socket, [null, '', '/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock'], true);
        if (! in_array($values['DB_CONNECTION'] ?? null, ['mysql', 'mariadb'], true) || ($values['DB_DATABASE'] ?? null) !== $expectedDatabase
            || ! $local($values['DB_HOST'] ?? null, $values['DB_PORT'] ?? null, $values['DB_SOCKET'] ?? null)
            || ! empty($values['DB_URL']) || ! empty($values['DATABASE_URL'])) {
            return ['sql_eligible' => false, 'reason' => 'database_declaration_not_expected_local_schema'];
        }
        $report = ['sql_eligible' => true, 'database' => $expectedDatabase, 'identity_basis' => 'env_declaration_only_not_runtime_confirmed',
            'runtime_configuration_confirmed' => false,
            'declared_timezone' => in_array($values['APP_TIMEZONE'] ?? null, ['UTC', 'Europe/Paris'], true) ? $values['APP_TIMEZONE'] : null];
        if (file_exists($root.'/bootstrap/cache/config.php') || is_link($root.'/bootstrap/cache/config.php')) {
            $diagnostic = null;
            try {
                $cache = self::literalArray(self::read($root, 'bootstrap/cache/config.php') ?? '', $diagnostic, true);
                $app = $cache['app'] ?? [];
                if (! is_array($app) || self::containsOpaqueValue($cache['database'] ?? null) || self::containsOpaqueValue($app['timezone'] ?? null)) {
                    return ['sql_eligible' => false, 'reason' => 'non_literal_database_or_timezone_configuration'];
                }
                $connection = $cache['database']['connections'][$cache['database']['default'] ?? ''] ?? [];
                if (($connection['driver'] ?? null) !== 'mysql' || ($connection['database'] ?? null) !== $expectedDatabase
                    || ! $local($connection['host'] ?? null, $connection['port'] ?? null, $connection['unix_socket'] ?? null)
                    || ! empty($connection['url']) || ! empty($connection['read']) || ! empty($connection['write'])) {
                    return ['sql_eligible' => false, 'reason' => 'cached_database_identity_disagrees'];
                }
                $ignoredObjects = $diagnostic['opaque_exported_objects'] ?? 0;
                $report['identity_basis'] = $ignoredObjects > 0 ? 'env_and_literal_database_cache_agree' : 'env_and_literal_config_cache_agree';
                $report['opaque_objects_outside_database_and_timezone_ignored'] = $ignoredObjects;
                $timezone = $cache['app']['timezone'] ?? null;
                $report['cached_timezone'] = is_string($timezone) && preg_match('~^[A-Za-z_/+-]{1,60}$~D', $timezone) ? $timezone : null;
                unset($cache, $connection);
            } catch (\Throwable) {
                return ['sql_eligible' => false, 'reason' => 'configuration_cache_not_statically_decodable',
                    'cache_syntax' => $diagnostic ?? ['reason' => 'parser_failure']];
            }
        }

        return $report;
    }

    public static function facts(string $source): array
    {
        $tokens = token_get_all($source);
        $facts = [];
        $method = null;
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            $key = $token[0] === T_CONSTANT_ENCAPSED_STRING ? self::literal($token[1]) : ($token[0] === T_STRING ? $token[1] : null);
            if (in_array($key, [...self::FIELDS, 'timezone'], true)) {
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }
                $operator = $tokens[$next] ?? null;
                if ($operator === '=' || (is_array($operator) && in_array($operator[0], [T_DOUBLE_ARROW, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL], true))) {
                    $next++;
                    while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }
                    $part = $tokens[$next] ?? null;
                    $literal = is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING ? self::literal($part[1]) : null;
                    if (is_array($part) && (($part[0] === T_LNUMBER && preg_match('/^[0-9]{1,4}$/D', $part[1])) || in_array($literal, ['UTC', 'Europe/Paris'], true))) {
                        $facts[] = ['line' => $token[2], 'method' => $method, 'field' => $key, 'operator' => is_array($operator) ? $operator[1] : $operator, 'literal' => $literal ?? (int) $part[1]];
                    }
                }
            }
            if ($token[0] === T_FUNCTION) {
                for ($j = $index + 1; isset($tokens[$j]); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $method = $tokens[$j][1];
                    }
                    break;
                }
            }
            if ($token[0] !== T_STRING) {
                continue;
            }
            $call = strtolower($token[1]);
            $j = $index + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
            $arguments = [];
            $depth = 1;
            for ($k = $j + 1; isset($tokens[$k]) && $k < $j + 2500; $k++) {
                if ($tokens[$k] === '(') {
                    $depth++;
                }
                if ($tokens[$k] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $arguments[] = $tokens[$k];
            }
            if ($depth !== 0) {
                continue;
            }
            $first = null;
            foreach ($arguments as $part) {
                if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $first = is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING ? self::literal($part[1]) : null;
                break;
            }
            $fieldCall = in_array($call, ['where', 'wherein', 'wherenotin', 'orwhere', 'wheredate', 'wherebetween', 'unique', 'orderby'], true) && in_array($first, self::FIELDS, true);
            $timeCall = in_array($call, ['addhour', 'addhours', 'addminutes', 'subminutes', 'createfromformat', 'format', 'parse', 'timezone', 'settimezone', 'startofday', 'endofday', 'copy'], true)
                || ($call === 'env' && $first === 'APP_TIMEZONE');
            $roleCall = in_array($call, ['role', 'hasrole'], true);
            if (! $fieldCall && ! $timeCall && ! $roleCall) {
                continue;
            }
            $complex = false;
            foreach ($arguments as $part) {
                if (is_array($part) && (in_array($part[0], [T_FUNCTION, T_FN], true)
                    || ($part[0] === T_VARIABLE && preg_match('/password|secret|token|credential|authorization/i', $part[1])))) {
                    $complex = true;
                }
            }
            $rendered = '';
            foreach ($arguments as $part) {
                if (! is_array($part)) {
                    $rendered .= $part;

                    continue;
                }
                [$type, $text] = $part;
                if (in_array($type, [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($type === T_CONSTANT_ENCAPSED_STRING) {
                    $literal = self::literal($text);
                    $safe = in_array($literal, [...self::FIELDS, 'APP_TIMEZONE', '=', '!=', '<>', '>', '<', '>=', '<=', 'UTC', 'Europe/Paris', 'Y-m-d', 'd/m/Y', 'Y-m-d H:i:s', 'd/m/Y H:i', 'H:i', 'H:i:s'], true)
                        || ($roleCall && $first === $literal && preg_match('/^[\pL _-]{1,60}$/uD', $literal))
                        || ($fieldCall && preg_match('/^[0-9]{1,4}$/D', $literal));
                    $rendered .= $safe ? var_export($literal, true) : "'[MASKED]'";
                } elseif (in_array($type, [T_LNUMBER, T_DNUMBER], true)) {
                    $rendered .= preg_match('/^[0-9]{1,4}$/D', $text) ? $text : '0/*masked*/';
                } elseif (in_array($type, [T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    $rendered .= '[MASKED]';
                } else {
                    $rendered .= $text;
                }
            }
            if (count($facts) >= 1500) {
                throw new RuntimeException('Source fact limit.');
            }
            $rendered = $complex ? '[complex expression omitted]' : trim(preg_replace('/\s+/', ' ', $rendered));
            $facts[] = ['line' => $token[2], 'method' => $method, 'call' => $token[1],
                'arguments_redacted' => substr($rendered, 0, 2048), 'arguments_truncated' => strlen($rendered) > 2048];
        }

        return $facts;
    }

    public static function metadataSql(): string
    {
        return self::begin().<<<'SQL'
SELECT JSON_OBJECT('section','server','port',@@port,'socket',@@socket,'version',VERSION());
SELECT JSON_OBJECT('section','table','schema',TABLE_SCHEMA,'table',TABLE_NAME,'engine',ENGINE,'estimated_rows',TABLE_ROWS)
 FROM information_schema.TABLES WHERE TABLE_TYPE='BASE TABLE' AND
 ((TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('status','roles','model_has_roles','users','dossiers','dossier_rdv'))
 OR (TABLE_SCHEMA='cofrac2production' AND TABLE_NAME IN ('users','coffrac_user_links'))) ORDER BY TABLE_SCHEMA,TABLE_NAME LIMIT 9;
SELECT JSON_OBJECT('section','column','schema',TABLE_SCHEMA,'table',TABLE_NAME,'column',COLUMN_NAME)
 FROM information_schema.COLUMNS WHERE
 ((TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('status','roles','model_has_roles','users','dossiers','dossier_rdv'))
 OR (TABLE_SCHEMA='cofrac2production' AND TABLE_NAME IN ('users','coffrac_user_links'))) LIMIT 600;
ROLLBACK;
SQL;
    }

    public static function validateMetadata(array $rows): void
    {
        $required = ['MYMAR.status' => ['id', 'name', 'deleted_at'], 'MYMAR.roles' => ['id', 'name'],
            'MYMAR.model_has_roles' => ['role_id', 'model_id', 'model_type'], 'MYMAR.users' => ['id', 'name', 'email', 'enabled', 'cofrac_id'],
            'MYMAR.dossiers' => ['id', 'status_id', 'technicien_id', 'auditeur_id', 'mar_type'],
            'MYMAR.dossier_rdv' => ['id', 'dossier_id', 'date_rdv', 'duree', 'is_old', 'updated_at'],
            'cofrac2production.users' => ['id', 'email', 'first_name', 'last_name', 'role', 'deleted_at', 'external_client_id'],
            'cofrac2production.coffrac_user_links' => ['source_user_id', 'user_id']];
        $tables = [];
        $columns = [];
        $server = array_values(array_filter($rows, static fn ($row) => ($row['section'] ?? null) === 'server'));
        if (count($server) !== 1 || (int) ($server[0]['port'] ?? 0) !== 3306
            || ! in_array($server[0]['socket'] ?? null, ['/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock'], true)
            || ! str_starts_with($server[0]['version'] ?? '', '8.0.')) {
            throw new RuntimeException('Unexpected MySQL instance.');
        }
        foreach ($rows as $row) {
            $key = ($row['schema'] ?? '').'.'.($row['table'] ?? '');
            if (($row['section'] ?? null) === 'table') {
                $tables[$key] = $row;
            }
            if (($row['section'] ?? null) === 'column') {
                $columns[$key][] = $row['column'] ?? '';
            }
        }
        foreach ($required as $key => $fields) {
            if (($tables[$key]['engine'] ?? null) !== 'InnoDB' || ($tables[$key]['estimated_rows'] ?? PHP_INT_MAX) > 100000
                || array_diff($fields, $columns[$key] ?? [])) {
                throw new RuntimeException('Unexpected schema or volume; data collection refused.');
            }
        }
    }

    private static function begin(): string
    {
        return "SET SESSION max_execution_time=1500;\nSET SESSION lock_wait_timeout=2;\nSET SESSION innodb_lock_wait_timeout=2;\nSET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\nSET SESSION TRANSACTION READ ONLY;\nSTART TRANSACTION READ ONLY;\n";
    }

    public static function dataSql(): string
    {
        // Fixed schemas/columns only. Emails are compared in SQL, never selected into the report.
        $references = <<<'SQL'
SELECT JSON_OBJECT('section','clock','session_timezone',@@session.time_zone,'global_timezone',@@global.time_zone,'system_timezone',@@system_time_zone,'now',NOW(),'utc_now',UTC_TIMESTAMP(),'transaction_read_only',@@session.transaction_read_only);
SELECT JSON_OBJECT('section','counts','statuses',(SELECT COUNT(*) FROM MYMAR.status),'roles',(SELECT COUNT(*) FROM MYMAR.roles),'role_bindings',(SELECT COUNT(*) FROM MYMAR.model_has_roles),'source_users',(SELECT COUNT(*) FROM MYMAR.users),'target_users',(SELECT COUNT(*) FROM cofrac2production.users),'rdvs',(SELECT COUNT(*) FROM MYMAR.dossier_rdv));
SELECT JSON_OBJECT('section','status','id',id,'name',name,'deleted_at',deleted_at) FROM MYMAR.status ORDER BY id LIMIT 201;
SELECT JSON_OBJECT('section','role','id',id,'name',name) FROM MYMAR.roles ORDER BY id LIMIT 101;
SELECT JSON_OBJECT('section','role_binding','model_id',model_id,'model_type',model_type,'role_id',role_id) FROM MYMAR.model_has_roles ORDER BY model_id,role_id LIMIT 2501;
SELECT JSON_OBJECT('section','rdv_group','status_id',d.status_id,'mar_type',d.mar_type,'is_old',r.is_old,'rdvs',COUNT(*),'dossiers',COUNT(DISTINCT r.dossier_id),'missing_dossiers',SUM(d.id IS NULL),'missing_technicians',SUM(d.technicien_id IS NULL),'duration_absent_or_nonpositive',SUM(r.duree IS NULL OR r.duree<=0),'duration_min',MIN(r.duree),'duration_max',MAX(r.duree),'first_rdv',MIN(r.date_rdv),'last_rdv',MAX(r.date_rdv),'last_update',MAX(r.updated_at))
 FROM MYMAR.dossier_rdv r LEFT JOIN MYMAR.dossiers d ON d.id=r.dossier_id GROUP BY d.status_id,d.mar_type,r.is_old ORDER BY d.status_id,d.mar_type,r.is_old LIMIT 1001;
SQL;

        return self::begin().$references."\n".self::technicianSql()."\nROLLBACK;\nSELECT JSON_OBJECT('section','complete','read_only_collection',true);\n";
    }

    public static function technicianSql(): string
    {
        // UNION materializes the candidate IDs once; no per-user dependent subquery scans the dossiers.
        return <<<'SQL'
WITH candidate_ids AS (
 SELECT technicien_id mymar_id FROM MYMAR.dossiers WHERE technicien_id IS NOT NULL
 UNION SELECT auditeur_id FROM MYMAR.dossiers WHERE auditeur_id IS NOT NULL
 UNION SELECT b.model_id FROM MYMAR.model_has_roles b JOIN MYMAR.roles role ON role.id=b.role_id
 WHERE b.model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User'))
 AND (LOWER(role.name) LIKE '%techn%' OR LOWER(role.name) LIKE '%audit%')
), historical_counts AS (
 SELECT cofrac_id,COUNT(*) duplicates FROM MYMAR.users WHERE cofrac_id IS NOT NULL GROUP BY cofrac_id
), email_candidates AS (
 SELECT CAST(LOWER(TRIM(CONVERT(email USING utf8mb4))) AS BINARY) email_key,COUNT(*) candidates,MIN(id) v2_id,
 MIN(CONCAT_WS(' ',first_name,last_name)) v2_name,MIN(role) v2_role,MAX(deleted_at IS NOT NULL) disabled,MAX(external_client_id IS NOT NULL) external_account
 FROM cofrac2production.users WHERE TRIM(COALESCE(email,''))<>'' GROUP BY email_key
)
SELECT JSON_OBJECT('section','technician','mymar_id',u.id,'mymar_name',u.name,'enabled_raw',u.enabled,'cofrac_id',u.cofrac_id,
 'historical_source_duplicates',COALESCE(h.duplicates,0),
 'historical_v2_id',v.id,'historical_v2_name',CONCAT_WS(' ',v.first_name,v.last_name),'historical_v2_role',v.role,
 'historical_v2_disabled',IF(v.id IS NULL,NULL,v.deleted_at IS NOT NULL),'historical_v2_external',IF(v.id IS NULL,NULL,v.external_client_id IS NOT NULL),
 'historical_email_matches',IF(TRIM(COALESCE(u.email,''))='' OR TRIM(COALESCE(v.email,''))='',NULL,
 CAST(LOWER(TRIM(CONVERT(u.email USING utf8mb4))) AS BINARY)=CAST(LOWER(TRIM(CONVERT(v.email USING utf8mb4))) AS BINARY)),
 'email_candidates',COALESCE(e.candidates,0),'unique_email_v2_id',IF(e.candidates=1,e.v2_id,NULL),
 'unique_email_v2_name',IF(e.candidates=1,e.v2_name,NULL),'unique_email_v2_role',IF(e.candidates=1,e.v2_role,NULL),
 'unique_email_v2_disabled',IF(e.candidates=1,e.disabled,NULL),'unique_email_v2_external',IF(e.candidates=1,e.external_account,NULL))
 FROM candidate_ids c JOIN MYMAR.users u ON u.id=c.mymar_id
 LEFT JOIN historical_counts h ON h.cofrac_id=u.cofrac_id
 LEFT JOIN cofrac2production.coffrac_user_links l ON l.source_user_id=u.cofrac_id
 LEFT JOIN cofrac2production.users v ON v.id=l.user_id
 LEFT JOIN email_candidates e
 ON e.email_key=CAST(LOWER(TRIM(CONVERT(u.email USING utf8mb4))) AS BINARY)
 ORDER BY u.id LIMIT 2001;
SQL;
    }

    public static function explainSql(): string
    {
        return self::begin()."EXPLAIN SELECT r.id,r.dossier_id,r.date_rdv,r.duree,d.technicien_id,d.status_id,d.mar_type FROM MYMAR.dossier_rdv r JOIN MYMAR.dossiers d ON d.id=r.dossier_id WHERE r.date_rdv>=UTC_TIMESTAMP()-INTERVAL 1 DAY AND r.date_rdv<UTC_TIMESTAMP()+INTERVAL 30 DAY;\nROLLBACK;\n";
    }
}
