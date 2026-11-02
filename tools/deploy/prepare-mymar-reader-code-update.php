<?php

declare(strict_types=1);

// Private code-only package, pinned to the committed application release.
umask(0077);
chdir(dirname(__DIR__, 2));
function readerUpdateGit(array $arguments): string
{
    $process = proc_open(['git', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Git indisponible.');
    }
    $value = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($value)) {
        throw new RuntimeException('Revision Git non disponible.');
    }

    return $value;
}
try {
    if ($argc !== 1 || trim(readerUpdateGit(['branch', '--show-current'])) !== 'v2') {
        throw new RuntimeException('Lancer sans argument sur v2 apres commit.');
    }
    $revision = trim(readerUpdateGit(['rev-parse', 'HEAD']));
    $out = rtrim(getenv('HOME'), '/').'/Downloads/cofrac2-mymar-reader-update-'.bin2hex(random_bytes(5));
    mkdir($out, 0700); mkdir($out.'/code', 0700); mkdir($out.'/validation', 0700);
    $files = ['code/entry.php' => 'tools/deploy/mymar/booking-http/entry.php',
        'update-code.php' => 'tools/deploy/mymar/booking-http/update-code.php', 'update-code.sh' => 'tools/deploy/mymar/booking-http/update-code.sh'];
    foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy', 'MymarReadWindow'] as $class) {
        $files['code/'.$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
    }
    foreach (['MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource', 'MymarBookingGateway'] as $class) {
        $files['code/'.$class.'.php'] = 'tools/deploy/mymar/booking/'.$class.'.php';
    }
    foreach (['MymarObservation', 'MymarReadWindow', 'MymarCachedObservation', 'MymarCalendarLabels', 'MymarPendingBookingPage'] as $class) {
        $files['validation/'.$class.'.php'] = 'app/Services/Mymar/'.$class.'.php';
    }
    $code = [];
    foreach ($files as $name => $source) {
        $body = readerUpdateGit(['show', 'HEAD:'.$source]);
        file_put_contents($out.'/'.$name, $body); chmod($out.'/'.$name, 0600);
        if (str_starts_with($name, 'code/')) {
            $code[basename($name)] = hash('sha256', $body);
        }
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-reader-code-update', 'version' => 1,
        'revision' => $revision, 'files' => $code], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $names = [...array_keys($files), 'REQUEST.json']; sort($names);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(fn ($name) => hash_file('sha256', $out.'/'.$name).'  '.$name, $names))."\n");
    echo "PACKAGE=$out\nMANIFEST_SHA256=".hash_file('sha256', $out.'/SHA256SUMS')."\nREVISION=$revision\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL); exit(1);
}
