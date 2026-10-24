<?php

use App\Services\Coffrac\CoffracDataset;
use App\Services\Coffrac\DocumentReferenceExport;
use App\Services\Coffrac\ProductionDataset;
use Cofrac2\Deploy\CoffracDocumentArchive;
use Cofrac2\Deploy\CoffracDocumentIssueReview;
use Cofrac2\Deploy\CoffracDocumentReconciliation;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/tools/deploy/lib/CoffracDocumentArchive.php';
require_once dirname(__DIR__, 2).'/tools/deploy/lib/CoffracDocumentIssueReview.php';
require_once dirname(__DIR__, 2).'/tools/deploy/lib/CoffracDocumentReconciliation.php';

function reconciliationRemoveFixture(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            reconciliationRemoveFixture($entry->getPathname());
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

function reconciliationSealFixture(string $directory, array $names): void
{
    file_put_contents($directory.'/SHA256SUMS', implode('', array_map(
        fn ($name) => hash_file('sha256', $directory.'/'.$name).'  '.$name."\n", $names)));
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/cofrac2-reconciliation-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0700);
    $this->directory = realpath($this->directory);
    $this->source = $this->directory.'/source';
    mkdir($this->source.'/public/documents', 0700, true);
    mkdir($this->source.'/public/pdf', 0700, true);
    foreach (['normal.pdf' => '%PDF private', 'shared.pdf' => '%PDF shared', 'empty.pdf' => '', 'unknown.bin' => 'QUARANTINE'] as $name => $content) {
        file_put_contents($this->source.'/public/documents/'.$name, $content);
    }
    file_put_contents($this->source.'/public/pdf/retained.pdf', '%PDF no proven dossier owner');
    file_put_contents($this->source.'/public/documents/excluded.php', '<?php throw new RuntimeException("NEVER EXECUTE");');
    $this->inventory = $this->directory.'/inventory';
    $this->archive = $this->directory.'/archive';
    $this->verification = $this->directory.'/verification';
    $this->references = $this->directory.'/references';
    $this->output = $this->directory.'/reconciliation';
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    CoffracDocumentArchive::prepareCopy($this->inventory, $this->archive, 0);
    foreach (file($this->inventory.'/inventory.jsonl') as $line) {
        $row = json_decode($line, true);
        $path = base64_decode($row['path_base64']);
        $target = $this->archive.'/files/'.$path;
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        copy($this->source.'/'.$path, $target);
        chmod($target, 0600);
    }
    CoffracDocumentArchive::verifyCopy($this->inventory, $this->archive, $this->verification);
    $base = ['source_table' => 'dossier_document', 'source_field' => 'path', 'dossier_id' => 101, 'owner_exists' => true,
        'client_visible' => false, 'delegataire_visible' => false, 'payload_sha256' => str_repeat('d', 64)];
    $this->rows = [];
    foreach (['normal.pdf', 'shared.pdf', 'shared.pdf', 'missing.pdf', 'empty.pdf', 'unknown.bin', '../outside.pdf', 'normal.pdf', 'normal.pdf'] as $offset => $path) {
        $this->rows[] = ['source_id' => $offset + 1, 'path_base64' => base64_encode($path)] + $base;
    }
    $this->rows[1]['client_visible'] = true;
    $this->rows[2] = ['dossier_id' => 102, 'client_visible' => true, 'delegataire_visible' => true] + $this->rows[2];
    $this->rows[7]['owner_exists'] = false;
    $this->rows[8] = ['source_table' => 'fiche_document', 'dossier_id' => null, 'owner_exists' => false] + $this->rows[8];
    $this->sourceMetadata = ['dump_sha256' => str_repeat('a', 64), 'dataset_sha256' => str_repeat('b', 64), 'dossier_document_records' => 8];
});

afterEach(function () {
    reconciliationRemoveFixture($this->directory);
});

