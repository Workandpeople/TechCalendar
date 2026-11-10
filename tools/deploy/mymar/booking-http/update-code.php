<?php

declare(strict_types=1);

use App\Services\Mymar\MymarCalendarLabels;
use App\Services\Mymar\MymarPendingBookingPage;
use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;

// Gateway upgrade. Only the reader may gain two SELECT columns for company labels.
// Health checks remain GET-only, including on the writer.
umask(0077);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');

function upgradeRead(string $path, int $mode = 0600): array
{
    if (realpath($path) !== $path || ! is_file($path) || fileowner($path) !== 0
        || (fileperms($path) & 0777) !== $mode || filesize($path) > 131072) {
        throw new RuntimeException('mymar.unsafe_upgrade_input');
    }

    return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}

function upgradeCommand(array $command): void
{
    global $run;
    $process = proc_open(['/usr/bin/timeout', '--kill-after=3', '30', ...$command],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $run.'/commands.log', 'a'], 2 => ['file', $run.'/commands.log', 'a']], $pipes,
        null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root']);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('mymar.reader_service_command_failed');
    }
}

function upgradeProof(): array
{
    $paths = ['/var/lib/cofrac-mymar-booking-gateway/private/config.json',
        '/var/lib/cofrac2-production/RUNTIME-PREPARED.json', '/var/lib/cofrac2-production/INTERNAL-SITE-OPEN.json',
        '/var/lib/cofrac2-production/MYMAR-BOOKING-HTTP.json'];
    foreach (['/etc/apache2', '/etc/cofrac-mymar-booking-gateway', '/etc/cofrac-mymar-gateway',
        '/etc/cofrac-mymar-actions-gateway', '/etc/cofrac2-mymar', '/etc/cofrac2-mymar-booking', '/etc/cofrac2-mymar-actions'] as $directory) {
        if (! is_dir($directory)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $paths[] = $file->getPathname();
            }
        }
    }
    $proof = [];
    foreach (['/var/lib/cofrac2-production/MYMAR-ACTIONS.json', '/var/lib/cofrac-mymar-gateway/private/config.json', '/var/lib/cofrac-mymar-gateway/private/scope.json',
        '/var/lib/cofrac-mymar-actions-gateway/private/config.json'] as $optional) {
        if (file_exists($optional) || is_link($optional)) {
            $paths[] = $optional;
        }
    }
    foreach ($paths as $path) {
        if (realpath($path) !== $path || ! is_file($path)) {
            throw new RuntimeException('mymar.unsafe_upgrade_proof');
        }
        $proof[$path] = hash_file('sha256', $path);
    }
    ksort($proof);

    return $proof;
}

function upgradeFetch(string $path, #[SensitiveParameter] string $token, int $expectedStatus = 200): array
{
    global $port;
    $body = '';
    $curl = curl_init('http://127.0.0.1:'.$port.$path);
    curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 5000, CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        }]);
    if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== $expectedStatus
        || ! str_starts_with((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 'application/json')) {
        throw new RuntimeException('mymar.reader_get_check_failed');
    }

    return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
}

function upgradePendingCheck(#[SensitiveParameter] string $token): void
{
    global $writer;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        try {
            if ($writer) {
                $receipt = upgradeFetch('/v1/booking-commands/ffffffff-ffff-4fff-bfff-ffffffffffff', $token, 404);
                if (($receipt['error'] ?? null) !== 'booking_receipt_not_found' || ($receipt['outcome'] ?? null) !== 'unknown') {
                    throw new RuntimeException('mymar.writer_get_check_failed');
                }
            } else {
                MymarPendingBookingPage::fromArray(upgradeFetch('/v1/pending-bookings?after=0&limit=1', $token), 0, 1);
            }

            return;
        } catch (Throwable $error) {
            if ($attempt === 29) {
                throw $error;
            }
            usleep(200000);
        }
    }
}

