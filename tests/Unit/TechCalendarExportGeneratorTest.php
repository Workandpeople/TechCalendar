<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('generates self-contained readonly collection commands without bootstrapping a local application', function (array $arguments, string $planningOnly, string $requireProduction) {
    $process = new Process([PHP_BINARY, __DIR__.'/../../tools/fixtures/prepare-techcalendar-export.php', '/var/www/TechCalendar', ...$arguments]);
    $process->mustRun();
    $output = $process->getOutput();
    expect($output)->toContain('$planningOnly = '.$planningOnly.';', '$requireProduction = '.$requireProduction.';', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', '$pdo->rollBack();')
        ->toContain("\nphp -d display_errors=0 <<")
        ->not->toContain('Kernel::class', 'artisan migrate', 'DB_PASSWORD=', "\nphp8.4 -d");
    $syntax = new Process(['bash', '-n']);
    $syntax->setInput($output)->mustRun();
})->with([
    [[], 'false', 'false'],
    [['--planning-only'], 'true', 'true'],
    [['--production-settings'], 'false', 'true'],
    [['--internal-users'], 'false', 'true'],
]);

it('allows a separate staff-only CT demo export without weakening other source guards', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../../tools/fixtures/prepare-techcalendar-export.php', '/var/www/TechCalendar-v2', '--ct-demo-internal-users']);
    $process->mustRun();
    $output = $process->getOutput();
    expect($output)->toContain('$ctDemoInternalUsers = true;', '$internalUsersOnly = true;', '$requireProduction = false;',
        "!== '/var/www/TechCalendar-v2'", "!== 'techcalendar'", "!== 'demo'", "!== 'techcalendar_v2_demo_simulation'",
        'SOURCE_PROJECT=', 'LAST_UPDATED_', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
        'command -v php8.4', "\nphp8.4 -d display_errors=0 <<", 'PHP_VERSION_ID < 80401')
        ->not->toContain("\nphp -d display_errors=0 <<", 'update-alternatives', 'systemctl');
    expect(strpos($output, 'PHP_VERSION_ID < 80401'))->toBeLessThan(strpos($output, "require getcwd().'/vendor/autoload.php'"));
    (new Process(['bash', '-n']))->setInput($output)->mustRun();
});

it('runs CT demo collection with explicit PHP 8.4 and never falls back to the default CLI', function (bool $installed) {
    $directory = sys_get_temp_dir().'/techcalendar-php-selection-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    mkdir($directory.'/bin', 0700);
    try {
        file_put_contents($directory.'/bin/php', "#!/bin/bash\necho WRONG_DEFAULT_PHP >&2\nexit 99\n");
        chmod($directory.'/bin/php', 0700);
        if ($installed) {
            file_put_contents($directory.'/bin/php8.4', "#!/bin/bash\n/bin/cat >/dev/null\nprintf 'PHP84_SELECTED\\n'\n");
            chmod($directory.'/bin/php8.4', 0700);
        }
        $generator = new Process([PHP_BINARY, __DIR__.'/../../tools/fixtures/prepare-techcalendar-export.php', '/var/www/TechCalendar-v2', '--ct-demo-internal-users']);
        $generator->mustRun();
        $script = str_replace("cd '/var/www/TechCalendar-v2'", 'cd '.escapeshellarg($directory), $generator->getOutput());
        $run = new Process(['/bin/bash'], env: ['PATH' => $directory.'/bin']);
        $run->setInput($script)->run();
        expect($run->isSuccessful())->toBe($installed)
            ->and($run->getOutput().$run->getErrorOutput())->not->toContain('WRONG_DEFAULT_PHP');
        if ($installed) {
            expect($run->getOutput())->toContain('PHP84_SELECTED');
        } else {
            expect($run->getErrorOutput())->toContain('executable php8.4 absent du CT.');
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([true, false]);

it('refuses the CT demo collection mode for any other project', function (string $project) {
    $process = new Process([PHP_BINARY, __DIR__.'/../../tools/fixtures/prepare-techcalendar-export.php', $project, '--ct-demo-internal-users']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()->and($process->getOutput())->toBe('');
})->with(['/var/www/TechCalendar', '/var/www/cofrac2', '/tmp/TechCalendar-v2']);
