<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarRosterActivation as Files;
use Cofrac2\Deploy\Mymar\MymarRosterRetirement as Retirement;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;

ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
umask(0077);
$run = null;
$changed = false;

function restoreRetirement(string $run): void
{
    if (is_file($run.'/summary.json')) {
        throw new RuntimeException('mymar.retirement_already_completed');
    }
    $before = State::read($run.'/before.private.json');
    $planned = State::read($run.'/planned.private.json');
    $current = Files::readFiles();
    foreach ($current as $name => $file) {
        if (! in_array($file['content'], [$before[$name]['content'], $planned[$name]], true)
            || array_intersect_key($file, array_flip(['uid', 'gid', 'mode'])) !== array_intersect_key($before[$name], array_flip(['uid', 'gid', 'mode']))) {
            throw new RuntimeException('mymar.retirement_recovery_configuration_changed');
        }
    }
    foreach (array_reverse(array_keys(Files::PATHS)) as $name) {
        if ($current[$name]['content'] !== $before[$name]['content']) {
            Files::replace($name, $current[$name]['sha256'], $before[$name]['content'], $before[$name]);
        }
    }
}

try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 3 || ! in_array($argv[1], ['--audit', '--apply', '--recover'], true)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-roster-retirement-[A-Za-z0-9]+$~D', $argv[2])
        || realpath($argv[2]) !== $argv[2] || fileowner($argv[2]) !== 0 || (fileperms($argv[2]) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_retirement_invocation');
    }
    $run = $argv[2];
    require __DIR__.'/MymarRosterActivation.php';
    require __DIR__.'/MymarRosterRetirement.php';
    require __DIR__.'/MymarRuntimeState.php';
    require '/var/www/cofrac2/vendor/autoload.php';
    if ($argv[1] === '--recover') {
        restoreRetirement($run);
        echo "Configuration precedente restauree. Aucun compte ni rendez-vous modifie.\n";
        exit;
    }
    $request = State::read(__DIR__.'/REQUEST.json');
    if (($request['format'] ?? null) !== 'cofrac.mymar-roster-retirement' || ($request['version'] ?? null) !== 1
        || ! is_array($request['runtime_contract'] ?? null) || count($request['runtime_contract']) < 5) {
        throw new RuntimeException('mymar.invalid_retirement_request');
    }
    $proof = [];
    foreach ($request['runtime_contract'] as $path => $sha) {
        if (! preg_match('~^app/Services/Mymar/[A-Za-z]+\.php$~D', $path) || ! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            throw new RuntimeException('mymar.invalid_retirement_runtime_contract');
        }
        $proof['/var/www/cofrac2/'.$path] = $sha;
    }
    State::assertProof($proof);
    $before = Files::readFiles();
    $configs = array_map(fn ($file) => json_decode($file['content'], true, 16, JSON_THROW_ON_ERROR), $before);
    $plan = Retirement::prepare($configs, $request);
    $audit = static function () use ($run, $plan, $request): void {
        $raw = State::admin(Retirement::sql($plan['remaining'], $request['cofrac_user_id']), $run);
        Retirement::validateTargets(array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), explode("\n", $raw)),
            $plan['remaining'], $request['cofrac_user_id']);
    };
    $audit();
    $report = ['format' => 'cofrac.mymar-roster-retirement-result', 'version' => 1,
        'source_technician_id' => $request['source_technician_id'], 'cofrac_user_id' => $request['cofrac_user_id'],
        'remaining_technicians' => count($plan['remaining']), 'already_applied' => $plan['already_applied'],
        'native_deleted_account_confirmed' => true, 'remaining_targets_active' => true, 'active_commands' => 0,
        'accounts_changed' => false, 'business_writes' => false, 'messages_sent' => false];
    State::write($run.'/audit.json', $report);
    if ($argv[1] === '--audit') {
        echo json_encode([...$report, 'dry_run' => true, 'configuration_changed' => false, 'http_requests' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }
    $contents = array_map(fn ($config) => json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", $plan['configs']);
    if (! $plan['already_applied']) {
        State::write($run.'/before.private.json', $before);
        State::write($run.'/planned.private.json', $contents);
        $changed = true;
        foreach (['occupations', 'reader', 'writer', 'pilot'] as $name) {
            State::assertProof($proof);
            Files::replace($name, $before[$name]['sha256'], $contents[$name], $before[$name]);
        }
    }
    $probe = static function (bool $refresh, array $client, string $name) use ($run, $plan): array {
        $expected = json_encode(['remaining' => $plan['remaining'], 'client' => $client, 'refresh' => $refresh], JSON_THROW_ON_ERROR);
        $process = proc_open(['/usr/bin/timeout', '--signal=TERM', '--kill-after=3', '45', '/usr/sbin/runuser', '-u', 'cofrac2-app', '--',
            '/usr/bin/env', '-i', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'HOME=/var/lib/cofrac2-app', 'USER=cofrac2-app', 'LOGNAME=cofrac2-app',
            '/usr/bin/php8.4', '-d', 'display_errors=0', '-d', 'zend.exception_ignore_args=1', '-d', 'memory_limit=256M', '--', $expected],
            [0 => ['file', __DIR__.'/retire-runtime-probe.php', 'r'], 1 => ['file', $run.'/'.$name.'.json', 'w'],
                2 => ['file', $run.'/'.$name.'.private.stderr', 'w']], $pipes);
        if (! is_resource($process) || proc_close($process) !== 0 || (State::read($run.'/'.$name.'.json')['ready'] ?? false) !== true) {
            throw new RuntimeException('mymar.retirement_cache_not_ready');
        }

        return State::read($run.'/'.$name.'.json');
    };
    $probe(true, $configs['client'], 'warm-cache');
    $audit();
    State::assertProof($proof);
    $current = Files::readFiles();
    foreach ($current as $name => $file) {
        $expected = $name === 'client' || $plan['already_applied'] ? $before[$name]['content'] : $contents[$name];
        if ($file['content'] !== $expected) {
            throw new RuntimeException('mymar.retirement_configuration_changed');
        }
    }
    if (! $plan['already_applied']) {
        Files::replace('client', $before['client']['sha256'], $contents['client'], $before['client']);
    }
    $cache = $probe(false, $plan['already_applied'] ? $configs['client'] : $plan['configs']['client'], 'final-cache');
    $summary = [...$report, 'complete' => true, 'cache_ready' => true, 'periods' => $cache['periods'],
        'generated_at' => $cache['generated_at'], 'http_get_only' => true, 'checked_at_utc' => gmdate(DATE_ATOM)];
    State::write($run.'/summary.json', $summary);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.retirement_failed';
    if ($changed && $run !== null) {
        try {
            restoreRetirement($run);
        } catch (Throwable) {
            $reason = 'mymar.retirement_recovery_required';
        }
    }
    fwrite(STDERR, 'STOP : '.$reason.'. Aucun compte ni rendez-vous modifie.'.PHP_EOL);
    exit(1);
}
