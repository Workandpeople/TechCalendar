<?php

use Symfony\Component\Process\Process;

it('keeps both code update scripts valid bash', function (string $script) {
    $process = new Process(['bash', '-n', __DIR__.'/../../tools/deploy/'.$script]);
    expect($process->run())->toBe(0);
})->with(['prepare-code-update.sh', 'update-demo.sh']);

it('rejects an update invocation without the explicit flag before any side effect', function () {
    $process = new Process(['bash', __DIR__.'/../../tools/deploy/update-demo.sh']);
    expect($process->run())->toBe(1)
        ->and($process->getErrorOutput())->toContain('--update-v2');
});

it('packages committed code and compiled assets without exporting runtime data', function () {
    $source = file_get_contents(__DIR__.'/../../tools/deploy/prepare-code-update.sh');
    expect($source)->toContain('git archive "$REVISION"', 'public/build/.', 'git status --porcelain')
        ->not->toContain('export-demo.php', 'mysqldump', 'demo-secrets.env', 'storage/app');
});

it('backs up before migration and never seeds, imports or deletes runtime files', function () {
    $source = file_get_contents(__DIR__.'/../../tools/deploy/update-demo.sh');
    expect(strpos($source, 'gzip -t "$BACKUP/database-before.sql.gz"'))
        ->toBeLessThan(strpos($source, 'artisan migrate --force'));
    expect($source)->toContain("--exclude='/.env*'", "--exclude='/storage/'", 'env-checksum', 'artisan down --retry=60')
        ->not->toContain('artisan db:seed', 'artisan migrate:fresh', 'artisan key:generate', 'artisan migrate:rollback', 'mysql <');
    expect($source)->toContain('artisan clients:reconcile-legacy |')->not->toContain('clients:reconcile-legacy --apply');
});
