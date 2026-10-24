<?php

declare(strict_types=1);

// Run locally. Outputs a self-contained command to paste into the production CT console.
// Neither the application nor a database connection is loaded by this generator.
$project = $argv[1] ?? '/var/www/TechCalendar';
$planningOnly = ($argv[2] ?? null) === '--planning-only';
$productionSettings = ($argv[2] ?? null) === '--production-settings';
if (! str_starts_with($project, '/') || str_contains($project, "\n") || str_contains($project, "\r")
    || (isset($argv[2]) && ! $planningOnly && ! $productionSettings) || $argc > 3) {
    fwrite(STDERR, "Indiquer le chemin absolu du projet dans le CT.\n");
    exit(1);
}

$collector = file_get_contents(__DIR__.'/collect-techcalendar-references.php');
if ($collector === false) {
    throw new RuntimeException('Collecteur introuvable.');
}
$collector = preg_replace('/^<\?php\s+declare\(strict_types=1\);\s*/', '', $collector);

$runner = <<<'PHP'
umask(0077);
ini_set('display_errors', '0');
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    // Load configuration only: do not boot application providers, jobs or integrations.
    (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
    (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
    $database = $app->make('config')->get('database');
    $connection = $database['connections'][$database['default']] ?? [];
    $connection = (new Illuminate\Database\ConfigurationUrlParser)->parseConfiguration($connection);
    if (! in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)
        || ! empty($connection['prefix']) || isset($connection['read']) || isset($connection['write'])) {
        throw new RuntimeException('Connexion inattendue. Export annule.');
    }
    $capsule = new Illuminate\Database\Capsule\Manager;
    $capsule->addConnection($connection);
    if ($requireProduction && ($app->make('config')->get('app.env') !== 'production'
        || preg_match('/demo|simulat|test/i', $capsule->getConnection()->getDatabaseName()))) {
        throw new RuntimeException('Configuration de demonstration/test refusee pour la reprise des reglages TechCalendar.');
    }
    $snapshot = $collect($capsule->getConnection()->getPdo(), $planningOnly);
    $snapshot['app_environment'] = $app->make('config')->get('app.env');
    $snapshot['source_database'] = $capsule->getConnection()->getDatabaseName();
    $snapshot['source_project'] = getcwd();
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $gzip = gzencode($json, 6);
    if ($gzip === false) {
        throw new RuntimeException('Compression impossible.');
    }
    $directory = rtrim(sys_get_temp_dir(), '/').'/techcalendar-references-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Creation du dossier prive impossible.');
    }
    $file = $directory.'/references.json.gz';
    if (file_put_contents($file, $gzip, LOCK_EX) !== strlen($gzip)) {
        throw new RuntimeException('Export incomplet. Ne pas utiliser le fichier.');
    }
    $hash = hash('sha256', $gzip);
    $checksum = $hash."  references.json.gz\n";
    if (file_put_contents($directory.'/SHA256SUMS', $checksum, LOCK_EX) !== strlen($checksum)) {
        throw new RuntimeException('Ecriture du checksum impossible.');
    }
    echo "EXPORT_DIR=".$directory."\n";
    echo "SHA256=".$hash."\n";
    echo "TAILLE_OCTETS=".strlen($gzip)."\n";
    foreach ($snapshot['tables'] as $table => $data) {
        echo $table.' : '.$data['count']."\n";
    }
    echo "Export termine. Donnees personnelles : ne pas publier ce fichier.\n";
    echo 'RECUPERATION : base64 -w76 '.escapeshellarg($file)."\n";
} catch (Throwable $exception) {
    // Database exceptions can contain credentials or record contents.
    $message = $exception instanceof PDOException
        ? 'Lecture SQL impossible. Verifier les droits et le schema ; aucune donnee exportee validee.'
        : ($exception instanceof RuntimeException ? $exception->getMessage() : 'Erreur de configuration ou de collecte.');
    fwrite(STDERR, "EXPORT ANNULE : ".$message."\n");
    exit(1);
}
PHP;

echo "(\ncd ".escapeshellarg($project)." || exit 1\nphp -d display_errors=0 <<'TECHCALENDAR_REFERENCE_EXPORT_PHP'\n<?php\n";
echo '$planningOnly = '.($planningOnly ? 'true' : 'false').";\n";
echo '$requireProduction = '.($planningOnly || $productionSettings ? 'true' : 'false').";\n";
echo '$collect = (static function () {'."\n".$collector."\n})();\n".$runner."\n";
echo "TECHCALENDAR_REFERENCE_EXPORT_PHP\n)\n";
