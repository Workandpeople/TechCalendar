<?php

function internalUsersPlanFixture(): array
{
    $date = '2026-10-25 19:00:00';
    $user = ['id' => 5, 'first_name' => 'Tech', 'last_name' => 'Source', 'email' => ' TECH@example.fr ', 'role' => 2,
        'admin' => 1, 'phone' => '0600000000', 'address' => 'Adresse source', 'department_code' => '01',
        'latitude' => '45.1234567', 'longitude' => '5.1234567', 'day_start_time' => '08:00:00', 'day_end_time' => '18:00:00',
        'break_duration_minutes' => 30, 'created_at' => $date, 'updated_at' => $date, 'deleted_at' => null];
    $admin = array_replace($user, ['id' => 6, 'first_name' => 'Admin', 'last_name' => 'Simulation',
        'email' => 'contact@lucas-dinnichert.fr', 'role' => 0]);
    $data = ['format' => 'techcalendar.internal-users-snapshot', 'version' => 1, 'source' => 'techcalendar',
        'exported_at' => '2026-10-25T20:41:43+00:00', 'complete' => true, 'consistency' => 'repeatable-read',
        'source_project' => '/var/www/TechCalendar-v2', 'source_hostname' => 'techcalendar',
        'app_environment' => 'demo', 'source_database' => 'techcalendar_v2_demo_simulation', 'tables' => []];
    $rows = [
        'departments' => [['code' => '01', 'name' => 'Ain', 'created_at' => $date, 'updated_at' => $date],
            ['code' => '02', 'name' => 'Aisne', 'created_at' => $date, 'updated_at' => $date]],
        'users' => [$user, $admin],
        'services' => [['id' => 10, 'type' => 'COFFRAC', 'name' => 'Controle', 'average_duration_minutes' => 60, 'created_at' => $date, 'updated_at' => $date]],
        'external_service_aliases' => [['id' => 20, 'service_id' => 10, 'source' => 'coffrac', 'external_type' => 'COFFRAC',
            'external_name' => 'SAV Controle', 'normalized_external_type' => 'coffrac', 'normalized_external_name' => 'sav controle',
            'created_at' => $date, 'updated_at' => $date]],
        'service_user' => [['id' => 1, 'user_id' => 5, 'service_id' => 10, 'created_at' => $date, 'updated_at' => $date]],
        'department_user' => [['id' => 1, 'user_id' => 5, 'department_code' => '01', 'created_at' => $date, 'updated_at' => $date]],
    ];
    foreach ($rows as $table => $values) {
        $data['tables'][$table] = ['columns' => array_keys($values[0]), 'count' => count($values), 'rows' => $values];
    }
    $targetUser = array_replace($user, ['id' => 77, 'admin' => 0, 'email' => 'tech@example.fr', 'first_name' => 'Ancien',
        'role' => 1, 'deleted_at' => $date, 'password' => 'DO_NOT_EXPORT_PASSWORD_HASH', 'remember_token' => 'DO_NOT_EXPORT_TOKEN',
        'can_manage_billing' => 1, 'external_client_id' => null]);
    $target = ['departments' => $rows['departments'],
        'users' => [$targetUser, array_replace($targetUser, ['id' => 88, 'email' => 'contact@lucas-dinnichert.fr', 'admin' => 1,
            'first_name' => 'Super', 'last_name' => 'Admin', 'role' => 0, 'deleted_at' => null]),
            array_replace($targetUser, ['id' => 99, 'email' => 'other@example.fr'])],
        'services' => [array_replace($rows['services'][0], ['id' => 500, 'retired_at' => null]),
            array_replace($rows['services'][0], ['id' => 501, 'name' => 'SAV Controle', 'retired_at' => null])],
        'external_service_aliases' => [], 'service_user' => [['id' => 8, 'user_id' => 77, 'service_id' => 500]],
        'department_user' => [['id' => 9, 'user_id' => 77, 'department_code' => '02']]];

    return [$data, $target];
}
