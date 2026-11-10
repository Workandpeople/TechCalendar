<?php

declare(strict_types=1);

use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

if (! is_file('/.dockerenv') || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'dossier_duplicate_test') {
    exit(1);
}
require '/workspace/vendor/autoload.php';
$app = require '/workspace/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'dossier_duplicate_test') {
    exit(1);
}
config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
Http::preventStrayRequests();
Mail::fake();
Queue::fake();
$geocoder = Mockery::mock(MapboxAddressGeocoder::class);
$geocoder->shouldReceive('geocode')->andReturn(['latitude' => 48.85, 'longitude' => 2.35, 'mapbox_id' => 'address.fixture']);
$app->instance(MapboxAddressGeocoder::class, $geocoder);
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $app->make(Kernel::class)->call('migrate:fresh', ['--force' => true]);
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $service = Service::create(['type' => 'COFRAC', 'name' => 'RACE-FICHE', 'average_duration_minutes' => 60]);
    file_put_contents('/fixtures/race.json', json_encode(['actor' => $actor->id, 'service' => $service->id], JSON_THROW_ON_ERROR));
    exit;
}
$fixture = json_decode(file_get_contents('/fixtures/race.json'), true, 8, JSON_THROW_ON_ERROR);
$data = ['reference' => 'RACE-'.$mode, 'company_name' => 'Fixture', 'address' => '1 Rue Test', 'postal_code' => '75001',
    'city' => 'Paris', 'phone' => '0612345678', 'mapbox_id' => 'address.fixture', 'service_ids' => [$fixture['service']]];
if (in_array($mode, ['a', 'b'], true)) {
    $ready = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($mode, &$ready): void {
        if ($ready || ! str_contains($sql, '`services`') || ! str_contains($sql, 'for update')) {
            return;
        }
        $ready = true;
        file_put_contents('/fixtures/ready-'.$mode, 'ready');
        $until = microtime(true) + 10;
        while (! is_file('/fixtures/ready-a') || ! is_file('/fixtures/ready-b')) {
            if (microtime(true) > $until) {
                throw new RuntimeException('Race barrier timed out.');
            }
            usleep(10000);
        }
    });
    try {
        $dossier = app(DossierRegistry::class)->create(User::findOrFail($fixture['actor']), $data);
        $result = ['state' => 'created', 'id' => $dossier->id];
    } catch (ValidationException $error) {
        if (! array_key_exists('service_ids', $error->errors())) {
            throw $error;
        }
        $result = ['state' => 'blocked'];
    }
    file_put_contents('/fixtures/result-'.$mode.'.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit;
}
if ($mode !== 'check') {
    exit(1);
}
$states = array_map(fn ($name) => json_decode(file_get_contents('/fixtures/result-'.$name.'.json'), true)['state'], ['a', 'b']);
sort($states);
assert($states === ['blocked', 'created']);
assert(Dossier::count() === 1 && DB::table('dossier_operations')->count() === 1 && DB::table('dossier_activities')->count() === 1);
// Worst-case lookup on a populous fiche, not a tiny empty fixture.
foreach (range(0, 19) as $batch) {
    $rows = [];
    foreach (range(1, 500) as $offset) {
        $index = $batch * 500 + $offset;
        $rows[] = ['reference' => 'PERF-'.$index, 'address' => $index.' Rue Fixture', 'postal_code' => '75002', 'city' => 'Paris'];
    }
    DB::table('dossiers')->insert($rows);
}
DB::statement('INSERT INTO dossier_operations (dossier_id,service_id) SELECT id,? FROM dossiers WHERE reference LIKE ?', [$fixture['service'], 'PERF-%']);
$start = microtime(true);
app(DossierRegistry::class)->create(User::findOrFail($fixture['actor']), [...$data, 'phone' => null, 'address' => 'Nouvelle voie']);
$milliseconds = (int) ((microtime(true) - $start) * 1000);
assert($milliseconds < 5000);
echo json_encode(['concurrent_submissions' => 2, 'created' => 1, 'blocked' => 1,
    'scan_candidates' => 10001, 'worst_case_creation_ms' => $milliseconds, 'external_requests' => 0], JSON_THROW_ON_ERROR)."\n";
