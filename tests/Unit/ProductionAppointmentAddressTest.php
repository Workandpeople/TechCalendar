<?php

it('permits the long appointment address migration in every deployment preflight and targeted migration step', function (string $path) {
    expect(file_get_contents(__DIR__.'/../../tools/deploy/'.$path))
        ->toContain('2026_11_18_090000_expand_appointment_address_length');
})->with(['update-production-release.php', 'update-production-release.sh', 'templates/production-release-launcher.sh.in']);
