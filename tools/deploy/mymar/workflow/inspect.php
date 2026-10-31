<?php

declare(strict_types=1);

use Cofrac2\Deploy\MymarWorkflowAudit;

require __DIR__.'/MymarReadOnlyAudit.php';
require __DIR__.'/MymarWorkflowAudit.php';

umask(0077);

function workflowWrite(string $file, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    $bytes = str_ends_with($file, '.gz') ? gzencode($json, 6) : $json;
    $stream = fopen($file, 'xb');
    if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || ! fclose($stream)) {
        throw new RuntimeException('Incomplete output.');
    }
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! in_array($argv[1], ['prepare', 'finish'], true)) {
        throw new RuntimeException('Invalid invocation.');
    }
    $run = realpath($argv[2]);
    if ($run === false || $run !== $argv[2] || is_link($run)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-workflow-[A-Za-z0-9]+$~D', $run)
        || fileowner($run) !== 0 || (fileperms($run) & 0077) !== 0) {
        throw new RuntimeException('Unexpected private directory.');
    }
    $root = '/home/ubuntu/www/MYMAR';
    if ($argv[1] === 'prepare') {
        $source = MymarWorkflowAudit::sources($root);
        workflowWrite($run.'/source-proof.private.json', $source['proof']);
        unset($source['proof']);
        workflowWrite($run.'/source-facts.json.gz', ['format' => 'cofrac.mymar-booking-source-audit',
            'version' => 1, 'collected_at_utc' => gmdate(DATE_ATOM), ...$source]);
        $sql = MymarWorkflowAudit::metadataSql();
        if (file_put_contents($run.'/metadata.sql', $sql) !== strlen($sql)) {
            throw new RuntimeException('Incomplete SQL preparation.');
        }
        echo "Analyse statique preparee. Metadonnees uniquement, aucune ligne de dossier ou de compte lue.\n";
    } else {
        $proof = json_decode(file_get_contents($run.'/source-proof.private.json'), true, 32, JSON_THROW_ON_ERROR);
        if (! MymarWorkflowAudit::unchanged($root, $proof) || MymarWorkflowAudit::sources($root)['proof'] !== $proof) {
            throw new RuntimeException('Source changed during inspection.');
        }
        $metadata = $run.'/metadata.jsonl';
        if (! is_file($metadata) || is_link($metadata) || filesize($metadata) > 262144) {
            throw new RuntimeException('Metadata absent or excessive.');
        }
        $rows = $tables = [];
        $allowedTables = ['dossiers', 'dossier_rdv', 'dossier_historiques', 'clients', 'status', 'users'];
        foreach (file($metadata, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($row) || ! in_array($row['table'] ?? null, $allowedTables, true)
                || ! in_array($row['section'] ?? null, ['table', 'column', 'foreign_key', 'trigger'], true)
                || count($rows) >= 756) {
                throw new RuntimeException('Unexpected metadata.');
            }
            if ($row['section'] === 'table') {
                if (($row['engine'] ?? null) !== 'InnoDB') {
                    throw new RuntimeException('Non-transactional workflow table.');
                }
                $tables[] = $row['table'];
            }
            $rows[] = $row;
        }
        if (count($tables) !== 6 || array_diff($allowedTables, $tables)) {
            throw new RuntimeException('Workflow tables missing.');
        }
        foreach (['column' => 601, 'foreign_key' => 101, 'trigger' => 51] as $section => $limit) {
            if (count(array_filter($rows, static fn ($row) => $row['section'] === $section)) >= $limit) {
                throw new RuntimeException('Metadata truncated.');
            }
        }
        workflowWrite($run.'/schema.json', ['format' => 'cofrac.mymar-booking-schema-audit', 'version' => 1, 'rows' => $rows]);
        $summary = ['format' => 'cofrac.mymar-booking-workflow-audit', 'version' => 1,
            'complete' => true, 'checked_at_utc' => gmdate(DATE_ATOM), 'source_files' => count(array_filter($proof)),
            'source_unchanged' => true, 'metadata_complete' => true,
            'business_rows_read' => false, 'database_writes' => false, 'application_bootstrapped' => false,
            'http_requests' => false, 'services_changed' => false, 'booking_enabled' => false,
            'existing_readonly_gateway_unchanged' => true];
        workflowWrite($run.'/summary.json', $summary);
        echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable) {
    fwrite(STDERR, "STOP : audit des workflows incomplet. Aucun secret ou diagnostic brut affiche, aucune activation.\n");
    exit(1);
}
