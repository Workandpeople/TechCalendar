<?php

namespace App\Services\Coffrac;

use DateTimeImmutable;
use RuntimeException;

final class TechCalendarPlanningSnapshot
{
    private const COLUMNS = [
        'departments' => ['code', 'name', 'created_at', 'updated_at'],
        'users' => ['id', 'email', 'role', 'department_code', 'day_start_time', 'day_end_time', 'break_duration_minutes', 'deleted_at'],
        'services' => ['id', 'type', 'name', 'average_duration_minutes', 'created_at', 'updated_at'],
        'department_user' => ['id', 'user_id', 'department_code', 'created_at', 'updated_at'],
    ];

    private const FULL_COLUMNS = [
        ...self::COLUMNS,
        'users' => ['id', 'first_name', 'last_name', 'email', 'role', 'admin', 'phone', 'address', 'department_code', 'latitude', 'longitude', 'day_start_time', 'day_end_time', 'break_duration_minutes', 'created_at', 'updated_at', 'deleted_at'],
        'external_service_aliases' => ['id', 'service_id', 'source', 'external_type', 'external_name', 'normalized_external_type', 'normalized_external_name', 'created_at', 'updated_at'],
        'service_user' => ['id', 'user_id', 'service_id', 'created_at', 'updated_at'],
        'external_delegataires' => ['id', 'source', 'external_id', 'name', 'company_name', 'email', 'phone', 'is_active', 'last_synced_at', 'created_at', 'updated_at'],
        'mail_senders' => ['id', 'name', 'mail_from_address', 'mail_from_name', 'mail_admin_email', 'logo_path', 'is_active', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at', 'deleted_at'],
        'mail_templates' => ['id', 'name', 'slug', 'mail_sender_id', 'subject', 'markdown_body', 'logo_path', 'is_active', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at', 'deleted_at'],
        'technician_absences' => ['id', 'technician_id', 'created_by', 'starts_at', 'ends_at', 'reason', 'created_at', 'updated_at'],
    ];

    public readonly array $data;

    public readonly string $fingerprint;

    public function __construct(string $directory, string $expectedSha256)
    {
        $file = $directory.'/references.json.gz';
        clearstatcache();
        $protectedGroup = is_dir($directory) && is_file($file) && NativeImportTarget::canReadPlanningGroup($directory, $file);
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)
            || is_link($directory) || ! is_dir($directory) || (! $protectedGroup && (fileperms($directory) & 0077) !== 0)
            || is_link($file) || ! is_file($file) || ! is_readable($file) || (! $protectedGroup && (fileperms($file) & 0077) !== 0)
            || fileowner($file) !== fileowner($directory) || filesize($file) > 8 * 1024 * 1024) {
            throw new RuntimeException('Collecte planning absente, publique, symbolique ou trop volumineuse.');
        }
        $gzip = file_get_contents($file);
        if ($gzip === false || ! hash_equals($expectedSha256, hash('sha256', $gzip))) {
            throw new RuntimeException('Empreinte planning differente de la collecte attendue.');
        }
        $json = @gzdecode($gzip, 32 * 1024 * 1024);
        if ($json === false) {
            throw new RuntimeException('Compression planning invalide.');
        }
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $full = is_array($data) && ($data['format'] ?? null) === 'techcalendar.reference-snapshot';
        $schema = $full ? self::FULL_COLUMNS : self::COLUMNS;
        if (! is_array($data) || ! in_array($data['format'] ?? null, ['techcalendar.planning-snapshot', 'techcalendar.reference-snapshot'], true)
            || ($data['version'] ?? null) !== 1 || ($data['source'] ?? null) !== 'techcalendar'
            || ($data['complete'] ?? null) !== true || ($data['consistency'] ?? null) !== 'repeatable-read'
            || ($data['app_environment'] ?? null) !== 'production'
            || ($data['source_project'] ?? null) !== '/var/www/TechCalendar'
            || ! is_string($data['source_database'] ?? null) || $data['source_database'] === ''
            || preg_match('/demo|simulat|test/i', $data['source_database'])
            || ! $this->validTimestamp($data['exported_at'] ?? null)
            || ! is_array($data['tables'] ?? null) || count($data['tables']) !== count($schema)
            || array_diff(array_keys($schema), array_keys($data['tables']))) {
            throw new RuntimeException('Source planning inattendue : collecte V1 de production requise.');
        }
        $keys = [];
        foreach ($schema as $table => $columns) {
            $metadata = $data['tables'][$table];
            if (! is_array($metadata) || ($metadata['columns'] ?? null) !== $columns
                || ! is_array($metadata['rows'] ?? null) || ! array_is_list($metadata['rows'])
                || ! is_int($metadata['count'] ?? null) || $metadata['count'] < 0 || $metadata['count'] > 50000
                || count($metadata['rows']) !== $metadata['count']) {
                throw new RuntimeException('Schema ou comptage planning invalide : '.$table);
            }
            foreach ($metadata['rows'] as $row) {
                if (! is_array($row) || count($row) !== count($columns) || array_diff($columns, array_keys($row))
                    || array_filter($row, fn ($value) => ! is_scalar($value) && $value !== null)) {
                    throw new RuntimeException('Colonnes planning inattendues : '.$table);
                }
                $key = $table === 'departments' ? $this->department($row['code']) : $this->positiveId($row['id']);
                if ($key === null || isset($keys[$table][$key])) {
                    throw new RuntimeException('Identite planning dupliquee ou invalide : '.$table);
                }
                $keys[$table][$key] = true;
                if ($table === 'users' && (! in_array((string) $row['role'], $full ? ['0', '1', '2', '3'] : ['2'], true) || ! is_string($row['email'])
                    || mb_strlen($row['email']) > 190)) {
                    throw new RuntimeException('Collecte planning contenant un profil non technicien ou incompatible.');
                }
            }
        }
        $pivots = [];
        foreach ($data['tables']['department_user']['rows'] as $row) {
            $user = $this->positiveId($row['user_id']);
            $department = $this->department($row['department_code']);
            if (! isset($keys['users'][$user], $keys['departments'][$department]) || isset($pivots[$user][$department])) {
                throw new RuntimeException('Affectation planning orpheline ou dupliquee.');
            }
            $pivots[$user][$department] = true;
        }
        foreach (['external_service_aliases', 'service_user'] as $table) {
            $seen = [];
            foreach ($data['tables'][$table]['rows'] ?? [] as $row) {
                $service = $this->positiveId($row['service_id']);
                if (! isset($keys['services'][$service])) {
                    throw new RuntimeException('Prestation planning orpheline : '.$table);
                }
                if ($table === 'service_user') {
                    $user = $this->positiveId($row['user_id']);
                    if (! isset($keys['users'][$user]) || isset($seen[$user][$service])) {
                        throw new RuntimeException('Habilitation planning orpheline ou dupliquee.');
                    }
                    $seen[$user][$service] = true;
                }
            }
        }
        foreach ($data['tables']['technician_absences']['rows'] ?? [] as $row) {
            if (! isset($keys['users'][$this->positiveId($row['technician_id'])])
                || ($row['created_by'] !== null && ! isset($keys['users'][$this->positiveId($row['created_by'])]))) {
                throw new RuntimeException('Identite d absence planning orpheline.');
            }
        }
        $this->data = $data;
        $this->fingerprint = $expectedSha256;
    }

    public function rows(string $table): array
    {
        if (! array_key_exists($table, $this->data['tables'])) {
            throw new RuntimeException('Table planning non autorisee.');
        }

        return $this->data['tables'][$table]['rows'];
    }

    public function technicians(): array
    {
        return array_values(array_filter($this->rows('users'), fn ($row) => (string) $row['role'] === '2'));
    }

    public function hasFullSettings(): bool
    {
        return $this->data['format'] === 'techcalendar.reference-snapshot';
    }

    private function positiveId(mixed $value): ?int
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,14}$/D', (string) $value) ? (int) $value : null;
    }

    private function department(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(?:[0-9]{2,3}|2A|2B)$/D', $value) ? $value : null;
    }

    private function validTimestamp(mixed $value): bool
    {
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)
            ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;

        return $date !== false && $date->format(DATE_ATOM) === $value;
    }
}
