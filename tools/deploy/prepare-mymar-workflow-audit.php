<?php

declare(strict_types=1);

umask(0077);
chdir(dirname(__DIR__, 2));

function workflowGit(string $path): string
{
    $process = proc_open(['git', 'show', 'HEAD:'.$path], [0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Git indisponible.');
    }
    $contents = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($contents)) {
        throw new RuntimeException('Source non committee.');
    }

    return $contents;
}

try {
    if (PHP_SAPI !== 'cli' || $argc !== 1) {
        throw new RuntimeException('Usage : php prepare-mymar-workflow-audit.php');
    }
    $home = getenv('HOME');
    if (! is_string($home) || $home === '' || ! is_dir($home.'/Downloads')) {
        throw new RuntimeException('Downloads absent.');
    }
    $out = $home.'/Downloads/cofrac2-mymar-workflow-'.bin2hex(random_bytes(5));
    if (! mkdir($out, 0700)) {
        throw new RuntimeException('Creation du paquet impossible.');
    }
    $files = ['MymarReadOnlyAudit.php' => 'tools/deploy/mymar/MymarReadOnlyAudit.php'];
    foreach (['MymarWorkflowAudit.php', 'inspect.php', 'collect.sh', 'fetch-workflow.sh', 'README.txt'] as $file) {
        $files[$file] = 'tools/deploy/mymar/workflow/'.$file;
    }
    $hashes = [];
    foreach ($files as $name => $path) {
        $contents = workflowGit($path);
        if (file_put_contents($out.'/'.$name, $contents) !== strlen($contents)) {
            throw new RuntimeException('Ecriture incomplete.');
        }
        chmod($out.'/'.$name, 0600);
        $hashes[$name] = hash('sha256', $contents);
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($name, $hash) => "$hash  $name", array_keys($hashes), $hashes))."\n");
    echo "PACKAGE=$out\nCOMMAND=bash \"$out/fetch-workflow.sh\"\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'STOP : '.$exception->getMessage().PHP_EOL);
    exit(1);
}
