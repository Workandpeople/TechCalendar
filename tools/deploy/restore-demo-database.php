<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

// Run only on the demo CT. No Laravel bootstrap, workers, migrations or API calls.
umask(0077);
ini_set('display_errors', '0');

$app = '/var/www/TechCalendar-v2';
$package = '/srv/techcalendar-v2-demo/exports/release-v2-20261015';
$database = 'techcalendar_v2_demo_simulation';
$username = 'techcalendar_v2_demo';
$stage = 'validation';
$privateDirectory = null;
$credentials = null;
$input = null;
$root = null;
$exitCode = 1;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ($argv[1] ?? '') !== '--create-new-demo-database') {
        throw new RuntimeException('Utiliser PHP >= 8.4.1 avec --create-new-demo-database.');
    }
    if (realpath($app) !== $app || realpath($package) !== $package) {
        throw new RuntimeException('Repertoires V2 attendus absents ou rediriges.');
    }

    require $app.'/vendor/autoload.php';

    $required = ['database-v2.sql.gz', 'code-v2.tar.gz', 'runtime-files.tar.gz', 'demo-secrets.env', 'metadata.json', 'source-revision.txt', 'source-worktree.txt'];
    $verified = [];
    foreach (file($package.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (! preg_match('/^([a-f0-9]{64})  ([a-zA-Z0-9_.-]+)$/D', $line, $matches)
            || ! in_array($matches[2], $required, true) || isset($verified[$matches[2]])
            || is_link($package.'/'.$matches[2])
            || ! hash_equals($matches[1], hash_file('sha256', $package.'/'.$matches[2]))) {
            throw new RuntimeException('Integrite du paquet invalide.');
        }
        $verified[$matches[2]] = true;
    }
    if (count($verified) !== count($required)) {
        throw new RuntimeException('Controle du paquet incomplet.');
    }

    $env = Dotenv\Dotenv::parse(file_get_contents($app.'/.env'));
    $secrets = Dotenv\Dotenv::parse(file_get_contents($package.'/demo-secrets.env'));
    foreach ([
        'APP_ENV' => 'demo', 'APP_DEBUG' => 'false', 'SIMULATION_MODE' => 'true',
        'CRM_V2_ENABLED' => 'true', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306', 'DB_DATABASE' => $database, 'DB_USERNAME' => $username, 'DB_URL' => '',
    ] as $key => $expected) {
        if (($env[$key] ?? null) !== $expected) {
            throw new RuntimeException('Configuration V2 inattendue : '.$key.'.');
        }
    }
    if (! preg_match('/^[a-f0-9]{48}$/D', $env['DB_PASSWORD'] ?? '')
        || ($env['DB_PASSWORD'] ?? '') !== ($secrets['DB_PASSWORD'] ?? null)
        || empty($env['APP_KEY']) || $env['APP_KEY'] !== ($secrets['APP_KEY'] ?? null)) {
        throw new RuntimeException('Secrets V2 absents ou differents du paquet.');
    }

    $metadata = json_decode(file_get_contents($package.'/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    $tables = $metadata['tables'] ?? [];
    if (($metadata['format'] ?? '') !== 'techcalendar.v2-demo' || empty($tables['dossiers'])) {
        throw new RuntimeException('Metadonnees du dump inattendues.');
    }
    foreach ($tables as $table => $count) {
        if (! preg_match('/^[a-z_]+$/D', $table) || ! is_int($count) || $count < 0) {
            throw new RuntimeException('Inventaire du dump invalide.');
        }
    }

    $gzipCheck = new Process(['/usr/bin/gzip', '-t', $package.'/database-v2.sql.gz']);
    $gzipCheck->setTimeout(300);
    if ($gzipCheck->run() !== 0) {
        throw new RuntimeException('Archive SQL incomplete.');
    }

    $socket = $argv[2] ?? '/var/run/mysqld/mysqld.sock';
    if (! str_starts_with($socket, '/') || preg_match('/[;\r\n\x00]/', $socket)) {
        throw new RuntimeException('Socket MySQL invalide.');
    }
    $stage = 'controle MySQL local';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $root = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', $options);
    if ((int) $root->query('SELECT @@port')->fetchColumn() !== 3306
        || (int) $root->query("SELECT GET_LOCK('techcalendar_v2_demo_restore', 0)")->fetchColumn() !== 1) {
        throw new RuntimeException('Port inattendu ou autre import en cours.');
    }
    $check = $root->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $check->execute([$database]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Base V2 deja presente : aucune modification effectuee.');
    }
    $check = $root->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
    $check->execute([$username]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Compte V2 deja present : aucune modification effectuee.');
    }

    $privateDirectory = $package.'/restore-ct-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    mkdir($privateDirectory, 0700);
    $credentials = $privateDirectory.'/mysql.cnf';
    $ini = "[client]\nhost=127.0.0.1\nport=3306\nprotocol=TCP\nuser={$username}\npassword=\"{$env['DB_PASSWORD']}\"\n";
    if (file_put_contents($credentials, $ini, LOCK_EX) !== strlen($ini)) {
        throw new RuntimeException('Creation du fichier de connexion impossible.');
    }

    $stage = 'creation de la base V2 isolee';
    $root->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Escape database-level GRANT wildcards unless partial_revokes makes them literal.
    $grantDatabase = (bool) $root->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn()
        ? $database : str_replace('_', '\\_', $database);
    foreach (['localhost', '127.0.0.1'] as $host) {
        $account = $root->quote($username).'@'.$root->quote($host);
        $root->exec('CREATE USER '.$account.' IDENTIFIED BY '.$root->quote($env['DB_PASSWORD']));
        $root->exec("GRANT ALL PRIVILEGES ON `{$grantDatabase}`.* TO ".$account);
    }

    $target = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4', $username, $env['DB_PASSWORD'], $options);
    if ($target->query('SELECT DATABASE()')->fetchColumn() !== $database
        || (int) $target->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('La cible doit etre la nouvelle base V2 vide.');
    }

    $stage = 'import du dump';
    echo "Import dans {$database}, avec le compte limite a la V2...\n";
    $input = gzopen($package.'/database-v2.sql.gz', 'rb');
    $import = new Process([
        '/usr/bin/mysql', '--defaults-file='.$credentials, '--local-infile=0',
        '--binary-mode', '--skip-reconnect', '--default-character-set=utf8mb4', $database,
    ]);
    $import->setTimeout(1800);
    $import->setInput($input);
    $result = $import->run();
    fclose($input);
    $input = null;
    if ($result !== 0) {
        file_put_contents($privateDirectory.'/mysql-error.log', $import->getErrorOutput(), LOCK_EX);
        throw new RuntimeException('Import interrompu. Diagnostic SQL conserve dans le dossier prive, ne pas le publier sans masquage.');
    }

    $stage = 'verification des comptages';
    $actualTables = $target->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    $expectedTables = array_keys($tables);
    sort($expectedTables);
    if ($actualTables !== $expectedTables) {
        throw new RuntimeException('Inventaire des tables restaurees different du paquet.');
    }
    foreach ($tables as $table => $expectedCount) {
        $actualCount = (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if (! in_array($table, ['sessions', 'cache', 'cache_locks'], true) && $actualCount !== $expectedCount) {
            throw new RuntimeException('Comptage different pour '.$table.'.');
        }
    }
    echo 'Comptages verifies : '.count($tables).' tables, '.$tables['dossiers'].' dossiers, '.$tables['appointments']." RDV.\n";

    $stage = 'purge des etats temporaires de la copie V2';
    $target->beginTransaction();
    foreach (['jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'cache_locks', 'password_reset_tokens', 'mobile_access_tokens', 'mobile_push_tokens'] as $table) {
        $target->exec("DELETE FROM `{$table}`");
    }
    $target->exec('UPDATE users SET remember_token = NULL');
    $target->commit();

    $report = json_encode([
        'database' => $database, 'verified_at' => gmdate(DATE_ATOM), 'tables' => count($tables),
        'dossiers' => $tables['dossiers'], 'appointments' => $tables['appointments'],
        'dump_sha256' => hash_file('sha256', $package.'/database-v2.sql.gz'),
        'temporary_state_cleared' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($privateDirectory.'/RESTORE-VERIFIED.json', $report, LOCK_EX) !== strlen($report)) {
        throw new RuntimeException('Ecriture du compte rendu impossible.');
    }
    echo "Base V2 restauree et verifiee. Sessions et jobs importes retires.\nAucun service demarre, aucune bascule effectuee.\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    // PDO and process exceptions may include credentials or customer data.
    $message = get_class($exception) === RuntimeException::class
        ? $exception->getMessage() : 'Erreur technique masquee ('.get_class($exception).', code '.$exception->getCode().').';
    fwrite(STDERR, 'STOP ['.$stage.'] : '.$message."\nAucune base existante ne sera ecrasee ou supprimee automatiquement.\n");
} finally {
    if (is_resource($input)) {
        fclose($input);
    }
    if ($credentials !== null && is_file($credentials)) {
        unlink($credentials);
    }
    // Closing the root connection releases the advisory lock, including on failure.
    $root = null;
    if ($privateDirectory !== null) {
        echo 'Compte rendu prive : '.$privateDirectory."\n";
    }
}

exit($exitCode);
