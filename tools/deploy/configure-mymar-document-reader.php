<?php

declare(strict_types=1);

use App\Services\Mymar\MymarDocumentSettings;
use Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess;
use Symfony\Component\Process\Process;

umask(0077);
$app = '/var/www/cofrac2';
$directory = '/etc/cofrac2-mymar-documents';
$account = null;
$created = false;
$admin = null;
$bindingInstalled = false;
$dropin = '/etc/systemd/system/cofrac2-php-fpm.service.d/mymar-documents.conf';
try {
    $action = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || ! in_array($action, ['--enable-read-only', '--disable-read-only'], true) || count($argv) !== 2
        || explode('.', gethostname())[0] !== 'ns365601' || realpath(__FILE__) !== $app.'/tools/deploy/configure-mymar-document-reader.php'
        || realpath($app) !== $app) {
        throw new RuntimeException('Root sur ns365601 requis avec --enable-read-only ou --disable-read-only.');
    }
    require $app.'/vendor/autoload.php';
    $state = '/var/lib/cofrac2-production';
    if (realpath($state) !== $state || fileowner($state) !== 0 || (fileperms($state) & 0777) !== 0700) {
        throw new RuntimeException('Etat prive Cofrac inattendu.');
    }
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
    foreach (['MymarDocumentFailure', 'MymarDocumentSqlAccess'] as $class) {
        require_once __DIR__.'/mymar/documents/'.$class.'.php';
    }
    if ($action === '--disable-read-only') {
        if (! file_exists($directory.'/reader.json') && ! is_link($directory.'/reader.json')) {
            echo "Acces documentaire deja desactive ou absent. Aucun service ou rendez-vous modifie.\n";
            exit(0);
        }
        (new MymarDocumentSettings)->load();
        $configurationToDisable = json_decode(file_get_contents($directory.'/reader.json'), true, 8, JSON_THROW_ON_ERROR);
        MymarDocumentSettings::validate($configurationToDisable);
        $process = new Process(['/usr/bin/mysql', '--defaults-file=/etc/mysql/debian.cnf', '--protocol=socket', '--connect-timeout=5', '--skip-reconnect'], null, ['MYSQL_PWD' => false]);
        $process->setInput("ALTER USER '".$configurationToDisable['username']."'@'localhost' ACCOUNT LOCK;\n");
        $process->setTimeout(15);
        if ($process->run() !== 0) {
            throw new RuntimeException('Verrouillage du lecteur documentaire refuse. Aucun autre acces modifie.');
        }
        $configurationToDisable['enabled'] = false;
        $temporary = $directory.'/disabled-'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, json_encode($configurationToDisable, JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false
            || ! chgrp($temporary, filegroup($directory.'/reader.json')) || ! chmod($temporary, 0640) || ! rename($temporary, $directory.'/reader.json')) {
            throw new RuntimeException('Lecteur SQL verrouille ; publication du mode desactive a verifier.');
        }
        echo "Acces aux factures/devis MAR desactive et compte SQL dedie verrouille. Aucun service redemarre, rendez-vous ou fichier modifie.\n";
        exit(0);
    }
    if (file_exists($directory) || is_link($directory)) {
        throw new RuntimeException('Preparation neuve uniquement : une configuration documentaire existe deja.');
    }
    $runtime = posix_getpwnam('cofrac2-app');
    $deploy = posix_getpwnam('cofrac2-deploy');
    if (! $runtime || ! $deploy || fileowner($app) !== $deploy['uid']) {
        throw new RuntimeException('Comptes ou proprietaire inattendus.');
    }
    $process = new Process(['/usr/bin/systemctl', 'is-active', '--quiet', 'cofrac2-php-fpm.service']);
    if ($process->run() !== 0 || file_exists($dropin) || is_link($dropin)
        || ! is_executable('/usr/bin/nsenter') || file_exists('/srv/cofrac2-mymar-documents') || is_link('/srv/cofrac2-mymar-documents')) {
        throw new RuntimeException('Runtime actif et preparation documentaire neuve requis.');
    }
    $uploads = realpath('/home/ubuntu/www/MYMAR/public/storage/uploads');
    if ($uploads === false || ! str_starts_with($uploads, '/home/ubuntu/www/MYMAR/') || ! is_dir($uploads) || preg_match('/[\s:%\\\\]/', $uploads)) {
        throw new RuntimeException('Racine documentaire MYMAR absente ou hors perimetre.');
    }
    $probe = new Process(['/usr/sbin/runuser', '-u', 'cofrac2-app', '--', '/usr/bin/php8.4', '-r',
        'exit(is_dir($argv[1]) && is_readable($argv[1]) && is_executable($argv[1]) ? 0 : 1);', $uploads]);
    $probe->setTimeout(10);
    if ($probe->run() !== 0) {
        throw new RuntimeException('Le runtime ne peut pas lire la racine existante. Aucun droit MYMAR ne sera modifie automatiquement.');
    }
    if (! mkdir($directory, 0750) || ! chgrp($directory, $runtime['gid']) || ! chmod($directory, 0750)) {
        throw new RuntimeException('Creation du dossier prive impossible.');
    }
    $admin = static function (string $sql) use ($directory): string {
        $process = new Process(['/usr/bin/mysql', '--defaults-file=/etc/mysql/debian.cnf', '--protocol=socket', '--connect-timeout=5',
            '--batch', '--raw', '--skip-column-names', '--skip-reconnect', '--default-character-set=utf8mb4'], null, ['MYSQL_PWD' => false]);
        $process->setInput($sql);
        $process->setTimeout(15);
        if ($process->run() !== 0) {
            file_put_contents($directory.'/preparation-error.log', $process->getErrorOutput(), LOCK_EX);
            throw new RuntimeException('Controle SQL refuse ; diagnostic prive conserve sur le serveur.');
        }

        return trim($process->getOutput());
    };
    foreach (MymarDocumentSqlAccess::privileges() as $table => $operations) {
        [$schema, $name] = explode('.', $table);
        if ($admin("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME='$name' AND TABLE_TYPE='BASE TABLE';") !== 'InnoDB') {
            throw new RuntimeException('Schema documentaire inattendu.');
        }
        $columns = explode("\n", $admin("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME='$name';"));
        if (array_diff($operations['SELECT'], $columns) !== []) {
            throw new RuntimeException('Colonnes documentaires inattendues.');
        }
    }
    $account = 'cofrac_mymar_dr_'.bin2hex(random_bytes(4));
    $password = 'Md4!'.bin2hex(random_bytes(32));
    $admin(MymarDocumentSqlAccess::createSql($account, $password));
    $created = true;
    MymarDocumentSqlAccess::validateGrants(explode("\n", $admin("SHOW GRANTS FOR '$account'@'localhost';")));
    $configuration = ['format' => 'cofrac.mymar-document-reader', 'version' => 1, 'enabled' => false,
        'writes_enabled' => false, 'username' => $account, 'password' => $password, 'uploads_root' => MymarDocumentSettings::UPLOADS];
    $write = static function (array $data) use ($directory, $runtime): void {
        $temporary = $directory.'/reader-'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false
            || ! chgrp($temporary, $runtime['gid']) || ! chmod($temporary, 0640) || ! rename($temporary, $directory.'/reader.json')) {
            throw new RuntimeException('Publication de la configuration impossible.');
        }
    };
    $write($configuration);
    // Keep ProtectHome=true: expose only this uploads root, read-only inside the FPM namespace.
    if (! mkdir('/srv/cofrac2-mymar-documents', 0755) || ! mkdir(MymarDocumentSettings::UPLOADS, 0755)
        || ! chmod('/srv/cofrac2-mymar-documents', 0755) || ! chmod(MymarDocumentSettings::UPLOADS, 0755)) {
        throw new RuntimeException('Preparation de la racine de lecture impossible.');
    }
    $dropinDirectory = dirname($dropin);
    if (is_link($dropinDirectory) || (file_exists($dropinDirectory) && (realpath($dropinDirectory) !== $dropinDirectory || fileowner($dropinDirectory) !== 0))) {
        throw new RuntimeException('Dossier systemd inattendu.');
    }
    if (! is_dir($dropinDirectory) && (! mkdir($dropinDirectory, 0755) || ! chmod($dropinDirectory, 0755))) {
        throw new RuntimeException('Preparation systemd impossible.');
    }
    $handle = fopen($dropin, 'x');
    if ($handle === false) {
        throw new RuntimeException('Creation de la liaison en lecture seule impossible.');
    }
    $bindingInstalled = true;
    $content = "[Service]\nBindReadOnlyPaths=$uploads:".MymarDocumentSettings::UPLOADS."\n";
    try {
        if (fwrite($handle, $content) !== strlen($content) || ! chmod($dropin, 0644)) {
            throw new RuntimeException('Publication de la liaison systemd impossible.');
        }
    } finally {
        fclose($handle);
    }
    $systemctl = static function (array $arguments) use ($directory): string {
        $process = new Process(['/usr/bin/systemctl', ...$arguments]);
        $process->setTimeout(200);
        if ($process->run() !== 0) {
            file_put_contents($directory.'/runtime-binding-error.log', $process->getErrorOutput(), LOCK_EX);
            throw new RuntimeException('Preparation du runtime documentaire refusee.');
        }

        return trim($process->getOutput());
    };
    $systemctl(['daemon-reload']);
    echo "Reprise du seul PHP Cofrac pour appliquer le montage documentaire en lecture seule. MYMAR inchange.\n";
    $systemctl(['restart', 'cofrac2-php-fpm.service']);
    $admin("ALTER USER '$account'@'localhost' ACCOUNT UNLOCK;");
    $configuration['enabled'] = true;
    MymarDocumentSettings::validate($configuration);
    $write($configuration);
    $pid = $systemctl(['show', '--property=MainPID', '--value', 'cofrac2-php-fpm.service']);
    if (! ctype_digit($pid) || (int) $pid < 2) {
        throw new RuntimeException('PID PHP inattendu.');
    }
    $probe = new Process(['/usr/bin/nsenter', '--target', $pid, '--mount', '--', '/usr/sbin/runuser', '-u', 'cofrac2-app', '--', '/usr/bin/php8.4', '-r', <<<'PHP'
require '/var/www/cofrac2/vendor/autoload.php';
$c = (new App\Services\Mymar\MymarDocumentSettings)->load();
if (!$c || !is_dir($c['uploads_root']) || !is_readable($c['uploads_root']) || !is_executable($c['uploads_root'])) exit(1);
$mounts = file_get_contents('/proc/self/mountinfo');
if (!preg_match('~ /srv/cofrac2-mymar-documents/uploads ro(?:,| )~', $mounts)) exit(1);
$p = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
require '/var/www/cofrac2/tools/deploy/mymar/documents/MymarDocumentFailure.php';
require '/var/www/cofrac2/tools/deploy/mymar/documents/MymarDocumentSqlAccess.php';
Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess::validateGrants($p->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
if ($p->query('SELECT CURRENT_ROLE()')->fetchColumn() !== 'NONE') exit(1);
$p->exec('START TRANSACTION READ ONLY');
$p->query('SELECT 1');
$p->rollBack();
PHP]);
    $probe->setTimeout(10);
    if ($probe->run() !== 0) {
        file_put_contents($directory.'/runtime-error.log', $probe->getErrorOutput(), LOCK_EX);
        throw new RuntimeException('Verification du lecteur runtime refusee.');
    }
    echo "Lecteur documentaire MAR active : SELECT par colonnes, factures/devis de travaux uniquement.\n";
    echo "Aucun dossier, document, statut, compte MYMAR, service MYMAR ou permission de fichier source modifie. Aucun envoi.\n";
    echo "Les documents sont lus sur place, sans copie. Aucun jeton ni mot de passe affiche.\n";
} catch (Throwable $error) {
    if ($created && $admin !== null) {
        try {
            $admin("ALTER USER '$account'@'localhost' ACCOUNT LOCK;");
        } catch (Throwable) {
            fwrite(STDERR, "ATTENTION : verifier manuellement le verrouillage du nouveau lecteur documentaire.\n");
        }
    }
    if (isset($configuration, $write)) {
        try {
            $configuration['enabled'] = false;
            $write($configuration);
        } catch (Throwable) {
            // The dedicated SQL account is locked even if publication of the disabled config fails.
        }
    }
    if ($bindingInstalled) {
        try {
            if (! unlink($dropin)) {
                throw new RuntimeException('Retrait de la liaison refuse.');
            }
            foreach ([['daemon-reload'], ['restart', 'cofrac2-php-fpm.service']] as $arguments) {
                $process = new Process(['/usr/bin/systemctl', ...$arguments]);
                $process->setTimeout(200);
                if ($process->run() !== 0) {
                    throw new RuntimeException('Reprise du runtime refusee.');
                }
            }
        } catch (Throwable) {
            fwrite(STDERR, "ATTENTION : verifier le service cofrac2-php-fpm et son diagnostic prive avant toute autre action.\n");
        }
    }
    fwrite(STDERR, 'STOP : '.($error instanceof RuntimeException ? $error->getMessage() : 'preparation documentaire annulee ; aucun detail sensible affiche.').PHP_EOL);
    exit(1);
}
