<?php

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\DocumentReconciliationPlan;
use App\Services\Coffrac\NativeDocumentAudit;
use App\Services\Coffrac\NativeImportTarget;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\StagingImportPackage;
use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';
require_once __DIR__.'/../Fixtures/TechCalendarPlanningFixture.php';
require_once __DIR__.'/../Fixtures/StagingImportPackageFixture.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    $this->source = productionCoffracFixture();
    $this->planning = techCalendarPlanningFixture();
    $dataset = new ProductionDataset($this->source);
    $this->documents = productionCoffracDocumentPlanFixture($dataset);
    $planning = new TechCalendarPlanningSnapshot($this->planning, hash_file('sha256', $this->planning.'/references.json.gz'));
    $report = app(CoffracNativeImporter::class)->rehearse($dataset, $planning);
    $report['verification'] = ['single_seeded_super_admin' => true, 'matching_password_hashes' => 6,
        'enabled_external_portals' => 0, 'outbound_jobs_or_messages' => 0, 'imported_auth_tokens_or_sessions' => 0];
    $report['document_verification'] = app(NativeDocumentAudit::class)->rehearse($dataset, new DocumentReconciliationPlan($this->documents, $dataset));
    $this->package = stagingImportPackageFixture($this->source, $this->planning, $this->documents, $report);
    $this->digest = hash_file('sha256', $this->package.'/manifest.json');
});

afterEach(function () {
    foreach ([$this->source, $this->planning, $this->documents, $this->package] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('validates private sealed sources while keeping production staging separate from rehearsal', function () {
    $package = new StagingImportPackage($this->package, $this->digest);
    expect($package->fingerprint)->toBe($this->digest)
        ->and($package->manifest['final_cutover_ready'])->toBeFalse();
    expect(fn () => NativeImportTarget::production($package, fn () => true))->toThrow(RuntimeException::class, 'Cible refusee');
    expect(fn () => app(CoffracNativeImporter::class)->stageProduction(new ProductionDataset($this->source),
        new TechCalendarPlanningSnapshot($this->planning, hash_file('sha256', $this->planning.'/references.json.gz'))))
        ->toThrow(RuntimeException::class, 'contexte CLI protege');
    expect(NativeImportTarget::canReadPlanningGroup($this->planning, $this->planning.'/references.json.gz'))->toBeFalse();
});

it('rejects a different manifest fingerprint before accepting any source', function () {
    expect(fn () => new StagingImportPackage($this->package, str_repeat('b', 64)))->toThrow(RuntimeException::class, 'Empreinte');
});

it('rejects changed missing public symbolic extra and credential-like inputs', function (string $case) {
    $file = $this->package.'/dataset/credentials.jsonl';
    match ($case) {
        'changed' => file_put_contents($file, "\n", FILE_APPEND),
        'missing' => unlink($file),
        'public' => chmod($file, 0644),
        'symlink' => (function () use ($file) {
            unlink($file);
            symlink($this->source.'/credentials.jsonl', $file);
        })(),
        'extra' => file_put_contents($this->package.'/unexpected.sql', 'NO EXECUTABLE SQL'),
        'env' => file_put_contents($this->package.'/.env', 'DO NOT READ'),
    };
    expect(fn () => new StagingImportPackage($this->package, $this->digest))->toThrow(RuntimeException::class);
})->with(['changed', 'missing', 'public', 'symlink', 'extra', 'env']);

it('rejects resealed sources not matching their successful rehearsal', function (string $case) {
    $digest = resealStagingImportPackageFixture($this->package, function ($manifest) use ($case) {
        if ($case === 'purpose') {
            $manifest['purpose'] = 'final_cutover';
        } elseif ($case === 'ready') {
            $manifest['final_cutover_ready'] = true;
        } elseif ($case === 'policy') {
            $manifest['access_policy_version'] = 'outdated';
        } elseif ($case === 'path') {
            $manifest['server_documents']['inventory'] = '/home/ubuntu/unsafe';
        } else {
            $manifest[$case] = str_repeat('b', 64);
        }

        return $manifest;
    });
    expect(fn () => new StagingImportPackage($this->package, $digest))->toThrow(RuntimeException::class);
})->with(['purpose', 'ready', 'policy', 'path', 'dataset_sha256', 'planning_sha256', 'document_plan_sha256', 'dump_sha256']);

it('rejects a rehearsal with activated portals or unverified documents even if resealed', function (string $case) {
    $path = $this->package.'/rehearsal.json';
    $report = json_decode(file_get_contents($path), true);
    if ($case === 'portals') {
        $report['verification']['enabled_external_portals'] = 1;
    } else {
        $report['document_verification']['native_database_links_match_plan'] = false;
    }
    file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR));
    $digest = resealStagingImportPackageFixture($this->package, fn ($manifest) => $manifest);
    expect(fn () => new StagingImportPackage($this->package, $digest))->toThrow(RuntimeException::class, 'Repetition complete');
})->with(['portals', 'documents']);

it('detects an input modified after construction before a transaction may be committed', function () {
    $package = new StagingImportPackage($this->package, $this->digest);
    file_put_contents($this->package.'/rehearsal.json', "\n", FILE_APPEND);
    expect(fn () => $package->assertUnchanged())->toThrow(RuntimeException::class, 'Contenu du paquet modifie');
});

it('requires a sealed explicit policy to reuse an active internal source account as super admin', function () {
    expect((new StagingImportPackage($this->package, $this->digest))->seededAdminSourceId)->toBeNull();
    $digest = resealStagingImportPackageFixture($this->package, function ($manifest) {
        $manifest['seeded_admin'] = ['source_user_id' => 11, 'policy' => 'reuse_active_internal_account_reset_password'];

        return $manifest;
    });
    expect((new StagingImportPackage($this->package, $digest))->seededAdminSourceId)->toBe(11);
});

it('rejects unsafe resealed super admin identity policies', function (array $policy) {
    $digest = resealStagingImportPackageFixture($this->package, function ($manifest) use ($policy) {
        $manifest['seeded_admin'] = $policy;

        return $manifest;
    });
    expect(fn () => new StagingImportPackage($this->package, $digest))->toThrow(RuntimeException::class, 'super admin');
})->with([
    'inactive internal' => [['source_user_id' => 12, 'policy' => 'reuse_active_internal_account_reset_password']],
    'technician' => [['source_user_id' => 21, 'policy' => 'reuse_active_internal_account_reset_password']],
    'client' => [['source_user_id' => 31, 'policy' => 'reuse_active_internal_account_reset_password']],
    'delegate' => [['source_user_id' => 41, 'policy' => 'reuse_active_internal_account_reset_password']],
    'held auditor' => [['source_user_id' => 51, 'policy' => 'reuse_active_internal_account_reset_password']],
    'unknown' => [['source_user_id' => 999, 'policy' => 'reuse_active_internal_account_reset_password']],
    'wrong policy' => [['source_user_id' => 11, 'policy' => 'guess_from_email']],
    'missing policy' => [['source_user_id' => 11]],
    'string id' => [['source_user_id' => '11', 'policy' => 'reuse_active_internal_account_reset_password']],
]);
