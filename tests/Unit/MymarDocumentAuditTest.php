<?php

use Cofrac2\Deploy\MymarDocumentAudit;

require_once __DIR__.'/../../tools/deploy/mymar/MymarReadOnlyAudit.php';
require_once __DIR__.'/../../tools/deploy/mymar/workflow/MymarWorkflowAudit.php';
require_once __DIR__.'/../../tools/deploy/mymar/documents-audit/MymarDocumentAudit.php';

function documentMetadataFixture(): array
{
    $rows = array_map(fn ($table) => ['section' => 'table', 'table' => $table, 'engine' => 'InnoDB'], MymarDocumentAudit::TABLES);
    foreach (['status' => ['id', 'name', 'deleted_at'], 'dossier_documents' => ['id', 'dossier_id', 'path', 'nom']] as $table => $columns) {
        foreach ($columns as $column) {
            $rows[] = ['section' => 'column', 'table' => $table, 'column' => $column];
        }
    }

    return $rows;
}

it('prepares only bounded read-only metadata and the status reference catalogue', function () {
    $metadata = MymarDocumentAudit::metadataSql();
    $status = MymarDocumentAudit::statusSql();
    foreach ([$metadata, $status] as $sql) {
        expect($sql)->toContain('START TRANSACTION READ ONLY', 'ROLLBACK;', 'max_execution_time=1500', 'lock_wait_timeout=2')
            ->not->toMatch('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|GRANT|CALL|INTO)\b/i');
    }
    expect($metadata)->not->toContain('FROM MYMAR.', 'ACTION_STATEMENT')
        ->and($status)->toContain('FROM MYMAR.status', 'LIMIT 201', 'CAST(deleted_at IS NOT NULL AS UNSIGNED)')
        ->not->toContain('FROM MYMAR.dossiers', 'FROM MYMAR.dossier_documents', 'FROM MYMAR.users', 'champs');
    MymarDocumentAudit::validateMetadata(documentMetadataFixture());
    expect(MymarDocumentAudit::statuses([['section' => 'status', 'id' => 25, 'name' => 'Dépôt', 'deleted' => 0]]))
        ->toBe([['id' => 25, 'name' => 'Dépôt', 'deleted' => false]]);
});

it('normalizes MySQL JSON boolean and integer status flags without accepting loose values', function (bool|int $flag) {
    $row = ['section' => 'status', 'id' => 25, 'name' => 'Dépôt', 'deleted' => $flag];
    expect(MymarDocumentAudit::statuses([$row]))->toBe([['id' => 25, 'name' => 'Dépôt', 'deleted' => (bool) $flag]]);
})->with([false, true, 0, 1]);

it('rejects string and null status flags rather than casting them to true', function (mixed $flag) {
    expect(fn () => MymarDocumentAudit::statuses([['section' => 'status', 'id' => 25, 'name' => 'Dépôt', 'deleted' => $flag]]))
        ->toThrow(RuntimeException::class);
})->with(['false', 'true', '0', '1', 2, null]);

it('rejects missing unexpected nontransactional and truncated metadata', function () {
    $rows = documentMetadataFixture();
    $rows[0]['engine'] = 'MyISAM';
    expect(fn () => MymarDocumentAudit::validateMetadata($rows))->toThrow(RuntimeException::class)
        ->and(fn () => MymarDocumentAudit::validateMetadata([]))->toThrow(RuntimeException::class)
        ->and(fn () => MymarDocumentAudit::validateMetadata([['section' => 'value', 'table' => 'users']]))->toThrow(RuntimeException::class);
    $rows = documentMetadataFixture();
    array_pop($rows);
    expect(fn () => MymarDocumentAudit::validateMetadata($rows))->toThrow(RuntimeException::class);
    $rows = array_merge(documentMetadataFixture(), array_fill(0, 601, ['section' => 'column', 'table' => 'status', 'column' => 'name']));
    expect(fn () => MymarDocumentAudit::validateMetadata($rows))->toThrow(RuntimeException::class);
});

it('rejects truncated or overbroad status reference exports', function () {
    $row = ['section' => 'status', 'id' => 25, 'name' => 'Depot', 'deleted' => 0];
    expect(fn () => MymarDocumentAudit::statuses([]))->toThrow(RuntimeException::class)
        ->and(fn () => MymarDocumentAudit::statuses([$row, $row]))->toThrow(RuntimeException::class)
        ->and(fn () => MymarDocumentAudit::statuses(array_fill(0, 201, $row)))->toThrow(RuntimeException::class)
        ->and(fn () => MymarDocumentAudit::statuses([array_merge($row, ['email' => 'PRIVATE_CANARY'])]))->toThrow(RuntimeException::class);
});

it('reads only explicit source files without executing them or leaking secrets', function () {
    $root = sys_get_temp_dir().'/mymar-document-audit-'.bin2hex(random_bytes(5));
    $paths = ['app/Http/Controllers/DossierController.php', 'app/Http/Controllers/ApiController.php',
        'app/Models/Dossier.php', 'app/Models/DossierDocument.php', 'app/Models/DossierHistorique.php',
        'app/Models/Status.php', 'config/filesystems.php', 'routes/web.php', 'routes/api.php'];
    $directories = [];
    mkdir($root, 0700);
    $root = realpath($root);
    try {
        foreach ($paths as $path) {
            $directory = dirname($path);
            $parts = explode('/', $directory);
            $current = $root;
            foreach ($parts as $part) {
                $current .= '/'.$part;
                if (! is_dir($current)) {
                    mkdir($current, 0700);
                    $directories[] = $current;
                }
            }
            file_put_contents($root.'/'.$path, <<<'PHP'
<?php
throw new RuntimeException('NEVER_EXECUTE');
class Legacy {
    public function uploadDocument($request) {
        $secret = 'PRIVATE_CANARY';
        $request->file('file')->storeAs('documents', $filename, 'public');
        $document->is_public = 0;
    }
    public function addDocument($request) { $document->nom = $request->nomDoc; $document->save(); }
    public function unrelated() { return 'HIDDEN_CANARY'; }
}
PHP);
        }
        $source = MymarDocumentAudit::sources($root);
        $json = json_encode($source['files']);
        expect($source['files'])->toHaveCount(9)
            ->and($source['proof'])->toHaveCount(9)
            ->and($json)->toContain('uploadDocument', 'storeAs', 'documents', 'is_public', 'nomDoc')
            ->not->toContain('PRIVATE_CANARY', 'NEVER_EXECUTE', 'HIDDEN_CANARY');
        symlink($root, $root.'-link');
        expect(fn () => MymarDocumentAudit::sources($root.'-link'))->toThrow(RuntimeException::class);
        unlink($root.'-link');
        unlink($root.'/'.$paths[0]);
        expect(fn () => MymarDocumentAudit::sources($root))->toThrow(RuntimeException::class);
    } finally {
        foreach ($paths as $path) {
            if (is_file($root.'/'.$path)) {
                unlink($root.'/'.$path);
            }
        }
        foreach (array_reverse($directories) as $directory) {
            rmdir($directory);
        }
        rmdir($root);
    }
});
