<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap;
use Cofrac2\Deploy\Mymar\MymarGatewayConfig;
use Cofrac2\Deploy\Mymar\MymarGatewayPreparation;
use Cofrac2\Deploy\Mymar\MymarSqlGatewayPreparation;
use Cofrac2\Deploy\MymarReadOnlyAudit;

umask(0077);
ini_set('display_errors', '0');

function sqlGatewayJson(string $path, int $maximum = 131072): array
{
    if (realpath($path) !== $path || ! is_file($path) || filesize($path) > $maximum) {
        throw new RuntimeException('mymar.invalid_preparation_input');
    }
    $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('mymar.invalid_preparation_input');
    }

    return $value;
}

function sqlGatewayWrite(string $path, array $value, bool $replace = false): void
{
    $content = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $file = $replace ? $path.'.new-'.bin2hex(random_bytes(6)) : $path;
    $handle = fopen($file, 'x');
    if ($handle === false || fwrite($handle, $content) !== strlen($content) || ! fclose($handle) || ! chmod($file, 0600)
        || ($replace && ! rename($file, $path))) {
        throw new RuntimeException('mymar.preparation_write_failed');
    }
}

function sqlGatewayAdmin(#[SensitiveParameter] string $sql, string $run): string
{
    $process = proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=3', '20', '/usr/bin/mysql',
        '--defaults-file=/etc/mysql/debian.cnf', '--protocol=socket', '--socket=/var/run/mysqld/mysqld.sock',
        '--connect-timeout=3', '--skip-reconnect', '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $run.'/mysql-private.stderr', 'a']], $pipes, null,
        ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root', 'MYSQL_HISTFILE' => '/dev/null', 'MYSQL_TEST_LOGIN_FILE' => '/dev/null']);
    if (! is_resource($process)) {
        throw new RuntimeException('mymar.maintenance_client_failed');
    }
    $written = 0;
    while ($written < strlen($sql)) {
        $bytes = @fwrite($pipes[0], substr($sql, $written));
        if ($bytes === false || $bytes === 0) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('mymar.maintenance_input_failed');
        }
        $written += $bytes;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 1048577);
    fclose($pipes[1]);
    $result = proc_close($process);
    if ($result !== 0 || ! is_string($output) || strlen($output) > 1048576) {
        throw new RuntimeException('mymar.maintenance_sql_failed');
    }

    return trim($output);
}

function sqlGatewayRelock(string $run): bool
{
    $file = $run.'/sql-account.private.json';
    if (! is_file($file)) {
        return true;
    }
    $account = sqlGatewayJson($file);
    $user = $account['database_user'] ?? '';
    $current = sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'locked'), $run);
    if ($current === 'Y' || $current === '') {
        return true;
    }
    sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'lock'), $run);
    $locked = sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'locked'), $run);

    return $locked === 'Y' || $locked === '';
}

