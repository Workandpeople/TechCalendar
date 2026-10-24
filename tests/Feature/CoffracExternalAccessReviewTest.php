<?php

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\NativeExternalAccessReview;
use App\Services\Coffrac\ProductionDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    $this->datasetDirectory = productionCoffracFixture([
        'status' => [['id' => 1, 'name' => 'PRISE DE RDV'], ['id' => 2, 'name' => 'RDV ATTENTE VISITE'],
            ['id' => 4, 'name' => 'RAPPORT GENIUS'], ['id' => 5, 'name' => 'DOSSIER LITIGE'],
            ['id' => 8, 'name' => 'NON CONFORMITES'], ['id' => 11, 'name' => 'DOSSIERS FACTURER']],
        'user_type' => [['id' => 5, 'autorisation' => '1,2,4,5,8', 'autorisation_types' => '1,5'],
            ['id' => 7, 'autorisation' => '4', 'autorisation_types' => '1,5']],
    ]);
});

afterEach(fn () => File::deleteDirectory($this->datasetDirectory));

it('retains report access after billing under the approved rule without enabling an account', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset)['matching']['external_access'];
    expect($report['profiles']['client']['candidate_categories'])->toBe(['pending', 'awaiting_visit', 'non_compliant', 'report'])
        ->and($report['profiles']['client']['source_status_ids_without_native_category'])->toBe([5])
        ->and($report['profiles']['delegataire']['candidate_categories'])->toBe(['report'])
        ->and($report['profiles']['delegataire']['approved_category_expansions']['report'])
        ->toBe(['policy_version' => NativeExternalAccessReview::POLICY_VERSION,
            'rule' => 'report_access_survives_billing', 'source_status_ids_not_authorized' => [11]])
        ->and($report['profiles']['delegataire']['source_status_ids'])->toBe([4])
        ->and($report['policy_version'])->toBe(NativeExternalAccessReview::POLICY_VERSION)
        ->and($report['confirmed_business_rules']['report_access_survives_billing'])->toBeTrue()
        ->and($report['source_external_accounts'])->toBe(2)
        ->and($report['native_disabled_accounts_checked'])->toBe(2)
        ->and($report['source_assignment_counts'])->toBe(['client' => 3, 'delegataire' => 3])
        ->and($report['proposal_applied'])->toBeFalse()
        ->and($report['module_permissions_translated'])->toBeFalse()
        ->and($report['rights_approval_required'])->toBeTrue()
        ->and(DB::table('users')->where('portal_enabled', true)->count())->toBe(0)
        ->and(DB::table('external_delegataires')->where('portal_categories', '!=', '[]')->count())->toBe(0);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    expect(app(NativeExternalAccessReview::class)->rehearse($dataset))->toBe($report)->and($writes)->toBe([]);
    expect(json_encode($report))->not->toContain('source-31@example.test', 'Source-password', 'Compte');
});

it('keeps full source report authorizations distinct from the approved expansion', function () {
    $path = $this->datasetDirectory.'/user_type.jsonl';
    $rows = array_map(fn ($line) => json_decode($line, true), file($path));
    $rows[1]['autorisation'] = ' 11, 4, 4 ';
    file_put_contents($path, implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory));
    expect($report['matching']['external_access']['profiles']['delegataire']['candidate_categories'])->toBe(['report'])
        ->and($report['matching']['external_access']['profiles']['delegataire']['source_status_ids'])->toBe([4, 11])
        ->and($report['matching']['external_access']['profiles']['delegataire']['approved_category_expansions'])->toBe([]);
});

it('does not grant report access without an existing source report authorization', function (string $authorization) {
    $path = $this->datasetDirectory.'/user_type.jsonl';
    $rows = array_map(fn ($line) => json_decode($line, true), file($path));
    $rows[1]['autorisation'] = $authorization;
    file_put_contents($path, implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory));
    $profile = $report['matching']['external_access']['profiles']['delegataire'];
    expect($profile['candidate_categories'])->not->toContain('report')
        ->and($profile['approved_category_expansions'])->toBe([]);
})->with(['', '11', '1,2,8', '4,999', '4,,11']);

it('refuses to reuse a cached import with an outdated access policy', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset);
    unset($report['matching']['external_access']['policy_version']);
    DB::table('coffrac_import_runs')->update(['report' => json_encode($report, JSON_THROW_ON_ERROR)]);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse($dataset))->toThrow(RuntimeException::class, 'Regles de droits differentes');
    expect(DB::table('users')->where('portal_enabled', true)->count())->toBe(0)
        ->and(DB::table('coffrac_import_runs')->count())->toBe(1);
});

it('fails closed for malformed, missing or unrecognised source authorizations', function (mixed $authorization) {
    $path = $this->datasetDirectory.'/user_type.jsonl';
    $rows = array_map(fn ($line) => json_decode($line, true), file($path));
    $rows[0]['autorisation'] = $authorization;
    file_put_contents($path, implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory));
    expect($report['matching']['external_access']['profiles']['client']['candidate_categories'])->toBe([]);
})->with([null, '1,,4', '1;4', '1,-1', '1,04', '1,999', 'array' => [['1', '4']]]);

it('identifies source assignments to a non-client instead of widening ownership', function () {
    $path = $this->datasetDirectory.'/dossiers.jsonl';
    $rows = array_map(fn ($line) => json_decode($line, true), file($path));
    $rows[0]['client_id'] = 11;
    file_put_contents($path, implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory));
    expect($report['matching']['external_access']['assignments_with_wrong_or_missing_profile']['client'])->toBe([101])
        ->and(DB::table('dossiers')->where('id', 101)->value('client_id'))->toBeNull();
});

it('detects an altered organisation or rights lock and refuses a non-rehearsal target', function (string $field, mixed $value) {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $import = app(CoffracNativeImporter::class)->rehearse($dataset);
    DB::table('users')->where('id', $import['matching']['users'][31])->update([$field => $value]);
    expect(fn () => app(NativeExternalAccessReview::class)->rehearse($dataset))->toThrow(RuntimeException::class, 'verrou');
    config(['coffrac.offline_rehearsal' => false]);
    expect(fn () => app(NativeExternalAccessReview::class)->rehearse($dataset))->toThrow(RuntimeException::class, 'Cible refusee');
})->with([
    ['portal_enabled', true], ['portal_permissions', '{"documents":true}'], ['role', 0], ['admin', true], ['external_client_id', null],
]);
