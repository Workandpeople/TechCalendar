<?php

use Cofrac2\Deploy\CoffracDocumentArchive;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/tools/deploy/lib/CoffracDocumentArchive.php';

function documentArchiveDeleteFixture(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            documentArchiveDeleteFixture($entry->getPathname());
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

function documentArchiveCopyFixture(string $inventory, string $archive): void
{
    CoffracDocumentArchive::prepareCopy($inventory, $archive, 0);
    foreach (file($inventory.'/inventory.jsonl') as $line) {
        $row = json_decode($line, true);
        $relative = base64_decode($row['path_base64']);
        $source = json_decode(file_get_contents($inventory.'/summary.json'), true)['source'];
        $path = $archive.'/files/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        copy($source.'/'.$relative, $path);
        chmod($path, 0600);
        touch($path, $row['mtime']);
    }
}

function documentArchiveResealFixture(string $inventory): void
{
    $names = ['inventory.jsonl', 'files.list0', 'documents.list0', 'exceptions.jsonl', 'summary.json'];
    file_put_contents($inventory.'/SHA256SUMS', implode('', array_map(fn ($name) => hash_file('sha256', $inventory.'/'.$name).'  '.$name."\n", $names)));
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/cofrac2-document-unit-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0700);
    $this->source = $this->root.'/source';
    foreach (CoffracDocumentArchive::ROOTS as $directory) {
        mkdir($this->source.'/'.$directory, 0700, true);
    }
    $files = ['public/documents/normal.pdf' => '%PDF normal', 'public/documents/already-billed.pdf' => '%PDF billed',
        'public/documents/empty.pdf' => '', 'public/documents/client; espace.pdf' => '%PDF with spaces',
        "public/documents/line\nbreak.pdf" => '%PDF quarantine unsafe name',
        'public/documents/.htaccess' => 'ORIGINAL RULES', 'public/documents/backdoor.php' => '<?php exit(99);',
        'public/pdf/report.pdf' => '%PDF report', 'public/media/site.jpg' => 'image', 'public/signature/sig.png' => 'signature',
        'public/lot_dossier/lot.xlsx' => 'workbook', 'storage/app/unknown.bin' => 'unknown private content'];
    foreach ($files as $path => $content) {
        file_put_contents($this->source.'/'.$path, $content);
    }
    file_put_contents($this->source.'/.env', 'ORIGINAL APP CONFIG');
    file_put_contents($this->root.'/external.txt', 'DO NOT COPY THROUGH SYMLINK');
    symlink($this->root.'/external.txt', $this->source.'/public/documents/link.pdf');
    $this->inventory = $this->root.'/inventory';
    $this->archive = $this->root.'/archive';
});

afterEach(function () {
    documentArchiveDeleteFixture($this->root);
});

it('inventories all six roots without a billing filter and isolates code, links, empty files and unknown formats', function () {
    $summary = CoffracDocumentArchive::inventory($this->source, $this->inventory);
    expect($summary['complete'])->toBeTrue()->and($summary['files'])->toBe(10)
        ->and($summary['servable_documents'])->toBe(3)->and($summary['empty_files'])->toBe(1)
        ->and($summary['quarantined_files'])->toBe(2)
        ->and($summary['exceptions']['code_configuration_or_metadata_not_copied'])->toBe(2)
        ->and($summary['exceptions']['symlink_not_followed'])->toBe(1)
        ->and($summary['source_database_read'])->toBeFalse()->and($summary['final_cutover_ready'])->toBeFalse();
    expect(CoffracDocumentArchive::validate($this->inventory)['files'])->toBe(10);
    $list = explode("\0", file_get_contents($this->inventory.'/files.list0'));
    expect($list)->toContain('public/documents/already-billed.pdf', "public/documents/line\nbreak.pdf", 'storage/app/unknown.bin')
        ->not->toContain('.env', 'public/documents/backdoor.php', 'public/documents/link.pdf');
    $documents = explode("\0", file_get_contents($this->inventory.'/documents.list0'));
    expect($documents)->toContain('normal.pdf', 'already-billed.pdf')->not->toContain('empty.pdf', "line\nbreak.pdf");
    expect(fileperms($this->inventory) & 0777)->toBe(0700)
        ->and(fileperms($this->inventory.'/inventory.jsonl') & 0777)->toBe(0600);
});

it('verifies contents and independence without creating public access or changing the source', function () {
    $hash = hash_file('sha256', $this->source.'/public/documents/normal.pdf');
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    documentArchiveCopyFixture($this->inventory, $this->archive);
    $report = CoffracDocumentArchive::verifyCopy($this->inventory, $this->archive, $this->root.'/verified');
    expect($report['copy_verified'])->toBeTrue()->and($report['verified_files'])->toBe(10)
        ->and($report['database_links_verified'])->toBeFalse()->and($report['final_cutover_ready'])->toBeFalse()
        ->and(hash_file('sha256', $this->source.'/public/documents/normal.pdf'))->toBe($hash)
        ->and(file_get_contents($this->source.'/.env'))->toBe('ORIGINAL APP CONFIG')
        ->and(file_get_contents($this->source.'/public/documents/.htaccess'))->toBe('ORIGINAL RULES')
        ->and(is_file($this->archive.'/files/public/documents/backdoor.php'))->toBeFalse()
        ->and(lstat($this->archive.'/files/public/documents/normal.pdf')['ino'])->not->toBe(lstat($this->source.'/public/documents/normal.pdf')['ino']);
    expect(is_file($this->root.'/verified/SHA256SUMS'))->toBeTrue();
    $again = CoffracDocumentArchive::prepareCopy($this->inventory, $this->archive, 0);
    expect($again['additional_bytes'])->toBe(0);
});

