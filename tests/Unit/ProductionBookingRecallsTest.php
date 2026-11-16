<?php

use Symfony\Component\Process\Process;

it('installs a dedicated bounded local-only recall timer without enabling the general scheduler', function () {
    $root = __DIR__.'/../../tools/deploy/';
    $service = file_get_contents($root.'cofrac2-booking-recalls.service.example');
    $timer = file_get_contents($root.'cofrac2-booking-recalls.timer.example');
    $activation = file_get_contents($root.'enable-production-booking-recalls.sh');

    expect($service)->toContain('User=cofrac2-app', 'dossiers:process-recalls --apply --limit=200',
        'ConditionPathExists=/var/lib/cofrac2-production/INTERNAL-SITE-OPEN.json',
        'IPAddressDeny=any', 'IPAddressAllow=127.0.0.1/32', 'ProtectSystem=strict')
        ->not->toContain('schedule:run', 'mymar:');
    expect($timer)->toContain('OnUnitInactiveSec=60s', 'Unit=cofrac2-booking-recalls.service');
    expect($activation)->toContain('cofrac2production', 'booking_recall_activity_id', 'RUNTIME-PREPARED.json',
        'git_app status --porcelain', 'dossiers:process-recalls --limit=1', 'systemd-analyze verify',
        'systemctl disable --now cofrac2-booking-recalls.timer')
        ->not->toContain('artisan schedule:run', 'artisan migrate', 'crontab', 'artisan dossiers:process-recalls --backfill --apply');
});

it('permits the recall migration in every application release preflight and scoped migration step', function (string $path) {
    expect(file_get_contents(__DIR__.'/../../tools/deploy/'.$path))
        ->toContain('2026_11_16_120000_add_booking_recalls_to_dossier_operations');
})->with(['update-production-release.php', 'update-production-release.sh', 'templates/production-release-launcher.sh.in']);

it('keeps activation and release scripts syntactically valid without executing them', function (string $path) {
    $process = new Process(['/bin/bash', '-n', __DIR__.'/../../tools/deploy/'.$path]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
})->with(['enable-production-booking-recalls.sh', 'update-production-release.sh',
    'templates/production-release-launcher.sh.in', 'tests/check-production-release.sh']);