function upgradeCompanyPermissions(array $config, bool $grant): void
{
    global $run;
    $username = $config['database_user'];
    if (! preg_match('/^cofrac_mymar_br_[a-f0-9]{8}$/D', $username)) {
        throw new RuntimeException('mymar.company_permissions_identity_refused');
    }
    $pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4', $username,
        $config['database_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $columns = $pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA='MYMAR' AND PRIVILEGE_TYPE='SELECT' AND GRANTEE=?");
    $columns->execute(["'$username'@'localhost'"]);
    $present = $columns->fetchAll(PDO::FETCH_ASSOC);
    $sql = '';
    foreach (['dossiers' => 'partenaire_id', 'users' => 'name'] as $table => $column) {
        $exists = in_array(['TABLE_NAME' => $table, 'COLUMN_NAME' => $column], $present, true);
        if ($grant && ! $exists) {
            $sql .= "GRANT SELECT (`$column`) ON `MYMAR`.`$table` TO '$username'@'localhost';\n";
        } elseif (! $grant && $exists) {
            $sql .= "REVOKE SELECT (`$column`) ON `MYMAR`.`$table` FROM '$username'@'localhost';\n";
        }
    }
    if ($sql !== '') {
        $process = proc_open(['/usr/bin/timeout', '--kill-after=3', '10', '/usr/bin/mysql', '--defaults-file=/etc/mysql/debian.cnf',
            '--protocol=socket', '--connect-timeout=3', '--batch'],
            [0 => ['pipe', 'r'], 1 => ['file', $run.'/permissions.log', 'a'], 2 => ['file', $run.'/permissions.log', 'a']], $pipes,
            null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root']);
        if (! is_resource($process)) {
            throw new RuntimeException('mymar.company_permissions_update_failed');
        }
        fwrite($pipes[0], $sql);
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('mymar.company_permissions_update_failed');
        }
    }
    MymarBookingSqlAccess::validateGrants($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN),
        readOnly: true, companyNames: $grant);
}

