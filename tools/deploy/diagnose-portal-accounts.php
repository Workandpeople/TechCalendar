<?php

declare(strict_types=1);

final class PortalAccountDiagnostic
{
    public static function inspect(array $rows): array
    {
        $invalid = [];
        foreach ($rows as $row) {
            if (! (bool) $row['portal_enabled'] || $row['deleted_at'] !== null) {
                continue;
            }
            $reasons = [];
            if ($row['role'] === null || (int) $row['role'] !== 3) {
                $reasons[] = 'invalid_external_role';
            }
            if ((bool) $row['admin']) {
                $reasons[] = 'administrator_account';
            }
            if ($row['external_client_id'] === null) {
                $reasons[] = 'missing_organisation_link';
            } elseif ($row['organisation_id'] === null) {
                $reasons[] = 'organisation_not_found';
            } else {
                if (! (bool) $row['organisation_active']) {
                    $reasons[] = 'organisation_inactive';
                }
                if (! in_array($row['organisation_kind'], ['client', 'delegataire'], true)) {
                    $reasons[] = 'invalid_organisation_kind';
                }
            }
            if ($reasons === []) {
                continue;
            }
            $invalid[] = ['user_id' => (int) $row['id'], 'name' => trim($row['first_name'].' '.$row['last_name']),
                'role' => $row['role'] === null ? null : (int) $row['role'], 'admin' => (bool) $row['admin'], 'reasons' => $reasons,
                'linked_organisation_id' => $row['external_client_id'] === null ? null : (int) $row['external_client_id'],
                'organisation_name' => $row['organisation_name'], 'organisation_kind' => $row['organisation_kind'],
                'organisation_active' => $row['organisation_id'] === null ? null : (bool) $row['organisation_active']];
        }

        return ['format' => 'cofrac.portal-account-diagnostic', 'version' => 1, 'read_only' => true,
            'invalid_accounts' => array_slice($invalid, 0, 100), 'truncated' => count($invalid) > 100,
            'application_bootstrapped' => false, 'source_databases_accessed' => false, 'dossier_or_document_rows_read' => false,
            'http_requests' => false, 'accounts_changed' => false, 'business_writes' => false, 'messages_sent' => false];
    }

    public static function sql(): string
    {
        return <<<'SQL'
SELECT u.id,u.first_name,u.last_name,u.role,u.admin,u.portal_enabled,u.deleted_at,u.external_client_id,
       o.id AS organisation_id,o.name AS organisation_name,o.kind AS organisation_kind,o.is_active AS organisation_active
FROM cofrac2production.users u
LEFT JOIN cofrac2production.external_delegataires o ON o.id=u.external_client_id
WHERE u.portal_enabled=1 AND u.deleted_at IS NULL
  AND (u.role IS NULL OR u.role<>3 OR COALESCE(u.admin,0)<>0 OR u.external_client_id IS NULL OR o.id IS NULL
       OR o.is_active IS NULL OR o.is_active=0 OR o.kind IS NULL OR o.kind NOT IN ('client','delegataire'))
ORDER BY u.id LIMIT 101
SQL;
    }
}

// Accept stdin so the unprivileged runtime account needs no access to the operator's home.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($_SERVER['SCRIPT_FILENAME'] ?? '') === '') {
    ini_set('display_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
    try {
        if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 1
            || posix_geteuid() !== (posix_getpwnam('cofrac2-app')['uid'] ?? null)) {
            throw new RuntimeException('Invalid invocation.');
        }
        $root = '/var/www/cofrac2';
        require $root.'/vendor/autoload.php';
        $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
        foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CRM_V2_ENABLED' => 'true', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'cofrac2production', 'DB_USERNAME' => 'cofrac2_app',
            'DB_URL' => '', 'DB_SOCKET' => ''] as $key => $value) {
            if (($env[$key] ?? null) !== $value) {
                throw new RuntimeException('Unexpected native database configuration.');
            }
        }
        $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_EMULATE_PREPARES => false]);
        $db->exec('SET SESSION max_execution_time=1500');
        $db->exec('SET SESSION TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            if ((int) $db->query('SELECT @@transaction_read_only')->fetchColumn() !== 1) {
                throw new RuntimeException('Read-only transaction required.');
            }
            $report = PortalAccountDiagnostic::inspect($db->query(PortalAccountDiagnostic::sql())->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $db->rollBack();
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        echo json_encode(['diagnostic_failed' => true, 'read_only' => true, 'error_class' => $error::class,
            'accounts_changed' => false, 'business_writes' => false, 'http_requests' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
