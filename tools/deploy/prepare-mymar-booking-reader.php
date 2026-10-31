<?php

declare(strict_types=1);

umask(0077);
chdir(dirname(__DIR__, 2));

function readerPackageSource(string $path): string
{
    $process = proc_open(['git', 'show', 'HEAD:'.$path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Git indisponible.');
    }
    $source = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($source)) {
        throw new RuntimeException('Source non committee.');
    }

    return $source;
}

try {
    if (PHP_SAPI !== 'cli' || $argc !== 3 || ! preg_match('/^[a-f0-9]{40}$/D', $argv[1]) || realpath($argv[2]) !== $argv[2]) {
        throw new RuntimeException('Usage : php prepare-mymar-booking-reader.php RUNTIME_REVISION PREPARATION_REPORT_DIR');
    }
    $directory = $argv[2];
    $sum = trim(file_get_contents($directory.'/SHA256SUMS'));
    if (! preg_match('/^([a-f0-9]{64})  summary\.json$/D', $sum, $match) || is_link($directory.'/summary.json')
        || ! hash_equals($match[1], hash_file('sha256', $directory.'/summary.json'))) {
        throw new RuntimeException('Empreinte de preparation invalide.');
    }
    $summary = json_decode(file_get_contents($directory.'/summary.json'), true, 16, JSON_THROW_ON_ERROR);
    if (($summary['format'] ?? null) !== 'cofrac.mymar-booking-backend-preparation' || ($summary['complete'] ?? null) !== true
        || ($summary['technical_author_disabled'] ?? null) !== true || ($summary['technical_author_has_roles'] ?? null) !== false
        || ($summary['new_sql_account_locked'] ?? null) !== true || ($summary['journal_rows'] ?? null) !== 0
        || ($summary['read_enabled'] ?? null) !== false || ($summary['writes_enabled'] ?? null) !== false
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-preparation-[A-Za-z0-9]+$~D', $summary['run_directory'] ?? '')) {
        throw new RuntimeException('Preparation fermee non confirmee.');
    }
    $files = ['MymarRuntimeState.php' => 'tools/deploy/mymar/runtime/MymarRuntimeState.php',
        'MymarRuntimePlan.php' => 'tools/deploy/mymar/runtime/MymarRuntimePlan.php',
        'MymarReadOnlyAudit.php' => 'tools/deploy/mymar/MymarReadOnlyAudit.php',
        'MymarWorkflowAudit.php' => 'tools/deploy/mymar/workflow/MymarWorkflowAudit.php',
        'MymarApprovedTechnicianMap.php' => 'tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php'];
    foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy', 'MymarPendingBookingPage'] as $class) {
        $files[$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
    }
    foreach (['MymarBookingFailure', 'MymarBookingGateway', 'MymarBookingSqlAccess', 'MymarBookingSource'] as $class) {
        $files[$class.'.php'] = 'tools/deploy/mymar/booking/'.$class.'.php';
    }
    foreach (['MymarBookingHttpPlan.php', 'entry.php', 'control.php', 'install.sh', 'stop.sh', 'fetch-reader.sh', 'README.txt'] as $file) {
        $files[$file] = 'tools/deploy/mymar/booking-http/'.$file;
    }
    $out = rtrim(getenv('HOME'), '/').'/Downloads/cofrac2-mymar-booking-reader-'.bin2hex(random_bytes(5));
    if (! mkdir($out, 0700)) {
        throw new RuntimeException('Creation du paquet impossible.');
    }
    foreach ($files as $name => $path) {
        file_put_contents($out.'/'.$name, readerPackageSource($path));
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-booking-http-request', 'version' => 1,
        'runtime_revision' => $argv[1], 'preparation_directory' => $summary['run_directory'],
        'preparation_summary_sha256' => $match[1]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $hashes = [];
    foreach (new DirectoryIterator($out) as $file) {
        if ($file->isDot()) {
            continue;
        }
        chmod($file->getPathname(), 0600);
        $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($name, $sha) => "$sha  $name", array_keys($hashes), $hashes))."\n");
    echo "PACKAGE=$out\nCOMMAND=bash \"$out/fetch-reader.sh\"\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL);
    exit(1);
}
