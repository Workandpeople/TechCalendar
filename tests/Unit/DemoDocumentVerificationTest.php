<?php

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/demo-doc-test-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    mkdir($this->directory.'/documents', 0700);
    file_put_contents($this->directory.'/documents/a.pdf', 'test');
    file_put_contents($this->directory.'/documents/b.pdf', '');
    file_put_contents($this->directory.'/documents.list0', "a.pdf\0b.pdf\0");
    file_put_contents($this->directory.'/summary.json', json_encode(['complete' => true, 'unique_files' => 2, 'total_bytes' => 4]));
    $this->verify = fn () => new Process([
        PHP_BINARY, __DIR__.'/../../tools/deploy/verify-documents.php',
        $this->directory.'/documents', $this->directory.'/documents.list0', $this->directory.'/summary.json',
    ]);
});

afterEach(function () {
    foreach (glob($this->directory.'/documents/*') as $file) {
        unlink($file);
    }
    rmdir($this->directory.'/documents');
    foreach (glob($this->directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->directory);
});

it('verifies the selection without hiding source empty files', function () {
    $process = ($this->verify)();
    expect($process->run())->toBe(0)->and($process->getOutput())->toContain('"empty": 1');
});

it('rejects missing or changed files', function (bool $missing) {
    if ($missing) {
        unlink($this->directory.'/documents/a.pdf');
    } else {
        file_put_contents($this->directory.'/documents/a.pdf', 'changed');
    }
    expect(($this->verify)()->run())->toBe(1);
})->with([true, false]);

it('rejects symlinks even when their target belongs to the collection', function () {
    unlink($this->directory.'/documents/b.pdf');
    symlink($this->directory.'/documents/a.pdf', $this->directory.'/documents/b.pdf');
    expect(($this->verify)()->run())->toBe(1);
});

it('rejects traversal and incomplete manifests', function (string $manifest) {
    file_put_contents($this->directory.'/documents.list0', $manifest);
    expect(($this->verify)()->run())->toBe(1);
})->with(["../a.pdf\0b.pdf\0", "a.pdf\0b.pdf", "a.pdf\0a.pdf\0"]);
