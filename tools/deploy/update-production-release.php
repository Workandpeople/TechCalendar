<?php

declare(strict_types=1);
use Cofrac2\Deploy\ProductionAssetUpdate;

// Root-only preflight; no Laravel bootstrap, source database or shared MySQL credentials.
umask(0077);
ini_set('display_errors', '0');
try {
    $app = '/var/www/cofrac2';
    if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 6 || $argv[1] !== '--check'
        || realpath(__FILE__) !== $app.'/tools/deploy/update-production-release.php') {
        throw new RuntimeException('Controle reserve au serveur de production V2.');
    }
    require $app.'/vendor/autoload.php';
    require __DIR__.'/lib/ProductionAssetUpdate.php';
    $bundle = new ProductionAssetUpdate($argv[2], $argv[3]);
    $prepared = json_decode(file_get_contents('/var/lib/cofrac2-production/RUNTIME-PREPARED.json'), true, 32, JSON_THROW_ON_ERROR);
    if ($bundle->manifest['revision'] !== $argv[4] || $bundle->manifest['from_revision'] !== ($prepared['revision'] ?? null)
        || ! hash_equals($prepared['env_sha256'] ?? '', hash_file('sha256', $app.'/.env'))) {
        throw new RuntimeException('Revision du code, base du paquet ou configuration preparee differente.');
    }
    $env = Dotenv\Dotenv::parse(file_get_contents($app.'/.env'));
    $mailEnabled = match ($env['MAIL_DELIVERY_ENABLED'] ?? null) {
        'true' => true,
        'false' => false,
        default => throw new RuntimeException('Verrou mail explicite requis.'),
    };
    foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'cofrac2production',
        'DB_USERNAME' => 'cofrac2_app', 'DB_URL' => '', 'DB_SOCKET' => '', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'MAIL_MAILER' => $mailEnabled ? 'smtp' : 'log', 'SMSFACTOR_ENABLED' => 'false', 'SMSFACTOR_MODE' => 'simulate', 'CAPCEE_ENABLED' => 'false'] as $key => $value) {
        if (($env[$key] ?? null) !== $value) {
            throw new RuntimeException('Configuration de recette V2 modifiee : '.$key);
        }
    }
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', 'cofrac2_app', $env['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $grants = $db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cofrac2production' || count($grants) !== 2
        || count(array_filter($grants, fn ($grant) => str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `cofrac2production`.* TO '))) !== 1
        || count(array_filter($grants, fn ($grant) => str_starts_with($grant, 'GRANT USAGE ON *.* TO '))) !== 1
        || str_contains(implode("\n", $grants), 'WITH GRANT OPTION')) {
        throw new RuntimeException('Compte SQL non limite a la base V2.');
    }
    foreach ($db->query('SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table) {
        if ($table['ENGINE'] !== 'InnoDB' || $table['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new RuntimeException('Moteur ou objet SQL inattendu dans la V2.');
        }
    }
    $applied = $db->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $expected = array_map(fn ($file) => basename($file, '.php'), glob($app.'/database/migrations/*.php'));
    $pending = array_values(array_diff($expected, $applied));
    $allowed = ['2026_10_25_110000_add_reversible_dossier_deletion', '2026_10_25_120000_add_automatic_capcee_reports',
        '2026_10_26_100000_activate_existing_mail_templates', '2026_10_26_110000_add_dossier_inspection_geocode'];
    if (array_diff($pending, $allowed) !== [] || array_diff($applied, $expected) !== [] || $db->query('SELECT COUNT(*) FROM dossiers')->fetchColumn() < 1
        || disk_free_space('/var/lib/cofrac2-production') < 5 * 1024 ** 3) {
        throw new RuntimeException('Migrations inattendues, base vide ou marge disque insuffisante.');
    }
    foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA'] as $table => $column) {
        if ((int) $db->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn() !== 0) {
            throw new RuntimeException('Automatisme SQL inattendu.');
        }
    }
    $path = $argv[5];
    if (! preg_match('#^/var/lib/cofrac2-production/release-[A-Za-z0-9_-]+/mysql\.cnf$#D', $path) || file_exists($path) || is_link($path)) {
        throw new RuntimeException('Destination SQL privee inattendue.');
    }
    $escape = fn ($value) => str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value);
    $handle = fopen($path, 'x');
    fwrite($handle, "[client]\nhost=127.0.0.1\nport=3306\nprotocol=tcp\nuser=cofrac2_app\npassword=\"".$escape($env['DB_PASSWORD'])."\"\n");
    fclose($handle);
    echo json_encode(['from_revision' => $prepared['revision'], 'revision' => $argv[4], 'pending_migrations' => $pending,
        'asset_probe' => $bundle->assetProbe, 'source_database_read' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    $detail = $exception::class === RuntimeException::class ? ' '.$exception->getMessage() : '';
    fwrite(STDERR, 'STOP : controle de mise a jour refuse ('.$exception::class.').'.$detail.' Aucun secret affiche.'.PHP_EOL);
    exit(1);
}
