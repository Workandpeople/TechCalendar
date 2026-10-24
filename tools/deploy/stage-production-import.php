<?php

declare(strict_types=1);

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\DocumentReconciliationPlan;
use App\Services\Coffrac\NativeDocumentAudit;
use App\Services\Coffrac\NativeImportTarget;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\StagingImportPackage;
use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use Cofrac2\Deploy\CoffracDocumentArchive;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

umask(0077);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $appPath = '/var/www/cofrac2';
    $state = '/var/lib/cofrac2-production';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid')
        || explode('.', gethostname())[0] !== 'ns365601' || realpath($appPath) !== $appPath
        || realpath(__FILE__) !== $appPath.'/tools/deploy/stage-production-import.php'
        || ! in_array($argc, [4, 5], true) || ! in_array($argv[1], ['--check', '--run'], true)) {
        throw new RuntimeException('Commande CLI reservee a la preparation sur ns365601.');
    }
    require $appPath.'/vendor/autoload.php';
    $package = new StagingImportPackage($argv[2], $argv[3]);

    if ($argv[1] === '--check') {
        if (posix_geteuid() !== 0 || is_link($state) || realpath($state) !== $state
            || fileowner($state) !== 0 || (fileperms($state) & 0777) !== 0700) {
            throw new RuntimeException('Controle root et preparation privee requis.');
        }
        foreach (['DATABASE-READY.json', 'SCHEMA-READY.json', 'runtime-identity'] as $name) {
            if (! is_file($state.'/'.$name) || is_link($state.'/'.$name) || fileowner($state.'/'.$name) !== 0) {
                throw new RuntimeException('Preparation initiale V2 absente.');
            }
        }
        $databaseReady = json_decode(file_get_contents($state.'/DATABASE-READY.json'), true, 32, JSON_THROW_ON_ERROR);
        $schemaReady = json_decode(file_get_contents($state.'/SCHEMA-READY.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($databaseReady['database'] ?? null) !== 'cofrac2production' || ($databaseReady['username'] ?? null) !== 'cofrac2_app'
            || ($schemaReady['database'] ?? null) !== 'cofrac2production' || ($schemaReady['runtime_user'] ?? null) !== 'cofrac2-app'
            || ($schemaReady['business_data_empty'] ?? null) !== true || disk_free_space($state) < 5 * 1024 ** 3) {
            throw new RuntimeException('Preparation initiale ou marge disque de 5 Gio non conforme.');
        }
        $git = static function (array $arguments) use ($appPath): string {
            $process = new Process(['/usr/sbin/runuser', '-u', 'cofrac2-deploy', '--', '/usr/bin/git', '-C', $appPath, ...$arguments]);
            $process->setTimeout(20);
            if ($process->run() !== 0) {
                throw new RuntimeException('Verification Git refusee.');
            }

            return trim($process->getOutput());
        };
        if ($git(['branch', '--show-current']) !== 'v2' || $git(['status', '--porcelain']) !== ''
            || $git(['rev-parse', 'HEAD']) !== $package->manifest['revision']) {
            throw new RuntimeException('Checkout propre a la revision du paquet obligatoire.');
        }
        $vhost = '/etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf';
        if (is_link($vhost) || ! is_file($vhost) || fileowner($vhost) !== 0
            || ! hash_equals(hash_file('sha256', $appPath.'/tools/deploy/apache-production-https.conf.example'), hash_file('sha256', $vhost))
            || realpath('/etc/apache2/sites-enabled/cofrac2.geniuscontrole.com.conf') !== $vhost) {
            throw new RuntimeException('Le seul vhost cofrac2 doit rester en maintenance HTTPS statique.');
        }
        if (is_link($appPath.'/.env') || fileowner($appPath.'/.env') !== 0 || (fileperms($appPath.'/.env') & 0027) !== 0
            || is_file($appPath.'/bootstrap/cache/config.php') || is_link($appPath.'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Configuration privee non cachee attendue.');
        }
        $env = Dotenv\Dotenv::parse(file_get_contents($appPath.'/.env'));
        foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://cofrac2.geniuscontrole.com', 'APP_TIMEZONE' => 'Europe/Paris',
            'CRM_V2_ENABLED' => 'true', 'SIMULATION_MODE' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_DATABASE' => 'cofrac2production', 'DB_USERNAME' => 'cofrac2_app', 'DB_URL' => '', 'DB_SOCKET' => '',
            'MAIL_MAILER' => 'log', 'SMSFACTOR_ENABLED' => 'false', 'SMSFACTOR_MODE' => 'simulate', 'CAPCEE_ENABLED' => 'false'] as $key => $expected) {
            if (($env[$key] ?? null) !== $expected) {
                throw new RuntimeException('Configuration V2 inattendue : '.$key);
            }
        }
        if (filter_var($env['COFFRAC_OFFLINE_REHEARSAL'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Ne pas activer le mode repetition sur le serveur.');
        }
        $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
        ]);
        $grants = $db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cofrac2production' || count($grants) !== 2
            || count(array_filter($grants, fn ($grant) => str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `cofrac2production`.* TO '))) !== 1
            || count(array_filter($grants, fn ($grant) => str_starts_with($grant, 'GRANT USAGE ON *.* TO '))) !== 1
            || str_contains(implode("\n", $grants), 'WITH GRANT OPTION')) {
            throw new RuntimeException('Compte SQL non limite a la seule base V2.');
        }
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $field) {
            if ((int) $db->query("SELECT COUNT(*) FROM information_schema.$table WHERE $field = DATABASE()")->fetchColumn() !== 0) {
                throw new RuntimeException('Automatisme SQL inattendu dans la cible V2.');
            }
        }
        $tables = $db->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($tables, 'TABLE_NAME');
        foreach (['users', 'dossiers', 'appointments', 'legacy_records', 'migrations'] as $required) {
            if (! in_array($required, $names, true)) {
                throw new RuntimeException('Schema V2 initialise obligatoire.');
            }
        }
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            if (! preg_match('/^[a-z][a-z0-9_]+$/D', $name) || $table['ENGINE'] !== 'InnoDB' || $table['TABLE_TYPE'] !== 'BASE TABLE') {
                throw new RuntimeException('Objet ou moteur SQL inattendu dans la cible V2.');
            }
            if (! in_array($name, ['migrations', 'departments', 'mail_senders', 'cache', 'cache_locks'], true)
                && (int) $db->query("SELECT COUNT(*) FROM `$name`")->fetchColumn() !== 0) {
                throw new RuntimeException('Cible non vide : '.$name.'. Aucun ecrasement autorise.');
            }
        }
        $applied = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $expected = array_map(fn ($file) => basename($file, '.php'), glob($appPath.'/database/migrations/*.php'));
        $pending = array_values(array_diff($expected, $applied));
        if (array_diff($applied, $expected) || ($pending !== [] && $pending !== ['2026_10_23_210000_create_coffrac_import_tracking'])) {
            throw new RuntimeException('Migrations inattendues : examen manuel requis.');
        }
        require $appPath.'/tools/deploy/lib/CoffracDocumentArchive.php';
        $proof = CoffracDocumentArchive::verifiedIndex(
            $package->manifest['server_documents']['inventory'], '/srv/cofrac2-document-archive',
            $package->manifest['server_documents']['verification']);
        $documents = json_decode(file_get_contents($package->directory.'/documents/report.json'), true, 64, JSON_THROW_ON_ERROR);
        if ($proof['summary']['inventory_sha256'] !== ($documents['inventory_sha256'] ?? null)
            || $proof['verification_sha256'] !== ($documents['verification_sha256'] ?? null)) {
            throw new RuntimeException('Preuves physiques serveur differentes du plan documentaire.');
        }
        $package->assertUnchanged();
        if ($argc === 5) {
            $path = $argv[4];
            if (! preg_match('#^/var/lib/cofrac2-production/native-import-[A-Za-z0-9_-]+/mysql.cnf$#D', $path)
                || file_exists($path) || is_link($path)) {
                throw new RuntimeException('Destination de configuration SQL temporaire inattendue.');
            }
            $escape = static fn ($value) => str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value);
            $handle = fopen($path, 'x');
            fwrite($handle, "[client]\nhost=127.0.0.1\nport=3306\nprotocol=tcp\nuser=cofrac2_app\npassword=\"".$escape($env['DB_PASSWORD'])."\"\n");
            fclose($handle);
        }
        echo json_encode(['target_database' => 'cofrac2production', 'business_data_empty' => true,
            'pending_migrations' => $pending, 'physical_files_checked_by_size' => count($proof['files']),
            'physical_contents_rehashed' => false, 'final_cutover_ready' => false,
            'services_changed' => false, 'source_database_read' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    if ($argc !== 4 || ! preg_match('#^/srv/cofrac2-staging-imports/[A-Za-z0-9_-]+/inputs$#D', $package->directory)) {
        throw new RuntimeException('Entree runtime inattendue.');
    }
    $adminFile = dirname($package->directory).'/admin.json';
    if (is_link($adminFile) || ! is_file($adminFile) || fileowner($adminFile) !== 0
        || (fileperms($adminFile) & 0777) !== 0440 || is_writable($adminFile) || filesize($adminFile) > 4096) {
        throw new RuntimeException('Identifiants de seeder temporaires non proteges.');
    }
    $admin = json_decode(file_get_contents($adminFile), true, 16, JSON_THROW_ON_ERROR);
    if (($admin['package_sha256'] ?? null) !== $package->fingerprint || ! filter_var($admin['email'] ?? '', FILTER_VALIDATE_EMAIL)
        || mb_strlen($admin['email']) > 190 || ! is_string($admin['password'] ?? null) || strlen($admin['password']) < 8) {
        throw new RuntimeException('Identifiants du super-admin de preparation invalides.');
    }
    $app = require $appPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    config(['admin.mail' => strtolower(trim($admin['email'])), 'admin.password' => $admin['password'],
        'admin.coffrac_source_user_id' => $package->seededAdminSourceId, 'logging.default' => 'stderr']);
    $report = NativeImportTarget::production($package, function () use ($package): array {
        $dataset = new ProductionDataset($package->directory.'/dataset');
        $planning = new TechCalendarPlanningSnapshot($package->directory.'/planning', $package->manifest['planning_sha256']);
        $documents = new DocumentReconciliationPlan($package->directory.'/documents', $dataset);

        // The outer transaction includes metadata audit and final input verification, not just projection.
        return DB::transaction(function () use ($package, $dataset, $planning, $documents): array {
            $report = app(CoffracNativeImporter::class)->stageProduction($dataset, $planning);
            $report['document_verification'] = app(NativeDocumentAudit::class)->rehearse($dataset, $documents);
            $expected = json_decode(file_get_contents($package->directory.'/rehearsal.json'), true, 512, JSON_THROW_ON_ERROR);
            $adminSourceId = $package->seededAdminSourceId;
            if ($adminSourceId !== null) {
                // The sealed baseline rehearsed a separate admin; explicit identity reuse removes that one extra row.
                $expected['counts']['users']--;
            }
            foreach ($expected['counts'] as $table => $count) {
                if (($report['counts'][$table] ?? null) !== $count) {
                    throw new RuntimeException('Effectif natif different de la repetition approuvee.');
                }
            }
            $hashes = 0;
            $seededPasswords = 0;
            foreach ($dataset->credentials() as $credential) {
                $id = $report['matching']['users'][$credential['id']];
                $password = DB::table('users')->where('id', $id)->value('password');
                if ($credential['id'] === $adminSourceId) {
                    if ($id !== $report['matching']['seeded_admin']['user_id']
                        || ! DB::table('users')->where('id', $id)->where('admin', true)->exists()
                        || ! password_verify(config('admin.password'), $password)) {
                        throw new RuntimeException('Mot de passe du compte super admin repris non verifie.');
                    }
                    $seededPasswords++;

                    continue;
                }
                if (! hash_equals($credential['password_hash'], $password)) {
                    throw new RuntimeException('Hash source non conserve.');
                }
                $hashes++;
            }
            if ($seededPasswords !== ($adminSourceId === null ? 0 : 1)
                || $hashes + $seededPasswords !== $dataset->manifest['tables']['users']['count']) {
                throw new RuntimeException('Couverture des mots de passe importes incomplete.');
            }
            foreach (['jobs', 'failed_jobs', 'sessions', 'password_reset_tokens', 'mobile_access_tokens', 'mobile_push_tokens', 'dossier_sms', 'capcee_deliveries'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Session, jeton ou envoi inattendu pendant la preparation.');
                }
            }
            if (DB::table('users')->where('portal_enabled', true)->exists() || DB::table('mail_templates')->where('is_active', true)->exists()) {
                throw new RuntimeException('Portail ou modele de communication active pendant la preparation.');
            }
            $package->assertUnchanged();
            $report['staging_import'] = ['purpose' => 'isolated_production_preparation', 'package_sha256' => $package->fingerprint,
                'matching_password_hashes' => $hashes, 'seeded_source_password_resets' => $seededPasswords, 'completed_at_utc' => gmdate(DATE_ATOM),
                'external_portals_enabled' => false, 'serving_enabled' => false, 'final_cutover_ready' => false];

            return $report;
        });
    });
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Never echo exception messages or SQL bindings: they may include credentials and personal data.
    $detail = get_class($error) === RuntimeException::class ? ' '.$error->getMessage() : '';
    fwrite(STDERR, 'STOP : preparation annulee ('.get_class($error).', '.basename($error->getFile()).':'.$error->getLine().').'.$detail." Aucun detail personnel affiche.\n");
    exit(1);
}
