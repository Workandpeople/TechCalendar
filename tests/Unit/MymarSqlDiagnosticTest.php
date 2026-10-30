<?php

use Cofrac2\Deploy\SqlAuditDiagnostic;

require_once __DIR__.'/../../tools/deploy/mymar/sql-diagnostic/SqlAuditDiagnostic.php';

beforeEach(function () {
    $this->diagnosticRoot = sys_get_temp_dir().'/mymar-sql-diagnostic-test-'.bin2hex(random_bytes(8));
    mkdir($this->diagnosticRoot, 0700);
    $this->diagnosticRoot = realpath($this->diagnosticRoot);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->diagnosticRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->diagnosticRoot);
});

it('publishes only SQL codes and required schema checks from existing logs', function () {
    $run = $this->diagnosticRoot;
    file_put_contents($run.'/metadata.jsonl', implode("\n", array_map(json_encode(...), [
        ['section' => 'server', 'version' => '8.0.46-PRIVATE_SERVER', 'socket' => '/var/run/mysqld/mysqld.sock', 'port' => 3306],
        ['section' => 'table', 'schema' => 'MYMAR', 'table' => 'status', 'engine' => 'InnoDB', 'estimated_rows' => 28],
        ['section' => 'column', 'schema' => 'MYMAR', 'table' => 'status', 'column' => 'id'],
        ['section' => 'column', 'schema' => 'MYMAR', 'table' => 'status', 'column' => 'PRIVATE_COLUMN'],
        ['section' => 'table', 'schema' => 'PRIVATE_SCHEMA', 'table' => 'PRIVATE_TABLE', 'engine' => 'InnoDB'],
    ])));
    file_put_contents($run.'/metadata-private.stderr', "ERROR 1045 (28000) at line 7: PRIVATE_PASSWORD private@example.test\n");
    file_put_contents($run.'/database-private.stderr', "ERROR 1267 (HY000) at line 22: PRIVATE_SQL_VALUES\n");
    file_put_contents($run.'/explain-private.stderr', "private unknown client option PRIVATE_TOKEN\n");
    $before = hash_file('sha256', $run.'/metadata.jsonl');
    $report = SqlAuditDiagnostic::inspect($run);
    expect($report['errors']['metadata']['codes'])->toBe([['mysql_code' => 1045, 'sqlstate' => '28000', 'statement_line' => 7]])
        ->and($report['errors']['database']['codes'][0]['mysql_code'])->toBe(1267)
        ->and($report['errors']['explain']['unclassified_output'])->toBeTrue()
        ->and($report['schema_checks']['MYMAR.status']['missing_required_columns'])->toBe(['name', 'deleted_at'])
        ->and($report['server_metadata']['version_prefix'])->toBe('8.0.46')
        ->and($report['sql_executed'])->toBeFalse()
        ->and(hash_file('sha256', $run.'/metadata.jsonl'))->toBe($before)
        ->and(json_encode($report))->not->toContain('PRIVATE', '@example', 'password', 'unknown client option');
});

it('finds exactly one completed audit by checksum instead of guessing the latest directory', function () {
    $root = $this->diagnosticRoot;
    mkdir($root.'/mymar-readonly-A', 0700);
    mkdir($root.'/mymar-readonly-B', 0700);
    file_put_contents($root.'/mymar-readonly-A/source-facts.json.gz', 'REFERENCE_BYTES');
    file_put_contents($root.'/mymar-readonly-A/summary.json', '{}');
    file_put_contents($root.'/mymar-readonly-B/source-facts.json.gz', 'OTHER_REFERENCE');
    file_put_contents($root.'/mymar-readonly-B/summary.json', '{}');
    $hash = hash('sha256', 'REFERENCE_BYTES');
    expect(SqlAuditDiagnostic::findRun($root, $hash))->toBe($root.'/mymar-readonly-A');
    file_put_contents($root.'/mymar-readonly-B/source-facts.json.gz', 'REFERENCE_BYTES');
    expect(fn () => SqlAuditDiagnostic::findRun($root, $hash))->toThrow(RuntimeException::class)
        ->and(fn () => SqlAuditDiagnostic::findRun($root, str_repeat('0', 64)))->toThrow(RuntimeException::class);
});

it('refuses symlinked oversized or malformed diagnostic files', function () {
    $root = $this->diagnosticRoot;
    file_put_contents($root.'/outside', 'PRIVATE_SECRET');
    symlink($root.'/outside', $root.'/metadata.jsonl');
    expect(fn () => SqlAuditDiagnostic::inspect($root))->toThrow(RuntimeException::class);
    unlink($root.'/metadata.jsonl');
    file_put_contents($root.'/metadata.jsonl', 'not JSON PRIVATE_SECRET');
    $report = SqlAuditDiagnostic::inspect($root);
    expect($report['metadata_json_valid'])->toBeFalse()->and(json_encode($report))->not->toContain('PRIVATE_SECRET', 'not JSON');
    file_put_contents($root.'/metadata.jsonl', str_repeat(' ', 262145));
    expect(fn () => SqlAuditDiagnostic::inspect($root))->toThrow(RuntimeException::class);
});
