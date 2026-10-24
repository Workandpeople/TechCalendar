<?php

function techCalendarPlanningFixture(array $overrides = [], bool $full = false): string
{
    $directory = sys_get_temp_dir().'/cofrac2-planning-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $columns = [
        'departments' => ['code', 'name', 'created_at', 'updated_at'],
        'users' => ['id', 'email', 'role', 'department_code', 'day_start_time', 'day_end_time', 'break_duration_minutes', 'deleted_at'],
        'services' => ['id', 'type', 'name', 'average_duration_minutes', 'created_at', 'updated_at'],
        'department_user' => ['id', 'user_id', 'department_code', 'created_at', 'updated_at'],
    ];
    $defaults = [
        'departments' => [['code' => '01', 'name' => 'Ain'], ['code' => '75', 'name' => 'Paris'], ['code' => '92', 'name' => 'Hauts-de-Seine']],
        'users' => [['id' => 901, 'email' => ' SOURCE-21@example.test ', 'role' => 2, 'department_code' => '01',
            'day_start_time' => '08:00:00', 'day_end_time' => '18:00:00', 'break_duration_minutes' => 60, 'deleted_at' => null]],
        'services' => [['id' => 71, 'type' => 'COFFRAC', 'name' => 'Prestation source', 'average_duration_minutes' => 90]],
        'department_user' => [['id' => 1, 'user_id' => 901, 'department_code' => '75'], ['id' => 2, 'user_id' => 901, 'department_code' => '92']],
    ];
    if ($full) {
        $columns['users'] = ['id', 'first_name', 'last_name', 'email', 'role', 'admin', 'phone', 'address', 'department_code', 'latitude', 'longitude', 'day_start_time', 'day_end_time', 'break_duration_minutes', 'created_at', 'updated_at', 'deleted_at'];
        $columns += [
            'external_service_aliases' => ['id', 'service_id', 'source', 'external_type', 'external_name', 'normalized_external_type', 'normalized_external_name', 'created_at', 'updated_at'],
            'service_user' => ['id', 'user_id', 'service_id', 'created_at', 'updated_at'],
            'external_delegataires' => ['id', 'source', 'external_id', 'name', 'company_name', 'email', 'phone', 'is_active', 'last_synced_at', 'created_at', 'updated_at'],
            'mail_senders' => ['id', 'name', 'mail_from_address', 'mail_from_name', 'mail_admin_email', 'logo_path', 'is_active', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at', 'deleted_at'],
            'mail_templates' => ['id', 'name', 'slug', 'mail_sender_id', 'subject', 'markdown_body', 'logo_path', 'is_active', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at', 'deleted_at'],
            'technician_absences' => ['id', 'technician_id', 'created_by', 'starts_at', 'ends_at', 'reason', 'created_at', 'updated_at'],
        ];
        $defaults['users'][0] += ['address' => 'Rue de test', 'latitude' => 48.8566, 'longitude' => 2.3522, 'admin' => false];
        $defaults['users'][] = ['id' => 1, 'email' => 'source-11@example.test', 'role' => 0, 'admin' => true];
        $defaults += ['external_service_aliases' => [['id' => 6, 'service_id' => 71, 'source' => 'coffrac', 'external_type' => 'COFFRAC', 'external_name' => 'Prestation source']],
            'service_user' => [['id' => 1, 'user_id' => 901, 'service_id' => 71]],
            'external_delegataires' => [['id' => 800, 'source' => 'native', 'external_id' => '800', 'name' => 'Ancien compte externe']],
            'mail_senders' => [], 'mail_templates' => [], 'technician_absences' => []];
    }
    $rows = array_replace($defaults, $overrides);
    $tables = [];
    foreach ($columns as $table => $fields) {
        $records = array_map(fn ($row) => array_replace(array_fill_keys($fields, null), $row), $rows[$table]);
        $tables[$table] = ['columns' => $fields, 'count' => count($records), 'rows' => $records];
    }
    writeTechCalendarPlanningFixture($directory, ['format' => $full ? 'techcalendar.reference-snapshot' : 'techcalendar.planning-snapshot', 'version' => 1,
        'source' => 'techcalendar', 'complete' => true, 'consistency' => 'repeatable-read', 'contains_personal_data' => true,
        'exported_at' => '2026-10-24T18:00:00+00:00', 'app_environment' => 'production',
        'source_database' => 'techcalendar', 'source_project' => '/var/www/TechCalendar', 'tables' => $tables]);

    return $directory;
}

function writeTechCalendarPlanningFixture(string $directory, array $data): void
{
    file_put_contents($directory.'/references.json.gz', gzencode(json_encode($data, JSON_THROW_ON_ERROR), 6));
    chmod($directory.'/references.json.gz', 0600);
}
