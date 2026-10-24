<?php

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\DocumentReconciliationPlan;
use App\Services\Coffrac\NativeDocumentAudit;
use App\Services\Coffrac\ProductionDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    Http::preventStrayRequests();
    $this->datasetDirectory = productionCoffracFixture();
    $this->dataset = new ProductionDataset($this->datasetDirectory);
    $this->planDirectory = productionCoffracDocumentPlanFixture($this->dataset);
});

afterEach(function () {
    File::deleteDirectory($this->datasetDirectory);
    File::deleteDirectory($this->planDirectory);
});

it('matches native ownership, metadata and private ACLs without creating or changing documents', function () {
    app(CoffracNativeImporter::class)->rehearse($this->dataset);
    $plan = new DocumentReconciliationPlan($this->planDirectory, $this->dataset);
    $before = DB::table('legacy_records')->where('source_table', 'dossier_document')->first();
    DB::enableQueryLog();
    $report = app(NativeDocumentAudit::class)->rehearse($this->dataset, $plan);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($report['native_database_links_match_plan'])->toBeTrue()
        ->and($report['archived_document_records_checked'])->toBe(1)->and($report['snapshot_linked_references'])->toBe(1)
        ->and($report['audiences'])->toBe(['internal' => 1, 'client' => 0, 'shared' => 0])
        ->and($report['native_documents_created'])->toBe(0)->and($report['native_records_changed'])->toBe(0)
        ->and($report['original_files_read'])->toBeFalse()->and($report['serving_enabled'])->toBeFalse()
        ->and($report['final_cutover_ready'])->toBeFalse()
        ->and(DB::table('dossier_documents')->count())->toBe(0)
        ->and(DB::table('legacy_records')->where('source_table', 'dossier_document')->first())->toEqual($before);
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    expect(app(NativeDocumentAudit::class)->rehearse($this->dataset, $plan))->toBe($report);
    Http::assertNothingSent();
});

it('retains unavailable and catalogue references without inventing files or native duplicate documents', function () {
    File::deleteDirectory($this->datasetDirectory);
    File::deleteDirectory($this->planDirectory);
    $base = ['dossier_id' => 101, 'author_id' => 11, 'name' => 'Piece', 'is_private' => 0, 'is_delegataire' => 1];
    $documents = [];
    foreach ([601 => 'linked.pdf', 602 => 'missing.pdf', 603 => 'empty.pdf', 604 => 'unknown.bin', 605 => 'excluded.php'] as $id => $path) {
        $documents[] = ['id' => $id, 'path' => $path] + $base;
    }
    $this->datasetDirectory = productionCoffracFixture(['dossier_document' => $documents,
        'fiche_document' => [['id' => 1, 'fiche_id' => 7, 'path' => 'catalogue.pdf']]]);
    $this->dataset = new ProductionDataset($this->datasetDirectory);
    $this->planDirectory = productionCoffracDocumentPlanFixture($this->dataset, [602 => 'missing_in_verified_inventory',
        603 => 'empty_file', 604 => 'quarantined_file', 605 => 'missing_in_verified_inventory']);
    app(CoffracNativeImporter::class)->rehearse($this->dataset);
    $report = app(NativeDocumentAudit::class)->rehearse($this->dataset, new DocumentReconciliationPlan($this->planDirectory, $this->dataset));
    expect($report['archived_document_records_checked'])->toBe(5)
        ->and($report['unavailable_dossier_documents_retained'])->toBe(4)
        ->and($report['non_dossier_references_retained_for_review'])->toBe(1)
        ->and($report['snapshot_linked_references'])->toBe(1)
        ->and(DB::table('legacy_records')->where('source_table', 'dossier_document')->count())->toBe(5)
        ->and(DB::table('dossier_documents')->count())->toBe(0);
});

it('rejects changed native ownership, payloads, overrides and hidden records', function (string $case) {
    app(CoffracNativeImporter::class)->rehearse($this->dataset);
    $record = DB::table('legacy_records')->where('source_table', 'dossier_document');
    if ($case === 'owner') {
        $record->update(['dossier_id' => 102]);
    } elseif ($case === 'payload') {
        $payload = json_decode($record->value('payload'), true);
        $payload['name'] = 'Different metadata';
        $record->update(['payload' => json_encode($payload)]);
    } elseif ($case === 'override') {
        $record->update(['client_visible_override' => true]);
    } else {
        $record->update(['hidden_at' => now()]);
    }
    expect(fn () => app(NativeDocumentAudit::class)->rehearse($this->dataset, new DocumentReconciliationPlan($this->planDirectory, $this->dataset)))
        ->toThrow(RuntimeException::class);
})->with(['owner', 'payload', 'override', 'hidden']);

