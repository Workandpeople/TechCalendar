<?php

declare(strict_types=1);

umask(0077);
$root = dirname(__DIR__, 2);
chdir($root);
function actionsBuildRun(array $command): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Commande locale indisponible.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($output)) {
        throw new RuntimeException('Commande locale refusee : '.$command[0]);
    }

    return $output;
}
try {
    if (PHP_SAPI !== 'cli' || $argc !== 3 || ! preg_match('/^[a-f0-9]{40}$/D', $argv[1]) || realpath($argv[2]) !== $argv[2]) {
        throw new RuntimeException('Usage : php tools/deploy/prepare-mymar-actions.php BASE_REVISION UI_REPORT_DIR');
    }
    $sum = trim(file_get_contents($argv[2].'/SHA256SUMS'));
    if (! preg_match('/^([a-f0-9]{64})  summary\.json$/D', $sum, $match) || is_link($argv[2].'/summary.json') || ! hash_equals($match[1], hash_file('sha256', $argv[2].'/summary.json'))) {
        throw new RuntimeException('Rapport de lecture altere.');
    }
    $summary = json_decode(file_get_contents($argv[2].'/summary.json'), true, 16, JSON_THROW_ON_ERROR);
    if (($summary['format'] ?? null) !== 'cofrac.mymar-booking-ui' || ($summary['version'] ?? null) !== 1 || ($summary['complete'] ?? null) !== true
        || ($summary['revision'] ?? null) !== $argv[1] || ($summary['read_enabled'] ?? null) !== true || ($summary['writes_enabled'] ?? null) !== false || ($summary['prepared_writer_still_locked'] ?? null) !== true) {
        throw new RuntimeException('Lecture du pilote non confirmee.');
    }
    $revision = trim(actionsBuildRun(['git', 'rev-parse', 'HEAD']));
    if (trim(actionsBuildRun(['git', 'diff', '--name-only', $argv[1], $revision, '--', 'database/migrations'])) !== '') {
        throw new RuntimeException('Pas de migration automatique autorisee dans ce lot.');
    }
    actionsBuildRun(['git', 'diff', '--exit-code', 'HEAD', '--', 'tools/deploy/prepare-correctifs-delivery.php']);
    $built = actionsBuildRun([PHP_BINARY, $root.'/tools/deploy/prepare-correctifs-delivery.php', $argv[1], '--application-only']);
    if (! preg_match('/^PACKAGE=(.+)$/m', $built, $found)) {
        throw new RuntimeException('Paquet applicatif absent.');
    }
    $out = $found[1];
    if (realpath($out) !== $out || dirname($out) !== rtrim(getenv('HOME'), '/').'/Downloads') {
        throw new RuntimeException('Destination inattendue.');
    }
    mkdir($out.'/actions', 0700);
    $files = ['actions/MymarRuntimeState.php' => 'tools/deploy/mymar/runtime/MymarRuntimeState.php',
        'actions/MymarRuntimePlan.php' => 'tools/deploy/mymar/runtime/MymarRuntimePlan.php',
        'actions/MymarReadOnlyAudit.php' => 'tools/deploy/mymar/MymarReadOnlyAudit.php',
        'actions/MymarWorkflowAudit.php' => 'tools/deploy/mymar/workflow/MymarWorkflowAudit.php',
        'actions/MymarApprovedTechnicianMap.php' => 'tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php'];
    foreach (['MymarActionsHttpPlan.php', 'MymarActionsConfiguration.php', 'entry.php', 'control.php'] as $file) {
        $files['actions/'.$file] = 'tools/deploy/mymar/actions/'.$file;
    }
    foreach (['MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource', 'MymarBookingGateway'] as $class) {
        $files['actions/'.$class.'.php'] = 'tools/deploy/mymar/booking/'.$class.'.php';
    }
    foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy'] as $class) {
        $files['actions/'.$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
    }
    foreach (['run-all.sh', 'fetch-actions.sh'] as $file) {
        $files[$file] = 'tools/deploy/mymar/actions/'.$file;
    }
    foreach ($files as $name => $source) {
        file_put_contents($out.'/'.$name, actionsBuildRun(['git', 'show', $revision.':'.$source]));
    }
    file_put_contents($out.'/actions/REQUEST.json', json_encode(['format' => 'cofrac.mymar-actions-request', 'version' => 1,
        'revision' => $revision, 'base_revision' => $argv[1], 'ui_summary_sha256' => $match[1]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    file_put_contents($out.'/README.txt', "Pilote MAR : placement / probleme RDV pour les seules correspondances validees.\n"
        ."Precontrole avant maintenance ; installation isolee sur 127.0.0.1:19848 ; courte maintenance applicative et reouverture ; creation du seul journal technique V2 ; activation explicite finale.\n"
        ."Controle du technicien/verrou/absences/RDV natifs/MAR ; aucune copie de dossier MYMAR. Une confirmation incertaine garde sa reservation et se reconcilie par GET, jamais par un second POST.\n"
        ."Le compte technique MYMAR reste sans connexion. Les lecteurs 19846/19847, mots de passe et modes mail/SMS sont conserves. Aucun placement, probleme, email ou SMS de test.\n"
        ."Commande Mac : bash ./fetch-actions.sh\nArret des seules actions dans le stop.sh root-prive indique a la fin. Ne pas relancer un lot interrompu, ne pas faire de pull manuel ni restaurer la base.\n");
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->isLink()) {
            throw new RuntimeException('Fichier indirect dans le paquet.');
        }
        if ($file->getFilename() === 'SHA256SUMS') {
            continue;
        }
        chmod($file->getPathname(), 0600);
        $hashes[substr($file->getPathname(), strlen($out) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(fn ($file, $sha) => "$sha  $file", array_keys($hashes), $hashes))."\n");
    foreach (['run-all.sh', 'fetch-actions.sh', 'deploy.sh'] as $file) {
        actionsBuildRun(['bash', '-n', $out.'/'.$file]);
    }
    echo "PACKAGE=$out\nREVISION=$revision\nCOMMAND=bash \"$out/fetch-actions.sh\"\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL);
    exit(1);
}
