<?php

declare(strict_types=1);

use Cofrac2\Deploy\MymarDocumentAudit;
use Cofrac2\Deploy\MymarWorkflowAudit;

require __DIR__.'/MymarReadOnlyAudit.php';
require __DIR__.'/MymarWorkflowAudit.php';
require __DIR__.'/MymarDocumentAudit.php';
umask(0077);

function documentAuditWrite(string $file, mixed $data): void
{
    $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    if (str_ends_with($file, '.gz')) {
        $bytes = gzencode($bytes, 6);
    }
    $stream = fopen($file, 'xb');
    if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || ! fclose($stream)) {
        throw new RuntimeException('Incomplete output.');
    }
}

function documentAuditRows(string $file): array
{
    if (! is_file($file) || is_link($file) || filesize($file) > 262144) {
        throw new RuntimeException('Invalid SQL report.');
    }
    $rows = [];
    foreach (file($file, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES) as $line) {
        $rows[] = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        if (count($rows) > 700) {
            throw new RuntimeException('Report size limit.');
        }
    }

    return $rows;
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! in_array($argv[1], ['prepare', 'metadata', 'finish'], true)) {
        throw new RuntimeException('Invalid invocation.');
    }
    $run = realpath($argv[2]);
    if ($run === false || $run !== $argv[2] || is_link($run)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-documents-audit-[A-Za-z0-9]+$~D', $run)
        || fileowner($run) !== 0 || (fileperms($run) & 0077) !== 0) {
        throw new RuntimeException('Unexpected private directory.');
    }
    $root = '/home/ubuntu/www/MYMAR';
    if ($argv[1] === 'prepare') {
        $source = MymarDocumentAudit::sources($root);
        documentAuditWrite($run.'/source-proof.private.json', $source['proof']);
        unset($source['proof']);
        documentAuditWrite($run.'/source-facts.json.gz', ['format' => 'cofrac.mymar-document-source-audit', 'version' => 1, ...$source]);
        file_put_contents($run.'/metadata.sql', MymarDocumentAudit::metadataSql());
    } elseif ($argv[1] === 'metadata') {
        MymarDocumentAudit::validateMetadata(documentAuditRows($run.'/metadata.jsonl'));
        file_put_contents($run.'/status.sql', MymarDocumentAudit::statusSql());
    } else {
        $proof = json_decode(file_get_contents($run.'/source-proof.private.json'), true, 32, JSON_THROW_ON_ERROR);
        if (! MymarWorkflowAudit::unchanged($root, $proof)) {
            throw new RuntimeException('Source changed.');
        }
        $rows = documentAuditRows($run.'/metadata.jsonl');
        MymarDocumentAudit::validateMetadata($rows);
        documentAuditWrite($run.'/schema.json', ['rows' => $rows]);
        documentAuditWrite($run.'/status-catalogue.json', MymarDocumentAudit::statuses(documentAuditRows($run.'/status.jsonl')));
        $summary = ['format' => 'cofrac.mymar-document-workflow-audit', 'version' => 1, 'complete' => true,
            'checked_at_utc' => gmdate(DATE_ATOM), 'source_unchanged' => true, 'status_reference_catalogue_read' => true,
            'dossier_or_account_rows_read' => false, 'document_contents_read' => false, 'database_writes' => false,
            'application_bootstrapped' => false, 'http_requests' => false, 'services_changed' => false,
            'document_access_enabled' => false, 'vt2_upload_enabled' => false];
        documentAuditWrite($run.'/summary.json', $summary);
        echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $error) {
    $file = basename($error->getFile());
    $diagnostic = ['stage' => in_array($argv[1] ?? null, ['prepare', 'metadata', 'finish'], true) ? $argv[1] : 'invocation',
        'kind' => $error instanceof JsonException ? 'json_validation' : ($error instanceof RuntimeException ? 'validation' : 'php_error'),
        'file' => in_array($file, ['inspect.php', 'MymarDocumentAudit.php', 'MymarReadOnlyAudit.php', 'MymarWorkflowAudit.php'], true) ? $file : 'internal',
        'line' => $error->getLine()];
    fwrite(STDERR, 'AUDIT_DIAGNOSTIC='.json_encode($diagnostic, JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, "STOP : audit documentaire incomplet. Aucun diagnostic brut affiche, aucune activation.\n");
    exit(1);
}
