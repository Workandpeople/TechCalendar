<?php

declare(strict_types=1);

umask(0077);
$root = dirname(__DIR__, 2);
chdir($root);
function conflictGitFile(string $revision, string $path): string
{
    $process = proc_open(['git', 'show', $revision.':'.$path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Git indisponible.');
    }
    $value = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(substr($error, 0, 300));
    }

    return $value;
}
try {
    if (PHP_SAPI !== 'cli' || $argc !== 2 || ! preg_match('/^[a-f0-9]{40}$/D', $argv[1])) {
        throw new RuntimeException('Usage : php prepare-mymar-conflict-review.php RUNTIME_REVISION');
    }
    $out = rtrim(getenv('HOME'), '/').'/Downloads/cofrac2-mymar-conflicts-'.bin2hex(random_bytes(5));
    if (! mkdir($out, 0700)) {
        throw new RuntimeException('Creation du paquet impossible.');
    }
    foreach (['inspect.php', 'collect.sh', 'fetch-conflicts.sh', 'README.txt'] as $file) {
        file_put_contents($out.'/'.$file, conflictGitFile('HEAD', 'tools/deploy/mymar/conflicts/'.$file));
    }
    file_put_contents($out.'/MymarOccupancySnapshot.php', conflictGitFile('HEAD', 'app/Services/Mymar/MymarOccupancySnapshot.php'));
    $proof = [];
    foreach (['app/Models/User.php', 'app/Models/Appointment.php', 'app/Services/Crm/DossierStatusCatalog.php',
        'app/Services/Crm/AppointmentDateTime.php', 'app/Services/Mymar/MymarPilotSettings.php',
        'app/Services/Mymar/MymarPilotConfiguration.php', 'app/Services/Mymar/MymarCachedObservation.php',
        'app/Services/Mymar/MymarObservation.php'] as $file) {
        $proof[$file] = hash('sha256', conflictGitFile($argv[1], $file));
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-conflict-preview-request', 'version' => 1,
        'runtime_revision' => $argv[1], 'application_files' => $proof], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $hashes = [];
    foreach (new DirectoryIterator($out) as $file) {
        if ($file->isDot()) {
            continue;
        }
        if (! $file->isFile() || $file->isLink()) {
            throw new RuntimeException('Fichier indirect.');
        }
        chmod($file->getPathname(), 0600);
        $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($file, $sha) => "$sha  $file", array_keys($hashes), $hashes))."\n");
    echo "PACKAGE=$out\nCOMMAND=bash \"$out/fetch-conflicts.sh\"\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'STOP : paquet non valide. '.$exception->getMessage().PHP_EOL);
    exit(1);
}
