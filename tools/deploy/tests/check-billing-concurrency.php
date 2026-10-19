<?php

// Run only against an empty disposable MySQL container, never an application database.
// BILLING_TEST_PORT=13378 php tools/deploy/tests/check-billing-concurrency.php
declare(strict_types=1);

use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\User;
use App\Services\Crm\DossierBillingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$port = filter_var(getenv('BILLING_TEST_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
if (! $port || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Explicit disposable MySQL port and pcntl required.');
}
putenv('APP_ENV=testing');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Testing environment without config cache required.');
}
config([
    'database.default' => 'billing_test',
    'database.connections.billing_test' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
        'database' => 'testing_billing_concurrency', 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
    ],
    'crm.v2_enabled' => true, 'simulation.enabled' => false,
    'services.smsfactor.enabled' => false, 'mail.default' => 'array', 'queue.default' => 'null',
    'broadcasting.default' => 'null', 'cache.default' => 'array',
]);
if (DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n != 0) {
    throw new RuntimeException('Disposable test database must be empty. No existing table will be changed.');
}
Artisan::call('migrate', ['--force' => true]);
$user = User::factory()->create(['role' => 0, 'admin' => false]);
$dossier = Dossier::create(['reference' => 'CONCURRENCY', 'address' => 'Synthetic', 'city' => 'Test']);
$operation = $dossier->operations()->create([]);
$operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
DB::purge('billing_test');
$children = [];
$gates = [];
for ($i = 0; $i < 2; $i++) {
    [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Fork failed.');
    }
    if ($pid === 0) {
        fclose($parent);
        fread($child, 1);
        try {
            app(DossierBillingService::class)->mark($user, $dossier, $operation, ['version' => 1, 'invoice_reference' => 'F-TEST']);
            exit(0);
        } catch (ValidationException $exception) {
            exit(isset($exception->errors()['billing']) ? 20 : 21);
        } catch (Throwable $exception) {
            fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
            exit(22);
        }
    }
    fclose($child);
    $children[] = $pid;
    $gates[] = $parent;
}
foreach ($gates as $gate) {
    fwrite($gate, '1');
    fclose($gate);
}
$results = [];
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $results[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
}
sort($results);
if ($results !== [0, 20] || DossierBilling::count() !== 1 || $dossier->activities()->where('kind', 'billing')->count() !== 1
    || $dossier->fresh()->version !== 2) {
    throw new RuntimeException('Concurrency invariant failed: '.json_encode($results));
}
echo "MySQL concurrency OK: one successful mark, one rejected duplicate, one audit entry.\n";
