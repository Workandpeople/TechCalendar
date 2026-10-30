<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

final class MymarTimeCalibration
{
    public const DOSSIER_ID = 91;

    public const DISPLAYED_START = '2026-11-02 09:30:00';

    private const MAX_FILES = 64;

    private const MAX_BYTES = 8388608;

    private static function begin(): string
    {
        return "SET SESSION max_execution_time=1500;\nSET SESSION lock_wait_timeout=2;\nSET SESSION innodb_lock_wait_timeout=2;\nSET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\nSET SESSION TRANSACTION READ ONLY;\nSTART TRANSACTION READ ONLY;\n";
    }

    public static function metadataSql(): string
    {
        return self::begin().<<<'SQL'
SELECT JSON_OBJECT('section','server','port',@@port,'socket',@@socket,'version',VERSION());
SELECT JSON_OBJECT('section','table','table',TABLE_NAME,'engine',ENGINE,'estimated_rows',TABLE_ROWS)
 FROM information_schema.TABLES WHERE TABLE_SCHEMA='MYMAR' AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ('dossiers','dossier_rdv') ORDER BY TABLE_NAME LIMIT 3;
SELECT JSON_OBJECT('section','column','table',TABLE_NAME,'column',COLUMN_NAME,'type',DATA_TYPE)
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('dossiers','dossier_rdv') ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 201;
SELECT JSON_OBJECT('section','index','table',TABLE_NAME,'column',COLUMN_NAME)
 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('dossiers','dossier_rdv') AND SEQ_IN_INDEX=1 ORDER BY TABLE_NAME,INDEX_NAME LIMIT 101;
ROLLBACK;
SQL;
    }

    public static function validateMetadata(array $rows): void
    {
        $tables = $columns = $indexes = [];
        $servers = [];
        foreach ($rows as $row) {
            $table = $row['table'] ?? '';
            match ($row['section'] ?? '') {
                'server' => $servers[] = $row,
                'table' => $tables[$table] = $row,
                'column' => $columns[$table][$row['column'] ?? ''] = $row['type'] ?? '',
                'index' => $indexes[$table][] = $row['column'] ?? '',
                default => throw new RuntimeException('Unexpected metadata section.'),
            };
        }
        if (count($servers) !== 1 || (int) ($servers[0]['port'] ?? 0) !== 3306
            || ! in_array($servers[0]['socket'] ?? null, ['/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock'], true)
            || ! str_starts_with($servers[0]['version'] ?? '', '8.0.')) {
            throw new RuntimeException('Unexpected MySQL instance.');
        }
        foreach (['dossiers' => ['id', 'status_id', 'technicien_id', 'mar_type'],
            'dossier_rdv' => ['id', 'dossier_id', 'date_rdv', 'duree', 'is_old']] as $table => $required) {
            if (($tables[$table]['engine'] ?? null) !== 'InnoDB'
                || ($tables[$table]['estimated_rows'] ?? PHP_INT_MAX) > 100000
                || array_diff($required, array_keys($columns[$table] ?? []))) {
                throw new RuntimeException('Unexpected schema or volume.');
            }
        }
        if (($columns['dossier_rdv']['date_rdv'] ?? null) !== 'datetime'
            || ! in_array($columns['dossier_rdv']['duree'] ?? null, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)
            || ! in_array('id', $indexes['dossiers'] ?? [], true)
            || ! in_array('dossier_id', $indexes['dossier_rdv'] ?? [], true)) {
            throw new RuntimeException('Targeted indexed datetime lookup not confirmed.');
        }
    }

    public static function dataSql(): string
    {
        return self::begin().<<<'SQL'
SELECT JSON_OBJECT('section','clock','session_timezone',@@session.time_zone,'global_timezone',@@global.time_zone,'system_timezone',@@system_time_zone,'transaction_read_only',@@session.transaction_read_only);
SELECT JSON_OBJECT('section','reference','rdv_id',r.id,'dossier_id',r.dossier_id,'stored_start',DATE_FORMAT(r.date_rdv,'%Y-%m-%d %H:%i:%s'),'raw_duration',r.duree,'is_old',r.is_old,'technician_id',d.technicien_id,'status_id',d.status_id,'mar_type',d.mar_type)
 FROM MYMAR.dossiers d JOIN MYMAR.dossier_rdv r ON r.dossier_id=d.id WHERE d.id=91 AND r.is_old=0 ORDER BY r.id DESC LIMIT 11;
ROLLBACK;
SELECT JSON_OBJECT('section','complete','read_only_collection',true);
SQL;
    }

