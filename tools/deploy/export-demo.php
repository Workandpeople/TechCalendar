<?php

// Local-only export helper. Secrets are written to a private file by prepare-demo.sh.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
if (! $app->environment('local') || ! config('simulation.enabled') || ! config('crm.v2_enabled')
    || $connection->getDriverName() !== 'mysql' || $connection->getConfig('host') !== 'mysql'
    || $connection->getDatabaseName() !== 'techcalendar_v2_simulation') {
    throw new RuntimeException('Export refuse : seule la simulation locale Docker V2 est autorisee.');
}
if (DB::table('jobs')->exists()) {
    throw new RuntimeException('Attendre la fin des jobs locaux avant de preparer le paquet.');
}

if (($argv[1] ?? '') === 'secrets') {
    $values = [
        'APP_KEY' => config('app.key'),
        'MAPBOX_TOKEN' => config('services.mapbox.token'),
        'OPENAI_API_KEY' => config('services.openai.api_key'),
        'OPENAI_MODEL' => config('services.openai.model'),
        'GLOBAL_PLUS_API_KEY' => rtrim((string) config('services.global_plus.api_url'), '/') === 'https://cee-api.test.globalplus.fr'
            ? config('services.global_plus.api_key') : '',
        'GLOBAL_PLUS_BUREAU_ID' => config('services.global_plus.bureau_id'),
        'DB_PASSWORD' => bin2hex(random_bytes(24)),
        'REVERB_APP_ID' => (string) random_int(10000000, 99999999),
        'REVERB_APP_KEY' => bin2hex(random_bytes(16)),
        'REVERB_APP_SECRET' => bin2hex(random_bytes(32)),
    ];
    if (! filled($values['APP_KEY'])) {
        throw new RuntimeException('APP_KEY locale absente.');
    }
    foreach ($values as $key => $value) {
        $value = (string) $value;
        $encoded = str_replace('$', '\\$', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $line = $key.'='.$encoded."\n";
        if ((Dotenv\Dotenv::parse($line)[$key] ?? null) !== $value) {
            throw new RuntimeException('Encodage de configuration refuse.');
        }
        echo $line;
    }
    exit(0);
}
if (($argv[1] ?? '') !== 'metadata') {
    throw new RuntimeException('Mode attendu : metadata ou secrets.');
}
$tables = $connection->select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME', ['BASE TABLE']);
$counts = [];
foreach ($tables as $table) {
    if ($table->engine !== 'InnoDB') {
        throw new RuntimeException('Table non transactionnelle : export interrompu.');
    }
    $counts[$table->name] = DB::table($table->name)->count();
}
echo json_encode([
    'format' => 'techcalendar.v2-demo',
    'generated_at' => date(DATE_ATOM),
    'source_database' => $connection->getDatabaseName(),
    'mysql_version' => $connection->selectOne('SELECT VERSION() AS version')->version,
    'minimum_php' => '8.4.1',
    'tables' => $counts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
