<?php

declare(strict_types=1);

use Cofrac2\Deploy\TechCalendarInternalUsersPlan;

umask(0077);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $app = '/var/www/cofrac2';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 5 || $argv[1] !== '--plan'
        || realpath($app) !== $app
        || ! preg_match('#^/var/lib/cofrac2-production/internal-users-plan-[A-Za-z0-9_-]+/report\.json$#D', $argv[4])
        || file_exists($argv[4]) || is_link($argv[4])) {
        throw new RuntimeException('Controle reserve a la seule production Cofrac2.');
    }
    require $app.'/vendor/autoload.php';
    require __DIR__.'/lib/TechCalendarInternalUsersPlan.php';
    $source = new TechCalendarInternalUsersPlan($argv[2], $argv[3]);
    $envFile = $app.'/.env';
    if (is_link($envFile) || ! is_file($envFile) || fileowner($envFile) !== 0 || (fileperms($envFile) & 0027) !== 0) {
        throw new RuntimeException('Configuration V2 privee et protegee par root requise.');
    }
    $envHash = hash_file('sha256', $envFile);
    $env = Dotenv\Dotenv::parse(file_get_contents($envFile));
    foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://cofrac2.geniuscontrole.com',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'cofrac2production', 'DB_USERNAME' => 'cofrac2_app', 'DB_URL' => '', 'DB_SOCKET' => ''] as $key => $value) {
        if (($env[$key] ?? null) !== $value) {
            throw new RuntimeException('Cible V2 inattendue : '.$key);
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
    ]);
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'cofrac2production' || count($grants) !== 2
        || count(array_filter($grants, fn ($g) => str_starts_with($g, 'GRANT ALL PRIVILEGES ON `cofrac2production`.* TO '))) !== 1
        || count(array_filter($grants, fn ($g) => str_starts_with($g, 'GRANT USAGE ON *.* TO '))) !== 1
        || str_contains(implode("\n", $grants), 'WITH GRANT OPTION')) {
        throw new RuntimeException('Compte SQL non limite a la seule base Cofrac2.');
    }
    $tables = ['users', 'departments', 'services', 'external_service_aliases', 'service_user', 'department_user'];
    $tableCheck = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=?');
    foreach ($tables as $table) {
        $tableCheck->execute([$table, 'BASE TABLE']);
        if ($tableCheck->fetchColumn() !== 'InnoDB') {
            throw new RuntimeException('Table cible absente ou non transactionnelle.');
        }
    }
    $pdo->exec('SET SESSION MAX_EXECUTION_TIME=10000');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $report = $source->inspect($pdo);
    } finally {
        $pdo->rollBack();
    }
    if (! hash_equals($envHash, hash_file('sha256', $envFile))
        || ! hash_equals($argv[3], hash_file('sha256', $argv[2]))) {
        throw new RuntimeException('Configuration ou source changee pendant le controle.');
    }
    $report['target_database'] = 'cofrac2production';
    $report['checked_at_utc'] = gmdate(DATE_ATOM);
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    $handle = fopen($argv[4], 'x');
    if (fwrite($handle, $json) !== strlen($json)) {
        throw new RuntimeException('Rapport incomplet : ne pas utiliser le fichier.');
    }
    fclose($handle);
    echo json_encode($report['summary'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    echo "Rapprochement en lecture seule termine. Aucun import, mot de passe modifie ou envoi.\n";
} catch (Throwable $exception) {
    // PDO errors and invalid values can contain credentials or personal records.
    $detail = $exception::class === RuntimeException::class ? ' '.$exception->getMessage() : '';
    fwrite(STDERR, 'STOP : rapprochement annule ('.$exception::class.').'.$detail.' Aucun secret affiche.'.PHP_EOL);
    exit(1);
}
