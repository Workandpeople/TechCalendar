<?php

function techCalendarReferenceConnection(): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = method_exists(PDO::class, 'connect')
        ? PDO::connect('sqlite::memory:', options: $options)
        : new PDO('sqlite::memory:', options: $options);
    $tables = [
        'departments' => 'code,name,created_at,updated_at',
        'users' => 'id,first_name,last_name,email,role,admin,phone,address,department_code,latitude,longitude,day_start_time,day_end_time,break_duration_minutes,created_at,updated_at,deleted_at,password,remember_token,new_secret_field',
        'services' => 'id,type,name,average_duration_minutes,created_at,updated_at',
        'external_service_aliases' => 'id,service_id,source,external_type,external_name,normalized_external_type,normalized_external_name,created_at,updated_at',
        'service_user' => 'id,user_id,service_id,created_at,updated_at',
        'department_user' => 'id,user_id,department_code,created_at,updated_at',
        'external_delegataires' => 'id,source,external_id,name,company_name,email,phone,is_active,last_synced_at,created_at,updated_at,payload',
        'mail_senders' => 'id,name,mail_from_address,mail_from_name,mail_admin_email,logo_path,is_active,created_by_user_id,updated_by_user_id,created_at,updated_at,deleted_at,mail_host,mail_port,mail_username,mail_password,mail_encryption',
        'mail_templates' => 'id,name,slug,mail_sender_id,subject,markdown_body,logo_path,is_active,created_by_user_id,updated_by_user_id,created_at,updated_at,deleted_at',
        'technician_absences' => 'id,technician_id,created_by,starts_at,ends_at,reason,created_at,updated_at',
    ];
    foreach ($tables as $table => $columns) {
        $definitions = array_map(static fn ($column) => $column === 'id' ? 'id INTEGER PRIMARY KEY' : $column.' TEXT', explode(',', $columns));
        $pdo->exec('CREATE TABLE '.$table.' ('.implode(',', $definitions).')');
    }

    return $pdo;
}

test('reference collector includes empty tables explicitly', function () {
    $collect = require __DIR__.'/../../tools/fixtures/collect-techcalendar-references.php';
    $pdo = techCalendarReferenceConnection();
    $result = $collect($pdo);

    expect($result['complete'])->toBeTrue()
        ->and($result['format'])->toBe('techcalendar.reference-snapshot')
        ->and($result['tables'])->toHaveCount(10)
        ->and($result['tables']['mail_templates']['rows'])->toBe([])
        ->and($pdo->inTransaction())->toBeFalse();
});