it('rejects a resealed plan from another dump or with changed ownership or ACLs', function (string $case) {
    if ($case === 'dump') {
        $path = $this->planDirectory.'/report.json';
        $row = json_decode(file_get_contents($path), true);
        $row['dump_sha256'] = str_repeat('e', 64);
        file_put_contents($path, json_encode($row));
    } else {
        $path = $this->planDirectory.'/links.jsonl';
        $row = json_decode(file($path)[0], true);
        if ($case === 'ACL') {
            $row['client_visible'] = true;
        } else {
            $row['dossier_id'] = 102;
        }
        file_put_contents($path, json_encode($row)."\n");
    }
    sealProductionCoffracDocumentPlanFixture($this->planDirectory);
    expect(fn () => new DocumentReconciliationPlan($this->planDirectory, $this->dataset))->toThrow(RuntimeException::class);
})->with(['dump', 'ACL', 'owner']);

it('rejects public, symbolic, changed or truncated plan files', function (string $case) {
    $path = $this->planDirectory.'/links.jsonl';
    if ($case === 'public') {
        chmod($path, 0644);
    } elseif ($case === 'symlink') {
        rename($path, $this->planDirectory.'/target');
        symlink($this->planDirectory.'/target', $path);
    } elseif ($case === 'truncated') {
        file_put_contents($path, '');
        sealProductionCoffracDocumentPlanFixture($this->planDirectory);
    } else {
        file_put_contents($path, "{}\n", FILE_APPEND);
    }
    expect(fn () => new DocumentReconciliationPlan($this->planDirectory, $this->dataset))->toThrow(RuntimeException::class);
})->with(['public', 'symlink', 'truncated', 'changed']);

it('rejects a changed plan after object construction', function () {
    $plan = new DocumentReconciliationPlan($this->planDirectory, $this->dataset);
    file_put_contents($this->planDirectory.'/issues.jsonl', "{}\n");
    expect(fn () => iterator_to_array($plan->rows()))->toThrow(RuntimeException::class, 'modifie');
});

it('refuses a production target before opening its connection', function () {
    $plan = new DocumentReconciliationPlan($this->planDirectory, $this->dataset);
    $original = config('database.default');
    try {
        app()->detectEnvironment(fn () => 'production');
        config(['database.default' => 'mysql', 'database.connections.mysql.host' => 'must-not-connect.invalid',
            'database.connections.mysql.database' => 'cofrac2production']);
        expect(fn () => app(NativeDocumentAudit::class)->rehearse($this->dataset, $plan))->toThrow(RuntimeException::class, 'Cible refusee');
    } finally {
        config(['database.default' => $original]);
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('batches native lookups instead of issuing one query per document', function () {
    File::deleteDirectory($this->datasetDirectory);
    File::deleteDirectory($this->planDirectory);
    $documents = [];
    for ($id = 1; $id <= 401; $id++) {
        $documents[] = ['id' => $id, 'dossier_id' => $id % 2 === 0 ? 101 : 102, 'author_id' => 11,
            'path' => 'shared.pdf', 'name' => 'Shared bytes', 'is_private' => 0, 'is_delegataire' => $id % 2];
    }
    $this->datasetDirectory = productionCoffracFixture(['dossier_document' => $documents]);
    $this->dataset = new ProductionDataset($this->datasetDirectory);
    $this->planDirectory = productionCoffracDocumentPlanFixture($this->dataset);
    app(CoffracNativeImporter::class)->rehearse($this->dataset);
    $plan = new DocumentReconciliationPlan($this->planDirectory, $this->dataset);
    DB::enableQueryLog();
    $report = app(NativeDocumentAudit::class)->rehearse($this->dataset, $plan);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(8)
        ->and($report['archived_document_records_checked'])->toBe(401)
        ->and($report['audiences'])->toBe(['internal' => 0, 'client' => 200, 'shared' => 201]);
});
