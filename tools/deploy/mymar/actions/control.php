<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarActionsConfiguration as Configuration;
use Cofrac2\Deploy\Mymar\MymarActionsHttpPlan as Plan;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess as Sql;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;
use Cofrac2\Deploy\MymarWorkflowAudit;

umask(0077);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
foreach (['MymarRuntimeState', 'MymarRuntimePlan', 'MymarActionsHttpPlan', 'MymarActionsConfiguration', 'MymarBookingSqlAccess'] as $class) {
    require __DIR__.'/'.$class.'.php';
}

function actionsPrivate(string $path, int $mode = 0600): array
{
    if (realpath($path) !== $path || ! is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0777) !== $mode) {
        throw new RuntimeException('mymar.unsafe_actions_input');
    }

    return State::read($path);
}
function actionsGroupWrite(string $path, array $value, int $group): void
{
    $tmp = $path.'.new-'.bin2hex(random_bytes(6));
    State::write($tmp, $value);
    if (! chgrp($tmp, $group) || ! chmod($tmp, 0640) || ! rename($tmp, $path)) {
        throw new RuntimeException('mymar.actions_write_failed');
    }
}
try {
    $phase = $argv[1] ?? '';
    $run = $argv[2] ?? '';
    $appConfig = '/etc/cofrac2-mymar-actions';
    $sourceConfig = Plan::ROOT.'/private/config.json';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3
        || ! in_array($phase, ['--check', '--stage', '--unlock', '--app', '--probe', '--enable', '--disable', '--lock'], true)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-actions-[A-Za-z0-9]+$~D', $run)
        || realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700
        || realpath(State::STATE) !== State::STATE || fileowner(State::STATE) !== 0 || (fileperms(State::STATE) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_actions_invocation');
    }
    if (in_array($phase, ['--disable', '--lock'], true)) {
        // Cleanup deliberately ignores the old release/mapping/source proofs.
        $owned = actionsPrivate($run.'/ownership.private.json');
        if (is_file($appConfig.'/actions.json')) {
            $app = actionsPrivate($appConfig.'/actions.json', 0640);
            if (($app['generation'] ?? '') !== $owned['generation']) {
                throw new RuntimeException('mymar.actions_disable_identity_refused');
            }
            $app['writes_enabled'] = false; // Keep reservations and live availability checks active.
            actionsGroupWrite($appConfig.'/actions.json', $app, filegroup($appConfig.'/actions.json'));
        }
        if (is_file($sourceConfig)) {
            $source = actionsPrivate($sourceConfig, 0640);
            if (($source['database_user'] ?? '') !== $owned['database_user']) {
                throw new RuntimeException('mymar.actions_disable_identity_refused');
            }
            $source['writes_enabled'] = false;
            actionsGroupWrite($sourceConfig, $source, filegroup($sourceConfig));
        }
        $markerPath = State::STATE.'/MYMAR-ACTIONS.json';
        if (is_file($markerPath)) {
            $marker = actionsPrivate($markerPath);
            if (($marker['run_directory'] ?? null) !== $run) {
                throw new RuntimeException('mymar.actions_disable_identity_refused');
            }
            State::write($markerPath, array_replace($marker, ['stage' => 'pilot_actions_disabled', 'writes_enabled' => false]), replace: true);
        }
        if ($phase === '--lock') {
            $user = $owned['database_user'];
            if (! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $user)) {
                throw new RuntimeException('mymar.actions_invalid_writer');
            }
            State::admin("ALTER USER '$user'@'localhost' ACCOUNT LOCK;", $run);
            if (State::admin("SELECT account_locked FROM mysql.user WHERE User='$user' AND Host='localhost';", $run) !== 'Y') {
                throw new RuntimeException('mymar.actions_lock_failed');
            }
        }
        exit;
    }
    $request = actionsPrivate(__DIR__.'/REQUEST.json');
    $uiMarker = actionsPrivate(State::STATE.'/MYMAR-BOOKING-UI.json');
    $uiDirectory = $uiMarker['run_directory'] ?? '';
    if (($request['format'] ?? null) !== 'cofrac.mymar-actions-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $request['revision'] ?? '') || ! preg_match('/^[a-f0-9]{40}$/D', $request['base_revision'] ?? '')
        || ($uiMarker['stage'] ?? null) !== 'reader_only'
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-ui-[A-Za-z0-9]+$~D', $uiDirectory)
        || ! preg_match('/^[a-f0-9]{64}$/D', $request['ui_summary_sha256'] ?? '')
        || ! hash_equals($request['ui_summary_sha256'], hash_file('sha256', $uiDirectory.'/summary.json'))) {
        throw new RuntimeException('mymar.actions_unpinned_request');
    }
    $revision = actionsPrivate(State::STATE.'/RUNTIME-PREPARED.json')['revision'] ?? '';
    if (! in_array($revision, $phase === '--check' || $phase === '--stage' ? [$request['base_revision']] : [$request['revision']], true)
        || ! is_file(State::STATE.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('mymar.actions_runtime_refused');
    }
    $preparedMarker = actionsPrivate(State::STATE.'/MYMAR-BOOKING-PREPARED.json');
    $preparedRun = $preparedMarker['run_directory'] ?? '';
    if (! preg_match('~^/var/lib/cofrac2-production/mymar-booking-preparation-[A-Za-z0-9]+$~D', $preparedRun)) {
        throw new RuntimeException('mymar.actions_preparation_refused');
    }
    $prepared = actionsPrivate($preparedRun.'/config.private.json');
    $user = $prepared['database_user'] ?? '';
    if (! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $user) || ($prepared['writes_enabled'] ?? null) !== false) {
        throw new RuntimeException('mymar.actions_invalid_writer');
    }
    Sql::validateGrants(explode("\n", State::admin("SHOW GRANTS FOR '$user'@'localhost';", $run)));
    if ($phase === '--check') {
        foreach ([Plan::ROOT, Plan::CONFIG, $appConfig, State::STATE.'/MYMAR-ACTIONS.json', '/var/log/cofrac-mymar-actions-gateway', '/run/cofrac-mymar-actions-fpm', '/run/cofrac-mymar-actions-http'] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('mymar.actions_already_present');
            }
        }
        if (State::admin("SELECT account_locked FROM mysql.user WHERE User='$user' AND Host='localhost';", $run) !== 'Y'
            || State::admin('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands;', $run) !== '0'
            || State::admin("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cofrac2production' AND TABLE_NAME='mymar_booking_requests';", $run) !== '0') {
            throw new RuntimeException('mymar.actions_initial_state_changed');
        }
        if (State::admin("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA='MYMAR' AND EVENT_OBJECT_TABLE IN ('dossiers','dossier_rdv','dossier_historiques');", $run) !== '0') {
            throw new RuntimeException('mymar.actions_source_trigger_refused');
        }
        foreach (['MymarReadOnlyAudit', 'MymarWorkflowAudit', 'MymarApprovedTechnicianMap'] as $class) {
            require __DIR__.'/'.$class.'.php';
        }
        $original = actionsPrivate($preparedRun.'/bundle/REQUEST.json');
        $map = actionsPrivate($original['mapping_path']);
        if (! hash_equals($original['mapping_sha256'], hash_file('sha256', $original['mapping_path']))) {
            throw new RuntimeException('mymar.actions_mapping_changed');
        }
        $fresh = State::freshMap($original['mapping_path'], $map, $run);
        if ($fresh['bindings'] !== $prepared['bindings']
            || array_map(fn ($file) => $file['sha256'], MymarWorkflowAudit::sources('/home/ubuntu/www/MYMAR')['files']) !== $original['workflow_files']) {
            throw new RuntimeException('mymar.actions_source_changed');
        }
        $author = $prepared['technical_author_id'];
        if (! is_int($author) || $author < 1 || State::admin("SELECT COUNT(*) FROM MYMAR.users WHERE id=$author AND enabled=0;", $run) !== '1') {
            throw new RuntimeException('mymar.actions_author_changed');
        }
        if (State::admin("SELECT COUNT(*) FROM MYMAR.model_has_roles WHERE model_id=$author AND model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User'));", $run) !== '0') {
            throw new RuntimeException('mymar.actions_author_changed');
        }
        State::write($run.'/validation.private.json', ['request_sha256' => hash_file('sha256', __DIR__.'/REQUEST.json'), 'prepared_sha256' => hash_file('sha256', $preparedRun.'/config.private.json')]);
        echo "Etat initial, source, correspondances et compte technique verifies ; aucune activation.\n";
        exit;
    }
    $proof = actionsPrivate($run.'/validation.private.json');
    if (! hash_equals($proof['request_sha256'], hash_file('sha256', __DIR__.'/REQUEST.json'))
        || ! hash_equals($proof['prepared_sha256'], hash_file('sha256', $preparedRun.'/config.private.json'))) {
        throw new RuntimeException('mymar.actions_preparation_changed');
    }
    if ($phase === '--stage') {
        $account = posix_getpwnam(Plan::USER);
        if (! $account || $account['uid'] === 0 || $account['shell'] !== '/usr/sbin/nologin') {
            throw new RuntimeException('mymar.actions_user_refused');
        }
        $hex = bin2hex(random_bytes(16));
        $generation = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20);
        State::write($run.'/ownership.private.json', ['database_user' => $user, 'generation' => $generation]);
        foreach ([Plan::ROOT, Plan::CONFIG, Plan::ROOT.'/code', Plan::ROOT.'/empty', '/var/log/cofrac-mymar-actions-gateway'] as $directory) {
            if (! mkdir($directory, 0755) || ! chmod($directory, 0755)) {
                throw new RuntimeException('mymar.actions_directory_failed');
            }
        }
        mkdir(Plan::ROOT.'/private', 0750);
        chgrp(Plan::ROOT.'/private', $account['gid']);
        chmod(Plan::ROOT.'/private', 0750);
        foreach (['entry', 'MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy', 'MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource', 'MymarBookingGateway'] as $class) {
            if (! copy(__DIR__.'/'.$class.'.php', Plan::ROOT.'/code/'.$class.'.php') || ! chmod(Plan::ROOT.'/code/'.$class.'.php', 0644)) {
                throw new RuntimeException('mymar.actions_copy_failed');
            }
        }
        actionsGroupWrite($sourceConfig, ['format' => 'cofrac.mymar-actions-gateway', 'version' => 1, 'read_enabled' => true, 'writes_enabled' => false,
            'technical_author_id' => $prepared['technical_author_id'], 'technician_ids' => $prepared['technician_ids'],
            'database_user' => $user, 'database_password' => $prepared['database_password'], 'token' => bin2hex(random_bytes(32))], $account['gid']);
        foreach (Plan::files() as $name => $content) {
            $path = str_ends_with($name, '.service') ? $run.'/'.$name : Plan::CONFIG.'/'.$name;
            if (file_put_contents($path, $content) !== strlen($content) || ! chmod($path, 0600)) {
                throw new RuntimeException('mymar.actions_artifact_failed');
            }
        }
        touch('/var/log/cofrac-mymar-actions-gateway/worker-error.log');
        chown('/var/log/cofrac-mymar-actions-gateway/worker-error.log', $account['uid']);
        chmod('/var/log/cofrac-mymar-actions-gateway/worker-error.log', 0600);
        exit;
    }
    $owned = actionsPrivate($run.'/ownership.private.json');
    $source = actionsPrivate($sourceConfig, 0640);
    if ($phase === '--app') {
        $group = posix_getgrnam('cofrac2-app');
        if (! $group || file_exists($appConfig) || is_link($appConfig)) {
            throw new RuntimeException('mymar.actions_app_path_refused');
        }
        mkdir($appConfig, 0750);
        chgrp($appConfig, $group['gid']);
        chmod($appConfig, 0750);
        actionsGroupWrite($appConfig.'/actions.json', ['format' => 'cofrac.mymar-actions-client', 'version' => 1,
            'guard_enabled' => true, 'writes_enabled' => false, 'port' => 19848, 'generation' => $owned['generation'], 'bindings' => Configuration::bindings($prepared['bindings'])], $group['gid']);
        if (file_put_contents($appConfig.'/api-token', $source['token']."\n") !== 65 || ! chgrp($appConfig.'/api-token', $group['gid']) || ! chmod($appConfig.'/api-token', 0640)) {
            throw new RuntimeException('mymar.actions_token_failed');
        }
        exit;
    }
    if ($phase === '--unlock') {
        $app = actionsPrivate($appConfig.'/actions.json', 0640);
        if ($app['guard_enabled'] !== true || $app['writes_enabled'] !== false || $source['writes_enabled'] !== false
            || $app['bindings'] !== Configuration::bindings($prepared['bindings'])) {
            throw new RuntimeException('mymar.actions_unlock_order_refused');
        }
        State::admin("ALTER USER '$user'@'localhost' ACCOUNT UNLOCK;", $run);
        exit;
    }
    if ($phase === '--probe') {
        $checks = [];
        foreach ([['Bearer wrong', 403], ['Bearer '.$source['token'], 404]] as [$token,$expected]) {
            $curl = curl_init('http://127.0.0.1:19848/v1/booking-commands/'.'11111111-1111-4111-8111-111111111111');
            curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Authorization: '.$token]]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($body === false || $status !== $expected || strlen($body) > 8192) {
                throw new RuntimeException('mymar.actions_http_read_probe_failed');
            }
            $checks[] = $status;
        }
        State::write($run.'/http-checks.json', ['get_statuses' => $checks, 'post_requests' => false]);
        exit;
    }
    $probe = actionsPrivate($run.'/application-probe.json');
    $checks = actionsPrivate($run.'/http-checks.json');
    if (($probe['ready'] ?? null) !== true || ($probe['post_requests'] ?? null) !== false || ($probe['mapped_technicians'] ?? 0) !== count($prepared['bindings'])
        || ($checks['get_statuses'] ?? null) !== [403, 404] || State::admin("SELECT account_locked FROM mysql.user WHERE User='$user' AND Host='localhost';", $run) !== 'N') {
        throw new RuntimeException('mymar.actions_probe_refused');
    }
    $app = actionsPrivate($appConfig.'/actions.json', 0640);
    if ($app['generation'] !== $owned['generation'] || $app['guard_enabled'] !== true || $app['writes_enabled'] !== false || $source['writes_enabled'] !== false
        || $app['bindings'] !== Configuration::bindings($prepared['bindings'])) {
        throw new RuntimeException('mymar.actions_enable_order_refused');
    }
    $source['writes_enabled'] = true;
    actionsGroupWrite($sourceConfig, $source, filegroup($sourceConfig));
    $app['writes_enabled'] = true;
    actionsGroupWrite($appConfig.'/actions.json', $app, filegroup($appConfig.'/actions.json'));
    State::write(State::STATE.'/MYMAR-ACTIONS.json', ['stage' => 'pilot_actions_enabled', 'run_directory' => $run, 'revision' => $request['revision'], 'writes_enabled' => true]);
    State::write($run.'/summary.json', ['format' => 'cofrac.mymar-actions-activation', 'version' => 1, 'complete' => true, 'revision' => $request['revision'],
        'checked_at_utc' => gmdate(DATE_ATOM), 'run_directory' => $run, 'mapped_technicians' => count($prepared['bindings']),
        'writes_enabled' => true, 'live_native_guard_enabled' => true, 'source_api_port' => 19848, 'post_tests_on_production' => false,
        'messages_sent' => false, 'existing_readers_unchanged' => true]);
} catch (Throwable $error) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.actions_failed';
    fwrite(STDERR, 'STOP : '.$reason.' ; phase='.($phase ?? 'unknown').'. Diagnostic prive conserve.'.PHP_EOL);
    exit(1);
}
