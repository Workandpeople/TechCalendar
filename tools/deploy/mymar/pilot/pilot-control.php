<?php

declare(strict_types=1);

use App\Services\Mymar\MymarPilotConfiguration;
use Cofrac2\Deploy\Mymar\MymarGatewayPreparation;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;

umask(0077);
ini_set('display_errors', '0');

function pilotConfigWrite(array $value): void
{
    $path = '/etc/cofrac2-mymar/pilot.json';
    $temporary = $path.'.new-'.bin2hex(random_bytes(6));
    $content = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $handle = fopen($temporary, 'x');
    if ($handle === false || fwrite($handle, $content) !== strlen($content) || ! fclose($handle)
        || ! chgrp($temporary, 'cofrac2-app') || ! chmod($temporary, 0640) || ! rename($temporary, $path)) {
        throw new RuntimeException('mymar.pilot_configuration_write_failed');
    }
}

$phase = $argv[1] ?? '';
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3
        || ! in_array($phase, ['--prepare', '--stage', '--display', '--disable', '--report'], true)) {
        throw new RuntimeException('mymar.invalid_pilot_invocation');
    }
    $run = $argv[2];
    if (realpath($run) !== $run || ! preg_match('~^/var/lib/cofrac2-production/mymar-cache-pilot-[A-Za-z0-9]+$~D', $run)
        || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_pilot_directory');
    }
    foreach (['MymarRuntimeState', 'MymarApprovedTechnicianMap', 'MymarGatewayPreparation', 'MymarPilotConfiguration'] as $class) {
        require __DIR__.'/'.$class.'.php';
    }
    if ($phase === '--disable') {
        $owner = State::read($run.'/owner.private.json');
        $current = State::read('/etc/cofrac2-mymar/pilot.json');
        if (($current['generation'] ?? null) !== ($owner['generation'] ?? null)) {
            throw new RuntimeException('mymar.unexpected_pilot_owner');
        }
        $current['sync_enabled'] = false;
        $current['display_enabled'] = false;
        $current['blocking_enabled'] = false;
        pilotConfigWrite($current);
        echo "Pilote cache/affichage desactive. API privee et RDV existants inchanges.\n";
        exit;
    }
    $request = State::read(__DIR__.'/REQUEST.json');
    $apiRun = $request['api_runtime_directory'] ?? '';
    $mapPath = $request['mapping_path'] ?? '';
    $mapSha = $request['mapping_sha256'] ?? '';
    $target = $request['target_revision'] ?? '';
    if (($request['format'] ?? null) !== 'cofrac.mymar-cache-pilot-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-http-runtime-[A-Za-z0-9]+$~D', $apiRun)
        || realpath($apiRun) !== $apiRun || fileowner($apiRun) !== 0 || (fileperms($apiRun) & 0777) !== 0700
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $mapPath)
        || ! preg_match('/^[a-f0-9]{64}$/D', $mapSha) || ! preg_match('/^[a-f0-9]{40}$/D', $target)
        || ! hash_equals($mapSha, hash_file('sha256', $mapPath))
        || (State::read(State::STATE.'/RUNTIME-PREPARED.json')['revision'] ?? null) !== $target
        || ! is_file(State::STATE.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('mymar.pilot_release_or_mapping_not_pinned');
    }
    $marker = State::read(State::STATE.'/MYMAR-HTTP-RUNTIME.json');
    if (($marker['run_directory'] ?? null) !== $apiRun || ($marker['mapping_sha256'] ?? null) !== $mapSha
        || ($marker['crm_connector_enabled'] ?? null) !== false || ($marker['can_affect_planning'] ?? null) !== false) {
        throw new RuntimeException('mymar.private_api_runtime_not_pinned');
    }
    State::assertProof($marker['files']);
    if ($phase === '--prepare') {
        if (file_exists('/etc/cofrac2-mymar') || is_link('/etc/cofrac2-mymar')
            || ! is_array(posix_getpwnam('cofrac2-app')) || ! is_array(posix_getgrnam('cofrac2-app'))) {
            throw new RuntimeException('mymar.pilot_already_present_or_account_missing');
        }
        $map = State::read($mapPath);
        if (($map['connector_enabled'] ?? null) !== false || ($map['can_affect_planning'] ?? null) !== false) {
            throw new RuntimeException('mymar.unexpected_mapping_activation');
        }
        $fresh = State::freshMap($mapPath, $map, $run);
        $validation = State::read($apiRun.'/validation.private.json');
        if (array_keys($validation['code_files'] ?? []) !== MymarGatewayPreparation::FILES) {
            throw new RuntimeException('mymar.private_api_code_not_pinned');
        }
        $proof = [];
        foreach ($validation['code_files'] as $relative => $sha) {
            $proof['/var/lib/cofrac-mymar-gateway/code/'.$relative] = $sha;
        }
        $proof['/var/lib/cofrac-mymar-gateway/code/tools/deploy/mymar/runtime/entry.php'] = hash_file('sha256', $apiRun.'/bundle/entry.php');
        $sourcePath = '/var/lib/cofrac-mymar-gateway/private/config.json';
        $scopePath = '/var/lib/cofrac-mymar-gateway/private/scope.json';
        if (realpath($sourcePath) !== $sourcePath || (fileperms($sourcePath) & 0777) !== 0600
            || (fileperms(dirname($sourcePath)) & 0777) !== 0700 || fileowner($sourcePath) !== (posix_getpwnam('cofrac-mymar')['uid'] ?? -1)) {
            throw new RuntimeException('mymar.unsafe_source_api_configuration');
        }
        $source = State::read($sourcePath, 8192);
        if (($source['enabled'] ?? null) !== true || ($source['source_timezone'] ?? null) !== 'Europe/Paris'
            || ($source['duration_unit'] ?? null) !== 'hours' || ! is_string($source['token'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $source['token']) || count(array_unique(str_split($source['token']))) < 8
            || (State::read($scopePath)['technician_ids'] ?? null) !== array_column($fresh['bindings'], 'source_technician_id')) {
            throw new RuntimeException('mymar.unexpected_api_policy_or_scope');
        }
        foreach ([$sourcePath, $scopePath, '/var/www/cofrac2/.env', '/var/www/cofrac2/bootstrap/cache/config.php'] as $path) {
            if (realpath($path) !== $path || ! is_file($path)) {
                throw new RuntimeException('mymar.indirect_pilot_proof');
            }
            $proof[$path] = hash_file('sha256', $path);
        }
        State::assertProof($proof);
        $pilot = ['format' => 'cofrac.mymar-observation-pilot', 'version' => 1, 'sync_enabled' => true, 'display_enabled' => false,
            'blocking_enabled' => false, 'generation' => bin2hex(random_bytes(16)),
            'bindings' => array_map(static fn ($binding) => array_intersect_key($binding, ['source_technician_id' => true, 'cofrac_user_id' => true]), $fresh['bindings'])];
        MymarPilotConfiguration::fromArray($pilot);
        State::write($run.'/owner.private.json', ['generation' => $pilot['generation']]);
        State::write($run.'/prepared.private.json', ['pilot' => $pilot, 'proof' => $proof, 'api_token' => $source['token']]);
        echo "Source API et correspondances reverifiees. Aucun identifiant SQL transmis au CRM.\n";
        exit;
    }
    $prepared = State::read($run.'/prepared.private.json');
    State::assertProof($prepared['proof']);
    if ($phase === '--stage') {
        if (file_exists('/etc/cofrac2-mymar') || ! mkdir('/etc/cofrac2-mymar', 0750)
            || ! chgrp('/etc/cofrac2-mymar', 'cofrac2-app') || ! chmod('/etc/cofrac2-mymar', 0750)) {
            throw new RuntimeException('mymar.pilot_directory_creation_failed');
        }
        pilotConfigWrite($prepared['pilot']);
        if (file_put_contents('/etc/cofrac2-mymar/api-token', $prepared['api_token']."\n", LOCK_EX) === false
            || ! chgrp('/etc/cofrac2-mymar/api-token', 'cofrac2-app') || ! chmod('/etc/cofrac2-mymar/api-token', 0640)) {
            throw new RuntimeException('mymar.pilot_token_write_failed');
        }
        echo "Cache prepare, affichage toujours masque avant la premiere synchronisation valide.\n";
        exit;
    }
    $status = State::read($run.'/cache-status.json');
    if (($status['cache_ready'] ?? null) !== true || ($status['blocking_enabled'] ?? null) !== false
        || ($status['pilot_technicians'] ?? null) !== count($prepared['pilot']['bindings'])) {
        throw new RuntimeException('mymar.pilot_cache_not_ready');
    }
    if ($phase === '--display') {
        $pilot = $prepared['pilot'];
        $pilot['display_enabled'] = true;
        pilotConfigWrite($pilot);
        echo "Affichage pilote active, aucune regle de placement modifiee.\n";
        exit;
    }
    $summary = ['format' => 'cofrac.mymar-cache-pilot', 'version' => 1, 'revision' => $target,
        'cache_ready' => true, 'cached_periods' => $status['periods'], 'pilot_technicians' => $status['pilot_technicians'],
        'generated_at' => $status['generated_at'], 'window_start' => $status['window_start'], 'window_end' => $status['window_end'],
        'refresh_interval_seconds' => 60, 'display_enabled' => true, 'blocking_enabled' => false,
        'http_requests_from_calendar_pages' => false, 'appointments_created_or_modified' => false,
        'source_database_writes' => false, 'passwords_or_rights_changed' => false,
        'warnings' => $status['warnings'], 'private_run_directory' => $run, 'stop_command' => 'sudo bash '.$run.'/bundle/stop-pilot.sh'];
    State::write($run.'/summary.json', $summary);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $exception->getMessage()) ? $exception->getMessage() : 'mymar.pilot_setup_failed';
    fwrite(STDERR, "STOP : $reason ; phase=$phase. Aucun blocage de disponibilite active.\n");
    exit(1);
}