it('matches every source record without merging dossier ACLs or serving files', function () {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    $report = CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    expect($report['complete'])->toBeTrue()->and($report['records'])->toBe(9)
        ->and($report['linked_records'])->toBe(3)->and($report['unique_linked_files'])->toBe(2)
        ->and($report['shared_file_references'])->toBe(1)->and($report['all_references_resolved'])->toBeFalse()
        ->and($report['copy_content_rehashed'])->toBeFalse()->and($report['native_database_read'])->toBeFalse()
        ->and($report['database_links_verified'])->toBeFalse()->and($report['serving_enabled'])->toBeFalse()
        ->and($report['final_cutover_ready'])->toBeFalse()->and(array_sum($report['issues']))->toBe(6);
    $links = array_map(fn ($line) => json_decode($line, true), file($this->output.'/links.jsonl'));
    expect($links[1]['client_visible'])->toBeTrue()->and($links[1]['delegataire_visible'])->toBeFalse()
        ->and($links[2]['delegataire_visible'])->toBeTrue()->and($links[2]['dossier_id'])->toBe(102)
        ->and($links[1]['verified_sha256'])->toBe($links[2]['verified_sha256'])
        ->and($links[0]['verified_sha256'])->toBe(hash_file('sha256', $this->source.'/public/documents/normal.pdf'))
        ->and($report['issues'])->toBe([
            'missing_in_verified_inventory' => 1, 'empty_file' => 1, 'quarantined_file' => 1,
            'unsafe_or_missing_path' => 1, 'missing_dossier_owner' => 1, 'attachment_scope_to_review' => 1,
        ])
        ->and($report['unreferenced_files_retained'])->toBe(1)
        ->and(file_exists($this->archive.'/files/public/pdf/retained.pdf'))->toBeTrue()
        ->and(fileperms($this->output) & 0777)->toBe(0700)
        ->and(fileperms($this->output.'/links.jsonl') & 0777)->toBe(0600);
    $repeat = $this->directory.'/repeat';
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $repeat);
    expect(hash_file('sha256', $repeat.'/links.jsonl'))->toBe(hash_file('sha256', $this->output.'/links.jsonl'));
});

it('refuses forged references, private and delegated ACLs, duplicate IDs and omitted records', function (string $case) {
    if ($case === 'secret') {
        $this->rows[0]['password_hash'] = 'NEVER EXPORT';
    } elseif ($case === 'ACL') {
        $this->rows[0]['delegataire_visible'] = true;
    } elseif ($case === 'duplicate') {
        $this->rows[1]['source_id'] = 1;
    } else {
        array_shift($this->rows);
    }
    expect(fn () => CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references))
        ->toThrow(RuntimeException::class);
    expect(file_exists($this->references.'/SHA256SUMS'))->toBeFalse();
})->with(['secret', 'ACL', 'duplicate', 'omitted']);

it('refuses altered reference seals, public metadata and symlinks', function (string $case) {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    $file = $this->references.'/references.jsonl';
    if ($case === 'public') {
        chmod($file, 0644);
    } elseif ($case === 'symlink') {
        rename($file, $this->directory.'/reference-target');
        symlink($this->directory.'/reference-target', $file);
    } else {
        file_put_contents($file, "{}\n", FILE_APPEND);
    }
    expect(fn () => CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output))
        ->toThrow(RuntimeException::class);
    expect(file_exists($this->output))->toBeFalse();
})->with(['public', 'symlink', 'checksum']);

it('refuses a failed or mismatched verification even with a newly sealed report', function (string $case) {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    $path = $this->verification.'/report.json';
    $report = json_decode(file_get_contents($path), true);
    if ($case === 'failed') {
        $report['copy_verified'] = false;
    } elseif ($case === 'different inventory') {
        $report['inventory_sha256'] = str_repeat('e', 64);
    } else {
        $report['verified_files']--;
    }
    file_put_contents($path, json_encode($report));
    reconciliationSealFixture($this->verification, ['contents.jsonl', 'issues.jsonl', 'report.json']);
    expect(fn () => CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output))
        ->toThrow(RuntimeException::class);
})->with(['failed', 'different inventory', 'incomplete']);

it('refuses absent hashes even if the verification files were resealed', function () {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    $file = $this->verification.'/contents.jsonl';
    $lines = file($file);
    array_pop($lines);
    file_put_contents($file, implode('', $lines));
    reconciliationSealFixture($this->verification, ['contents.jsonl', 'issues.jsonl', 'report.json']);
    expect(fn () => CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output))
        ->toThrow(RuntimeException::class);
});

it('rejects a changed size, symlink or hardlink in the copy without touching any original', function (string $case) {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    $path = $this->archive.'/files/public/documents/normal.pdf';
    if ($case === 'size') {
        file_put_contents($path, 'A different size');
    } else {
        unlink($path);
        if ($case === 'symlink') {
            symlink($this->source.'/public/documents/normal.pdf', $path);
        } else {
            link($this->archive.'/files/public/documents/shared.pdf', $path);
        }
    }
    expect(fn () => CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output))
        ->toThrow(RuntimeException::class);
    expect(file_get_contents($this->source.'/public/documents/normal.pdf'))->toBe('%PDF private');
})->with(['size', 'symlink', 'hardlink']);