test('reference collector preserves relations and deleted identities but excludes credentials and arbitrary payloads', function () {
    $pdo = techCalendarReferenceConnection();
    $pdo->exec("INSERT INTO users (id, first_name, email, deleted_at, password, remember_token, new_secret_field)
        VALUES (19, 'Test', 'user@example.test', '2026-01-01', 'SECRET_PASSWORD', 'SECRET_TOKEN', 'SECRET_FUTURE')");
    $pdo->exec("INSERT INTO departments (code, name) VALUES ('01', 'Ain'), ('2A', 'Corse-du-Sud')");
    $pdo->exec("INSERT INTO services (id, type, name) VALUES (41, 'COFFRAC', 'BAR EN 101')");
    $pdo->exec('INSERT INTO service_user (id, user_id, service_id) VALUES (1, 19, 41)');
    $pdo->exec("INSERT INTO department_user (id, user_id, department_code) VALUES (1, 19, '01')");
    $pdo->exec("INSERT INTO external_service_aliases (id, service_id, source, external_name)
        VALUES (2, 41, 'coffrac', 'BAR EN 101 Isolation en combles perdus')");
    $pdo->exec("INSERT INTO external_delegataires (id, source, external_id, payload) VALUES (5, 'coffrac', '73', 'SECRET_PAYLOAD')");
    $pdo->exec("INSERT INTO mail_senders (id, mail_host, mail_username, mail_password, mail_from_name)
        VALUES (7, 'SECRET_SMTP_HOST', 'SECRET_SMTP_LOGIN', 'SECRET_SMTP_PASSWORD', 'Expediteur')");
    $pdo->exec("INSERT INTO mail_templates (id, mail_sender_id, markdown_body) VALUES (9, 7, '# Bonjour {{ prenom }}')");
    $before = $pdo->query('SELECT * FROM users')->fetchAll();
    $collect = require __DIR__.'/../../tools/fixtures/collect-techcalendar-references.php';
    $result = $collect($pdo);

    expect(json_encode($result))->not->toContain('SECRET_')
        ->and($result['tables']['users']['rows'][0]['deleted_at'])->toBe('2026-01-01')
        ->and($result['tables']['service_user']['rows'][0]['service_id'])->toBe('41')
        ->and($result['tables']['department_user']['rows'][0]['department_code'])->toBe('01')
        ->and($result['tables']['external_delegataires']['rows'][0]['external_id'])->toBe('73')
        ->and($result['tables']['mail_templates']['rows'][0]['mail_sender_id'])->toBe('7')
        ->and($result['tables']['mail_templates']['rows'][0]['markdown_body'])->toBe('# Bonjour {{ prenom }}')
        ->and($pdo->query('SELECT * FROM users')->fetchAll())->toBe($before);
});

test('reference collector paginates without losing records or duplicating sparse ids', function () {
    $pdo = techCalendarReferenceConnection();
    $insert = $pdo->prepare('INSERT INTO services (id, name) VALUES (?, ?)');
    for ($i = 1; $i <= 2001; $i++) {
        $insert->execute([$i * 3, 'Service '.$i]);
    }
    $collect = require __DIR__.'/../../tools/fixtures/collect-techcalendar-references.php';
    $result = $collect($pdo);

    expect($result['tables']['services']['count'])->toBe(2001)
        ->and(array_column($result['tables']['services']['rows'], 'id'))->toBe(range(3, 6003, 3));
});

test('reference collector fails instead of pretending a missing table is empty', function () {
    $pdo = techCalendarReferenceConnection();
    $pdo->exec('DROP TABLE mail_templates');
    $collect = require __DIR__.'/../../tools/fixtures/collect-techcalendar-references.php';

    expect(fn () => $collect($pdo))->toThrow(PDOException::class)
        ->and($pdo->inTransaction())->toBeFalse();
});

test('reference collector does not interfere with an existing transaction', function () {
    $pdo = techCalendarReferenceConnection();
    $pdo->beginTransaction();
    $collect = require __DIR__.'/../../tools/fixtures/collect-techcalendar-references.php';

    expect(fn () => $collect($pdo))->toThrow(RuntimeException::class, 'transaction deja ouverte')
        ->and($pdo->inTransaction())->toBeTrue();
    $pdo->rollBack();
});

test('planning collection limits identities to technicians and does not require mail or external client tables', function () {
    $pdo = techCalendarReferenceConnection();
    $pdo->exec("INSERT INTO users (id, email, role, department_code, deleted_at, password) VALUES
        (1, 'tech@example.test', 2, '75', NULL, 'SECRET_PASSWORD'),
        (2, 'inactive@example.test', 2, '01', '2026-01-01', 'SECRET_PASSWORD'),
        (3, 'external@example.test', 3, '33', NULL, 'SECRET_PASSWORD')");
    $pdo->exec("INSERT INTO department_user (id, user_id, department_code) VALUES (1, 1, '75'), (2, 2, '01'), (3, 3, '33')");
    $pdo->exec('DROP TABLE mail_templates');
    $pdo->exec('DROP TABLE external_delegataires');
    $before = $pdo->query('SELECT * FROM users')->fetchAll();
    $collect = require __DIR__.'/../../tools/fixtures/collect-techcalendar-references.php';
    $result = $collect($pdo, true);
    expect($result['format'])->toBe('techcalendar.planning-snapshot')
        ->and($result['complete'])->toBeTrue()
        ->and(array_keys($result['tables']))->toBe(['departments', 'users', 'services', 'department_user'])
        ->and(array_column($result['tables']['users']['rows'], 'id'))->toBe([1, 2])
        ->and(array_column($result['tables']['department_user']['rows'], 'user_id'))->toBe(['1', '2'])
        ->and($result['tables']['users']['columns'])->not->toContain('address', 'phone', 'first_name', 'password')
        ->and(json_encode($result))->not->toContain('SECRET_PASSWORD', 'external@example.test')
        ->and($pdo->query('SELECT * FROM users')->fetchAll())->toBe($before)
        ->and($pdo->inTransaction())->toBeFalse();
});
