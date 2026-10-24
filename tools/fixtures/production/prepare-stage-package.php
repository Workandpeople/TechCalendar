<?php

declare(strict_types=1);

use App\Services\Coffrac\DocumentReconciliationPlan;
use App\Services\Coffrac\NativeExternalAccessReview;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\StagingImportPackage;
use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use Symfony\Component\Process\Process;

umask(0077);
ini_set('display_errors', '0');
require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    if ($argc !== 9 || explode('.', gethostname())[0] === 'ns365601') {
        throw new RuntimeException('Usage local : DATASET PLANNING DOCUMENT_PLAN REHEARSAL_REPORT NEW_DIRECTORY PLANNING_SHA SERVER_INVENTORY SERVER_VERIFICATION');
    }
    $repo = dirname(__DIR__, 3);
    $git = static function (array $args) use ($repo): string {
        $process = new Process(['git', '-C', $repo, ...$args]);
        if ($process->run() !== 0) {
            throw new RuntimeException('Controle Git impossible.');
        }

        return trim($process->getOutput());
    };
    if ($git(['branch', '--show-current']) !== 'v2' || $git(['status', '--porcelain']) !== '') {
        throw new RuntimeException('Commiter et verifier le code V2 avant de preparer le paquet.');
    }
    $dataset = new ProductionDataset(realpath($argv[1]));
    $planning = new TechCalendarPlanningSnapshot(realpath($argv[2]), $argv[6]);
    $documents = new DocumentReconciliationPlan(realpath($argv[3]), $dataset);
    $report = realpath($argv[4]);
    if ($report === false || is_link($argv[4]) || ! is_file($report) || (fileperms($report) & 0077) !== 0
        || file_exists($argv[5]) || is_link($argv[5])) {
        throw new RuntimeException('Rapport prive ou nouvelle destination attendus.');
    }
    mkdir($argv[5], 0700);
    $output = realpath($argv[5]);
    foreach (['dataset', 'planning', 'documents'] as $directory) {
        mkdir($output.'/'.$directory, 0700);
    }
    foreach (StagingImportPackage::files() as $name) {
        if ($name === 'README.txt') {
            $content = "Preparation isolee de la base cofrac2production, pas une bascule finale.\n"
                ."Source : dataset Coffrac de production scelle, jamais la demonstration TechCalendar.\n"
                ."Ce paquet contient des donnees personnelles et des hashes : ne pas publier.\n"
                ."Aucun .env, secret API/SMTP/SMS, fichier physique ou SQL executable.\n"
                ."Ne pas executer les outils demo. Utiliser stage-production-import.sh avec le SHA du manifeste.\n"
                ."Le controle precede l import ; aucun service ou portail n est active.\n";
        } else {
            $source = match (strtok($name, '/')) {
                'dataset' => $argv[1].'/'.substr($name, 8),
                'planning' => $argv[2].'/'.substr($name, 9),
                'documents' => $argv[3].'/'.substr($name, 10),
                default => $report,
            };
            if (is_link($source) || ! is_file($source) || (fileperms($source) & 0007) !== 0) {
                throw new RuntimeException('Entree source absente ou publique.');
            }
            if (! copy($source, $output.'/'.$name)) {
                throw new RuntimeException('Copie privee incomplete.');
            }
            chmod($output.'/'.$name, 0600);

            continue;
        }
        file_put_contents($output.'/'.$name, $content);
        chmod($output.'/'.$name, 0600);
    }
    $files = [];
    foreach (StagingImportPackage::files() as $name) {
        $files[$name] = ['bytes' => filesize($output.'/'.$name), 'sha256' => hash_file('sha256', $output.'/'.$name)];
    }
    $manifest = ['format' => 'cofrac2.staging-import', 'version' => 1, 'purpose' => 'isolated_production_preparation',
        'final_cutover_ready' => false, 'created_at_utc' => gmdate(DATE_ATOM), 'revision' => $git(['rev-parse', 'HEAD']),
        'dataset_sha256' => $dataset->fingerprint, 'dump_sha256' => $dataset->manifest['dump_sha256'],
        'planning_sha256' => $planning->fingerprint, 'document_plan_sha256' => $documents->fingerprint,
        'access_policy_version' => NativeExternalAccessReview::POLICY_VERSION,
        'server_documents' => ['inventory' => $argv[7], 'verification' => $argv[8]], 'files' => $files];
    file_put_contents($output.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    chmod($output.'/manifest.json', 0600);
    $digest = hash_file('sha256', $output.'/manifest.json');
    new StagingImportPackage($output, $digest);
    echo "PACKAGE=$output\nMANIFEST_SHA256=$digest\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : paquet non valide ('.get_class($error).', ligne '.$error->getLine()."). Ne pas le transferer.\n");
    exit(1);
}