it('refuses output inside the original or copy and never overwrites an existing report', function () {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    foreach ([$this->source.'/report', $this->archive.'/report', $this->references.'/report', $this->verification.'/report'] as $path) {
        expect(fn () => CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $path))
            ->toThrow(RuntimeException::class);
    }
    mkdir($this->output, 0700);
    expect(fn () => CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output))
        ->toThrow(RuntimeException::class);
});

it('exports current source ACLs with private taking precedence and skips questionnaire JSON', function () {
    $directory = $this->directory.'/dataset';
    mkdir($directory, 0700);
    $tables = array_fill_keys([...CoffracDataset::TABLES, 'credentials'], []);
    $tables['dossiers'] = [['id' => 101]];
    $tables['dossier_document'] = [
        ['id' => 1, 'dossier_id' => 101, 'path' => 'normal.pdf', 'is_private' => 1, 'is_delegataire' => 1],
        ['id' => 2, 'dossier_id' => 101, 'path' => 'shared.pdf', 'is_private' => '0', 'is_delegataire' => '0'],
        ['id' => 3, 'dossier_id' => 999, 'path' => '../outside.pdf', 'is_private' => 0, 'is_delegataire' => 1],
    ];
    $tables['fiche_document'] = [['id' => 1, 'path' => 'catalogue.pdf', 'fiche_id' => 7]];
    $tables['media'] = [['id' => 1, 'path' => 'media.png', 'fiche_id' => 7]];
    $tables['dossier_fiches'] = [['id' => 201, 'dossier_id' => 101, 'rapport' => '[{"questions":[]}]', 'rapport_pdf' => null, 'signature' => 'sig.png']];
    $metadata = [];
    foreach ($tables as $table => $rows) {
        file_put_contents($directory.'/'.$table.'.jsonl', implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));
        chmod($directory.'/'.$table.'.jsonl', 0600);
        $metadata[$table] = ['count' => count($rows), 'sha256' => hash_file('sha256', $directory.'/'.$table.'.jsonl')];
    }
    file_put_contents($directory.'/manifest.json', json_encode(['format' => 'techcalendar.coffrac-production', 'version' => 1,
        'complete' => true, 'final_cutover_ready' => false, 'dump_sha256' => str_repeat('a', 64), 'tables' => $metadata]));
    chmod($directory.'/manifest.json', 0600);
    $rows = iterator_to_array(DocumentReferenceExport::rows(new ProductionDataset($directory)));
    expect($rows)->toHaveCount(6)->and($rows[0]['client_visible'])->toBeFalse()
        ->and($rows[0]['delegataire_visible'])->toBeFalse()->and($rows[1]['client_visible'])->toBeTrue()
        ->and($rows[1]['delegataire_visible'])->toBeFalse()->and($rows[2]['owner_exists'])->toBeFalse()
        ->and(base64_decode($rows[2]['path_base64']))->toBe('../outside.pdf')
        ->and($rows[5]['source_field'])->toBe('signature')
        ->and(json_encode($rows))->not->toContain('questions', 'password', 'token');
});

