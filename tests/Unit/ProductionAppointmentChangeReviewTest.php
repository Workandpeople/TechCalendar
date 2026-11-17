<?php

use Symfony\Component\Process\Process;

it('permits the appointment review migration in every preflight and targeted deployment step', function (string $path) {
    expect(file_get_contents(__DIR__.'/../../tools/deploy/'.$path))
        ->toContain('2026_11_17_230000_add_global_plus_change_review_to_appointments');
})->with(['update-production-release.php', 'update-production-release.sh', 'templates/production-release-launcher.sh.in']);

it('keeps the application deployment scripts valid without executing any production operation', function (string $path) {
    $process = new Process(['/bin/bash', '-n', __DIR__.'/../../tools/deploy/'.$path]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
})->with(['update-production-release.sh', 'templates/production-release-launcher.sh.in']);
