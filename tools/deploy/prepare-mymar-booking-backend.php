<?php

declare(strict_types=1);

umask(0077);
chdir(dirname(__DIR__, 2));

function bookingPackageGit(string $path): string
{
    $process = proc_open(['git', 'show', 'HEAD:'.$path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Git indisponible.');
    }
    $value = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($value)) {
        throw new RuntimeException('Source non committee.');
    }

    return $value;
}

try {
    if (PHP_SAPI !== 'cli' || $argc !== 5 || ! preg_match('/^[a-f0-9]{40}$/D', $argv[1]) || $argv[4] !== '--technical-author-approved') {
        throw new RuntimeException('Usage : php prepare-mymar-booking-backend.php RUNTIME_REVISION WORKFLOW_REPORT_DIR MAPPING_SUMMARY --technical-author-approved');
    }
    $audit = realpath($argv[2]);
    $mapping = realpath($argv[3]);
    if ($audit === false || $mapping === false || ! is_file($audit.'/SHA256SUMS') || ! is_file($mapping)) {
        throw new RuntimeException('Rapports manquants.');
    }
    $verified = [];
    foreach (file($audit.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (! preg_match('/^([a-f0-9]{64})  (source-facts\.json\.gz|schema\.json|summary\.json)$/D', $line, $match)
            || is_link($audit.'/'.$match[2]) || ! hash_equals($match[1], hash_file('sha256', $audit.'/'.$match[2]))) {
            throw new RuntimeException('Empreintes du rapport invalides.');
        }
        $verified[$match[2]] = true;
    }
    if (count($verified) !== 3) {
        throw new RuntimeException('Rapport incomplet.');
    }
    $summary = json_decode(file_get_contents($audit.'/summary.json'), true, 16, JSON_THROW_ON_ERROR);
    $facts = json_decode(gzdecode(file_get_contents($audit.'/source-facts.json.gz')), true, 64, JSON_THROW_ON_ERROR);
    $map = json_decode(file_get_contents($mapping), true, 16, JSON_THROW_ON_ERROR);
    if (($summary['format'] ?? null) !== 'cofrac.mymar-booking-workflow-audit' || ($summary['complete'] ?? null) !== true
        || ($summary['source_unchanged'] ?? null) !== true || ($summary['metadata_complete'] ?? null) !== true
        || ($map['format'] ?? null) !== 'cofrac.mymar-mapping-preparation' || ($map['configuration_unchanged'] ?? null) !== true
        || ! preg_match('/^[a-f0-9]{64}$/D', $map['mapping_sha256'] ?? '')
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $map['private_mapping_path'] ?? '')) {
        throw new RuntimeException('Rapports non valides pour cette preparation.');
    }
    $proof = [];
    foreach ($facts['files'] ?? [] as $path => $file) {
        if (! preg_match('~^(app|routes)/[A-Za-z0-9_/.]+\.php$~D', $path) || str_contains($path, '..')
            || ! preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '')) {
            throw new RuntimeException('Manifest source invalide.');
        }
        $proof[$path] = $file['sha256'];
    }
    if (count($proof) < 7 || count($proof) > 64) {
        throw new RuntimeException('Manifest source incomplet.');
    }
    $out = rtrim(getenv('HOME'), '/').'/Downloads/cofrac2-mymar-booking-backend-'.bin2hex(random_bytes(5));
    if (! mkdir($out, 0700)) {
        throw new RuntimeException('Creation du paquet impossible.');
    }
    $files = [
        'MymarReadOnlyAudit.php' => 'tools/deploy/mymar/MymarReadOnlyAudit.php',
        'MymarWorkflowAudit.php' => 'tools/deploy/mymar/workflow/MymarWorkflowAudit.php',
        'MymarApprovedTechnicianMap.php' => 'tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php',
    ];
    foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy'] as $class) {
        $files[$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
    }
    foreach (['MymarBookingFailure.php', 'MymarBookingGateway.php', 'MymarBookingSource.php', 'MymarBookingSqlAccess.php',
        'journal.sql', 'prepare-backend.php', 'collect-backend.sh', 'fetch-backend.sh', 'README.txt'] as $file) {
        $files[$file] = 'tools/deploy/mymar/booking/'.$file;
    }
    foreach ($files as $name => $path) {
        file_put_contents($out.'/'.$name, bookingPackageGit($path));
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-booking-preparation-request', 'version' => 1,
        'runtime_revision' => $argv[1], 'technical_author_approved' => true, 'mapping_path' => $map['private_mapping_path'],
        'mapping_sha256' => $map['mapping_sha256'], 'workflow_files' => $proof], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $hashes = [];
    foreach (new DirectoryIterator($out) as $file) {
        if ($file->isDot()) {
            continue;
        }
        chmod($file->getPathname(), 0600);
        $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($name, $hash) => "$hash  $name", array_keys($hashes), $hashes))."\n");
    echo "PACKAGE=$out\nCOMMAND=bash \"$out/fetch-backend.sh\"\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL);
    exit(1);
}
