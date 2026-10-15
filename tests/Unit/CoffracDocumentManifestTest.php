<?php

beforeEach(function () {
    $this->manifestRoot = sys_get_temp_dir().'/coffrac-document-test-'.bin2hex(random_bytes(6));
    mkdir($this->manifestRoot, 0700);
    mkdir($this->manifestRoot.'/source', 0700);
    $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $this->pdo->exec('CREATE TABLE status (id INTEGER, name TEXT)');
    $this->pdo->exec("INSERT INTO status VALUES (11, 'DOSSIERS FACTURER')");
    $this->pdo->exec('CREATE TABLE dossiers (id INTEGER PRIMARY KEY, status_id INTEGER)');
    $this->pdo->exec('CREATE TABLE dossier_fiches (dossier_id INTEGER, status_id INTEGER)');
    $this->pdo->exec('CREATE TABLE dossier_document (id INTEGER PRIMARY KEY, dossier_id INTEGER, path TEXT)');
    $this->collectDocuments = require __DIR__.'/../../tools/coffrac/collect-document-manifest.php';
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->manifestRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && ! $item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($this->manifestRoot);
});

test('document selection excludes fully invoiced dossiers but preserves mixed cancelled and unclassified dossiers', function () {
    $this->pdo->exec('INSERT INTO dossiers VALUES (1, 1), (2, 1), (3, 1), (4, 1), (5, -1)');
    $this->pdo->exec('INSERT INTO dossier_fiches VALUES (1, 11), (1, 11), (2, 11), (2, 2), (3, 6), (5, NULL)');
    for ($id = 1; $id <= 5; $id++) {
        file_put_contents($this->manifestRoot.'/source/document '.$id.'.pdf', str_repeat('x', $id));
        $query = $this->pdo->prepare('INSERT INTO dossier_document VALUES (?, ?, ?)');
        $query->execute([$id, $id, 'document '.$id.'.pdf']);
    }
    $this->pdo->exec("INSERT INTO dossier_document VALUES (6, 2, 'document 2.pdf')");
    $before = $this->pdo->query('SELECT * FROM dossier_document')->fetchAll();
    $report = ($this->collectDocuments)($this->pdo, $this->manifestRoot.'/source', $this->manifestRoot);
    expect($report['complete'])->toBeTrue()
        ->and($report['selected_document_records'])->toBe(5)
        ->and($report['selected_dossiers'])->toBe(4)
        ->and($report['unique_files'])->toBe(4)
        ->and($report['duplicate_file_references'])->toBe(1)
        ->and($report['total_bytes'])->toBe(14)
        ->and(file_get_contents($report['export_directory'].'/documents.list0'))->toBe("document 2.pdf\0document 3.pdf\0document 4.pdf\0document 5.pdf\0")
        ->and($this->pdo->query('SELECT * FROM dossier_document')->fetchAll())->toBe($before)
        ->and(file_exists($this->manifestRoot.'/source/document 1.pdf'))->toBeTrue()
        ->and(fileperms($report['export_directory'].'/summary.json') & 0777)->toBe(0600);
});

test('document manifest rejects traversal executable paths symlinks and missing files without reading file contents', function () {
    $this->pdo->exec('INSERT INTO dossiers VALUES (1, 1)');
    file_put_contents($this->manifestRoot.'/private.pdf', 'PRIVATE');
    symlink($this->manifestRoot.'/private.pdf', $this->manifestRoot.'/source/link.pdf');
    symlink($this->manifestRoot, $this->manifestRoot.'/source/linked-dir');
    $paths = ['../private.pdf', '/etc/passwd', "evil.pdf\nother.pdf", 'file.php', '.htaccess', 'folder/.hidden.pdf', 'link.pdf', 'linked-dir/private.pdf', 'missing.pdf', 'a\\b.pdf', 'https://example.test/file.pdf'];
    foreach ($paths as $index => $path) {
        $query = $this->pdo->prepare('INSERT INTO dossier_document VALUES (?, 1, ?)');
        $query->execute([$index + 1, $path]);
    }
    $report = ($this->collectDocuments)($this->pdo, $this->manifestRoot.'/source', $this->manifestRoot);
    expect($report['unique_files'])->toBe(0)->and($report['total_bytes'])->toBe(0)
        ->and(array_sum($report['skipped']))->toBe(count($paths))
        ->and($report['skipped']['symlink'])->toBe(2)
        ->and(file_get_contents($report['export_directory'].'/documents.list0'))->toBe('');
});

test('document collection refuses an unexpected status mapping or an export inside the source', function () {
    $this->pdo->exec("UPDATE status SET name = 'AUTRE ETAT'");
    expect(fn () => ($this->collectDocuments)($this->pdo, $this->manifestRoot.'/source', $this->manifestRoot))->toThrow(RuntimeException::class, 'Statut facture inattendu');
    expect(fn () => ($this->collectDocuments)($this->pdo, $this->manifestRoot.'/source', $this->manifestRoot.'/source'))->toThrow(RuntimeException::class, 'hors du repertoire');
});