$changed = false;
$stopped = false;
$permissionsChanged = false;
try {
    $run = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 2 || ! preg_match('~^/var/lib/cofrac2-production/mymar-reader-update-[A-Za-z0-9]+$~D', $run)
        || realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_reader_upgrade_invocation');
    }
    $request = upgradeRead(__DIR__.'/REQUEST.json');
    $target = $request['target'] ?? 'reader';
    if (! in_array($target, ['reader', 'actions'], true)) {
        throw new RuntimeException('mymar.invalid_gateway_upgrade_target');
    }
    $writer = $target === 'actions';
    $port = $writer ? 19848 : 19847;
    $units = $writer ? ['cofrac-mymar-actions-fpm.service', 'cofrac-mymar-actions-http.service']
        : ['cofrac-mymar-booking-fpm.service', 'cofrac-mymar-booking-http.service'];
    $revision = $request['revision'] ?? '';
    $root = $writer ? '/var/lib/cofrac-mymar-actions-gateway' : '/var/lib/cofrac-mymar-booking-gateway';
    $code = $root.'/code';
    $backup = $root.'/code-before-'.basename($run);
    $next = $root.'/code-next-'.basename($run);
    if (($request['format'] ?? null) !== ($writer ? 'cofrac.mymar-actions-code-update' : 'cofrac.mymar-reader-code-update') || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $revision)
        || (upgradeRead('/var/lib/cofrac2-production/RUNTIME-PREPARED.json')['revision'] ?? null) !== $revision
        || (upgradeRead('/var/lib/cofrac2-production/MYMAR-BOOKING-HTTP.json')['stage'] ?? null) !== 'reader_only'
        || ($writer && (upgradeRead('/var/lib/cofrac2-production/MYMAR-ACTIONS.json')['stage'] ?? null) !== 'pilot_actions_enabled')
        || realpath($root) !== $root || fileowner($root) !== 0 || (fileperms($root) & 0022) !== 0
        || realpath($code) !== $code || fileowner($code) !== 0 || (fileperms($code) & 0022) !== 0
        || file_exists($backup) || is_link($backup) || file_exists($next) || is_link($next)) {
        throw new RuntimeException('mymar.reader_upgrade_target_not_ready');
    }
    foreach (['MymarObservation', 'MymarReadWindow', 'MymarCachedObservation', 'MymarCalendarLabels', 'MymarPendingBookingPage'] as $class) {
        require __DIR__.'/validation/'.$class.'.php';
    }
    require __DIR__.'/code/MymarBookingSqlAccess.php';
    $config = upgradeRead($root.'/private/config.json', 0640);
    if (($config['format'] ?? null) !== ($writer ? 'cofrac.mymar-actions-gateway' : 'cofrac.mymar-booking-reader') || ($config['version'] ?? null) !== 1
        || ($config['read_enabled'] ?? null) !== true || ($config['writes_enabled'] ?? null) !== $writer
        || ! preg_match($writer ? '/^cofrac_mymar_rw_[a-f0-9]{8}$/D' : '/^cofrac_mymar_br_[a-f0-9]{8}$/D', $config['database_user'] ?? '')
        || ! preg_match('/^Mb3![a-f0-9]{64}$/D', $config['database_password'] ?? '')
        || ! preg_match('/^[a-f0-9]{64}$/D', $config['token'] ?? '')) {
        throw new RuntimeException('mymar.reader_upgrade_configuration_refused');
    }
    $windowStart = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->setTime(0, 0)->modify('-7 days');
    $window = new MymarReadWindow($windowStart->format(DATE_ATOM), $windowStart->modify('+28 days')->format(DATE_ATOM), $config['technician_ids']);
    $pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4', $config['database_user'],
        $config['database_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    if ($pdo->query('SELECT CURRENT_USER()')->fetchColumn() !== $config['database_user'].'@localhost') {
        throw new RuntimeException('mymar.reader_sql_identity_changed');
    }
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $extendCompanyPermissions = false;
    try {
        MymarBookingSqlAccess::validateGrants($grants, readOnly: ! $writer);
    } catch (RuntimeException $error) {
        if ($writer) {
            throw $error;
        }
        // Upgrade only the exact previous SELECT scope; never accept broader permissions.
        MymarBookingSqlAccess::validateGrants($grants, readOnly: true, companyNames: false);
        $extendCompanyPermissions = true;
    }
    unset($pdo);
    upgradePendingCheck($config['token']);
    $files = ['entry.php' => $writer ? 'tools/deploy/mymar/actions/entry.php' : 'tools/deploy/mymar/booking-http/entry.php'];
    foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy', 'MymarReadWindow'] as $class) {
        $files[$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
    }
    foreach (['MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource', 'MymarBookingGateway'] as $class) {
        $files[$class.'.php'] = 'tools/deploy/mymar/booking/'.$class.'.php';
    }
    $expectedNames = array_keys($files);
    foreach (new DirectoryIterator($code) as $file) {
        if ($file->isDot()) {
            continue;
        }
        if (! $file->isFile() || $file->isLink() || $file->getOwner() !== 0 || ($file->getPerms() & 0022) !== 0
            || ! in_array($file->getFilename(), $expectedNames, true)) {
            throw new RuntimeException('mymar.reader_code_tree_refused');
        }
    }
    if (! mkdir($next, 0755) || ! chmod($next, 0755)) {
        throw new RuntimeException('mymar.reader_staging_failed');
    }
    foreach ($files as $name => $source) {
        $sha = $request['files'][$name] ?? '';
        if (! preg_match('/^[a-f0-9]{64}$/D', $sha) || realpath(__DIR__.'/code/'.$name) !== __DIR__.'/code/'.$name
            || ! hash_equals($sha, hash_file('sha256', __DIR__.'/code/'.$name))
            || ! hash_equals($sha, hash_file('sha256', '/var/www/cofrac2/'.$source))
            || ! copy(__DIR__.'/code/'.$name, $next.'/'.$name) || ! chmod($next.'/'.$name, 0644)) {
            throw new RuntimeException('mymar.reader_release_code_mismatch');
        }
        upgradeCommand(['/usr/bin/php8.4', '-l', $next.'/'.$name]);
    }
    $proof = upgradeProof();
    upgradeCommand(['/usr/bin/systemctl', 'is-active', '--quiet', ...$units]);
    if ($extendCompanyPermissions) {
        file_put_contents($run.'/reader-grants-before.json', json_encode($grants, JSON_THROW_ON_ERROR));
        $permissionsChanged = true;
        upgradeCompanyPermissions($config, grant: true);
    }
    $stopped = true;
    upgradeCommand(['/usr/bin/systemctl', 'stop', ...array_reverse($units)]);
    if (! rename($code, $backup)) {
        throw new RuntimeException('mymar.reader_backup_failed');
    }
    $changed = true;
    if (! rename($next, $code)) {
        throw new RuntimeException('mymar.reader_swap_failed');
    }
    upgradeCommand(['/usr/bin/systemctl', 'start', ...$units]);
    upgradePendingCheck($config['token']);
    $labels = ['items' => []];
    if (! $writer) {
        $labels = upgradeFetch('/v1/calendar-labels?'.http_build_query($window->query()), $config['token']);
        MymarCalendarLabels::validate($labels, $window, new DateTimeImmutable);
        $missing = upgradeFetch('/v1/scheduled-bookings/999999999999999999', $config['token'], 404);
        if (($missing['error'] ?? null) !== 'booking_appointment_not_found') {
            throw new RuntimeException('mymar.reader_scheduled_check_failed');
        }
    }
    if ($proof !== upgradeProof()) {
        throw new RuntimeException('mymar.reader_upgrade_configuration_changed');
    }
    file_put_contents($run.'/summary.json', json_encode(['format' => $request['format'], 'version' => 1,
        'complete' => true, 'target' => $target, 'revision' => $revision, 'calendar_labels_ready' => ! $writer, 'label_count' => count($labels['items']),
        'configuration_unchanged' => true, 'sql_writes' => false, 'mymar_application_unchanged' => true,
        'reader_company_select_columns_added' => $permissionsChanged,
        'backup_directory' => $backup], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    echo "Passerelle MAR ($target) mise a jour et GET verifies. Aucun dossier, placement, message ou code MYMAR modifie.\n";
    if ($permissionsChanged) {
        echo "Seul le lecteur gagne SELECT sur partenaire_id et le nom de societe ; aucun nouveau droit d'ecriture.\n";
    }
    echo "Diagnostic prive : $run\n";
} catch (Throwable $failure) {
    if ($permissionsChanged) {
        try {
            upgradeCompanyPermissions($config, grant: false);
            fwrite(STDERR, "Droits SELECT du lecteur restaures.\n");
        } catch (Throwable) {
            fwrite(STDERR, "ATTENTION : verifier les deux droits SELECT du lecteur dans le diagnostic prive.\n");
        }
    }
    if ($stopped) {
        try {
            upgradeCommand(['/usr/bin/systemctl', 'stop', ...array_reverse($units)]);
            if ($changed) {
                if (is_dir($code) && ! rename($code, $root.'/code-rejected-'.basename($run))) {
                    throw new RuntimeException;
                }
                if (! rename($backup, $code)) {
                    throw new RuntimeException;
                }
            }
            upgradeCommand(['/usr/bin/systemctl', 'start', ...$units]);
            upgradePendingCheck($config['token']);
            fwrite(STDERR, "Ancien lecteur restaure et verifie. Cofrac et MYMAR restent inchanges.\n");
        } catch (Throwable) {
            fwrite(STDERR, "ATTENTION : reprise du seul lecteur MAR a verifier dans le diagnostic prive. Ne pas fermer Cofrac.\n");
        }
    }
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $failure->getMessage()) ? $failure->getMessage() : 'mymar.reader_code_update_failed';
    fwrite(STDERR, "STOP : $reason. Aucun changement de la base ni du site. Diagnostic : ".($run ?? 'invocation refusee')."\n");
    exit(1);
}
