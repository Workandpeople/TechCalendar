<?php

declare(strict_types=1);

require __DIR__.'/MymarReadOnlyAudit.php';

use Cofrac2\Deploy\MymarReadOnlyAudit as Audit;

umask(0077);

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
        throw new RuntimeException('Invalid invocation.');
    }
    $run = realpath($argv[1]);
    $root = '/home/ubuntu/www/MYMAR';
    if ($run === false || is_link($argv[1]) || ! is_dir($run) || (fileperms($run) & 0077) !== 0
        || ! str_starts_with($run, '/var/lib/cofrac2-production/mymar-cache-diagnostic-')
        || realpath($root) !== $root || is_link($root)) {
        throw new RuntimeException('Private paths required.');
    }
    $before = Audit::read($root, 'bootstrap/cache/config.php');
    $diagnostic = Audit::cacheDiagnostic($root);
    $after = Audit::read($root, 'bootstrap/cache/config.php');
    $file = $root.'/bootstrap/cache/config.php';
    clearstatcache(true, $file);
    $report = [
        'format' => 'cofrac.mymar-cache-syntax-diagnostic',
        'version' => 1,
        'collected_at_utc' => gmdate(DATE_ATOM),
        'cache_present' => file_exists($file) || is_link($file),
        'cache_is_symlink' => is_link($file),
        'cache_size_bytes' => is_file($file) && ! is_link($file) ? filesize($file) : null,
        'source_stable' => $before !== null && $after !== null && hash_equals(hash('sha256', $before), hash('sha256', $after)),
        'diagnostic' => $diagnostic,
        'configuration_values_exported' => false,
        'application_executed' => false,
        'sql_executed' => false,
        'http_requests' => false,
        'services_changed' => false,
        'connector_enabled' => false,
    ];
    $output = $run.'/cache-diagnostic.json';
    $handle = fopen($output, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Output refused.');
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if (fwrite($handle, $json) !== strlen($json) || ! fclose($handle)) {
        throw new RuntimeException('Output incomplete.');
    }
    chmod($output, 0600);
    echo json_encode(['literal_decode' => $diagnostic['literal_decode'], 'sql_executed' => false,
        'application_executed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "STOP : diagnostic statique incomplet. Aucun cache execute ni SQL lance.\n");
    exit(1);
}