it('refuses the production launcher on the development machine', function () {
    $process = new Process(['bash', dirname(__DIR__, 2).'/tools/deploy/reconcile-production-documents.sh']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()->and($process->getErrorOutput())->toContain('ns365601');
});

it('reviews unresolved references without opening, relinking or deleting files', function () {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    $target = $this->directory.'/review';
    $report = CoffracDocumentIssueReview::review($this->inventory, $this->verification, $this->archive, $this->output, $target);
    expect($report['complete'])->toBeTrue()->and($report['records'])->toBe(9)
        ->and($report['linked_records'])->toBe(3)->and($report['reviewed_records'])->toBe(6)
        ->and($report['original_contents_read'])->toBeFalse()->and($report['automatic_relinking'])->toBeFalse()
        ->and($report['database_links_verified'])->toBeFalse()->and($report['serving_enabled'])->toBeFalse()
        ->and($report['final_cutover_ready'])->toBeFalse()->and($report['copy_content_rehashed'])->toBeFalse()
        ->and($report['unreferenced_files_retained_by_root'])->toBe(['public/pdf' => 1])
        ->and($report['diagnoses']['not_in_inventory_and_missing_on_source_now'])->toBe(1)
        ->and(count(file($target.'/issues-review.jsonl')))->toBe(6)
        ->and(fileperms($target.'/issues-review.jsonl') & 0777)->toBe(0600);
});

it('distinguishes absent, newly present, excluded and unsafe source entries without executing them', function (string $case, string $diagnosis) {
    if ($case === 'excluded') {
        $this->rows[3]['path_base64'] = base64_encode('excluded.php');
    }
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    $path = $this->source.'/public/documents/missing.pdf';
    if ($case === 'appeared') {
        file_put_contents($path, '%PDF ADDED SINCE INVENTORY');
    } elseif ($case === 'symlink') {
        symlink($this->source.'/public/documents/excluded.php', $path);
    } elseif ($case === 'directory') {
        mkdir($path, 0700);
    }
    $report = CoffracDocumentIssueReview::review($this->inventory, $this->verification, $this->archive, $this->output, $this->directory.'/review');
    expect($report['diagnoses'][$diagnosis])->toBe(1)->and($report['automatic_relinking'])->toBeFalse()
        ->and(file_exists($this->archive.'/files/public/documents/excluded.php'))->toBeFalse()
        ->and(file_get_contents($this->source.'/public/documents/excluded.php'))->toContain('NEVER EXECUTE');
})->with([
    'missing' => ['missing', 'not_in_inventory_and_missing_on_source_now'],
    'appeared' => ['appeared', 'present_on_source_not_in_verified_inventory'],
    'excluded' => ['excluded', 'excluded_code_or_configuration'],
    'symlink' => ['symlink', 'source_path_requires_review'],
    'directory' => ['directory', 'source_path_requires_review'],
]);

it('keeps an exact other-root candidate private without treating it as proven ownership', function () {
    $this->rows[3]['path_base64'] = base64_encode('retained.pdf');
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    $target = $this->directory.'/review';
    $report = CoffracDocumentIssueReview::review($this->inventory, $this->verification, $this->archive, $this->output, $target);
    $rows = array_map(fn ($line) => json_decode($line, true), file($target.'/issues-review.jsonl'));
    expect($report['references_with_other_root_candidates'])->toBe(2)->and($report['linked_records'])->toBe(3)
        ->and($rows[0]['candidate_paths_base64'])->toBe([base64_encode('public/pdf/retained.pdf')])
        ->and(json_encode($report))->not->toContain('retained.pdf')
        ->and($rows[0]['automatic_relinking'])->toBeFalse();
});

it('requires a new copy for a previously empty original repaired after verification', function () {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    file_put_contents($this->source.'/public/documents/empty.pdf', '%PDF REPAIRED');
    $report = CoffracDocumentIssueReview::review($this->inventory, $this->verification, $this->archive, $this->output, $this->directory.'/review');
    expect($report['diagnoses']['previously_empty_source_now_nonempty'])->toBe(1)
        ->and(filesize($this->archive.'/files/public/documents/empty.pdf'))->toBe(0)
        ->and($report['serving_enabled'])->toBeFalse();
});

it('refuses changed or public reconciliation evidence and mismatched verification', function (string $case) {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    $path = $this->output.'/report.json';
    if ($case === 'public') {
        chmod($path, 0644);
    } elseif ($case === 'checksum') {
        file_put_contents($path, 'not JSON');
    } else {
        $report = json_decode(file_get_contents($path), true);
        $report['verification_sha256'] = str_repeat('f', 64);
        file_put_contents($path, json_encode($report));
        reconciliationSealFixture($this->output, ['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl', 'report.json']);
    }
    expect(fn () => CoffracDocumentIssueReview::review($this->inventory, $this->verification, $this->archive, $this->output, $this->directory.'/review'))
        ->toThrow(RuntimeException::class);
    expect(file_exists($this->directory.'/review'))->toBeFalse();
})->with(['public', 'checksum', 'different proof']);

it('rejects a resealed reconciliation with missing records or unknown fields', function (string $case) {
    CoffracDocumentReconciliation::exportReferences($this->rows, $this->sourceMetadata, $this->references);
    CoffracDocumentReconciliation::reconcile($this->references, $this->inventory, $this->verification, $this->archive, $this->output);
    $path = $this->output.'/links.jsonl';
    $lines = file($path);
    if ($case === 'omitted') {
        array_pop($lines);
    } else {
        $row = json_decode($lines[0], true);
        $row['secret'] = 'NEVER PRINT';
        $lines[0] = json_encode($row)."\n";
    }
    file_put_contents($path, implode('', $lines));
    reconciliationSealFixture($this->output, ['links.jsonl', 'issues.jsonl', 'unreferenced.jsonl', 'report.json']);
    expect(fn () => CoffracDocumentIssueReview::review($this->inventory, $this->verification, $this->archive, $this->output, $this->directory.'/review'))
        ->toThrow(RuntimeException::class);
    expect(file_exists($this->directory.'/review/SHA256SUMS'))->toBeFalse();
})->with(['omitted', 'unknown field']);
