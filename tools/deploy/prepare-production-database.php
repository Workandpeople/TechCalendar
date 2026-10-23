<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// Standalone provisioning: no Laravel bootstrap or access to old application files.
umask(0077);
ini_set('display_errors', '0');

$app = '/var/www/cofrac2';
$directory = '/var/lib/cofrac2-production';
$database = 'cofrac2production';
$username = 'cofrac2_app';
$stage = 'validation';
$privateDirectory = null;
$lock = null;
$target = null;
$exitCode = 1;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! extension_loaded('posix')
        || posix_geteuid() !== 0 || count($argv) !== 2
        || ($argv[1] ?? '') !== '--create-new-v2-database'
        || explode('.', gethostname())[0] !== 'ns365601') {
        throw new RuntimeException('Executer en root sur ns365601 avec PHP >= 8.4.1 et --create-new-v2-database.');
    }
    if (realpath($app) !== $app || realpath(__FILE__) !== $app.'/tools/deploy/prepare-production-database.php'
        || ! is_dir($app.'/.git') || ! is_file($app.'/vendor/autoload.php')) {
        throw new RuntimeException('Checkout V2 ou dependances absents, ou chemin redirige.');
    }
    $deploy = posix_getpwnam('cofrac2-deploy');
    if ($deploy === false || fileowner($app) !== $deploy['uid']) {
        throw new RuntimeException('Compte ou proprietaire de deploiement inattendu.');
    }
    if (! is_file('/etc/mysql/debian.cnf') || ! is_readable('/etc/mysql/debian.cnf')) {
        throw new RuntimeException('Configuration de maintenance MySQL indisponible.');
    }
    foreach ([$app.'/.env', $app.'/bootstrap/cache/config.php', $directory] as $path) {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Cible deja preparee ou configuration presente : aucune reprise automatique.');
        }
    }
    $lockPath = '/var/lib/cofrac2-production.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && fileowner($lockPath) !== 0)) {
        throw new RuntimeException('Verrou de preparation inattendu.');
    }
    $lock = fopen($lockPath, 'c');
    if (! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Une autre preparation V2 est en cours.');
    }

    require $app.'/vendor/autoload.php';

    $git = static function (array $arguments) use ($app): string {
        $process = new Process(array_merge(['/usr/sbin/runuser', '-u', 'cofrac2-deploy', '--', '/usr/bin/git', '-C', $app], $arguments));
        $process->setTimeout(15);
        if ($process->run() !== 0) {
            throw new RuntimeException('Verification Git impossible.');
        }

        return trim($process->getOutput());
    };
    if ($git(['branch', '--show-current']) !== 'v2' || $git(['status', '--porcelain']) !== '') {
        throw new RuntimeException('Un checkout V2 propre est obligatoire.');
    }

    $admin = static function (string $sql) use (&$privateDirectory): string {
        // The existing maintenance credentials stay in mysql.cnf, never in PHP or argv.
        $process = new Process([
            '/usr/bin/mysql', '--defaults-file=/etc/mysql/debian.cnf',
            '--protocol=socket', '--connect-timeout=5', '--batch', '--raw',
            '--skip-column-names', '--skip-reconnect', '--default-character-set=utf8mb4',
        ], null, ['MYSQL_PWD' => false]);
        $process->setInput($sql);
        $process->setTimeout(30);
        if ($process->run() !== 0) {
            if ($privateDirectory !== null) {
                file_put_contents($privateDirectory.'/mysql-error.log', $process->getErrorOutput(), LOCK_EX);
            }
            throw new RuntimeException('Commande MySQL refusee ; ne pas publier le diagnostic prive.');
        }

        return trim($process->getOutput());
    };

    $stage = 'controle MySQL et absence des cibles';
    $server = explode("\t", $admin('SELECT CURRENT_USER(), @@port, @@version;'));
    if (count($server) !== 3 || $server[0] !== 'debian-sys-maint@localhost'
        || $server[1] !== '3306' || ! str_starts_with($server[2], '8.0.')) {
        throw new RuntimeException('Compte administrateur, port ou version MySQL inattendu.');
    }
    $existing = $admin("SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$database}'), (SELECT COUNT(*) FROM mysql.user WHERE User = '{$username}');");
    if ($existing !== "0\t0") {
        throw new RuntimeException('Base ou compte SQL deja existant : aucune modification effectuee.');
    }

    $stage = 'preparation privee des nouveaux secrets';
    $reverbKey = bin2hex(random_bytes(16));
    $password = 'C2a!'.bin2hex(random_bytes(32));
    $env = strtr(file_get_contents($app.'/tools/deploy/production.env.example'), [
        '__APP_KEY__' => 'base64:'.base64_encode(random_bytes(32)),
        '__DB_PASSWORD__' => $password,
        '__REVERB_APP_KEY__' => $reverbKey,
        '__REVERB_APP_SECRET__' => bin2hex(random_bytes(32)),
    ]);
    $values = Dotenv\Dotenv::parse($env);
    if (preg_match('/__[A-Z_]+__/', $env)
        || ($values['APP_ENV'] ?? '') !== 'production'
        || ($values['DB_DATABASE'] ?? '') !== $database
        || ($values['DB_USERNAME'] ?? '') !== $username
        || ($values['DB_PASSWORD'] ?? '') !== $password
        || ($values['APP_DEBUG'] ?? '') !== 'false'
        || ($values['CRM_V2_ENABLED'] ?? '') !== 'true') {
        throw new RuntimeException('Modele de configuration V2 incoherent.');
    }
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Creation du dossier prive impossible.');
    }
    $privateDirectory = $directory;
    $writeNew = static function (string $path, string $content): void {
        $handle = fopen($path, 'x');
        try {
            if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Ecriture privee incomplete.');
            }
        } finally {
            fclose($handle);
        }
    };
    // DDL is not transactional: keep recovery credentials before creating anything.
    $writeNew($directory.'/initial-production.env', $env);

    $stage = 'creation de la nouvelle base et des comptes locaux V2';
    $admin("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    foreach (['localhost', '127.0.0.1'] as $host) {
        $admin("CREATE USER '{$username}'@'{$host}' IDENTIFIED BY '{$password}';");
        $admin("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$username}'@'{$host}';");
    }

    $stage = 'verification de la connexion applicative et des droits';
    $target = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4', $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($target->query('SELECT DATABASE()')->fetchColumn() !== $database
        || (int) $target->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('La nouvelle base V2 doit etre vide.');
    }
    $grants = $target->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $expectedScope = 'GRANT ALL PRIVILEGES ON `'.$database.'`.* TO ';
    if (count($grants) !== 2
        || count(array_filter($grants, static fn (string $grant): bool => str_starts_with($grant, 'GRANT USAGE ON *.* TO '))) !== 1
        || count(array_filter($grants, static fn (string $grant): bool => str_starts_with($grant, $expectedScope))) !== 1
        || str_contains(implode("\n", $grants), 'WITH GRANT OPTION')) {
        throw new RuntimeException('Les droits du compte applicatif doivent etre limites a la seule base V2.');
    }

    $stage = 'installation du fichier prive .env';
    $writeNew($app.'/.env', $env);
    if (! chown($app.'/.env', $deploy['uid']) || ! chgrp($app.'/.env', $deploy['gid'])
        || ! chmod($app.'/.env', 0600)) {
        throw new RuntimeException('Permissions du nouveau .env non validees.');
    }
    $writeNew($directory.'/DATABASE-READY.json', json_encode([
        'database' => $database,
        'username' => $username,
        'local_accounts' => ['localhost', '127.0.0.1'],
        'created_at' => gmdate(DATE_ATOM),
        'empty_database' => true,
        'env_sha256' => hash('sha256', $env),
        'revision' => $git(['rev-parse', 'HEAD']),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

    echo "Base V2 creee et vide : {$database}.\n";
    echo "Compte applicatif local : {$username}, droits limites a cette base, sans GRANT OPTION.\n";
    echo "Connexion applicative TCP verifiee. .env V2 cree en 0600, secrets non affiches.\n";
    echo "Aucun import, migration, bootstrap Laravel, demarrage ou rechargement de service.\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    // SQL and process errors can include passwords or data: never echo their text.
    $message = get_class($exception) === RuntimeException::class
        ? $exception->getMessage() : 'Erreur technique masquee ('.get_class($exception).').';
    fwrite(STDERR, 'STOP ['.$stage.'] : '.$message."\nAucune cible existante ne sera ecrasee, supprimee ou reinitialisee automatiquement.\n");
} finally {
    $target = null;
    if (is_resource($lock)) {
        fclose($lock);
    }
    if ($privateDirectory !== null) {
        echo 'Dossier prive de preparation : '.$privateDirectory." (ne pas publier son contenu).\n";
    }
}

exit($exitCode);
