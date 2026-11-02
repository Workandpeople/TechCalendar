<?php

declare(strict_types=1);

use App\Services\Mymar\MymarCalendarLabels;
use App\Services\Mymar\MymarPendingBookingPage;
use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;

// Code-only reader upgrade. No application bootstrap, SQL writes or write-gateway request.
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
    foreach (['/var/lib/cofrac-mymar-gateway/private/config.json', '/var/lib/cofrac-mymar-gateway/private/scope.json',
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

function upgradeFetch(string $path, #[SensitiveParameter] string $token): array
{
    $body = '';
    $curl = curl_init('http://127.0.0.1:19847'.$path);
    curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 5000, CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        }]);
    if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200
        || ! str_starts_with((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 'application/json')) {
        throw new RuntimeException('mymar.reader_get_check_failed');
    }

    return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
}

function upgradePendingCheck(#[SensitiveParameter] string $token): void
{
    for ($attempt = 0; $attempt < 30; $attempt++) {
        try {
            MymarPendingBookingPage::fromArray(upgradeFetch('/v1/pending-bookings?after=0&limit=1', $token), 0, 1);

            return;
        } catch (Throwable $error) {
            if ($attempt === 29) {
                throw $error;
            }
            usleep(200000);
        }
    }
}

$changed = false;
$stopped = false;
try {
    $run = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 2 || ! preg_match('~^/var/lib/cofrac2-production/mymar-reader-update-[A-Za-z0-9]+$~D', $run)
        || realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_reader_upgrade_invocation');
    }
    $request = upgradeRead(__DIR__.'/REQUEST.json');
    $revision = $request['revision'] ?? '';
    $root = '/var/lib/cofrac-mymar-booking-gateway';
    $code = $root.'/code';
    $backup = $root.'/code-before-'.basename($run);
    $next = $root.'/code-next-'.basename($run);
    if (($request['format'] ?? null) !== 'cofrac.mymar-reader-code-update' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $revision)
        || (upgradeRead('/var/lib/cofrac2-production/RUNTIME-PREPARED.json')['revision'] ?? null) !== $revision
        || (upgradeRead('/var/lib/cofrac2-production/MYMAR-BOOKING-HTTP.json')['stage'] ?? null) !== 'reader_only'
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
    if (($config['format'] ?? null) !== 'cofrac.mymar-booking-reader' || ($config['version'] ?? null) !== 1
        || ($config['read_enabled'] ?? null) !== true || ($config['writes_enabled'] ?? null) !== false
        || ! preg_match('/^cofrac_mymar_br_[a-f0-9]{8}$/D', $config['database_user'] ?? '')
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
    MymarBookingSqlAccess::validateGrants($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN), readOnly: true);
    unset($pdo);
    MymarPendingBookingPage::fromArray(upgradeFetch('/v1/pending-bookings?after=0&limit=1', $config['token']), 0, 1);
    $files = ['entry.php' => 'tools/deploy/mymar/booking-http/entry.php'];
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
    upgradeCommand(['/usr/bin/systemctl', 'is-active', '--quiet', 'cofrac-mymar-booking-fpm.service', 'cofrac-mymar-booking-http.service']);
    $stopped = true;
    upgradeCommand(['/usr/bin/systemctl', 'stop', 'cofrac-mymar-booking-http.service', 'cofrac-mymar-booking-fpm.service']);
    if (! rename($code, $backup)) {
        throw new RuntimeException('mymar.reader_backup_failed');
    }
    $changed = true;
    if (! rename($next, $code)) {
        throw new RuntimeException('mymar.reader_swap_failed');
    }
    upgradeCommand(['/usr/bin/systemctl', 'start', 'cofrac-mymar-booking-fpm.service', 'cofrac-mymar-booking-http.service']);
    upgradePendingCheck($config['token']);
    $labels = upgradeFetch('/v1/calendar-labels?'.http_build_query($window->query()), $config['token']);
    MymarCalendarLabels::validate($labels, $window, new DateTimeImmutable);
    if ($proof !== upgradeProof()) {
        throw new RuntimeException('mymar.reader_upgrade_configuration_changed');
    }
    file_put_contents($run.'/summary.json', json_encode(['format' => 'cofrac.mymar-reader-code-update', 'version' => 1,
        'complete' => true, 'revision' => $revision, 'calendar_labels_ready' => true, 'label_count' => count($labels['items']),
        'configuration_unchanged' => true, 'sql_writes' => false, 'mymar_application_unchanged' => true,
        'backup_directory' => $backup], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    echo "Lecteur MAR mis a jour et GET verifies. Aucun compte, reglage, placement ou message modifie.\nDiagnostic prive : $run\n";
} catch (Throwable $failure) {
    if ($stopped) {
        try {
            upgradeCommand(['/usr/bin/systemctl', 'stop', 'cofrac-mymar-booking-http.service', 'cofrac-mymar-booking-fpm.service']);
            if ($changed) {
                if (is_dir($code) && ! rename($code, $root.'/code-rejected-'.basename($run))) {
                    throw new RuntimeException;
                }
                if (! rename($backup, $code)) {
                    throw new RuntimeException;
                }
            }
            upgradeCommand(['/usr/bin/systemctl', 'start', 'cofrac-mymar-booking-fpm.service', 'cofrac-mymar-booking-http.service']);
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
