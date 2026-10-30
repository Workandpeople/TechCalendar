<?php

declare(strict_types=1);

require __DIR__.'/SqlAuditDiagnostic.php';

use Cofrac2\Deploy\SqlAuditDiagnostic;

umask(0077);

try {
    $state = '/var/lib/cofrac2-production';
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{64}$/D', $argv[2])) {
        throw new RuntimeException('Invalid invocation.');
    }
    $output = realpath($argv[1]);
    if ($output === false || is_link($argv[1]) || ! is_dir($output) || (fileperms($output) & 0077) !== 0
        || ! str_starts_with($output, $state.'/mymar-sql-diagnostic-')) {
        throw new RuntimeException('Private output path required.');
    }
    $target = SqlAuditDiagnostic::findRun($state, $argv[2]);
    $report = SqlAuditDiagnostic::inspect($target);
    $report['collected_at_utc'] = gmdate(DATE_ATOM);
    $file = fopen($output.'/sql-diagnostic.json', 'xb');
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if ($file === false || fwrite($file, $json) !== strlen($json) || ! fclose($file)) {
        throw new RuntimeException('Diagnostic output incomplete.');
    }
    echo "Diagnostics existants relus. Aucun SQL ni appel applicatif.\n";
} catch (Throwable) {
    fwrite(STDERR, "STOP : diagnostic incomplet ou audit cible non confirme. Aucun SQL lance.\n");
    exit(1);
}
