<?php

use Symfony\Component\Process\Process;

it('generates self-contained readonly collection commands without bootstrapping a local application', function (array $arguments, string $planningOnly, string $requireProduction) {
    $process = new Process([PHP_BINARY, __DIR__.'/../../tools/fixtures/prepare-techcalendar-export.php', '/var/www/TechCalendar', ...$arguments]);
    $process->mustRun();
    $output = $process->getOutput();
    expect($output)->toContain('$planningOnly = '.$planningOnly.';', '$requireProduction = '.$requireProduction.';', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', '$pdo->rollBack();')
        ->not->toContain('Kernel::class', 'artisan migrate', 'DB_PASSWORD=');
    $syntax = new Process(['bash', '-n']);
    $syntax->setInput($output)->mustRun();
})->with([
    [[], 'false', 'false'],
    [['--planning-only'], 'true', 'true'],
    [['--production-settings'], 'false', 'true'],
]);
