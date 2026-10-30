<?php

declare(strict_types=1);

// All release inputs come from the committed archive; production credentials never leave the server.
umask(0077);
$root = dirname(__DIR__, 2);
function pilotDeliveryRun(array $command): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['HOME' => getenv('HOME'), 'PATH' => getenv('PATH'), 'LC_ALL' => 'C']);
    if (! is_resource($process)) {
        throw new RuntimeException('Commande locale indisponible.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(substr($error, 0, 1000));
    }

    return $output;
}
try {
    if (PHP_SAPI !== 'cli' || $argc !== 5 || ! preg_match('/^[a-f0-9]{40}$/D', $argv[1])
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-http-runtime-[A-Za-z0-9]+$~D', $argv[2])
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $argv[3])
        || ! preg_match('/^[a-f0-9]{64}$/D', $argv[4])) {
        throw new RuntimeException('Usage : php prepare-mymar-pilot-delivery.php BASE_REVISION API_RUNTIME MAP_PATH MAP_SHA256');
    }
    chdir($root);
    $revision = trim(pilotDeliveryRun(['git', 'rev-parse', 'HEAD']));
    $builder = sys_get_temp_dir().'/cofrac-pilot-builder-'.bin2hex(random_bytes(6)).'.php';
    // Pin the generic builder to committed code, substituting only its local repository root.
    $committed = pilotDeliveryRun(['git', 'show', $revision.':tools/deploy/prepare-correctifs-delivery.php']);
    $committed = str_replace('$root = dirname(__DIR__, 2);', '$root = '.var_export($root, true).';', $committed, $count);
    if ($count !== 1 || file_put_contents($builder, $committed) === false) {
        throw new RuntimeException('Constructeur de release inattendu.');
    }
    try {
        $result = pilotDeliveryRun([PHP_BINARY, $builder, $argv[1]]);
    } finally {
        unlink($builder);
    }
    if (! preg_match('~^PACKAGE=(.+)$~m', $result, $match)) {
        throw new RuntimeException('Paquet applicatif absent.');
    }
    $out = $match[1];
    $launcher = file_get_contents($out.'/deploy.sh');
    $from = strpos($launcher, '# Confirm the new public reference using the runtime account, before closing V2.');
    $to = strpos($launcher, '# Run maintenance with the currently installed templates, before changing code.');
    if ($from === false || $to === false || $to <= $from) {
        throw new RuntimeException('Precontrole applicatif inattendu.');
    }
    // This lot changes no geocoding: do not couple its availability to an unrelated public API.
    $launcher = substr($launcher, 0, $from).substr($launcher, $to);
    $launcher = preg_replace('~^        php8\.4 "\$APP/tools/deploy/mymar/gateway/prepare-disabled\.php" --prepare-disabled "\$REV"\n~m', '', $launcher, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('Chemin de reexecution inattendu.');
    }
    $launcher = str_replace('Correctifs deja installes et preparation MYMAR desactivee verifiee.', 'Code pilote deja installe ; API privee inchangee.', $launcher);
    $tail = strpos($launcher, "printf 'Correctifs adresses, recherche beneficiaire et commentaires deployes.");
    if ($tail === false) {
        throw new RuntimeException('Fin de release inattendue.');
    }
    $launcher = substr($launcher, 0, $tail)."printf 'Code du pilote MYMAR deploye ; les deux domaines sont ouverts. API privee inchangee.\\n'\n";
    file_put_contents($out.'/deploy.sh', $launcher);
    $files = [
        'pilot-control.php' => 'tools/deploy/mymar/pilot/pilot-control.php',
        'setup-pilot.sh' => 'tools/deploy/mymar/pilot/setup-pilot.sh',
        'stop-pilot.sh' => 'tools/deploy/mymar/pilot/stop-pilot.sh',
        'cofrac2-mymar-observation.service' => 'tools/deploy/mymar/pilot/cofrac2-mymar-observation.service',
        'cofrac2-mymar-observation.timer' => 'tools/deploy/mymar/pilot/cofrac2-mymar-observation.timer',
        'MymarRuntimeState.php' => 'tools/deploy/mymar/runtime/MymarRuntimeState.php',
        'MymarApprovedTechnicianMap.php' => 'tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php',
        'MymarGatewayPreparation.php' => 'tools/deploy/mymar/gateway/MymarGatewayPreparation.php',
        'MymarPilotConfiguration.php' => 'app/Services/Mymar/MymarPilotConfiguration.php',
    ];
    foreach ($files as $name => $path) {
        file_put_contents($out.'/'.$name, pilotDeliveryRun(['git', 'show', $revision.':'.$path]));
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-cache-pilot-request', 'version' => 1,
        'target_revision' => $revision, 'api_runtime_directory' => $argv[2], 'mapping_path' => $argv[3], 'mapping_sha256' => $argv[4]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $setup = array_merge(array_keys($files), ['REQUEST.json']);
    sort($setup);
    file_put_contents($out.'/SETUP-SHA256SUMS', implode("\n", array_map(static fn ($name) => hash_file('sha256', $out.'/'.$name)."  $name", $setup))."\n");
    $fetch = pilotDeliveryRun(['git', 'show', $revision.':tools/deploy/mymar/pilot/fetch-pilot.sh.in']);
    file_put_contents($out.'/fetch-pilot.sh', str_replace('@PACKAGE_NAME@', basename($out), $fetch));
    file_put_contents($out.'/README.txt', pilotDeliveryRun(['git', 'show', $revision.':tools/deploy/mymar/pilot/README.txt']));
    foreach (['deploy.sh', 'setup-pilot.sh', 'stop-pilot.sh', 'fetch-pilot.sh'] as $script) {
        pilotDeliveryRun(['bash', '-n', $out.'/'.$script]);
    }
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->isLink()) {
            throw new RuntimeException('Fichier indirect dans le paquet.');
        }
        chmod($file->getPathname(), 0600);
        $name = substr($file->getPathname(), strlen($out) + 1);
        if ($name !== 'SHA256SUMS') {
            $hashes[$name] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($name, $sha) => "$sha  $name", array_keys($hashes), $hashes))."\n");
    echo "PACKAGE=$out\nREVISION=$revision\nCOMMAND=bash \"$out/fetch-pilot.sh\"\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'STOP : paquet pilote non valide. '.$exception->getMessage().PHP_EOL);
    exit(1);
}
