<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Read-only reconciliation. This class deliberately has no apply operation. */
final class TechCalendarInternalUsersPlan
{
    private const COLUMNS = [
        'departments' => 'code,name,created_at,updated_at',
        'users' => 'id,first_name,last_name,email,role,admin,phone,address,department_code,latitude,longitude,day_start_time,day_end_time,break_duration_minutes,created_at,updated_at,deleted_at',
        'services' => 'id,type,name,average_duration_minutes,created_at,updated_at',
        'external_service_aliases' => 'id,service_id,source,external_type,external_name,normalized_external_type,normalized_external_name,created_at,updated_at',
        'service_user' => 'id,user_id,service_id,created_at,updated_at',
        'department_user' => 'id,user_id,department_code,created_at,updated_at',
    ];

    private const PROFILE = ['first_name', 'last_name', 'role', 'phone', 'address', 'department_code',
        'latitude', 'longitude', 'day_start_time', 'day_end_time', 'break_duration_minutes', 'deleted_at'];

    public readonly array $source;

    public function __construct(string $file, public readonly string $sourceSha256)
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) || is_link($file) || ! is_file($file)
            || ! is_readable($file) || (fileperms($file) & 0077) !== 0 || filesize($file) > 8 * 1024 ** 2
            || ! hash_equals($sourceSha256, hash_file('sha256', $file))) {
            throw new RuntimeException('Collecte privee absente ou empreinte differente.');
        }
        $json = @gzdecode(file_get_contents($file), 32 * 1024 ** 2);
        if ($json === false) {
            throw new RuntimeException('Collecte compressee invalide.');
        }
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['format'] ?? null) !== 'techcalendar.internal-users-snapshot'
            || ($data['version'] ?? null) !== 1 || ($data['source'] ?? null) !== 'techcalendar'
            || ($data['complete'] ?? null) !== true || ($data['consistency'] ?? null) !== 'repeatable-read'
            || ($data['source_project'] ?? null) !== '/var/www/TechCalendar-v2'
            || ($data['source_hostname'] ?? null) !== 'techcalendar' || ($data['app_environment'] ?? null) !== 'demo'
            || ($data['source_database'] ?? null) !== 'techcalendar_v2_demo_simulation'
            || ! is_string($data['exported_at'] ?? null)
            || ! DateTimeImmutable::createFromFormat(DATE_ATOM, $data['exported_at'])
            || ! is_array($data['tables'] ?? null) || count($data['tables']) !== count(self::COLUMNS)
            || array_diff_key(self::COLUMNS, $data['tables'])) {
            throw new RuntimeException('Source CT V2 inattendue ou collecte incomplete.');
        }
        $keys = [];
        foreach (self::COLUMNS as $table => $columns) {
            $metadata = $data['tables'][$table];
            $fields = explode(',', $columns);
            if (! is_array($metadata) || ($metadata['columns'] ?? null) !== $fields
                || ! is_int($metadata['count'] ?? null) || $metadata['count'] < 0 || $metadata['count'] > 50000
                || ! is_array($metadata['rows'] ?? null) || ! array_is_list($metadata['rows'])
                || count($metadata['rows']) !== $metadata['count']) {
                throw new RuntimeException('Schema ou comptage source inattendu : '.$table);
            }
            foreach ($metadata['rows'] as $row) {
                if (! is_array($row) || count($row) !== count($fields) || array_diff($fields, array_keys($row))
                    || array_filter($row, fn ($v) => ! is_scalar($v) && $v !== null)) {
                    throw new RuntimeException('Colonnes source inattendues : '.$table);
                }
                $key = $table === 'departments' ? $row['code'] : $row['id'];
                if (($table === 'departments' ? ! self::validDepartment($key) : ! self::validId($key))
                    || isset($keys[$table][(string) $key])) {
                    throw new RuntimeException('Identite source invalide ou dupliquee : '.$table);
                }
                $keys[$table][(string) $key] = true;
            }
        }
        $emails = [];
        foreach ($data['tables']['users']['rows'] as $user) {
            $email = self::email($user['email']);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || isset($emails[$email])
                || ! in_array((string) $user['role'], ['0', '1', '2'], true)) {
                throw new RuntimeException('Email source ambigu ou profil non interne.');
            }
            $emails[$email] = true;
        }
        foreach (['service_user', 'department_user', 'external_service_aliases'] as $table) {
            $seen = [];
            foreach ($data['tables'][$table]['rows'] as $row) {
                $parent = $table === 'department_user' ? 'departments' : 'services';
                $foreign = $table === 'department_user' ? 'department_code' : 'service_id';
                if (! isset($keys[$parent][(string) $row[$foreign]])
                    || ($table !== 'external_service_aliases' && ! isset($keys['users'][(string) $row['user_id']]))) {
                    throw new RuntimeException('Relation source orpheline : '.$table);
                }
                if ($table !== 'external_service_aliases') {
                    $pair = $row['user_id'].'/'.$row[$foreign];
                    if (isset($seen[$pair])) {
                        throw new RuntimeException('Relation source dupliquee : '.$table);
                    }
                    $seen[$pair] = true;
                }
            }
        }
        $this->source = $data;
    }

    public function inspect(PDO $pdo): array
    {
        return $this->build($this->readTarget($pdo));
    }

    public function readTarget(PDO $pdo, bool $lock = false): array
    {
        if (! $pdo->inTransaction()) {
            throw new RuntimeException('Lecture coherente dans une transaction READ ONLY requise.');
        }
        $target = [];
        $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        foreach (self::COLUMNS as $table => $ignored) {
            $key = $table === 'departments' ? 'code' : 'id';
            $target[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY `$key` LIMIT 50001".$suffix)->fetchAll(PDO::FETCH_ASSOC);
            if (count($target[$table]) > 50000) {
                throw new RuntimeException('Cible trop volumineuse pour ce rapprochement : '.$table);
            }
        }

        return $target;
    }

    public function build(array $target): array
    {
        foreach (array_keys(self::COLUMNS) as $table) {
            if (! isset($target[$table]) || ! is_array($target[$table])) {
                throw new RuntimeException('Table cible manquante : '.$table);
            }
        }
        $admins = array_values(array_filter($target['users'], fn ($u) => (bool) $u['admin']));
        if (count($admins) !== 1 || self::email($admins[0]['email']) !== 'contact@lucas-dinnichert.fr'
            || $admins[0]['deleted_at'] !== null || (int) $admins[0]['role'] === 3 || $admins[0]['external_client_id'] !== null) {
            throw new RuntimeException('Le seul super-admin Cofrac2 attendu doit rester actif et interne.');
        }
        $sourceUsers = $this->source['tables']['users']['rows'];
        $sourceDepartments = $this->memberships($this->source['tables']['department_user']['rows'], 'department_code');
        $sourceGrants = $this->memberships($this->source['tables']['service_user']['rows'], 'service_id');
        $targetDepartmentMemberships = $this->memberships($target['department_user'], 'department_code');
        $targetGrants = $this->memberships($target['service_user'], 'service_id');
        $byEmail = $sourceEmails = [];
        foreach ($target['users'] as $user) {
            $byEmail[self::email($user['email'])][] = $user;
        }
        $services = $this->serviceMatches($target['services']);
        $targetDepartments = array_map('strval', array_column($target['departments'], 'code'));
        $users = $issues = [];
        $counts = ['source_users' => count($sourceUsers), 'matched' => 0, 'create' => 0, 'update' => 0,
            'unchanged' => 0, 'protected_super_admin' => 0, 'restore' => 0, 'deactivate' => 0,
            'source_inactive' => 0, 'review' => 0, 'target_only_untouched' => 0,
            'source_departments' => $this->source['tables']['department_user']['count'],
            'source_service_grants' => $this->source['tables']['service_user']['count']];
        foreach ($sourceUsers as $source) {
            $email = self::email($source['email']);
            $sourceEmails[$email] = true;
            $candidates = $byEmail[$email] ?? [];
            $entry = ['source_user_id' => (int) $source['id'], 'email' => $email,
                'target_user_id' => count($candidates) === 1 ? (int) $candidates[0]['id'] : null];
            if ($source['deleted_at'] !== null) {
                $counts['source_inactive']++;
            }
            if (count($candidates) > 1) {
                $entry += ['action' => 'review', 'reason' => 'ambiguous_target_email'];
            } elseif ($candidates && ((int) $candidates[0]['role'] === 3 || $candidates[0]['external_client_id'] !== null)) {
                $entry += ['action' => 'review', 'reason' => 'external_account_collision'];
            } elseif ($candidates && (bool) $candidates[0]['admin']) {
                $entry += ['action' => 'protected_super_admin', 'reason' => 'all_target_values_preserved'];
            } elseif ($this->testIdentity($source)) {
                $entry += ['action' => 'review', 'reason' => 'test_identity_requires_review'];
            } else {
                $existing = $candidates[0] ?? null;
                $profile = array_intersect_key($source, array_flip(self::PROFILE));
                $profile['role'] = (int) $profile['role'];
                $departments = $sourceDepartments[(int) $source['id']] ?? [];
                $grants = $sourceGrants[(int) $source['id']] ?? [];
                $mapped = $unmapped = [];
                foreach ($grants as $id) {
                    $matches = $services['by_source'][(int) $id] ?? [];
                    if ($matches === []) {
                        $unmapped[] = (int) $id;
                    }
                    $mapped = array_merge($mapped, $matches);
                }
                $mapped = array_values(array_unique($mapped));
                sort($mapped, SORT_NUMERIC);
                $fieldIssues = $this->profileIssues($profile, $targetDepartments);
                if (array_diff($departments, $targetDepartments)) {
                    $fieldIssues[] = 'unknown_intervention_department';
                }
                $entry += ['action' => $existing ? 'update' : 'create', 'profile' => $profile,
                    'departments' => $departments, 'mapped_service_ids' => $mapped,
                    'unmapped_source_service_ids' => $unmapped, 'profile_issues' => $fieldIssues,
                    'password_policy' => $existing ? 'preserve_cofrac2' : 'initialization_requires_review',
                    'source_admin_ignored' => (bool) $source['admin']];
                if ($existing) {
                    $changes = [];
                    foreach ($profile as $field => $value) {
                        if (! $this->equivalent($field, $value, $existing[$field])) {
                            $changes[] = $field;
                        }
                    }
                    $entry['changed_fields'] = $changes;
                    $entry['current_department_ids'] = $targetDepartmentMemberships[(int) $existing['id']] ?? [];
                    $entry['current_service_ids'] = $targetGrants[(int) $existing['id']] ?? [];
                    $entry['current_role'] = (int) $existing['role'];
                    if ($existing['deleted_at'] !== null && $source['deleted_at'] === null) {
                        $counts['restore']++;
                    } elseif ($existing['deleted_at'] === null && $source['deleted_at'] !== null) {
                        $counts['deactivate']++;
                    }
                    if ($changes === [] && $entry['current_department_ids'] === $departments && $entry['current_service_ids'] === $mapped) {
                        $entry['action'] = 'unchanged';
                    }
                }
                if ($fieldIssues !== [] || $unmapped !== []) {
                    $issues[] = ['source_user_id' => (int) $source['id'], 'fields' => $fieldIssues, 'unmapped_source_service_ids' => $unmapped];
                }
            }
            if ($entry['target_user_id'] !== null) {
                $counts['matched']++;
            }
            $counts[$entry['action']]++;
            $users[] = $entry;
        }
        foreach ($target['users'] as $user) {
            if (! isset($sourceEmails[self::email($user['email'])])) {
                $counts['target_only_untouched']++;
            }
        }

        // Only an aggregate fingerprint leaves memory; target passwords/tokens are never exported.
        return ['format' => 'cofrac2.internal-users-plan', 'version' => 1, 'source_sha256' => $this->sourceSha256,
            'source_exported_at' => $this->source['exported_at'], 'target_fingerprint' => hash('sha256', json_encode($target, JSON_THROW_ON_ERROR)),
            'summary' => $counts + ['users_with_mapping_or_profile_issues' => count($issues),
                'ambiguous_service_labels' => count($services['ambiguous']), 'catalog_policy_approval_required' => true],
            'users' => $users, 'issues' => $issues, 'services' => $services,
            'writes_performed' => false, 'passwords_exported' => false, 'apply_supported' => false,
            'requires_review' => true];
    }

    private function serviceMatches(array $targetServices): array
    {
        $exact = $aliases = [];
        $sourceServices = $this->source['tables']['services']['rows'];
        $byId = array_column($sourceServices, null, 'id');
        foreach ($sourceServices as $service) {
            $exact[$service['type'].'/'.trim($service['name'])][] = (int) $service['id'];
        }
        foreach ($this->source['tables']['external_service_aliases']['rows'] as $alias) {
            if ($alias['source'] === 'coffrac' && $alias['external_type'] === 'COFFRAC'
                && $byId[$alias['service_id']]['type'] === 'COFFRAC') {
                $aliases['COFFRAC/'.trim($alias['external_name'])][] = (int) $alias['service_id'];
            }
        }
        $matches = $bySource = $ambiguous = $unmatched = [];
        foreach ($targetServices as $target) {
            if (($target['retired_at'] ?? null) !== null) {
                continue;
            }
            $key = $target['type'].'/'.trim($target['name']);
            // A native exact label is more specific than a legacy catch-all alias.
            $candidates = array_values(array_unique($exact[$key] ?? $aliases[$key] ?? []));
            if (count($candidates) !== 1) {
                $item = ['target_service_id' => (int) $target['id'], 'type' => $target['type'], 'name' => $target['name'], 'source_candidates' => $candidates];
                if ($candidates === []) {
                    $unmatched[] = $item;
                } else {
                    $ambiguous[] = $item;
                }

                continue;
            }
            $sourceId = $candidates[0];
            $bySource[$sourceId][] = (int) $target['id'];
            $matches[] = ['target_service_id' => (int) $target['id'], 'source_service_id' => $sourceId,
                'type' => $target['type'], 'name' => $target['name'], 'evidence' => isset($exact[$key]) ? 'exact_type_and_name' : 'explicit_source_alias'];
        }
        $sourceCatalog = array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'type', 'name'])), $sourceServices);

        return ['by_source' => $bySource, 'matches' => $matches, 'ambiguous' => $ambiguous,
            'unmatched_target' => $unmatched, 'source_catalog' => $sourceCatalog];
    }

    private function profileIssues(array $profile, array $departments): array
    {
        $issues = [];
        foreach (['first_name', 'last_name', 'phone', 'address'] as $field) {
            $value = $profile[$field];
            if (($value !== null && (! is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)))
                || (in_array($field, ['first_name', 'last_name'], true) && $value === null)) {
                $issues[] = $field;
            }
        }
        if ($profile['department_code'] !== null && ! in_array($profile['department_code'], $departments, true)) {
            $issues[] = 'department_code';
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $field => $max) {
            if ($profile[$field] !== null && (! is_numeric($profile[$field]) || ! is_finite((float) $profile[$field]) || abs((float) $profile[$field]) > $max)) {
                $issues[] = $field;
            }
        }
        foreach (['day_start_time', 'day_end_time'] as $field) {
            if ($profile[$field] !== null && ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', (string) $profile[$field])) {
                $issues[] = $field;
            }
        }
        if (($profile['day_start_time'] === null) !== ($profile['day_end_time'] === null)
            || ($profile['day_start_time'] !== null && $profile['day_start_time'] >= $profile['day_end_time'])) {
            $issues[] = 'working_hours';
        }
        $break = $profile['break_duration_minutes'];
        if ($break !== null && (! preg_match('/^[0-9]{1,4}$/D', (string) $break) || (int) $break > 1440)) {
            $issues[] = 'break_duration_minutes';
        }

        return $issues;
    }

    private function memberships(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['user_id']][] = $field === 'service_id' ? (int) $row[$field] : (string) $row[$field];
        }
        foreach ($values as &$membership) {
            sort($membership, $field === 'service_id' ? SORT_NUMERIC : SORT_STRING);
        }
        unset($membership);

        return $values;
    }

    private function equivalent(string $field, mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (in_array($field, ['latitude', 'longitude'], true)) {
            return is_numeric($left) && is_numeric($right) && abs((float) $left - (float) $right) < 0.00000005;
        }

        return (string) $left === (string) $right;
    }

    private function testIdentity(array $user): bool
    {
        $domain = substr(strrchr(self::email($user['email']), '@'), 1);

        return preg_match('/(?:^|\.)(?:test|invalid|localhost)$/D', $domain) === 1
            || in_array($domain, ['example.com', 'example.net', 'example.org'], true)
            || (mb_strtolower(trim($user['first_name'].' '.$user['last_name'])) === 'admin simulation');
    }

    private static function email(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }

    private static function validId(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,14}$/D', (string) $value) === 1;
    }

    private static function validDepartment(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:[0-9]{2,3}|2A|2B)$/D', $value) === 1;
    }
}
