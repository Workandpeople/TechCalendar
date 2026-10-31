<?php

declare(strict_types=1);

umask(0077);
$root = dirname(__DIR__, 2);
chdir($root);

function bookingUiRun(array $command): string
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
        throw new RuntimeException('Usage : php tools/deploy/prepare-mymar-booking-ui.php BASE_REVISION READER_REPORT_DIR');
    }
    $sum = trim(file_get_contents($argv[2].'/SHA256SUMS'));
    if (! preg_match('/^([a-f0-9]{64})  summary\.json$/D', $sum, $match) || is_link($argv[2].'/summary.json')
        || ! hash_equals($match[1], hash_file('sha256', $argv[2].'/summary.json'))) {
        throw new RuntimeException('Rapport lecteur altere.');
    }
    $summary = json_decode(file_get_contents($argv[2].'/summary.json'), true, 16, JSON_THROW_ON_ERROR);
    if (($summary['format'] ?? null) !== 'cofrac.mymar-booking-http' || ($summary['version'] ?? null) !== 1
        || ($summary['complete'] ?? null) !== true || ($summary['new_reader_select_only'] ?? null) !== true
        || ($summary['prepared_writer_still_locked'] ?? null) !== true || ($summary['writes_enabled'] ?? null) !== false
        || ($summary['read_enabled'] ?? null) !== true || ($summary['post_refused'] ?? null) !== true
        || ($summary['runtime_revision'] ?? null) !== $argv[1]
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-http-[A-Za-z0-9]+$~D', $summary['run_directory'] ?? '')) {
        throw new RuntimeException('Etat lecteur non confirme.');
    }
    $revision = trim(bookingUiRun(['git', 'rev-parse', 'HEAD']));
    if (trim(bookingUiRun(['git', 'diff', '--name-only', $argv[1], $revision, '--', 'database/migrations'])) !== '') {
        throw new RuntimeException('Ce lot de lecture ne doit pas introduire de migration.');
    }
    $builder = 'tools/deploy/prepare-correctifs-delivery.php';
    bookingUiRun(['git', 'diff', '--exit-code', 'HEAD', '--', $builder]);
    $built = bookingUiRun([PHP_BINARY, $root.'/'.$builder, $argv[1], '--application-only']);
    if (! preg_match('/^PACKAGE=(.+)$/m', $built, $packageMatch)) {
        throw new RuntimeException('Paquet applicatif absent.');
    }
    $out = $packageMatch[1];
    if (realpath($out) !== $out || dirname($out) !== rtrim(getenv('HOME'), '/').'/Downloads') {
        throw new RuntimeException('Destination applicative inattendue.');
    }
    mkdir($out.'/reader-ui', 0700);
    $files = ['reader-ui/control.php' => 'tools/deploy/mymar/booking-ui/control.php',
        'reader-ui/MymarRuntimeState.php' => 'tools/deploy/mymar/runtime/MymarRuntimeState.php',
        'reader-ui/MymarPendingBookingPage.php' => 'app/Services/Mymar/MymarPendingBookingPage.php',
        'run-all.sh' => 'tools/deploy/mymar/booking-ui/run-all.sh', 'fetch-ui.sh' => 'tools/deploy/mymar/booking-ui/fetch-ui.sh'];
    foreach ($files as $name => $source) {
        file_put_contents($out.'/'.$name, bookingUiRun(['git', 'show', $revision.':'.$source]));
    }
    file_put_contents($out.'/reader-ui/REQUEST.json', json_encode(['format' => 'cofrac.mymar-booking-ui-request', 'version' => 1,
        'revision' => $revision, 'base_revision' => $argv[1], 'reader_directory' => $summary['run_directory'],
        'reader_summary_sha256' => $match[1]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    file_put_contents($out.'/README.txt', "Cofrac : liste des demandes MAR en lecture seule, chargee a la demande par pages de 25.\n"
        ."Precontrole du lecteur, mise a jour applicative avec courte maintenance et reouverture des deux domaines, puis ouverture de la liste.\n"
        ."Aucune migration, import, seeder, action MYMAR, ouverture du compte SQL d'ecriture ou message de test.\n"
        ."Le seul secret transmis au compte runtime Cofrac est le jeton du lecteur HTTP, jamais son mot de passe SQL.\n"
        ."Mail reel/SMS simulation et pilote d'occupation MAR conserves. Placement et probleme RDV MAR toujours desactives.\n"
        ."Lancer sur le Mac : bash ./fetch-ui.sh\nNe pas lancer git pull manuellement. En cas d'echec transmettre le journal sans relancer.\n");
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->isLink()) {
            throw new RuntimeException('Fichier de paquet inattendu.');
        }
        if ($file->getFilename() === 'SHA256SUMS') {
            continue;
        }
        chmod($file->getPathname(), 0600);
        $hashes[substr($file->getPathname(), strlen($out) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($file, $sha) => "$sha  $file", array_keys($hashes), $hashes))."\n");
    foreach (['run-all.sh', 'fetch-ui.sh', 'deploy.sh'] as $script) {
        bookingUiRun(['bash', '-n', $out.'/'.$script]);
    }
    echo "PACKAGE=$out\nREVISION=$revision\nCOMMAND=bash \"$out/fetch-ui.sh\"\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL);
    exit(1);
}
