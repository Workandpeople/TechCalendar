<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('fetches fresh staff data privately and refuses SSH or checksum failures without any import', function (string $mode) {
    $directory = sys_get_temp_dir().'/techcalendar-user-fetch-'.bin2hex(random_bytes(6));
    foreach (['package', 'bin', 'source', 'Downloads'] as $child) {
        mkdir($directory.'/'.$child, 0700, true);
    }
    try {
        copy(__DIR__.'/../../tools/fixtures/fetch-techcalendar-internal-users.sh', $directory.'/package/fetch-techcalendar-internal-users.sh');
        file_put_contents($directory.'/package/collect-techcalendar-internal-users.sh', "# Fixture read-only collector\n");
        $checksums = '';
        foreach (['fetch-techcalendar-internal-users.sh', 'collect-techcalendar-internal-users.sh'] as $file) {
            $checksums .= hash_file('sha256', $directory.'/package/'.$file).'  '.$file."\n";
        }
        file_put_contents($directory.'/package/SHA256SUMS', $checksums);
        file_put_contents($directory.'/source/references.json.gz', gzencode('{"fixture":true}'));
        $digest = hash_file('sha256', $directory.'/source/references.json.gz');
        file_put_contents($directory.'/source/SHA256SUMS', $digest."  references.json.gz\n");
        file_put_contents($directory.'/bin/ssh', <<<'BASH'
#!/usr/bin/env bash
set -eu
cat >/dev/null
[ "$FETCH_FIXTURE_MODE" != ssh-failure ] || exit 19
printf 'EXPORT_DIR=/tmp/techcalendar-references-0123456789abcdef\nSHA256=%s\nusers : 3\n' "$FETCH_FIXTURE_SHA"
BASH);
        file_put_contents($directory.'/bin/scp', <<<'BASH'
#!/usr/bin/env bash
set -eu
destination="${@: -1}"
cp "$FETCH_FIXTURE_DIRECTORY/source/references.json.gz" "$FETCH_FIXTURE_DIRECTORY/source/SHA256SUMS" "$destination/"
if [ "$FETCH_FIXTURE_MODE" = bad-checksum ]; then printf 'corrupt' >> "$destination/references.json.gz"; fi
BASH);
        chmod($directory.'/bin/ssh', 0700);
        chmod($directory.'/bin/scp', 0700);
        $process = new Process(['bash', $directory.'/package/fetch-techcalendar-internal-users.sh'], env: [
            'HOME' => $directory, 'PATH' => $directory.'/bin:'.getenv('PATH'), 'FETCH_FIXTURE_DIRECTORY' => $directory,
            'FETCH_FIXTURE_MODE' => $mode, 'FETCH_FIXTURE_SHA' => $digest,
        ]);
        $process->setTimeout(15)->run();
        expect($process->isSuccessful())->toBe($mode === 'success');
        if ($mode === 'success') {
            expect(preg_match('/^LOCAL_DIR=(.+)$/m', $process->getOutput(), $matches))->toBe(1);
            $destination = $matches[1];
            expect(hash_file('sha256', $destination.'/references.json.gz'))->toBe($digest)
                ->and(fileperms($destination) & 0777)->toBe(0700)
                ->and(fileperms($destination.'/references.json.gz') & 0777)->toBe(0600);
        } else {
            expect($process->getOutput())->not->toContain('LOCAL_DIR=');
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['success', 'ssh-failure', 'bad-checksum']);
