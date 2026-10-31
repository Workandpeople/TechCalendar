<?php

declare(strict_types=1);

use App\Services\Mymar\MymarBookingCommand;
use Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap;
use Cofrac2\Deploy\Mymar\MymarBookingFailure;
use Cofrac2\Deploy\Mymar\MymarBookingSource;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;
use Cofrac2\Deploy\MymarReadOnlyAudit;
use Cofrac2\Deploy\MymarWorkflowAudit;

umask(0077);
ini_set('display_errors', '0');
require __DIR__.'/MymarBookingSqlAccess.php';

function bookingJson(string $path): array
{
    if (realpath($path) !== $path || ! is_file($path) || filesize($path) > 131072
        || fileowner($path) !== 0 || (fileperms($path) & 0077) !== 0) {
        throw new RuntimeException('mymar.invalid_private_booking_input');
    }

    return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}

function bookingWrite(string $path, array $data, bool $replace = false): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $temporary = $replace ? $path.'.new-'.bin2hex(random_bytes(5)) : $path;
    $stream = fopen($temporary, 'xb');
    if ($stream === false || fwrite($stream, $json) !== strlen($json) || ! fclose($stream)
        || ! chmod($temporary, 0600) || ($replace && ! rename($temporary, $path))) {
        throw new RuntimeException('mymar.private_booking_write_failed');
    }
}

function bookingAdmin(#[SensitiveParameter] string $sql, string $run): string
{
    $process = proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=3', '20', '/usr/bin/mysql',
        '--defaults-file=/etc/mysql/debian.cnf', '--protocol=socket', '--socket=/var/run/mysqld/mysqld.sock',
        '--connect-timeout=3', '--skip-reconnect', '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $run.'/mysql-private.stderr', 'a']], $pipes, null,
        ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root', 'MYSQL_HISTFILE' => '/dev/null', 'MYSQL_TEST_LOGIN_FILE' => '/dev/null']);
    if (! is_resource($process)) {
        throw new RuntimeException('mymar.booking_maintenance_failed');
    }
    $offset = 0;
    while ($offset < strlen($sql)) {
        $written = @fwrite($pipes[0], substr($sql, $offset));
        if ($written === false || $written === 0) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('mymar.booking_maintenance_failed');
        }
        $offset += $written;
    }
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1], 1048577);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($result) || strlen($result) > 1048576) {
        throw new RuntimeException('mymar.booking_maintenance_failed');
    }

    return trim($result);
}

function bookingRelock(string $run): bool
{
    if (! is_file($run.'/sql-account.private.json')) {
        return true;
    }
    $user = bookingJson($run.'/sql-account.private.json')['database_user'] ?? '';
    if (! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $user)) {
        throw new RuntimeException('mymar.invalid_booking_account');
    }
    bookingAdmin("ALTER USER IF EXISTS '$user'@'localhost' ACCOUNT LOCK;", $run);
    $locked = bookingAdmin("SELECT account_locked FROM mysql.user WHERE User='$user' AND Host='localhost';", $run);

    return $locked === 'Y' || $locked === '';
}

