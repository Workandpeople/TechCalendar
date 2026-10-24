<?php

use Symfony\Component\Process\Process;

function productionAuditFixture(): array
{
    $root = sys_get_temp_dir().'/cofrac2-audit-unit-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $metadata = "source_database=fiche_crm\ncollected_at_utc=2026-10-23T17:50:54Z\n"
        ."collection_type=online_preparation_snapshot\ndata_consistency=mixed_engines_not_atomic\n"
        ."non_transactional_tables=clients\nfinal_cutover_ready=no\n";
    $files = ['coffrac.sql.gz' => gzencode('SELECT 1;'), 'server.tsv' => 'fixture', 'tables.tsv' => 'fixture',
        'columns.tsv' => 'fixture', 'collection.txt' => $metadata, 'mysqldump.stderr' => ''];
    foreach ($files as $name => $content) {
        file_put_contents($root.'/'.$name, $content);
    }
    $checksums = '';
    foreach (array_keys($files) as $name) {
        $checksums .= hash_file('sha256', $root.'/'.$name).'  '.$name."\n";
    }
    file_put_contents($root.'/SHA256SUMS', $checksums);
    $rows = [];
    for ($id = 1; $id <= 26; $id++) {
        $rows[] = ['section' => 'tables', 'table' => 'fixture_'.$id, 'engine' => 'InnoDB', 'coverage' => 'business_source'];
        $rows[] = ['section' => 'counts', 'table' => 'fixture_'.$id, 'rows' => 0];
    }
    for ($id = 1; $id <= 7; $id++) {
        $rows[] = ['section' => 'user_types', 'source_type' => $id, 'label' => 'Profile '.$id, 'type_status' => 1,
            'status_permissions' => '', 'module_permissions' => '', 'accounts' => 0, 'active_not_archived' => 0];
    }
    foreach (['relations' => 15, 'billing' => 9] as $section => $count) {
        for ($id = 1; $id <= $count; $id++) {
            $rows[] = ['section' => $section, 'metric' => 'fixture_'.$id, 'count' => 0];
        }
    }
    productionAuditRows($root, $rows);

    return [$root, $rows];
}

function productionAuditRows(string $root, array $rows): void
{
    file_put_contents($root.'/audit.jsonl', implode('', array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows)));
}

function productionAuditCommand(string $root): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/tools/fixtures/seal-production-audit.php',
        '--seal', $root, $root.'/audit.jsonl', $root.'/report.json']);
    $process->run();

    return $process;
}

function deleteProductionAuditFixture(string $root): void
{
    foreach (new FilesystemIterator($root) as $file) {
        unlink($file->getPathname());
    }
    rmdir($root);
}

beforeEach(function () {
    [$this->directory, $this->rows] = productionAuditFixture();
});

afterEach(function () {
    deleteProductionAuditFixture($this->directory);
});

it('seals an audit without approving import or granting source administrators global rights', function () {
    expect(productionAuditCommand($this->directory)->isSuccessful())->toBeTrue();
    $report = json_decode(file_get_contents($this->directory.'/report.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($report['audit_complete'])->toBeTrue()->and($report['native_import_ready'])->toBeFalse()
        ->and($report['final_cutover_ready'])->toBeFalse()
        ->and($report['confirmed_account_policy']['source_type_1'])->toBe(['native_role' => 0, 'admin' => false, 'label' => 'gerant'])
        ->and($report['confirmed_account_policy']['seeded_account_only_super_admin'])->toBeTrue()
        ->and($report['confirmed_account_policy']['reactivate_source_tokens_sessions_or_jobs'])->toBeFalse()
        ->and($report['source']['reference_date'])->toBe('2026-10-23');
});

it('rejects modified source files without creating a report', function () {
    file_put_contents($this->directory.'/coffrac.sql.gz', 'changed');
    expect(productionAuditCommand($this->directory)->isSuccessful())->toBeFalse()
        ->and(file_exists($this->directory.'/report.json'))->toBeFalse();
});

it('rejects secrets or individual fields in aggregate rows without printing their content', function () {
    $this->rows[] = ['section' => 'relations', 'metric' => 'fixture', 'count' => 0, 'password' => 'PRIVATE_TEST_VALUE'];
    productionAuditRows($this->directory, $this->rows);
    $process = productionAuditCommand($this->directory);
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput().$process->getErrorOutput())->not->toContain('PRIVATE_TEST_VALUE')
        ->and(file_exists($this->directory.'/report.json'))->toBeFalse();
});

it('rejects incomplete or duplicate table counts', function (string $mode) {
    if ($mode === 'missing') {
        array_shift($this->rows);
    } else {
        $this->rows[] = $this->rows[1];
    }
    productionAuditRows($this->directory, $this->rows);
    expect(productionAuditCommand($this->directory)->isSuccessful())->toBeFalse()
        ->and(file_exists($this->directory.'/report.json'))->toBeFalse();
})->with(['missing', 'duplicate']);

it('refuses source symlinks and an existing report', function (string $mode) {
    if ($mode === 'symlink') {
        rename($this->directory.'/server.tsv', $this->directory.'/real-server.tsv');
        symlink($this->directory.'/real-server.tsv', $this->directory.'/server.tsv');
    } else {
        file_put_contents($this->directory.'/report.json', 'KEEP_EXISTING_REPORT');
    }
    expect(productionAuditCommand($this->directory)->isSuccessful())->toBeFalse();
    if ($mode === 'existing') {
        expect(file_get_contents($this->directory.'/report.json'))->toBe('KEEP_EXISTING_REPORT');
    }
})->with(['symlink', 'existing']);
