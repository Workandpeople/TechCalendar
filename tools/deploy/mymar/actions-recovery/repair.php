<?php

declare(strict_types=1);

use App\Services\Mymar\MymarActionSettings;
use Cofrac2\Deploy\Mymar\MymarActionsConfiguration;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;
use Cofrac2\Deploy\MymarWorkflowAudit;

umask(0077);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');

try {
    $recovery = $argv[1] ?? '';
    $state = '/var/lib/cofrac2-production';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 2 || ! preg_match('~^/var/lib/cofrac2-production/mymar-actions-recovery-[A-Za-z0-9]+$~D', $recovery)
        || realpath($recovery) !== $recovery || fileowner($recovery) !== 0 || (fileperms($recovery) & 0777) !== 0700
        || realpath($state) !== $state || fileowner($state) !== 0 || (fileperms($state) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_recovery_invocation');
    }
    $request = json_decode(file_get_contents(__DIR__.'/REQUEST.json'), true, 16, JSON_THROW_ON_ERROR);
    $run = $request['original_run'] ?? '';
    if (($request['format'] ?? null) !== 'cofrac.mymar-actions-recovery-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $request['runtime_revision'] ?? '')
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-actions-[A-Za-z0-9]+$~D', $run)
        || realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700
        || ! is_array($request['original_bundle'] ?? null) || ! is_int($request['expected_technicians'] ?? null)) {
        throw new RuntimeException('mymar.invalid_recovery_request');
    }
    foreach ($request['original_bundle'] as $name => $hash) {
        $path = $run.'/bundle/'.$name;
        if (! preg_match('/^[A-Za-z][A-Za-z0-9-]*\.(php|json)$/D', $name) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
            || realpath($path) !== $path || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0600 || ! hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('mymar.recovery_original_bundle_changed');
        }
    }
    foreach (['control.php', 'REQUEST.json', 'entry.php', 'MymarBookingCommand.php', 'MymarObservation.php', 'MymarObservationPolicy.php',
        'MymarBookingFailure.php', 'MymarBookingSqlAccess.php', 'MymarBookingSource.php', 'MymarBookingGateway.php'] as $name) {
        if (! isset($request['original_bundle'][$name])) {
            throw new RuntimeException('mymar.recovery_bundle_incomplete');
        }
        if ($name !== 'control.php' && $name !== 'REQUEST.json') {
            $installed = '/var/lib/cofrac-mymar-actions-gateway/code/'.$name;
            if (realpath($installed) !== $installed || fileowner($installed) !== 0 || (fileperms($installed) & 0777) !== 0644
                || ! hash_equals($request['original_bundle'][$name], hash_file('sha256', $installed))) {
                throw new RuntimeException('mymar.recovery_gateway_code_changed');
            }
        }
    }
    foreach (['MymarRuntimeState', 'MymarReadOnlyAudit', 'MymarWorkflowAudit', 'MymarApprovedTechnicianMap', 'MymarBookingSqlAccess'] as $class) {
        if (! isset($request['original_bundle'][$class.'.php'])) {
            throw new RuntimeException('mymar.recovery_bundle_incomplete');
        }
        require $run.'/bundle/'.$class.'.php';
    }
    require __DIR__.'/MymarActionsConfiguration.php';
    require '/var/www/cofrac2/vendor/autoload.php';
    $read = static function (string $path, int $mode = 0600): array {
        if (realpath($path) !== $path || ! is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0777) !== $mode) {
            throw new RuntimeException('mymar.recovery_unsafe_file');
        }

        return State::read($path);
    };
    if (($read($state.'/RUNTIME-PREPARED.json')['revision'] ?? null) !== $request['runtime_revision']
        || ! is_file($state.'/INTERNAL-SITE-OPEN.json') || file_exists($state.'/MYMAR-ACTIONS.json') || is_link($state.'/MYMAR-ACTIONS.json')
        || file_exists($run.'/summary.json')) {
        throw new RuntimeException('mymar.recovery_not_a_failed_activation');
    }
    $originalRequest = $read($run.'/bundle/REQUEST.json');
    $proof = $read($run.'/validation.private.json');
    $preparedRun = $read($state.'/MYMAR-BOOKING-PREPARED.json')['run_directory'] ?? '';
    if (($originalRequest['revision'] ?? null) !== $request['runtime_revision']
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-preparation-[A-Za-z0-9]+$~D', $preparedRun)
        || ! hash_equals($proof['request_sha256'], hash_file('sha256', $run.'/bundle/REQUEST.json'))
        || ! hash_equals($proof['prepared_sha256'], hash_file('sha256', $preparedRun.'/config.private.json'))) {
        throw new RuntimeException('mymar.recovery_preparation_changed');
    }
    $prepared = $read($preparedRun.'/config.private.json');
    $input = $read($preparedRun.'/bundle/REQUEST.json');
    $mapPath = $input['mapping_path'] ?? '';
    if (! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $mapPath)
        || ! hash_equals($input['mapping_sha256'], hash_file('sha256', $mapPath))) {
        throw new RuntimeException('mymar.recovery_mapping_changed');
    }
    $fresh = State::freshMap($mapPath, $read($mapPath), $recovery);
    $bindings = MymarActionsConfiguration::bindings($prepared['bindings']);
    if ($fresh['bindings'] !== $prepared['bindings'] || count($bindings) !== $request['expected_technicians']
        || array_map(static fn ($file) => $file['sha256'], MymarWorkflowAudit::sources('/home/ubuntu/www/MYMAR')['files']) !== $input['workflow_files']) {
        throw new RuntimeException('mymar.recovery_source_or_identity_changed');
    }
    $source = $read('/var/lib/cofrac-mymar-actions-gateway/private/config.json', 0640);
    $owned = $read($run.'/ownership.private.json');
    $path = MymarActionSettings::DIRECTORY.'/actions.json';
    $client = $read($path, 0640);
    $writer = $owned['database_user'] ?? '';
    if (! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $writer) || $writer !== $prepared['database_user'] || $writer !== $source['database_user']
        || ($source['format'] ?? null) !== 'cofrac.mymar-actions-gateway' || ($source['version'] ?? null) !== 1
        || ($source['read_enabled'] ?? null) !== true || ($source['writes_enabled'] ?? null) !== false || ($prepared['writes_enabled'] ?? null) !== false
        || ($source['technical_author_id'] ?? null) !== $prepared['technical_author_id'] || ($source['technician_ids'] ?? null) !== $prepared['technician_ids']
        || ! hash_equals($prepared['database_password'], $source['database_password'] ?? '')
        || ($client['writes_enabled'] ?? null) !== false || ($client['guard_enabled'] ?? null) !== true
        || ($client['generation'] ?? null) !== ($owned['generation'] ?? null)
        || ! in_array($client['bindings'] ?? null, [$prepared['bindings'], $bindings], true)
        || ! hash_equals($source['token'], trim(file_get_contents(MymarActionSettings::DIRECTORY.'/api-token')))) {
        throw new RuntimeException('mymar.recovery_closed_state_changed');
    }
    MymarBookingSqlAccess::validateGrants(explode("\n", State::admin("SHOW GRANTS FOR '$writer'@'localhost';", $recovery)));
    if (State::admin("SELECT account_locked FROM mysql.user WHERE User='$writer' AND Host='localhost';", $recovery) !== 'Y'
        || State::admin('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands;', $recovery) !== '0'
        || State::admin('SELECT COUNT(*) FROM cofrac2production.mymar_booking_requests;', $recovery) !== '0') {
        throw new RuntimeException('mymar.recovery_writer_or_journal_changed');
    }
    $ids = implode(',', array_column($bindings, 'cofrac_user_id'));
    $author = $prepared['technical_author_id'];
    if (! is_int($author) || $author < 1
        || State::admin("SELECT COUNT(*) FROM cofrac2production.users WHERE id IN ($ids) AND role=2 AND admin=0 AND external_client_id IS NULL AND deleted_at IS NULL;", $recovery) !== (string) count($bindings)
        || State::admin("SELECT COUNT(*) FROM MYMAR.users WHERE id=$author AND enabled=0;", $recovery) !== '1'
        || State::admin("SELECT COUNT(*) FROM MYMAR.model_has_roles WHERE model_id=$author AND model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User'));", $recovery) !== '0'
        || State::admin("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA='MYMAR' AND EVENT_OBJECT_TABLE IN ('dossiers','dossier_rdv','dossier_historiques');", $recovery) !== '0') {
        throw new RuntimeException('mymar.recovery_account_or_schema_changed');
    }
    $client['bindings'] = $bindings;
    MymarActionSettings::validate($client);
    State::write($recovery.'/ownership.json', ['original_run' => $run, 'runtime_revision' => $request['runtime_revision']]);
    foreach (['actions.json' => $path, 'application-probe.json' => $run.'/application-probe.json', 'http-checks.json' => $run.'/http-checks.json'] as $name => $backup) {
        if (is_file($backup) && (! copy($backup, $recovery.'/before-'.$name) || ! chmod($recovery.'/before-'.$name, 0600))) {
            throw new RuntimeException('mymar.recovery_backup_failed');
        }
    }
    $group = posix_getgrnam('cofrac2-app')['gid'] ?? null;
    if (! is_int($group) || filegroup(MymarActionSettings::DIRECTORY) !== $group || filegroup($path) !== $group) {
        throw new RuntimeException('mymar.recovery_runtime_group_changed');
    }
    $temporary = $path.'.recovery-'.bin2hex(random_bytes(6));
    State::write($temporary, $client);
    if (! chgrp($temporary, $group) || ! chmod($temporary, 0640) || ! rename($temporary, $path)) {
        throw new RuntimeException('mymar.recovery_write_failed');
    }
    clearstatcache();
    if ((new MymarActionSettings)->load() !== $client) {
        throw new RuntimeException('mymar.recovery_runtime_validation_failed');
    }
    State::write($recovery.'/repair.json', ['format' => 'cofrac.mymar-actions-repair', 'version' => 1,
        'runtime_revision' => $request['runtime_revision'], 'bindings' => count($bindings), 'writes_enabled' => false,
        'business_writes' => false, 'account_privileges_changed' => false, 'source_code_changed' => false]);
    echo "Configuration du client reparee ; memes identifiants approuves, preuves privees conservees, ecritures toujours fermees.\n";
} catch (Throwable $error) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.recovery_failed';
    fwrite(STDERR, 'STOP : '.$reason.'. Aucun placement de test.'.PHP_EOL);
    exit(1);
}
