<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('retrieves the private readonly production plan and rejects SSH or integrity failures', function (string $mode) {
    $directory = sys_get_temp_dir().'/internal-users-plan-fetch-'.bin2hex(random_bytes(6));
    $package = $directory.'/cofrac2-internal-users-plan-abcdef12';
    foreach ([$package, $directory.'/bin', $directory.'/source', $directory.'/Downloads'] as $path) {
        mkdir($path, 0700, true);
    }
    try {
        $file = 'fetch-internal-users-plan.sh';
        copy(__DIR__.'/../../tools/deploy/'.$file, $package.'/'.$file);
        file_put_contents($package.'/SHA256SUMS', hash_file('sha256', $package.'/'.$file).'  '.$file."\n");
        file_put_contents($directory.'/source/report.json.gz', gzencode('{"writes_performed":false}'));
        $digest = hash_file('sha256', $directory.'/source/report.json.gz');
        file_put_contents($directory.'/source/SHA256SUMS', $digest."  report.json.gz\n");
        file_put_contents($directory.'/bin/ssh', <<<'BASH'
#!/usr/bin/env bash
set -eu
[ "$PLAN_FETCH_MODE" != ssh-failure ] || exit 19
printf 'READ_ONLY_CHECK\r\nREPORT_DIR=/home/ubuntu/cofrac2-internal-users-report-abcdef12\r\nREPORT_SHA256=%s\r\n' "$PLAN_FETCH_SHA"
BASH);
        file_put_contents($directory.'/bin/scp', <<<'BASH'
#!/usr/bin/env bash
set -eu
destination="${@: -1}"
if [[ "$destination" = ubuntu@* ]]; then exit 0; fi
cp "$PLAN_FETCH_DIRECTORY/source/report.json.gz" "$PLAN_FETCH_DIRECTORY/source/SHA256SUMS" "$destination/"
if [ "$PLAN_FETCH_MODE" = bad-checksum ]; then printf corrupt >> "$destination/report.json.gz"; fi
BASH);
        chmod($directory.'/bin/ssh', 0700);
        chmod($directory.'/bin/scp', 0700);
        $process = new Process(['bash', $package.'/'.$file], env: ['HOME' => $directory,
            'PATH' => $directory.'/bin:'.getenv('PATH'), 'PLAN_FETCH_MODE' => $mode,
            'PLAN_FETCH_SHA' => $digest, 'PLAN_FETCH_DIRECTORY' => $directory]);
        $process->setTimeout(15)->run();
        expect($process->isSuccessful())->toBe($mode === 'success');
        if ($mode === 'success') {
            expect(preg_match('/^LOCAL_DIR=(.+)$/m', $process->getOutput(), $matches))->toBe(1);
            expect(hash_file('sha256', $matches[1].'/report.json.gz'))->toBe($digest)
                ->and(fileperms($matches[1]) & 0777)->toBe(0700)
                ->and(fileperms($matches[1].'/report.json.gz') & 0777)->toBe(0600);
        } else {
            expect($process->getOutput())->not->toContain('LOCAL_DIR=');
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['success', 'ssh-failure', 'bad-checksum']);

it('refuses to run the production inspector on an unapproved host or CLI invocation before any database bootstrap', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../../tools/deploy/plan-techcalendar-internal-users.php', '--apply']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Controle reserve a la seule production Cofrac2.')
        ->and($process->getOutput())->toBe('');
});
