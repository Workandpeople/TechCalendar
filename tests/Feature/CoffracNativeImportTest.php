<?php

use App\Models\Dossier;
use App\Models\User;
use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    Http::preventStrayRequests();
    $this->datasetDirectory = productionCoffracFixture();
});

afterEach(function () {
    File::deleteDirectory($this->datasetDirectory);
});

it('rehearses native source data with a single seeded admin and exact private account mappings', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset);
    expect(DB::table('users')->count())->toBe(7)
        ->and(DB::table('users')->where('admin', true)->value('email'))->toBe('seed-admin@example.test')
        ->and(DB::table('users')->where('admin', true)->count())->toBe(1)
        ->and(DB::table('users')->where('id', $report['matching']['users'][11])->value('role'))->toBe(0)
        ->and(DB::table('users')->where('id', $report['matching']['users'][12])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('users')->where('id', $report['matching']['users'][51])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('coffrac_user_links')->where('source_user_id', 51)->value('access_state'))->toBe('profile_review')
        ->and(DB::table('external_delegataires')->count())->toBe(2)
        ->and(DB::table('users')->where('portal_enabled', true)->count())->toBe(0)
        ->and(DB::table('external_delegataires')->where('portal_categories', '!=', '[]')->count())->toBe(0)
        ->and($report['native_import_ready'])->toBeFalse();
    foreach ($dataset->credentials() as $credential) {
        $password = DB::table('users')->where('id', $report['matching']['users'][$credential['id']])->value('password');
        expect($password)->toBe($credential['password_hash'])->and(Hash::check('Source-password-123', $password))->toBeTrue();
    }
    $client = DB::table('external_delegataires')->where('external_id', '31')->value('id');
    $delegate = DB::table('external_delegataires')->where('external_id', '41')->value('id');
    expect(DB::table('dossiers')->where('id', 101)->value('client_id'))->toBe($client)
        ->and(DB::table('dossiers')->where('id', 101)->value('delegataire_id'))->toBe($delegate)
        ->and(json_decode(DB::table('dossiers')->where('id', 101)->value('client_contact'), true)['email'])->toBe('source-31@example.test')
        ->and(DB::table('dossiers')->where('id', 101)->value('planning_user_id'))->toBe($report['matching']['users'][11])
        ->and(DB::table('dossier_billings')->where('dossier_id', 102)->exists())->toBeTrue()
        ->and(DB::table('dossier_operations')->where('id', 202)->value('legacy_status'))->toBe('NON CONFORMITES')
        ->and($report['issues']['appointment_not_projectable']['source_ids'])->toBe([302])
        ->and($report['issues']['dossier_without_operation']['source_ids'])->toBe([103])
        ->and(DB::table('legacy_records')->where('source_table', 'dossier_document')->count())->toBe(1)
        ->and(DB::table('dossier_activities')->where('kind', 'legacy_comment')->value('author_id'))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(DB::table('dossier_sms')->count())->toBe(0);
    expect(app(CoffracNativeImporter::class)->rehearse($dataset))->toBe($report)
        ->and(DB::table('users')->count())->toBe(7);
    $admin = User::where('admin', true)->sole();
    $dossier = Dossier::findOrFail(102);
    expect(fn () => app(DossierBillingService::class)->mark($admin, $dossier, null, ['version' => $dossier->version]))
        ->toThrow(ValidationException::class);
    expect(DB::table('dossier_billings')->where('dossier_id', 102)->count())->toBe(1);
    $document = json_decode(DB::table('legacy_records')->where('source_table', 'dossier_document')->value('payload'), true);
    expect(app(ClientAccess::class)->legacyDocumentVisibility($document))
        ->toBe(['client_visible' => false, 'delegataire_visible' => false]);
    Http::assertNothingSent();
});

it('refuses outbound or simulation settings even with the rehearsal opt-in', function (string $key, mixed $value) {
    config([$key => $value]);
    expect(fn () => CoffracNativeImporter::assertRehearsalTarget())->toThrow(RuntimeException::class, 'Cible refusee');
    expect(DB::table('coffrac_import_runs')->count())->toBe(0);
})->with([
    'smtp' => ['mail.default', 'smtp'],
    'sms' => ['services.smsfactor.enabled', true],
    'capcee' => ['capcee.enabled', true],
    'simulation' => ['simulation.enabled', true],
    'missing opt in' => ['coffrac.offline_rehearsal', false],
]);

