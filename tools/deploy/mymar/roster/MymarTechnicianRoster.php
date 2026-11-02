<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Bulk identity planning only. Never modifies accounts, mappings or the planning. */
final class MymarTechnicianRoster
{
    private const REQUIRED = [
        'MYMAR.users' => ['id', 'name', 'email', 'enabled'],
        'MYMAR.roles' => ['id', 'name'],
        'MYMAR.model_has_roles' => ['role_id', 'model_id', 'model_type'],
        'cofrac2production.users' => ['id', 'first_name', 'last_name', 'email', 'role', 'admin', 'deleted_at', 'external_client_id', 'latitude', 'longitude'],
        'cofrac2production.services' => ['id', 'type', 'name', 'retired_at', 'average_duration_minutes'],
        'cofrac2production.service_user' => ['user_id', 'service_id'],
        'cofrac2production.department_user' => ['user_id', 'department_code'],
    ];

    public static function sql(string $salt): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $salt)) {
            throw new RuntimeException('mymar.invalid_roster_salt');
        }
        $scope = "(TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('users','roles','model_has_roles')) OR (TABLE_SCHEMA='cofrac2production' AND TABLE_NAME IN ('users','services','service_user','department_user'))";

        return <<<'SQL'
SET SESSION max_execution_time=1500;
SET SESSION lock_wait_timeout=2;
SET SESSION innodb_lock_wait_timeout=2;
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT JSON_OBJECT('section','server','version',@@version,'port',@@port,'read_only',@@transaction_read_only);
SQL
            ."\nSELECT JSON_OBJECT('section','table','key',CONCAT(TABLE_SCHEMA,'.',TABLE_NAME),'engine',ENGINE) FROM information_schema.TABLES WHERE $scope LIMIT 8;\n"
            ."SELECT JSON_OBJECT('section','column','key',CONCAT(TABLE_SCHEMA,'.',TABLE_NAME),'name',COLUMN_NAME) FROM information_schema.COLUMNS WHERE $scope LIMIT 601;\n"
            ."SELECT JSON_OBJECT('section','counts','sources',(SELECT COUNT(*) FROM MYMAR.users),'targets',(SELECT COUNT(*) FROM cofrac2production.users));\n"
            ."SELECT JSON_OBJECT('section','source','id',u.id,'name',COALESCE(u.name,''),'active',u.enabled=1,"
            ."'email_key',IF(TRIM(COALESCE(u.email,''))='',NULL,SHA2(CONCAT('$salt',LOWER(TRIM(CONVERT(u.email USING utf8mb4)))) ,256)),"
            ."'terrain',EXISTS(SELECT 1 FROM MYMAR.model_has_roles b JOIN MYMAR.roles r ON r.id=b.role_id WHERE b.model_id=u.id AND r.name='Technicien Terrain'"
            ." AND b.model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User')))) FROM MYMAR.users u ORDER BY u.id LIMIT 1001;\n"
            ."SELECT JSON_OBJECT('section','target','id',u.id,'name',CONCAT_WS(' ',u.first_name,u.last_name),'role',u.role,'active',u.deleted_at IS NULL,"
            ."'admin',u.admin=1,'external',u.external_client_id IS NOT NULL,'gps_present',u.latitude BETWEEN -90 AND 90 AND u.longitude BETWEEN -180 AND 180,"
            ."'email_key',IF(TRIM(COALESCE(u.email,''))='',NULL,SHA2(CONCAT('$salt',LOWER(TRIM(CONVERT(u.email USING utf8mb4)))) ,256))) FROM cofrac2production.users u ORDER BY u.id LIMIT 1001;\n"
            ."SELECT JSON_OBJECT('section','service','id',id,'name',name,'duration',average_duration_minutes) FROM cofrac2production.services WHERE type='MAR' AND retired_at IS NULL ORDER BY id LIMIT 201;\n"
            ."SELECT JSON_OBJECT('section','grant','user_id',su.user_id,'service_id',su.service_id) FROM cofrac2production.service_user su JOIN cofrac2production.services s ON s.id=su.service_id WHERE s.type='MAR' AND s.retired_at IS NULL LIMIT 20001;\n"
            ."SELECT JSON_OBJECT('section','department','user_id',du.user_id,'code',du.department_code) FROM cofrac2production.department_user du JOIN cofrac2production.users u ON u.id=du.user_id WHERE u.role=2 AND u.external_client_id IS NULL LIMIT 20001;\n"
            ."SELECT JSON_OBJECT('section','clock','utc',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'read_only',@@transaction_read_only);\nROLLBACK;\nSELECT JSON_OBJECT('section','complete','complete',true);\n";
    }

    public static function snapshot(array $rows, DateTimeImmutable $now): array
    {
        $single = $tables = $columns = $sources = $targets = $services = $grants = $departments = [];
        foreach ($rows as $row) {
            $section = $row['section'] ?? '';
            if (in_array($section, ['server', 'counts', 'clock', 'complete'], true) && ! isset($single[$section])) {
                $single[$section] = $row;
            } elseif ($section === 'table' && ! isset($tables[$row['key']])) {
                $tables[$row['key']] = $row['engine'];
            } elseif ($section === 'column' && ! isset($columns[$row['key']][$row['name']])) {
                $columns[$row['key']][$row['name']] = true;
            } elseif (in_array($section, ['source', 'target', 'service'], true)) {
                if (! is_int($row['id'] ?? null) || $row['id'] < 1 || ! is_string($row['name'] ?? null) || strlen($row['name']) > 1000) {
                    throw new RuntimeException('mymar.invalid_roster_identity');
                }
                if ($section !== 'service' && (($row['email_key'] ?? null) !== null && ! preg_match('/^[a-f0-9]{64}$/D', $row['email_key']))) {
                    throw new RuntimeException('mymar.invalid_roster_email_fingerprint');
                }
                $flags = match ($section) {
                    'source' => ['active', 'terrain'], 'target' => ['active', 'admin', 'external', 'gps_present'], default => []
                };
                foreach ($flags as $flag) {
                    if (! array_key_exists($flag, $row) || ! in_array($row[$flag], [null, 0, 1, false, true], true)) {
                        throw new RuntimeException('mymar.invalid_roster_profile_flag');
                    }
                    if (is_bool($row[$flag])) {
                        $row[$flag] = (int) $row[$flag];
                    }
                }
                if ($section === 'source') {
                    $collection = &$sources;
                } elseif ($section === 'target') {
                    $collection = &$targets;
                } else {
                    $collection = &$services;
                }
                if (isset($collection[$row['id']])) {
                    throw new RuntimeException('mymar.duplicate_roster_identity');
                }
                $collection[$row['id']] = $row;
                unset($collection);
            } elseif ($section === 'grant') {
                $grants[$row['user_id']][] = $row['service_id'];
            } elseif ($section === 'department') {
                $departments[$row['user_id']][] = $row['code'];
            } else {
                throw new RuntimeException('mymar.unexpected_or_duplicate_roster_record');
            }
        }
        if (array_diff(array_keys($tables), array_keys(self::REQUIRED)) || count($tables) !== count(self::REQUIRED)) {
            throw new RuntimeException('mymar.incomplete_roster_schema');
        }
        foreach (self::REQUIRED as $key => $required) {
            if (($tables[$key] ?? null) !== 'InnoDB' || array_diff($required, array_keys($columns[$key] ?? []))) {
                throw new RuntimeException('mymar.incomplete_roster_schema');
            }
        }
        $time = $single['clock']['utc'] ?? '';
        $clock = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $time, new DateTimeZone('UTC')) : false;
        if (($single['server']['port'] ?? null) !== 3306 || ! str_starts_with($single['server']['version'] ?? '', '8.0.')
            || ($single['server']['read_only'] ?? null) !== 1 || ($single['clock']['read_only'] ?? null) !== 1
            || ($single['complete']['complete'] ?? null) !== true || $clock === false || $clock->format('Y-m-d\TH:i:s\Z') !== $time
            || $now->getTimestamp() - $clock->getTimestamp() > 300 || $clock->getTimestamp() > $now->getTimestamp() + 60
            || count($sources) > 1000 || count($targets) > 1000 || count($services) > 200
            || ($single['counts']['sources'] ?? null) !== count($sources) || ($single['counts']['targets'] ?? null) !== count($targets)
            || count(array_filter($rows, fn ($r) => ($r['section'] ?? '') === 'grant')) > 20000
            || count(array_filter($rows, fn ($r) => ($r['section'] ?? '') === 'department')) > 20000) {
            throw new RuntimeException('mymar.incomplete_or_stale_roster');
        }

        return compact('sources', 'targets', 'services', 'grants', 'departments', 'time');
    }

    public static function plan(array $snapshot, array $existing, array $approved): array
    {
        $allSources = $snapshot['sources'];
        $allTargets = $snapshot['targets'];
        $sources = array_filter($allSources, fn ($r) => ($r['active'] ?? null) === 1 && ($r['terrain'] ?? null) === 1);
        $targets = array_filter($allTargets, fn ($r) => ($r['active'] ?? null) === 1 && ($r['role'] ?? null) === 2
            && ($r['admin'] ?? null) === 0 && ($r['external'] ?? null) === 0);
        $sourceEmails = self::index($allSources, 'email_key');
        $targetEmails = self::index($allTargets, 'email_key');
        $sourceNames = self::index($sources, 'name');
        $targetNames = self::index($targets, 'name');
        $links = $usedTargets = $reviews = $currentIssues = [];
        $add = function (int $sourceId, int $targetId, string $basis) use (&$links, &$usedTargets, $sources, $targets): bool {
            if (! isset($sources[$sourceId], $targets[$targetId]) || isset($links[$sourceId]) || isset($usedTargets[$targetId])) {
                return false;
            }
            $links[$sourceId] = ['source_technician_id' => $sourceId, 'source_name' => $sources[$sourceId]['name'],
                'cofrac_user_id' => $targetId, 'cofrac_name' => $targets[$targetId]['name'], 'basis' => $basis];
            $usedTargets[$targetId] = $sourceId;

            return true;
        };
        foreach ($existing as $pair) {
            if (! is_array($pair) || count($pair) !== 2 || ! is_int($pair['source_technician_id'] ?? null)
                || ! is_int($pair['cofrac_user_id'] ?? null) || $pair['source_technician_id'] < 1 || $pair['cofrac_user_id'] < 1) {
                throw new RuntimeException('mymar.invalid_existing_roster_binding');
            }
            if (! $add($pair['source_technician_id'], $pair['cofrac_user_id'], 'existing_validated_mapping')) {
                $currentIssues[] = [...$pair, 'reason' => 'inactive_missing_or_duplicate_existing_mapping'];
            }
        }
        foreach ($approved as $pair) {
            $sourceId = $pair['source_technician_id'] ?? 0;
            $targetId = $pair['cofrac_user_id'] ?? 0;
            if (! is_int($sourceId) || ! is_int($targetId)
                || self::name($sources[$sourceId]['name'] ?? '') !== self::name($pair['source_name'] ?? '')
                || self::name($targets[$targetId]['name'] ?? '') !== self::name($pair['cofrac_name'] ?? '')) {
                throw new RuntimeException('mymar.confirmed_roster_pair_changed_or_conflicting');
            }
            if (isset($links[$sourceId]) && $links[$sourceId]['cofrac_user_id'] === $targetId) {
                continue;
            }
            if (! $add($sourceId, $targetId, 'operator_confirmed_identity')) {
                throw new RuntimeException('mymar.confirmed_roster_pair_changed_or_conflicting');
            }
        }
        foreach ($sources as $sourceId => $source) {
            if (isset($links[$sourceId])) {
                continue;
            }
            $key = self::name($source['name']);
            $names = $targetNames[$key] ?? [];
            $email = $source['email_key'] ?? '';
            $emails = $targetEmails[$email] ?? [];
            $fullIdentity = substr_count($key, ' ') >= 1 && count(array_unique(explode(' ', $key))) >= 2;
            $uniqueName = $fullIdentity && count($sourceNames[$key] ?? []) === 1 && count($names) === 1;
            $emailCandidate = $email !== '' && count($sourceEmails[$email] ?? []) === 1 && count($emails) === 1
                && isset($targets[$emails[0]]) ? $emails[0] : null;
            $emailConflict = $email !== '' && (count($sourceEmails[$email] ?? []) !== 1 || count($emails) > 1
                || (count($emails) === 1 && $emailCandidate === null)
                || ($emailCandidate !== null && $uniqueName && $names[0] !== $emailCandidate));
            $candidate = $emailCandidate ?? ($uniqueName ? $names[0] : null);
            if ($candidate !== null && ! $emailConflict && ! isset($usedTargets[$candidate])) {
                $add($sourceId, $candidate, $emailCandidate !== null ? 'unique_email' : 'unique_full_name');

                continue;
            }
            $reviews[] = ['source_technician_id' => $sourceId, 'source_name' => $source['name'],
                'reason' => $emailConflict ? 'conflicting_or_shared_email' : ($candidate !== null ? 'target_already_linked' : 'no_unique_full_identity'),
                'candidate_cofrac_ids' => array_values(array_unique([...$names, ...$emails]))];
        }
        ksort($links);
        $marServices = array_values(array_filter($snapshot['services'], fn ($r) => self::name($r['name']) === 'apres travaux visite'));
        $marId = count($marServices) === 1 ? $marServices[0]['id'] : null;
        foreach ($links as &$link) {
            $id = $link['cofrac_user_id'];
            $link['mar_qualified'] = $marId !== null && in_array($marId, $snapshot['grants'][$id] ?? [], true);
            $link['gps_present'] = ($targets[$id]['gps_present'] ?? null) === 1;
            $link['departments'] = array_values(array_unique($snapshot['departments'][$id] ?? []));
        }
        unset($link);
        $unlinkedTargets = array_values(array_map(fn ($r) => ['cofrac_user_id' => $r['id'], 'cofrac_name' => $r['name']],
            array_filter($targets, fn ($r) => ! isset($usedTargets[$r['id']]))));

        return ['format' => 'cofrac.mymar-technician-roster-plan', 'version' => 1, 'checked_at_utc' => $snapshot['time'],
            'summary' => ['active_source_technicians' => count($sources), 'active_cofrac_technicians' => count($targets),
                'existing_links' => count($existing), 'proposed_links' => count($links), 'source_identities_to_review' => count($reviews),
                'cofrac_without_link' => count($unlinkedTargets), 'existing_mapping_issues' => count($currentIssues),
                'mapped_but_not_mar_qualified' => count(array_filter($links, fn ($l) => ! $l['mar_qualified'])),
                'mar_service_candidates' => count($marServices), 'mar_duration_minutes' => $marServices[0]['duration'] ?? null],
            'links' => array_values($links), 'review' => $reviews, 'unlinked_cofrac' => $unlinkedTargets, 'existing_mapping_issues' => $currentIssues,
            'policy' => 'preserve_existing_plus_operator_confirmed_unique_email_or_full_identity_without_conflict',
            'configuration_writes' => false, 'account_writes' => false, 'services_changed' => false, 'can_affect_planning' => false];
    }

    private static function name(string $name): string
    {
        if (! function_exists('transliterator_transliterate')) {
            throw new RuntimeException('mymar.intl_required');
        }
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', preg_replace('/\([^)]*\)/u', ' ', $name) ?? '');
        // Department annotations are not identity tokens, including suffixes outside parentheses.
        $words = preg_split('/[^a-z]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        sort($words, SORT_STRING);

        return implode(' ', $words);
    }

    private static function index(array $rows, string $field): array
    {
        $index = [];
        foreach ($rows as $row) {
            $value = $field === 'name' ? self::name($row['name']) : ($row[$field] ?? '');
            if ($value !== '') {
                $index[$value][] = $row['id'];
            }
        }

        return $index;
    }
}
