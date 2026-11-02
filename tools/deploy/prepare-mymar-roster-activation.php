<?php

declare(strict_types=1);

// Exports committed tooling only; includes no database snapshot or credential.
umask(0077);
chdir(dirname(__DIR__, 2));
function rosterActivationGit(array $arguments): string
{
    $process = proc_open(['git', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Git indisponible.');
    }
    $value = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($value)) {
        throw new RuntimeException('Revision Git non disponible.');
    }

    return $value;
}
try {
    if ($argc !== 1 || trim(rosterActivationGit(['branch', '--show-current'])) !== 'v2') {
        throw new RuntimeException('Lancer sans argument sur v2 apres commit.');
    }
    $revision = trim(rosterActivationGit(['rev-parse', 'HEAD']));
    $out = rtrim(getenv('HOME'), '/').'/Downloads/cofrac2-mymar-roster-activation-'.bin2hex(random_bytes(5));
    mkdir($out, 0700);
    $files = ['MymarReadOnlyAudit.php' => 'tools/deploy/mymar/MymarReadOnlyAudit.php',
        'MymarRuntimeState.php' => 'tools/deploy/mymar/runtime/MymarRuntimeState.php'];
    foreach (['activate.php', 'activate.sh', 'apply-roster.sh', 'runtime-probe.php', 'MymarTechnicianRoster.php', 'MymarRosterActivation.php', 'ACTIVATION.txt'] as $name) {
        $files[$name] = 'tools/deploy/mymar/roster/'.$name;
    }
    foreach ($files as $name => $source) {
        file_put_contents($out.'/'.$name, rosterActivationGit(['show', 'HEAD:'.$source]));
        chmod($out.'/'.$name, 0600);
    }
    $contract = [];
    foreach (['MymarActionSettings', 'MymarPilotConfiguration', 'MymarPilotSettings', 'MymarObservationSync', 'MymarCachedObservation',
        'MymarPendingBookings', 'MymarBookingReaderSettings', 'MymarSlotGuard', 'MymarBookingPlanning'] as $class) {
        $path = 'app/Services/Mymar/'.$class.'.php';
        $contract[$path] = hash('sha256', rosterActivationGit(['show', 'HEAD:'.$path]));
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-roster-activation', 'version' => 1,
        'revision' => $revision, 'runtime_contract' => $contract], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    file_put_contents($out.'/confirmed-identities.json', json_encode(['bindings' => [
        ['source_technician_id' => 27, 'source_name' => 'daoud yachouroutua', 'cofrac_user_id' => 40, 'cofrac_name' => 'Yach DAOUD'],
        ['source_technician_id' => 29, 'source_name' => 'ballekens bertrand', 'cofrac_user_id' => 34, 'cofrac_name' => 'Bertrand BALLEKENS'],
        ['source_technician_id' => 35, 'source_name' => 'jebari ramzy', 'cofrac_user_id' => 57, 'cofrac_name' => 'Jebarri RAMZY'],
        ['source_technician_id' => 275, 'source_name' => 'SAID (84,13,30,26,07,04,83)', 'cofrac_user_id' => 92, 'cofrac_name' => 'Hachemi SAID'],
    ]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $names = [...array_keys($files), 'REQUEST.json', 'confirmed-identities.json'];
    sort($names);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(fn ($name) => hash_file('sha256', $out.'/'.$name).'  '.$name, $names))."\n");
    echo "PACKAGE=$out\nMANIFEST_SHA256=".hash_file('sha256', $out.'/SHA256SUMS')."\nREVISION=$revision\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL);
    exit(1);
}