it('rejects production and ordinary local targets before opening a database connection', function () {
    $original = config('database.default');
    try {
        app()->detectEnvironment(fn () => 'production');
        config(['database.default' => 'mysql', 'database.connections.mysql.host' => 'must-not-connect.invalid',
            'database.connections.mysql.database' => 'cofrac2production']);
        expect(fn () => CoffracNativeImporter::assertRehearsalTarget())->toThrow(RuntimeException::class, 'Cible refusee');
        app()->detectEnvironment(fn () => 'testing');
        expect(fn () => CoffracNativeImporter::assertRehearsalTarget())->toThrow(RuntimeException::class, 'Cible refusee');
    } finally {
        config(['database.default' => $original]);
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('refuses a nonempty database without modifying accounts', function () {
    DB::table('users')->insert(['first_name' => 'Existing', 'last_name' => 'User', 'email' => 'existing@example.test', 'password' => 'not-a-real-password']);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory)))
        ->toThrow(RuntimeException::class, 'Cible non vide');
    expect(DB::table('users')->count())->toBe(1)->and(DB::table('legacy_records')->count())->toBe(0);
});

it('refuses a seeded admin email collision rather than upgrading a source account', function () {
    config(['admin.mail' => 'SOURCE-11@example.test']);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory)))
        ->toThrow(RuntimeException::class, 'reserve');
    expect(DB::table('users')->count())->toBe(0);
});

it('rolls back the entire import when credential ownership does not match', function () {
    $path = $this->datasetDirectory.'/credentials.jsonl';
    $rows = file($path);
    $last = json_decode(array_pop($rows), true);
    $last['id'] = 999;
    file_put_contents($path, implode('', $rows).json_encode($last)."\n");
    sealProductionCoffracFixture($this->datasetDirectory);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory)))
        ->toThrow(RuntimeException::class, 'Credential sans compte');
    foreach (['users', 'external_delegataires', 'services', 'legacy_records', 'coffrac_import_runs', 'coffrac_user_links'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

it('rejects changed or public files and never archives secrets', function () {
    $path = $this->datasetDirectory.'/users.jsonl';
    file_put_contents($path, "\n", FILE_APPEND);
    expect(fn () => new ProductionDataset($this->datasetDirectory))->toThrow(RuntimeException::class, 'modifiee');
    $lines = file($path);
    array_pop($lines);
    $row = json_decode($lines[0], true);
    $row['password'] = 'DO-NOT-PRINT';
    $lines[0] = json_encode($row)."\n";
    file_put_contents($path, implode('', $lines));
    sealProductionCoffracFixture($this->datasetDirectory);
    $dataset = new ProductionDataset($this->datasetDirectory);
    expect(fn () => iterator_to_array($dataset->rows('users')))->toThrow(RuntimeException::class, 'Secret interdit');
    chmod($path, 0644);
    expect(fn () => new ProductionDataset($this->datasetDirectory))->toThrow(RuntimeException::class, 'publique');
});

it('detects a file changed after verification and refuses unrecognised bcrypt values', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    file_put_contents($this->datasetDirectory.'/clients.jsonl', "\n");
    expect(fn () => iterator_to_array($dataset->rows('clients')))->toThrow(JsonException::class);
    file_put_contents($this->datasetDirectory.'/clients.jsonl', '');
    file_put_contents($this->datasetDirectory.'/credentials.jsonl', implode('', array_map(fn ($row) => json_encode(['id' => $row['id'], 'password_hash' => 'plaintext'])."\n", $dataset->keyed('users'))));
    sealProductionCoffracFixture($this->datasetDirectory);
    expect(fn () => iterator_to_array((new ProductionDataset($this->datasetDirectory))->credentials()))->toThrow(RuntimeException::class, 'Hash de compte incompatible');
});