    public static function validateData(array $rows): array
    {
        $clocks = $references = $ends = [];
        foreach ($rows as $row) {
            match ($row['section'] ?? '') {
                'clock' => $clocks[] = $row,
                'reference' => $references[] = $row,
                'complete' => $ends[] = $row,
                default => throw new RuntimeException('Unexpected data section.'),
            };
        }
        if (count($clocks) !== 1 || ($clocks[0]['transaction_read_only'] ?? null) !== 1
            || count($ends) !== 1 || ($ends[0]['read_only_collection'] ?? null) !== true
            || ($rows[array_key_last($rows)]['section'] ?? null) !== 'complete' || count($references) > 10) {
            throw new RuntimeException('Incomplete bounded read-only result.');
        }
        $keys = ['section', 'rdv_id', 'dossier_id', 'stored_start', 'raw_duration', 'is_old', 'technician_id', 'status_id', 'mar_type'];
        foreach ($references as $row) {
            if (array_diff(array_keys($row), $keys) || array_diff($keys, array_keys($row))
                || ($row['dossier_id'] ?? null) !== self::DOSSIER_ID || ($row['is_old'] ?? null) !== 0
                || ! is_int($row['rdv_id']) || $row['rdv_id'] < 1
                || ! is_string($row['stored_start']) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $row['stored_start'])
                || (! is_int($row['raw_duration']) && $row['raw_duration'] !== null)) {
                throw new RuntimeException('Unexpected reference data.');
            }
        }
        if (count(array_unique(array_column($references, 'rdv_id'))) !== count($references)) {
            throw new RuntimeException('Duplicate reference rows.');
        }

