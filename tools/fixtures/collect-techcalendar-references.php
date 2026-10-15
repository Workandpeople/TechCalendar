<?php

declare(strict_types=1);

// Explicit columns: new production fields are never exported implicitly.
return static function (PDO $pdo): array {
    $columns = [
        'departments' => 'code,name,created_at,updated_at',
        'users' => 'id,first_name,last_name,email,role,admin,phone,address,department_code,latitude,longitude,day_start_time,day_end_time,break_duration_minutes,created_at,updated_at,deleted_at',
        'services' => 'id,type,name,average_duration_minutes,created_at,updated_at',
        'external_service_aliases' => 'id,service_id,source,external_type,external_name,normalized_external_type,normalized_external_name,created_at,updated_at',
        'service_user' => 'id,user_id,service_id,created_at,updated_at',
        'department_user' => 'id,user_id,department_code,created_at,updated_at',
        'external_delegataires' => 'id,source,external_id,name,company_name,email,phone,is_active,last_synced_at,created_at,updated_at',
        'mail_senders' => 'id,name,mail_from_address,mail_from_name,mail_admin_email,logo_path,is_active,created_by_user_id,updated_by_user_id,created_at,updated_at,deleted_at',
        'mail_templates' => 'id,name,slug,mail_sender_id,subject,markdown_body,logo_path,is_active,created_by_user_id,updated_by_user_id,created_at,updated_at,deleted_at',
        'technician_absences' => 'id,technician_id,created_by,starts_at,ends_at,reason,created_at,updated_at',
    ];

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (! in_array($driver, ['mysql', 'sqlite'], true) || $pdo->inTransaction()) {
        throw new RuntimeException('Connexion non prise en charge ou transaction deja ouverte.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $report = [
        'format' => 'techcalendar.reference-snapshot',
        'version' => 1,
        'source' => 'techcalendar',
        'exported_at' => gmdate(DATE_ATOM),
        'complete' => false,
        'consistency' => 'repeatable-read',
        'contains_personal_data' => true,
        'excluded' => ['passwords', 'tokens', 'sessions', 'smtp_connection', 'settings', 'raw_api_payloads', 'file_contents'],
        'tables' => [],
    ];

    foreach ($columns as $table => $fieldList) {
        if ($driver === 'mysql') {
            $statement = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?');
            $statement->execute([$table, 'BASE TABLE']);
            if (strtolower((string) $statement->fetchColumn()) !== 'innodb') {
                throw new RuntimeException("Table absente ou non transactionnelle : {$table}. Export annule sans verrouillage.");
            }
        }

        // Validate the complete schema before exporting any records; never skip a table silently.
        $select = implode(', ', array_map(static fn (string $field): string => '`'.$field.'`', explode(',', $fieldList)));
        $pdo->query("SELECT {$select} FROM `{$table}` WHERE 1 = 0")->closeCursor();
    }

    if ($driver === 'mysql') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    } else {
        $pdo->beginTransaction();
    }

    try {
        foreach ($columns as $table => $fieldList) {
            $fields = explode(',', $fieldList);
            $key = $table === 'departments' ? 'code' : 'id';
            $select = implode(', ', array_map(static fn (string $field): string => '`'.$field.'`', $fields));
            $rows = [];
            $cursor = null;

            do {
                $where = $cursor === null ? '' : " WHERE `{$key}` > ?";
                $statement = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(10000) */ {$select} FROM `{$table}`{$where} ORDER BY `{$key}` LIMIT 1000");
                $statement->execute($cursor === null ? [] : [$cursor]);
                $page = $statement->fetchAll(PDO::FETCH_ASSOC);
                $statement->closeCursor();

                foreach ($page as $row) {
                    $rows[] = $row;
                    $cursor = $row[$key];
                }

                if (count($rows) > 50000) {
                    throw new RuntimeException("Export de references trop volumineux : {$table}. Utiliser un export en flux.");
                }
            } while (count($page) === 1000);

            $report['tables'][$table] = ['columns' => $fields, 'count' => count($rows), 'rows' => $rows];
        }

        $report['complete'] = true;

        return $report;
    } finally {
        $pdo->rollBack();
    }
};
