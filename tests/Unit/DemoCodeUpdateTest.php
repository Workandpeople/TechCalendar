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

it('enforces code permissions independently of the private deployment umask', function () {
    $source = file_get_contents(__DIR__.'/../../tools/deploy/update-demo.sh');
    expect($source)->toContain('rsync -rpt --chown=root:root --chmod=D755,F644', 'runuser -u www-data -- test -r "$APP/$path"');
    $process = new Process(['bash', '-n', __DIR__.'/../../tools/deploy/tests/check-code-permissions.sh']);
    expect($process->run())->toBe(0);
});

it('reconciles local billing history after migration and before reopening', function () {
    $source = file_get_contents(__DIR__.'/../../tools/deploy/update-demo.sh');
    $reconciliation = strpos($source, 'artisan crm:reconcile-billing --apply');
    expect($reconciliation)->toBeGreaterThan(strpos($source, 'artisan migrate --force'))
        ->toBeLessThan(strpos($source, 'artisan up'));
    expect($source)->toContain("SmsConfiguration::class)->mode() !== 'simulate'", 'billing-reconciliation.txt');
});

it('prepares preview configuration before maintenance and preserves rollback copies', function () {
    $source = file_get_contents(__DIR__.'/../../tools/deploy/update-demo.sh');
    expect(strpos($source, '$STAGE/tools/deploy/document-preview-nginx.php'))
        ->toBeLessThan(strpos($source, 'MAINTENANCE=1'));
    expect($source)->toContain('nginx-vhost.conf', 'nginx-preview-map.conf', 'sha256sum -c "$BACKUP/nginx-checksums"',
        'NGINX_MAP_EXISTED=0', 'NGINX_CHANGED=1', 'rm -f -- "$NGINX_PREVIEW_MAP"', 'systemctl reload nginx');
    expect(strpos($source, 'install -o root -g root -m 0644 "$WORK/nginx-vhost.next"'))
        ->toBeLessThan(strrpos($source, 'nginx -t'));
});