        return ['clock' => $clocks[0], 'references' => $references, 'active_reference_unique' => count($references) === 1,
            'observed_display_start' => self::DISPLAYED_START,
            'stored_start_matches_observed_display' => count($references) === 1 ? $references[0]['stored_start'] === self::DISPLAYED_START : null,
            'reference_technician_matches_observation' => count($references) === 1 ? $references[0]['technician_id'] === 26 : null,
            'duration_unit_confirmed' => false, 'timezone_interpretation_confirmed' => false];
    }

    public static function sources(string $root): array
    {
        $queue = ['app/Http/Controllers/ApiController.php', 'app/Http/Controllers/DossierController.php',
            'app/Http/Controllers/HomeController.php', 'app/Models/DossierRDV.php', 'resources/js/views/Home.vue', 'resources/js/app.js'];
        $seen = $proof = $files = [];
        $bytes = 0;
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            if (count($seen) >= self::MAX_FILES) {
                throw new RuntimeException('Source dependency limit reached.');
            }
            $seen[$path] = true;
            $source = MymarReadOnlyAudit::read($root, $path);
            if ($source === null) {
                if (file_exists($root.'/'.$path) || is_link($root.'/'.$path)) {
                    throw new RuntimeException('Existing source path refused.');
                }
                $proof[$path] = null;

                continue;
            }
            if (($bytes += strlen($source)) > self::MAX_BYTES) {
                throw new RuntimeException('Source size limit reached.');
            }
            $proof[$path] = hash('sha256', $source);
            $files[$path] = ['sha256' => $proof[$path], 'bytes' => strlen($source),
                'duration_controls' => self::durationControls($source), 'time_calls' => self::timeCalls($source)];
            if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
                $files[$path]['php_time_facts'] = array_values(array_filter(MymarReadOnlyAudit::facts($source),
                    static fn ($fact) => in_array(strtolower($fact['call'] ?? ''), ['parse', 'format', 'addhour', 'addhours', 'addminutes', 'timezone', 'settimezone', 'createfromformat'], true)));
            }
            foreach (self::dependencies($path, $source) as $dependency) {
                if (! isset($seen[$dependency])) {
                    $queue[] = $dependency;
                }
            }
        }

        return ['files' => $files, 'proof' => $proof, 'bytes_read' => $bytes,
            'calendar_source_present' => isset($files['resources/js/views/Home.vue']),
            'raw_source_exported' => false, 'application_executed' => false];
    }

    public static function unchanged(string $root, array $proof): bool
    {
        foreach ($proof as $path => $hash) {
            $source = MymarReadOnlyAudit::read($root, $path);
            if ($hash === null ? file_exists($root.'/'.$path) || is_link($root.'/'.$path) : $source === null || ! hash_equals($hash, hash('sha256', $source))) {
                return false;
            }
        }

        return true;
    }

    public static function durationControls(string $source): array
    {
        $controls = [];
        preg_match_all('~<select\b[^>]*>.*?</select>|<input\b[^>]*>~is', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$html, $offset]) {
            $opening = substr($html, 0, strpos($html, '>') + 1);
            preg_match_all('~\b(?:v-model(?:\.[a-z]+)?|name|id)\s*=\s*([\'"])([a-zA-Z_$][a-zA-Z0-9_$.]*)\1~', $opening, $bindings, PREG_SET_ORDER);
            $binding = null;
            foreach ($bindings as $candidate) {
                if (preg_match('/(?:^|\.)(?:rdv_duree|duree|duration|rdvDuree|dureeRdv)$/iD', $candidate[2])) {
                    $binding = $candidate[2];
                    break;
                }
            }
            if ($binding === null) {
                continue;
            }
            $options = [];
            preg_match_all('~<option\b[^>]*>.*?</option>~is', $html, $optionTags);
            foreach ($optionTags[0] as $option) {
                if (! preg_match('~\bvalue\s*=\s*([\'"])([0-9]{1,4}(?:\.[0-9]{1,2})?)\1~', $option, $value)) {
                    continue;
                }
                $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($option), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                $labelSafe = preg_match('/^[0-9]{1,4}\s*(?:[.,][0-9]{1,2})?\s*(?:h(?:eures?)?(?:\s*[0-9]{1,2})?|heures?|min(?:utes?)?)$/iuD', $label);
                $options[] = ['value' => $value[2], 'label' => $labelSafe ? $label : '[unrecognized label omitted]'];
            }
            $numeric = [];
            foreach (['value', 'min', 'max', 'step'] as $attribute) {
                if (preg_match('~\b'.$attribute.'\s*=\s*([\'"])([0-9]{1,4}(?:\.[0-9]{1,2})?)\1~', $opening, $value)) {
                    $numeric[$attribute] = $value[2];
                }
            }
            $context = html_entity_decode(strip_tags(substr($source, max(0, $offset - 350), min(350, $offset))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $unitHint = null;
            if (preg_match('/(?:dur[ée]e|duration)\b[^.\r\n]{0,80}\b(heures?|hours?|minutes?|min)\b/iu', $context, $unit)) {
                $unitHint = preg_match('/^(?:h|heure|hour)/i', $unit[1]) ? 'hours' : 'minutes';
            }
            $controls[] = ['line' => 1 + substr_count(substr($source, 0, $offset), "\n"), 'binding' => $binding,
                'kind' => str_starts_with(strtolower($html), '<select') ? 'select' : 'input', 'numeric_options' => $options,
                'numeric_attributes' => $numeric, 'preceding_label_unit_hint' => $unitHint];
            if (count($controls) > 30 || count($options) > 100) {
                throw new RuntimeException('Duration control limit reached.');
            }
        }

        return $controls;
    }

    public static function timeCalls(string $source): array
    {
        $calls = [];
        preg_match_all('/\b(toISOString|toLocaleString|toLocaleTimeString|getHours|getUTCHours|setHours|setUTCHours|utcOffset|timezone|setTimezone|moment\.tz|Date\.UTC|new\s+Date)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach (array_slice($matches[1], 0, 200) as [$call, $offset]) {
            $calls[] = ['line' => 1 + substr_count(substr($source, 0, $offset), "\n"), 'call' => $call];
        }
        preg_match_all('~\badd\s*\(\s*(?:this\.)?(?:[a-zA-Z_$][a-zA-Z0-9_$]*\.)?(?:rdv_duree|duree|duration)\s*,\s*([\'"])(hours?|minutes?)\1\s*\)~i', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach (array_slice($matches, 0, 100) as $match) {
            $calls[] = ['line' => 1 + substr_count(substr($source, 0, $match[0][1]), "\n"), 'call' => 'add', 'duration_input' => true,
                'unit' => str_starts_with(strtolower($match[2][0]), 'hour') ? 'hours' : 'minutes'];
        }

        return $calls;
    }

    private static function dependencies(string $path, string $source): array
    {
        $paths = [];
        if (str_ends_with($path, '.php')) {
            preg_match_all('~(?:\bview\s*\(|@(?:include|extends|component)\s*\()\s*([\'"])([a-zA-Z0-9_./-]{1,120})\1~', $source, $views);
            foreach ($views[2] as $name) {
                if (! str_contains($name, '..')) {
                    $paths[] = 'resources/views/'.str_replace('.', '/', $name).'.blade.php';
                }
            }
        }
        if (preg_match('/\.(?:vue|js)$/D', $path)) {
            preg_match_all('~\bimport\s+[^;\n]*?\sfrom\s*([\'"])([@.][a-zA-Z0-9_./@-]{1,160})\1~', $source, $imports);
            foreach ($imports[2] as $name) {
                $relative = str_starts_with($name, '@/') ? 'resources/js/'.substr($name, 2) : dirname($path).'/'.$name;
                $segments = [];
                foreach (explode('/', $relative) as $part) {
                    if ($part === '..') {
                        array_pop($segments);
                    } elseif ($part !== '.' && $part !== '') {
                        $segments[] = $part;
                    }
                }
                $relative = implode('/', $segments);
                if (str_starts_with($relative, 'resources/js/')) {
                    $paths[] = preg_match('/\.(?:vue|js)$/D', $relative) ? $relative : $relative.'.js';
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
