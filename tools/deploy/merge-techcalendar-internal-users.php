<?php

declare(strict_types=1);

use Cofrac2\Deploy\TechCalendarInternalUsersMerge;
use Cofrac2\Deploy\TechCalendarInternalUsersPlan;

umask(0077);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$committed = false;
try {
    $app = '/var/www/cofrac2';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 4 || ! in_array($argv[1], ['--check', '--apply'], true)
        || ! preg_match('#^/var/lib/cofrac2-production/internal-users-merge-[A-Za-z0-9_-]+/inputs$#D', $argv[2])
        || realpath($argv[2]) !== $argv[2] || realpath($app) !== $app) {
        throw new RuntimeException('Fusion reservee a la seule production Cofrac2 et a des entrees privees.');
    }
    $inputs = $argv[2];
    $run = dirname($inputs);
    $output = $run.($argv[1] === '--check' ? '/preflight.json' : '/report.json');
    if ($argv[3] !== $output || file_exists($output) || is_link($output)) {
        throw new RuntimeException('Destination privee inattendue.');
    }
    require $app.'/vendor/autoload.php';
    require __DIR__.'/lib/TechCalendarInternalUsersPlan.php';
    require __DIR__.'/lib/TechCalendarInternalUsersMerge.php';
    $policy = json_decode(file_get_contents($inputs.'/DECISIONS.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($policy['format'] ?? null) !== 'cofrac2.internal-users-merge-approval' || ($policy['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{64}$/D', $policy['source_sha256'] ?? '')
        || ! preg_match('/^[a-f0-9]{64}$/D', $policy['plan_sha256'] ?? '')) {
        throw new RuntimeException('Approbation de fusion invalide.');
    }
    $snapshot = new TechCalendarInternalUsersPlan($inputs.'/references.json.gz', $policy['source_sha256']);
    $planFile = $inputs.'/approved-plan.json.gz';
    if (is_link($planFile) || ! is_file($planFile) || (fileperms($planFile) & 0077) !== 0
        || filesize($planFile) > 8 * 1024 ** 2 || ! hash_equals($policy['plan_sha256'], hash_file('sha256', $planFile))) {
        throw new RuntimeException('Rapport approuve modifie ou absent.');
    }
    $json = gzdecode(file_get_contents($planFile), 32 * 1024 ** 2);
    $approved = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (($approved['target_database'] ?? null) !== 'cofrac2production') {
        throw new RuntimeException('Le rapport doit concerner uniquement Cofrac2.');
    }
    $envFile = $app.'/.env';
    if (is_link($envFile) || ! is_file($envFile) || fileowner($envFile) !== 0 || (fileperms($envFile) & 0027) !== 0) {
        throw new RuntimeException('Configuration V2 non protegee.');
    }
    $envHash = hash_file('sha256', $envFile);
    $policyHash = hash_file('sha256', $inputs.'/DECISIONS.json');
    $env = Dotenv\Dotenv::parse(file_get_contents($envFile));
    foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://cofrac2.geniuscontrole.com', 'APP_TIMEZONE' => 'Europe/Paris',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'cofrac2production',
        'DB_USERNAME' => 'cofrac2_app', 'DB_URL' => '', 'DB_SOCKET' => ''] as $key => $value) {
        if (($env[$key] ?? null) !== $value) {
            throw new RuntimeException('Cible V2 inattendue : '.$key);
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=cofrac2production;charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
    ]);
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    if (count($grants) !== 2 || count(array_filter($grants, fn ($g) => str_starts_with($g, 'GRANT ALL PRIVILEGES ON `cofrac2production`.* TO '))) !== 1
        || count(array_filter($grants, fn ($g) => str_starts_with($g, 'GRANT USAGE ON *.* TO '))) !== 1
        || str_contains(implode("\n", $grants), 'WITH GRANT OPTION') || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'cofrac2production') {
        throw new RuntimeException('Compte SQL non limite a la seule V2.');
    }
    $tableCheck = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=?');
    foreach ($pdo->query('SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $object) {
        if ($object['ENGINE'] !== 'InnoDB' || $object['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new RuntimeException('Vue ou moteur inattendu dans la cible V2 : sauvegarde et fusion refusees.');
        }
    }
    foreach (['users', 'departments', 'services', 'external_service_aliases', 'service_user', 'department_user',
        'sessions', 'mobile_access_tokens', 'mobile_push_tokens'] as $table) {
        $tableCheck->execute([$table, 'BASE TABLE']);
        if ($tableCheck->fetchColumn() !== 'InnoDB') {
            throw new RuntimeException('Table cible absente ou non transactionnelle.');
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
        throw new RuntimeException('Trigger SQL inattendu : fusion refusee.');
    }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    $pdo->exec('SET SESSION MAX_EXECUTION_TIME=10000');
    $assertInputs = static function () use ($inputs, $policy, $envFile, $envHash, $policyHash): void {
        if (! hash_equals($envHash, hash_file('sha256', $envFile))
            || ! hash_equals($policyHash, hash_file('sha256', $inputs.'/DECISIONS.json'))
            || ! hash_equals($policy['source_sha256'], hash_file('sha256', $inputs.'/references.json.gz'))
            || ! hash_equals($policy['plan_sha256'], hash_file('sha256', $inputs.'/approved-plan.json.gz'))) {
            throw new RuntimeException('Entree ou configuration modifiee pendant la fusion.');
        }
    };
    $writeJson = static function (string $file, array $data): void {
        $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($file, 'x');
        if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
            throw new RuntimeException('Ecriture du journal prive incomplete.');
        }
        fclose($handle);
    };
    $merge = new TechCalendarInternalUsersMerge;
    if ($argv[1] === '--check') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $resolved = $merge->resolve($snapshot, $approved, $snapshot->readTarget($pdo), $policy['decisions'] ?? []);
        } finally {
            $pdo->rollBack();
        }
        $assertInputs();
        $summary = $resolved['summary'];
        $summary['legacy_habilitation_warnings'] = count(array_filter($resolved['warnings'], fn ($w) => $w['kind'] === 'legacy_habilitations_not_granted'));
        $summary['active_legacy_habilitation_warnings'] = count(array_filter($resolved['warnings'], fn ($w) => $w['kind'] === 'legacy_habilitations_not_granted' && ! $w['inactive']));
        $summary['inactive_primary_departments_cleared'] = count(array_filter($resolved['warnings'], fn ($w) => $w['kind'] === 'inactive_unknown_primary_department'));
        $writeJson($output, ['source_sha256' => $snapshot->sourceSha256, 'policy_sha256' => $policyHash, 'summary' => $summary]);
        $escape = static fn ($v) => str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $v);
        $cnf = fopen($run.'/mysql.cnf', 'x');
        fwrite($cnf, "[client]\nhost=127.0.0.1\nport=3306\nprotocol=tcp\nuser=cofrac2_app\npassword=\"".$escape($env['DB_PASSWORD'])."\"\n");
        fclose($cnf);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    $backup = json_decode(file_get_contents($run.'/backup.json'), true, 16, JSON_THROW_ON_ERROR);
    if (($backup['policy_sha256'] ?? null) !== $policyHash || ! is_file($run.'/database-before.sql.gz')
        || is_link($run.'/database-before.sql.gz') || filesize($run.'/database-before.sql.gz') < 64
        || ! hash_equals($backup['sha256'] ?? '', hash_file('sha256', $run.'/database-before.sql.gz'))) {
        throw new RuntimeException('Sauvegarde V2 valide obligatoire avant la transaction.');
    }
    $assertInputs();
    $report = $merge->apply($pdo, $snapshot, $approved, $policy['decisions'] ?? [],
        static function (array $pending) use ($assertInputs, $writeJson, $run, $policyHash): void {
            $assertInputs();
            $writeJson($run.'/pending-report.json', $pending + ['policy_sha256' => $policyHash, 'committed' => false]);
        });
    $committed = true;
    $report += ['policy_sha256' => $policyHash, 'committed' => true];
    $writeJson($output, $report);
    echo json_encode(array_intersect_key($report, array_flip(['created', 'updated', 'deactivated', 'restored',
        'protected_super_admin', 'existing_password_hashes_preserved', 'target_only_users_untouched',
        'departments_added', 'departments_removed', 'habilitations_added', 'habilitations_removed', 'revoked', 'outbound_deliveries'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    $detail = $exception::class === RuntimeException::class ? ' '.$exception->getMessage() : '';
    fwrite(STDERR, 'STOP : fusion non validee ('.$exception::class.').'.$detail.' Aucun secret affiche.'.PHP_EOL);
    if ($committed) {
        fwrite(STDERR, "ATTENTION : transaction deja validee, finalisation du journal en echec. Ne pas relancer ni restaurer automatiquement.\n");
    }
    exit(1);
}
