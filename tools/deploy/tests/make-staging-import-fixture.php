<?php

declare(strict_types=1);

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\DocumentReconciliationPlan;
use App\Services\Coffrac\DocumentReferenceExport;
use App\Services\Coffrac\NativeDocumentAudit;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use Cofrac2\Deploy\CoffracDocumentArchive;
use Cofrac2\Deploy\CoffracDocumentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

umask(0077);
if (! is_file('/.dockerenv') || getenv('COFRAC2_IMPORT_TEST') !== '1' || posix_geteuid() !== 0
    || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== ':memory:' || $argc !== 2) {
    throw new RuntimeException('Fixture Docker jetable uniquement.');
}
$repo = '/var/www/cofrac2';
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.offline-fixture-not-used');
$app->make(Kernel::class)->bootstrap();
config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
    'admin.mail' => 'offline-seed@example.invalid', 'admin.password' => 'FixtureOfflinePass123',
    'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
Artisan::call('migrate', ['--force' => true]);
require $repo.'/tests/Fixtures/CoffracProductionFixture.php';
require $repo.'/tests/Fixtures/TechCalendarPlanningFixture.php';
require $repo.'/tests/Fixtures/StagingImportPackageFixture.php';
require $repo.'/tools/deploy/lib/CoffracDocumentArchive.php';
require $repo.'/tools/deploy/lib/CoffracDocumentReconciliation.php';
$datasetPath = productionCoffracFixture();
$dataset = new ProductionDataset($datasetPath);
$planningPath = techCalendarPlanningFixture();
$planning = new TechCalendarPlanningSnapshot($planningPath, hash_file('sha256', $planningPath.'/references.json.gz'));
$source = '/home/ubuntu/www/coffrac_preprod';
$state = '/var/lib/cofrac2-production';
$inventory = $state.'/documents-inventory-fixture';
$verification = $state.'/documents-run-fixture';
$archive = '/srv/cofrac2-document-archive';
CoffracDocumentArchive::inventory($source, $inventory);
mkdir($archive, 0700);
mkdir($archive.'/files', 0700);
mkdir($archive.'/files/public', 0700);
mkdir($archive.'/files/public/documents', 0700);
file_put_contents($archive.'/ARCHIVE-IDENTITY.json', json_encode(['format' => 'cofrac2.private-document-archive', 'version' => 1, 'source' => $source], JSON_THROW_ON_ERROR));
copy($source.'/public/documents/test.pdf', $archive.'/files/public/documents/test.pdf');
CoffracDocumentArchive::verifyCopy($inventory, $archive, $verification);
$references = $state.'/document-references-fixture';
CoffracDocumentReconciliation::exportReferences(DocumentReferenceExport::rows($dataset), [
    'dump_sha256' => $dataset->manifest['dump_sha256'], 'dataset_sha256' => $dataset->fingerprint, 'dossier_document_records' => 1,
], $references);
$documents = $state.'/documents-reconciliation-fixture';
CoffracDocumentReconciliation::reconcile($references, $inventory, $verification, $archive, $documents);
$report = app(CoffracNativeImporter::class)->rehearse($dataset, $planning);
$report['document_verification'] = app(NativeDocumentAudit::class)->rehearse($dataset, new DocumentReconciliationPlan($documents, $dataset));
$report['verification'] = ['single_seeded_super_admin' => true, 'matching_password_hashes' => 6,
    'enabled_external_portals' => 0, 'outbound_jobs_or_messages' => 0, 'imported_auth_tokens_or_sessions' => 0];
$package = stagingImportPackageFixture($datasetPath, $planningPath, $documents, $report, $argv[1]);
echo $package."\n";
