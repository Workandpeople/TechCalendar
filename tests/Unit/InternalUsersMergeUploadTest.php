<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('uploads the sealed merge package and propagates SCP or SSH failures without bypassing target checks', function (string $mode) {
    $directory = sys_get_temp_dir().'/internal-users-merge-upload-'.bin2hex(random_bytes(6));
    $package = $directory.'/cofrac2-internal-users-merge-abcdef12';
    mkdir($package, 0700, true);
    mkdir($directory.'/bin', 0700);
    try {
        $file = 'apply-internal-users-from-mac.sh';
        copy(__DIR__.'/../../tools/deploy/'.$file, $package.'/'.$file);
        file_put_contents($package.'/SHA256SUMS', hash_file('sha256', $package.'/'.$file).'  '.$file."\n");
        file_put_contents($directory.'/bin/scp', <<<'BASH'
#!/usr/bin/env bash
set -eu
printf 'scp %s\n' "$*" >> "$UPLOAD_CALLS"
[ "$UPLOAD_MODE" != scp-failure ]
BASH);
        file_put_contents($directory.'/bin/ssh', <<<'BASH'
#!/usr/bin/env bash
set -eu
printf 'ssh %s\n' "$*" >> "$UPLOAD_CALLS"
[ "$UPLOAD_MODE" != ssh-failure ] || exit 19
echo 'MOCKED_TRANSFER_ONLY'
BASH);
        chmod($directory.'/bin/scp', 0700);
        chmod($directory.'/bin/ssh', 0700);
        $process = new Process(['bash', $package.'/'.$file], env: [
            'PATH' => $directory.'/bin:'.getenv('PATH'), 'UPLOAD_CALLS' => $directory.'/calls', 'UPLOAD_MODE' => $mode]);
        $process->setTimeout(15)->run();
        expect($process->isSuccessful())->toBe($mode === 'success');
        $calls = file_get_contents($directory.'/calls');
        expect($calls)->toContain('ubuntu@46.105.110.76:/home/ubuntu/');
        if ($mode === 'scp-failure') {
            expect($calls)->not->toContain('ssh ');
        } else {
            expect($calls)->toContain('sudo bash', 'merge-techcalendar-internal-users.sh', '--apply', hash_file('sha256', $package.'/SHA256SUMS'));
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['success', 'scp-failure', 'ssh-failure']);
