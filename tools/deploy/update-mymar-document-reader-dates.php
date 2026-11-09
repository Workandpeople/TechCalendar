<?php

declare(strict_types=1);

use App\Services\Mymar\MymarDocumentSettings;
use Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess;
use Symfony\Component\Process\Process;

// Extend only the already enabled document reader, without reading business rows or restarting services.
umask(0077);
$added = false;
$sql = null;
$user = null;
try {
    $app = '/var/www/cofrac2';
    $state = '/var/lib/cofrac2-production';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0
        || ($argv[1] ?? '') !== '--apply' || count($argv) !== 2 || explode('.', gethostname())[0] !== 'ns365601'
        || realpath(__FILE__) !== $app.'/tools/deploy/update-mymar-document-reader-dates.php' || realpath($app) !== $app
        || realpath($state) !== $state || fileowner($state) !== 0 || (fileperms($state) & 0777) !== 0700) {
        throw new RuntimeException('Usage : sudo php8.4 /var/www/cofrac2/tools/deploy/update-mymar-document-reader-dates.php --apply sur ns365601.');
    }
    require $app.'/vendor/autoload.php';
    require_once __DIR__.'/mymar/documents/MymarDocumentFailure.php';
    require_once __DIR__.'/mymar/documents/MymarDocumentSqlAccess.php';
    $locks = [];
    foreach (['two-lots-release.lock', 'runtime-operation.lock'] as $name) {
        $path = $state.'/'.$name;
        if (is_link($path) || (file_exists($path) && (! is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0077) !== 0))) {
            throw new RuntimeException('Verrou runtime inattendu.');
        }
        $lock = fopen($path, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Une operation Cofrac est deja en cours.');
        }
        $locks[] = $lock;
    }
    $configuration = (new MymarDocumentSettings)->load();
    if ($configuration === null) {
        throw new RuntimeException('Lecteur documentaire existant et active requis. Aucune activation automatique.');
    }
    $user = $configuration['username'];
    $directory = $state.'/mymar-document-dates-'.bin2hex(random_bytes(6));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Creation du diagnostic prive refusee.');
    }
    $sql = static function (string $query) use ($directory): string {
        $process = new Process(['/usr/bin/mysql', '--defaults-file=/etc/mysql/debian.cnf', '--protocol=socket', '--connect-timeout=5',
            '--skip-reconnect', '--batch', '--raw', '--skip-column-names'], null, ['MYSQL_PWD' => false]);
        $process->setTimeout(15);
        $process->setInput("SET SESSION lock_wait_timeout=2;\n".$query);
        if ($process->run() !== 0) {
            file_put_contents($directory.'/sql.stderr', $process->getErrorOutput(), FILE_APPEND | LOCK_EX);
            throw new RuntimeException('Controle SQL refuse. Diagnostic prive : '.$directory);
        }

        return trim($process->getOutput());
    };
    $grants = static fn (): array => explode("\n", $sql("SHOW GRANTS FOR '$user'@'localhost';"));
    $hasDates = MymarDocumentSqlAccess::validateGrants($grants());
    if ($sql("SELECT account_locked FROM mysql.user WHERE User='$user' AND Host='localhost';") !== 'N'
        || $sql("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR' AND TABLE_NAME='dossier_documents' AND COLUMN_NAME='created_at';") !== 'timestamp') {
        throw new RuntimeException('Lecteur actif et date de depot TIMESTAMP requis. Aucun droit modifie.');
    }
    if (! $hasDates) {
        // DDL grants are not transactional: remember this one addition for a targeted rollback.
        $added = true;
        $sql(MymarDocumentSqlAccess::dateAccessSql($user));
    }
    if (! MymarDocumentSqlAccess::validateGrants($grants())) {
        throw new RuntimeException('Lecture de la date non confirmee.');
    }
    $probe = new Process(['/usr/sbin/runuser', '-u', 'cofrac2-app', '--', '/usr/bin/php8.4', '-r', <<<'PHP'
require '/var/www/cofrac2/vendor/autoload.php';
require '/var/www/cofrac2/tools/deploy/mymar/documents/MymarDocumentFailure.php';
require '/var/www/cofrac2/tools/deploy/mymar/documents/MymarDocumentSqlAccess.php';
try {
    $c = (new App\Services\Mymar\MymarDocumentSettings)->load();
    if (!$c) exit(1);
    $p = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4', $c['username'], $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
    if ($p->query('SELECT CURRENT_ROLE()')->fetchColumn() !== 'NONE'
        || !Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess::validateGrants($p->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN))) exit(1);
    $p->exec("SET SESSION time_zone='+00:00'");
    $p->exec('START TRANSACTION READ ONLY');
    $p->query('SELECT created_at FROM MYMAR.dossier_documents WHERE 1=0');
    $p->rollBack();
} catch (Throwable) { exit(1); }
PHP]);
    $probe->setTimeout(15);
    if ($probe->run() !== 0) {
        throw new RuntimeException('Lecture des dates non confirmee avec le compte runtime.');
    }
    file_put_contents($directory.'/report.json', json_encode(['format' => 'cofrac.mymar-document-dates', 'version' => 1,
        'date_read_enabled' => true, 'new_column_granted' => $added, 'business_rows_read' => false,
        'source_data_changed' => false, 'services_changed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    echo "Dates de depot MAR accessibles en lecture seule. Aucun document, compte applicatif, statut, secret ou service modifie.\n";
    echo "Diagnostic prive : $directory\n";
} catch (Throwable $error) {
    if ($added && $sql !== null && $user !== null) {
        try {
            $sql(MymarDocumentSqlAccess::dateAccessSql($user, enable: false));
        } catch (Throwable) {
            fwrite(STDERR, "ATTENTION : verifier le seul droit SELECT(created_at) du lecteur documentaire.\n");
        }
    }
    fwrite(STDERR, 'STOP : '.($error instanceof RuntimeException ? $error->getMessage() : 'mise a jour documentaire refusee ; aucun detail sensible affiche.').PHP_EOL);
    exit(1);
}