it('detects content corruption even when the size and mtime still match', function () {
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    documentArchiveCopyFixture($this->inventory, $this->archive);
    $path = $this->archive.'/files/public/documents/normal.pdf';
    $mtime = filemtime($path);
    file_put_contents($path, str_repeat('X', filesize($path)));
    touch($path, $mtime);
    $report = CoffracDocumentArchive::verifyCopy($this->inventory, $this->archive, $this->root.'/corrupt');
    expect($report['copy_verified'])->toBeFalse()->and($report['issues'])->toBe(1)
        ->and(file_get_contents($this->root.'/corrupt/issues.jsonl'))->toContain('content_checksum_mismatch');
});

it('refuses a source file replaced since the inventory and keeps every original', function () {
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    $path = $this->source.'/public/documents/normal.pdf';
    file_put_contents($path, 'A changed original with a different size');
    expect(fn () => CoffracDocumentArchive::prepareCopy($this->inventory, $this->archive, 0))->toThrow(RuntimeException::class, 'Source modifiee');
    expect(file_get_contents($path))->toBe('A changed original with a different size');
});

it('rejects source or destination symlinks and destination hardlinks', function (string $mode) {
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    documentArchiveCopyFixture($this->inventory, $this->archive);
    $target = $mode === 'source' ? $this->source.'/public/documents/normal.pdf' : $this->archive.'/files/public/documents/normal.pdf';
    unlink($target);
    if ($mode === 'hardlink') {
        link($this->root.'/external.txt', $target);
        chmod($target, 0600);
    } else {
        symlink($this->root.'/external.txt', $target);
    }
    $report = CoffracDocumentArchive::verifyCopy($this->inventory, $this->archive, $this->root.'/unsafe');
    expect($report['copy_verified'])->toBeFalse()->and($report['issues'])->toBeGreaterThan(0)
        ->and(file_get_contents($this->root.'/external.txt'))->toBe('DO NOT COPY THROUGH SYMLINK');
})->with(['source', 'destination', 'hardlink']);

it('refuses insufficient free space, unknown archive directories and output inside the source', function () {
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    expect(fn () => CoffracDocumentArchive::prepareCopy($this->inventory, $this->archive, PHP_INT_MAX))->toThrow(RuntimeException::class, 'Espace insuffisant');
    mkdir($this->root.'/unrelated', 0700);
    file_put_contents($this->root.'/unrelated/sentinel', 'DO NOT CHANGE');
    expect(fn () => CoffracDocumentArchive::prepareCopy($this->inventory, $this->root.'/unrelated', 0))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->root.'/unrelated/sentinel'))->toBe('DO NOT CHANGE');
    expect(fn () => CoffracDocumentArchive::inventory($this->source, $this->source.'/inventory'))->toThrow(RuntimeException::class);
});

it('rejects altered checksums, unsafe paths even after resealing, public manifests and overwrites', function (string $mode) {
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    if ($mode === 'unsafe path') {
        $lines = file($this->inventory.'/inventory.jsonl');
        $row = json_decode($lines[0], true);
        $row['path_base64'] = base64_encode('../external.txt');
        $lines[0] = json_encode($row)."\n";
        file_put_contents($this->inventory.'/inventory.jsonl', implode('', $lines));
        documentArchiveResealFixture($this->inventory);
    } elseif ($mode === 'public') {
        chmod($this->inventory.'/inventory.jsonl', 0644);
    } elseif ($mode === 'overwrite') {
        expect(fn () => CoffracDocumentArchive::inventory($this->source, $this->inventory))->toThrow(RuntimeException::class);

        return;
    } else {
        file_put_contents($this->inventory.'/files.list0', 'injected', FILE_APPEND);
    }
    expect(fn () => CoffracDocumentArchive::validate($this->inventory))->toThrow(RuntimeException::class);
})->with(['checksum', 'unsafe path', 'public', 'overwrite']);

it('retains extra destination files for review instead of deleting them', function () {
    CoffracDocumentArchive::inventory($this->source, $this->inventory);
    documentArchiveCopyFixture($this->inventory, $this->archive);
    file_put_contents($this->archive.'/files/public/documents/older-copy.pdf', 'KEEP OLD FILE');
    chmod($this->archive.'/files/public/documents/older-copy.pdf', 0600);
    $report = CoffracDocumentArchive::verifyCopy($this->inventory, $this->archive, $this->root.'/extras');
    expect($report['extra_files_retained'])->toBe(1)->and($report['copy_verified'])->toBeTrue()
        ->and(file_get_contents($this->archive.'/files/public/documents/older-copy.pdf'))->toBe('KEEP OLD FILE');
});

it('keeps the production launcher disabled on the development machine', function () {
    $process = new Process(['bash', dirname(__DIR__, 2).'/tools/deploy/prepare-production-documents.sh', '--inventory-all-documents']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()->and($process->getErrorOutput())->toContain('ns365601');
});