$run = null;
$marker = null;
$validatedRun = false;
$phase = 'preflight';
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || ! extension_loaded('pdo_mysql') || ! function_exists('proc_open') || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 3 || ! in_array($argv[1], ['--prepare-and-probe', '--lock-only'], true)) {
        throw new RuntimeException('mymar.invalid_sql_preparation_invocation');
    }
    $run = $argv[2];
    $state = '/var/lib/cofrac2-production';
    if (realpath($state) !== $state || (fileperms($state) & 0777) !== 0700 || fileowner($state) !== 0
        || realpath($run) !== $run || ! is_dir($run) || (fileperms($run) & 0777) !== 0700 || fileowner($run) !== 0
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-sql-gateway-[A-Za-z0-9]+$~D', $run)) {
        throw new RuntimeException('mymar.invalid_sql_preparation_directory');
    }
    $validatedRun = true;
    require __DIR__.'/MymarSqlGatewayPreparation.php';
    if ($argv[1] === '--lock-only') {
        if (! sqlGatewayRelock($run)) {
            throw new RuntimeException('mymar.sql_account_lock_not_confirmed');
        }
        exit(0);
    }
    require __DIR__.'/MymarApprovedTechnicianMap.php';
    require __DIR__.'/MymarReadOnlyAudit.php';
    require __DIR__.'/MymarGatewayPreparation.php';
    $request = sqlGatewayJson(__DIR__.'/REQUEST.json');
    $revision = $request['source_revision'] ?? '';
    $mapPath = $request['mapping_path'] ?? '';
    $mapSha = $request['mapping_sha256'] ?? '';
    if (($request['format'] ?? null) !== 'cofrac.mymar-sql-gateway-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $revision) || ! preg_match('/^[a-f0-9]{64}$/D', $mapSha)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $mapPath)
        || realpath($mapPath) !== $mapPath || ! is_file($mapPath) || fileowner($mapPath) !== 0
        || (fileperms($mapPath) & 0777) !== 0600 || (fileperms(dirname($mapPath)) & 0777) !== 0700
        || ! hash_equals($mapSha, hash_file('sha256', $mapPath))) {
        throw new RuntimeException('mymar.approved_mapping_not_pinned');
    }
    $prepared = sqlGatewayJson($state.'/RUNTIME-PREPARED.json');
    if (($prepared['revision'] ?? null) !== $revision || ! is_file($state.'/INTERNAL-SITE-OPEN.json') || is_link($state.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('mymar.unexpected_open_release');
    }
    $codeSource = $state.'/mymar-gateway-prepared/'.$revision;
    $disabled = sqlGatewayJson($codeSource.'/DISABLED.json');
    if (($disabled['format'] ?? null) !== 'cofrac.mymar-disabled-preparation' || ($disabled['version'] ?? null) !== 1
        || ($disabled['revision'] ?? null) !== $revision || ($disabled['enabled'] ?? null) !== false
        || ($disabled['configured'] ?? null) !== false || array_keys($disabled['files'] ?? []) !== MymarGatewayPreparation::FILES) {
        throw new RuntimeException('mymar.disabled_gateway_preparation_required');
    }
    $map = sqlGatewayJson($mapPath);
    $approvalPath = dirname($mapPath).'/bundle/approved-identities.json';
    $approval = sqlGatewayJson($approvalPath);
    if (($map['format'] ?? null) !== 'cofrac.mymar-approved-technician-map' || ($map['version'] ?? null) !== 1
        || ($map['source_revision'] ?? null) !== $revision || ($map['connector_enabled'] ?? null) !== false
        || ($map['can_affect_planning'] ?? null) !== false || ($map['requires_runtime_revalidation'] ?? null) !== true
        || ! hash_equals($map['approval_sha256'] ?? '', hash_file('sha256', $approvalPath))) {
        throw new RuntimeException('mymar.identity_approval_changed');
    }
    $parent = $state.'/mymar-sql-connections';
    if (! file_exists($parent) && ! is_link($parent) && ! mkdir($parent, 0700)) {
        throw new RuntimeException('mymar.private_connection_directory_failed');
    }
    if (realpath($parent) !== $parent || ! is_dir($parent) || fileowner($parent) !== 0 || (fileperms($parent) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_connection_directory');
    }
    $marker = $parent.'/'.$mapSha.'.json';
    if (file_exists($marker) || is_link($marker)) {
        $marker = null;
        throw new RuntimeException('mymar.connection_already_prepared_or_requires_review');
    }
    $proof = [];
    foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
        if (realpath($root) !== $root || ! (MymarReadOnlyAudit::identity($root, $schema)['sql_eligible'] ?? false)) {
            throw new RuntimeException('mymar.source_identity_not_confirmed');
        }
        foreach (['.env', 'bootstrap/cache/config.php'] as $relative) {
            $path = $root.'/'.$relative;
            if (is_link($path) || (file_exists($path) && realpath($path) !== $path)) {
                throw new RuntimeException('mymar.indirect_source_configuration');
            }
            $proof[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
    }
    foreach ([$mapPath, $approvalPath, $state.'/RUNTIME-PREPARED.json', $state.'/INTERNAL-SITE-OPEN.json', $codeSource.'/DISABLED.json'] as $path) {
        $proof[$path] = hash_file('sha256', $path);
    }
    foreach ($disabled['files'] as $relative => $sha) {
        $path = $codeSource.'/'.$relative;
        if (realpath($path) !== $path || ! is_file($path) || fileowner($path) !== 0 || ! hash_equals($sha, hash_file('sha256', $path))) {
            throw new RuntimeException('mymar.gateway_code_changed');
        }
        $proof[$path] = $sha;
        $target = $run.'/code/'.$relative;
        if ((! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true)) || ! copy($path, $target) || ! chmod($target, 0600)) {
            throw new RuntimeException('mymar.private_code_copy_failed');
        }
    }
    $phase = 'source_and_mapping_validation';
    $server = json_decode(sqlGatewayAdmin("SELECT JSON_OBJECT('user',CURRENT_USER(),'port',@@port,'version',@@version,'mandatory_roles',@@mandatory_roles);", $run), true, 8, JSON_THROW_ON_ERROR);
    if (($server['user'] ?? null) !== 'debian-sys-maint@localhost' || ($server['port'] ?? null) !== 3306
        || ! is_string($server['version'] ?? null) || ! str_starts_with($server['version'], '8.0.') || ($server['mandatory_roles'] ?? null) !== '') {
        throw new RuntimeException('mymar.unexpected_maintenance_identity_or_mandatory_roles');
    }
    $raw = sqlGatewayAdmin(MymarApprovedTechnicianMap::sql($approval), $run);
    $rows = array_map(static fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), explode("\n", $raw));
    $fresh = MymarApprovedTechnicianMap::validate($approval, $rows, new DateTimeImmutable('now'));
    if ($fresh['bindings'] !== ($map['bindings'] ?? null)) {
        throw new RuntimeException('mymar.approved_mapping_identity_drift');
    }
    $engineRows = sqlGatewayAdmin("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('dossiers','dossier_rdv') ORDER BY TABLE_NAME;", $run);
    if ($engineRows !== "dossier_rdv\tInnoDB\ndossiers\tInnoDB") {
        throw new RuntimeException('mymar.transactional_occupation_tables_required');
    }
    foreach (['MymarObservationPolicy', 'MymarReadWindow', 'MymarObservation'] as $class) {
        require $run.'/code/app/Services/Mymar/'.$class.'.php';
    }
    foreach (['MymarGateway', 'MymarGatewayConfig', 'MymarReadOnlySource'] as $class) {
        require $run.'/code/tools/deploy/mymar/gateway/'.$class.'.php';
    }
    $credentials = MymarSqlGatewayPreparation::credentials();
    $user = $credentials['database_user'];
    if (sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'absent'), $run) !== '0') {
        throw new RuntimeException('mymar.generated_account_already_exists');
    }
    if (! mkdir($run.'/config', 0700)) {
        throw new RuntimeException('mymar.private_configuration_failed');
    }
    $configPath = $run.'/config/config.json';
    sqlGatewayWrite($configPath, array_merge(['enabled' => false, 'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours',
        'database_socket' => '/var/run/mysqld/mysqld.sock'], $credentials));
    $config = MymarGatewayConfig::load($configPath);
    $phase = 'new_restricted_sql_account';
    sqlGatewayWrite($marker, ['stage' => 'creation_started', 'run_directory' => $run, 'mapping_sha256' => $mapSha]);
    sqlGatewayWrite($run.'/sql-account.private.json', ['database_user' => $user]);
    sqlGatewayAdmin(MymarSqlGatewayPreparation::createSql($user, $credentials['database_password']), $run);
    if (sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'locked'), $run) !== 'Y') {
        throw new RuntimeException('mymar.new_account_not_locked');
    }
    $phase = 'restricted_session';
    sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'unlock'), $run);
    try {
        $database = MymarGatewayConfig::database($config);
    } finally {
        // Lock new logins immediately; the one already-open session can perform the bounded probe.
        if (! sqlGatewayRelock($run)) {
            throw new RuntimeException('mymar.sql_account_lock_not_confirmed');
        }
    }
    $phase = 'authenticated_handler_probe';
    $observation = MymarSqlGatewayPreparation::probe($config, $fresh, new DateTimeImmutable('now'), $database);
    $database = null;
    foreach ($proof as $path => $sha) {
        if ($sha === null ? file_exists($path) || is_link($path) : realpath($path) !== $path || ! is_file($path) || ! hash_equals($sha, hash_file('sha256', $path))) {
            throw new RuntimeException('mymar.source_configuration_changed');
        }
    }
    if (sqlGatewayAdmin(MymarSqlGatewayPreparation::accountSql($user, 'locked'), $run) !== 'Y' || MymarGatewayConfig::load($configPath)['enabled'] !== false) {
        throw new RuntimeException('mymar.preparation_not_disabled_at_rest');
    }
    sqlGatewayWrite($run.'/observation.json', $observation);
    $summary = ['format' => 'cofrac.mymar-sql-gateway-preparation', 'version' => 1, 'source_revision' => $revision,
        'validated_links' => count($fresh['bindings']), 'mapping_sha256' => $mapSha, 'sql_account_created' => true, 'sql_account_locked' => true,
        'column_privileges_only' => true, 'authenticated_handler_tested' => true, 'production_http_tested' => false,
        'http_listener_started' => false, 'counts' => $observation['counts'], 'issue_counts' => array_count_values(array_column($observation['issues'], 'code')),
        'private_connection_directory' => $run, 'source_files_unchanged' => true, 'business_database_writes' => false,
        'business_accounts_modified' => false, 'credentials_exported' => false, 'services_changed' => false,
        'connector_enabled' => false, 'can_affect_planning' => false];
    sqlGatewayWrite($run.'/summary.json', $summary);
    sqlGatewayWrite($marker, ['stage' => 'prepared_account_locked', 'run_directory' => $run, 'mapping_sha256' => $mapSha,
        'configuration_sha256' => hash_file('sha256', $configPath), 'source_revision' => $revision], true);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $locked = null;
    if ($validatedRun && is_file($run.'/sql-account.private.json')) {
        try {
            $locked = sqlGatewayRelock($run);
        } catch (Throwable) {
            $locked = false;
        }
    }
    if ($marker !== null && is_file($marker)) {
        try {
            sqlGatewayWrite($marker, ['stage' => 'failed_requires_review', 'run_directory' => $run,
                'phase' => $phase, 'sql_account_lock_confirmed' => $locked], true);
        } catch (Throwable) {
            // The shell's independent relock path also runs if this process fails or times out.
        }
    }
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $exception->getMessage()) ? $exception->getMessage() : 'mymar.sql_gateway_preparation_failed';
    if ($validatedRun) {
        try {
            sqlGatewayWrite($run.'/failure.private.json', ['phase' => $phase, 'reason' => $reason, 'exception_class' => get_class($exception),
                'pdo_code' => $exception instanceof PDOException ? ($exception->errorInfo[1] ?? null) : null,
                'sql_account_lock_confirmed' => $locked]);
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'STOP : '.$reason.' ; phase='.$phase.". Aucun service ou planning active.\n");
    exit(1);
}