$run = null;
$validatedRun = false;
$phase = 'preflight';
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || ! extension_loaded('pdo_mysql') || ! function_exists('mb_check_encoding') || gethostname() !== 'ns365601' || $argc !== 3
        || ! in_array($argv[1], ['--prepare-disabled', '--lock-only'], true)) {
        throw new RuntimeException('mymar.invalid_booking_preparation_invocation');
    }
    $run = realpath($argv[2]);
    $state = '/var/lib/cofrac2-production';
    if ($run === false || $run !== $argv[2] || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-preparation-[A-Za-z0-9]+$~D', $run)
        || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700
        || realpath($state) !== $state || fileowner($state) !== 0 || (fileperms($state) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_booking_preparation_directory');
    }
    $validatedRun = true;
    if ($argv[1] === '--lock-only') {
        if (! bookingRelock($run)) {
            throw new RuntimeException('mymar.booking_account_lock_not_confirmed');
        }
        exit;
    }
    foreach (['MymarReadOnlyAudit', 'MymarWorkflowAudit', 'MymarApprovedTechnicianMap', 'MymarBookingCommand',
        'MymarObservation', 'MymarObservationPolicy', 'MymarBookingFailure', 'MymarBookingSource'] as $class) {
        require __DIR__.'/'.$class.'.php';
    }
    $request = bookingJson(__DIR__.'/REQUEST.json');
    $revision = $request['runtime_revision'] ?? '';
    $mapPath = $request['mapping_path'] ?? '';
    $mapSha = $request['mapping_sha256'] ?? '';
    $marker = $state.'/MYMAR-BOOKING-PREPARED.json';
    if (($request['format'] ?? null) !== 'cofrac.mymar-booking-preparation-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $revision) || ! preg_match('/^[a-f0-9]{64}$/D', $mapSha)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $mapPath)
        || ($request['technical_author_approved'] ?? null) !== true || file_exists($marker) || is_link($marker)) {
        throw new RuntimeException('mymar.booking_preparation_already_present_or_invalid');
    }
    if ((bookingJson($state.'/RUNTIME-PREPARED.json')['revision'] ?? null) !== $revision
        || ! is_file($state.'/INTERNAL-SITE-OPEN.json') || is_link($state.'/INTERNAL-SITE-OPEN.json')
        || ! hash_equals($mapSha, hash_file('sha256', $mapPath))) {
        throw new RuntimeException('mymar.booking_release_or_mapping_changed');
    }
    $map = bookingJson($mapPath);
    $approvalPath = dirname($mapPath).'/bundle/approved-identities.json';
    $approval = bookingJson($approvalPath);
    if (($map['format'] ?? null) !== 'cofrac.mymar-approved-technician-map'
        || ! hash_equals($map['approval_sha256'] ?? '', hash_file('sha256', $approvalPath))) {
        throw new RuntimeException('mymar.booking_identity_approval_changed');
    }
    $source = MymarWorkflowAudit::sources('/home/ubuntu/www/MYMAR');
    $proof = array_map(static fn ($file) => $file['sha256'], $source['files']);
    if ($proof !== ($request['workflow_files'] ?? null)) {
        throw new RuntimeException('mymar.booking_source_changed_since_audit');
    }
    $configurationProof = [];
    foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
        if (! (MymarReadOnlyAudit::identity($root, $schema)['sql_eligible'] ?? false)) {
            throw new RuntimeException('mymar.booking_source_identity_refused');
        }
        foreach (['.env', 'bootstrap/cache/config.php'] as $relative) {
            $path = $root.'/'.$relative;
            $configurationProof[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
    }
    $server = json_decode(bookingAdmin("SELECT JSON_OBJECT('user',CURRENT_USER(),'port',@@port,'version',@@version,'roles',@@mandatory_roles);", $run), true, 8, JSON_THROW_ON_ERROR);
    if (($server['user'] ?? null) !== 'debian-sys-maint@localhost' || ($server['port'] ?? null) !== 3306
        || ! str_starts_with($server['version'] ?? '', '8.0.') || version_compare(explode('-', $server['version'])[0], '8.0.22', '<')
        || ($server['roles'] ?? null) !== '') {
        throw new RuntimeException('mymar.booking_maintenance_identity_refused');
    }
    $rows = array_map(static fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), explode("\n", bookingAdmin(MymarApprovedTechnicianMap::sql($approval), $run)));
    $fresh = MymarApprovedTechnicianMap::validate($approval, $rows, new DateTimeImmutable);
    if ($fresh['bindings'] !== ($map['bindings'] ?? null)) {
        throw new RuntimeException('mymar.booking_mapping_drift');
    }
    $targetIds = implode(',', array_column($fresh['bindings'], 'cofrac_user_id'));
    if ((int) bookingAdmin("SELECT COUNT(*) FROM cofrac2production.users WHERE id IN ($targetIds) AND role=2 AND admin=0 AND external_client_id IS NULL AND deleted_at IS NULL;", $run) !== count($fresh['bindings'])) {
        throw new RuntimeException('mymar.booking_target_not_active_internal_technician');
    }
    $preflight = json_decode(bookingAdmin(<<<'SQL'
SELECT JSON_OBJECT(
 'schema_exists',(SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='cofrac_mymar_integration'),
 'author_exists',(SELECT COUNT(*) FROM MYMAR.users WHERE email='cofrac-planning@integration.invalid'),
 'triggers',(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE (TRIGGER_SCHEMA='MYMAR' AND EVENT_OBJECT_TABLE IN ('users','dossiers','dossier_rdv','dossier_historiques')) OR TRIGGER_SCHEMA='cofrac_mymar_integration'),
 'unexpected_required_user_fields',(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR' AND TABLE_NAME='users' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%' AND COLUMN_NAME NOT IN ('name','email','password','enabled','created_at','updated_at')));
SQL, $run), true, 8, JSON_THROW_ON_ERROR);
    if (array_keys($preflight) === [] || count($preflight) !== 4 || array_filter($preflight, static fn ($value) => $value !== 0)) {
        throw new RuntimeException('mymar.booking_new_resources_or_schema_refused');
    }
    $availableColumns = explode("\n", bookingAdmin("SELECT CONCAT(c.TABLE_NAME,'.',c.COLUMN_NAME) FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA='MYMAR' AND t.ENGINE='InnoDB' AND c.TABLE_NAME IN ('dossiers','clients','dossier_rdv','dossier_historiques','users','roles','model_has_roles','status') ORDER BY c.TABLE_NAME,c.COLUMN_NAME LIMIT 1001;", $run));
    foreach (MymarBookingSqlAccess::privileges() as $table => $operations) {
        if (! str_starts_with($table, 'MYMAR.')) {
            continue;
        }
        foreach ($operations as $columns) {
            foreach ($columns as $column) {
                if (! in_array(substr($table, 6).'.'.$column, $availableColumns, true)) {
                    throw new RuntimeException('mymar.booking_source_schema_changed');
                }
            }
        }
    }
    if (bookingAdmin("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR' AND ((TABLE_NAME='dossier_historiques' AND COLUMN_NAME='text' AND CHARACTER_MAXIMUM_LENGTH>=255) OR (TABLE_NAME='dossiers' AND COLUMN_NAME='sub_statut' AND CHARACTER_MAXIMUM_LENGTH>=100) OR (TABLE_NAME='dossiers' AND COLUMN_NAME='commentaire_status' AND CHARACTER_MAXIMUM_LENGTH>=255) OR (TABLE_NAME='dossier_rdv' AND COLUMN_NAME='date_rdv' AND DATA_TYPE='datetime') OR (TABLE_NAME='dossier_rdv' AND COLUMN_NAME='duree' AND DATA_TYPE IN ('tinyint','smallint','mediumint','int','bigint')));", $run) !== '5') {
        throw new RuntimeException('mymar.booking_source_storage_contract_refused');
    }
    $credentials = MymarBookingSqlAccess::credentials();
    $user = $credentials['database_user'];
    if (bookingAdmin("SELECT COUNT(*) FROM mysql.user WHERE User='$user';", $run) !== '0') {
        throw new RuntimeException('mymar.booking_generated_account_exists');
    }
    bookingWrite($marker, ['stage' => 'preparation_started', 'run_directory' => $run]);
    bookingWrite($run.'/sql-account.private.json', ['database_user' => $user]);
    $phase = 'new_journal_and_locked_account';
    bookingAdmin(file_get_contents(__DIR__.'/journal.sql'), $run);
    bookingAdmin(MymarBookingSqlAccess::createSql($user, $credentials['database_password']), $run);
    $phase = 'new_disabled_technical_author';
    // This password is random, unrecorded and never disclosed. No roles, token or login session is created.
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
    if (! is_string($passwordHash) || ! preg_match('~^\$2[aby]\$[0-9]{2}\$[A-Za-z0-9./]{53}$~D', $passwordHash)) {
        throw new RuntimeException('mymar.booking_technical_password_failed');
    }
    $author = bookingAdmin("SET SESSION time_zone='+00:00'; START TRANSACTION;"
        ." INSERT INTO MYMAR.users (name,email,password,enabled,created_at,updated_at) VALUES ('Cofrac planning','cofrac-planning@integration.invalid','$passwordHash',0,UTC_TIMESTAMP(),UTC_TIMESTAMP());"
        .' SELECT LAST_INSERT_ID(); COMMIT;', $run);
    unset($passwordHash);
    if (! preg_match('/^[1-9][0-9]{0,17}$/D', $author)) {
        throw new RuntimeException('mymar.booking_technical_author_failed');
    }
    $config = ['format' => 'cofrac.mymar-booking-preparation', 'version' => 1, 'read_enabled' => false, 'writes_enabled' => false,
        'technical_author_id' => (int) $author, 'technician_ids' => array_column($fresh['bindings'], 'source_technician_id'),
        'bindings' => $fresh['bindings'], 'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours', ...$credentials];
    bookingWrite($run.'/config.private.json', $config);
    $phase = 'read_only_restricted_probe';
    bookingAdmin("ALTER USER '$user'@'localhost' ACCOUNT UNLOCK;", $run);
    try {
        $database = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4', $user, $credentials['database_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    } finally {
        if (! bookingRelock($run)) {
            throw new RuntimeException('mymar.booking_account_lock_not_confirmed');
        }
    }
    $adapter = new MymarBookingSource($database, (int) $author, $config['technician_ids'], enabled: true, writesEnabled: false);
    $page = $adapter->pending(0, 1);
    $dummy = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => '11111111-1111-4111-8111-111111111111', 'action' => 'problem', 'dossier_id' => 1,
        'expected_revision' => str_repeat('0', 64), 'cofrac_actor_id' => 1, 'comment' => 'Preparation sans execution']);
    try {
        $adapter->execute($dummy, new DateTimeImmutable);
        throw new RuntimeException('mymar.booking_write_gate_not_enforced');
    } catch (MymarBookingFailure $error) {
        if ($error->reason !== 'booking_disabled') {
            throw $error;
        }
    }
    unset($adapter, $database);
    if (bookingAdmin('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands;', $run) !== '0'
        || bookingAdmin("SELECT COUNT(*) FROM MYMAR.users u WHERE u.id=$author AND u.enabled=0 AND NOT EXISTS (SELECT 1 FROM MYMAR.model_has_roles r WHERE r.model_id=u.id AND r.model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User')));", $run) !== '1'
        || ! MymarWorkflowAudit::unchanged('/home/ubuntu/www/MYMAR', $source['proof'])) {
        throw new RuntimeException('mymar.booking_final_verification_failed');
    }
    foreach ($configurationProof as $path => $hash) {
        if ($hash === null ? file_exists($path) : ! hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('mymar.booking_application_configuration_changed');
        }
    }
    $summary = ['format' => 'cofrac.mymar-booking-backend-preparation', 'version' => 1, 'complete' => true,
        'prepared_at_utc' => gmdate(DATE_ATOM), 'run_directory' => $run, 'runtime_revision' => $revision,
        'technical_author_created' => true, 'technical_author_disabled' => true, 'technical_author_has_roles' => false,
        'new_sql_account_locked' => bookingRelock($run), 'new_journal_schema_created' => true, 'journal_rows' => 0,
        'pending_items_probed' => count($page['items']), 'validated_technician_links' => count($fresh['bindings']),
        'existing_accounts_modified' => false, 'appointments_modified' => false, 'dossiers_modified' => false,
        'histories_modified' => false, 'read_enabled' => false, 'writes_enabled' => false,
        'application_bootstrapped' => false, 'http_listener_started' => false, 'services_changed' => false, 'messages_sent' => false];
    bookingWrite($run.'/summary.json', $summary);
    bookingWrite($marker, ['stage' => 'prepared_disabled', 'run_directory' => $run, 'runtime_revision' => $revision,
        'read_enabled' => false, 'writes_enabled' => false], true);
    echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($validatedRun) {
        try {
            bookingWrite($run.'/failure.private.json', ['phase' => $phase,
                'code' => preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.booking_preparation_failed',
                'diagnostic' => $error instanceof MymarBookingFailure ? $error->diagnostic : [], 'writes_enabled' => false]);
            bookingRelock($run);
        } catch (Throwable) {
            fwrite(STDERR, "STOP : verifier imperativement le verrouillage du nouveau compte SQL.\n");
        }
    }
    fwrite(STDERR, "STOP : preparation du backend MAR incomplete ; aucun placement active. Diagnostic prive conserve.\n");
    exit(1);
}
