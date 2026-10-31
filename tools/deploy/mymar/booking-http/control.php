<?php

declare(strict_types=1);

use App\Services\Mymar\MymarPendingBookingPage;
use Cofrac2\Deploy\Mymar\MymarBookingHttpPlan as Plan;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess as Sql;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;
use Cofrac2\Deploy\MymarReadOnlyAudit;
use Cofrac2\Deploy\MymarWorkflowAudit;

umask(0077);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__.'/MymarRuntimeState.php';
require __DIR__.'/MymarBookingSqlAccess.php';
require __DIR__.'/MymarRuntimePlan.php';
require __DIR__.'/MymarBookingHttpPlan.php';

function readerPrivate(string $path): array
{
    if (realpath($path) !== $path || ! is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0600) {
        throw new RuntimeException('mymar.unsafe_reader_input');
    }

    return State::read($path);
}

function readerAccount(string $run, string $action): string
{
    $user = readerPrivate($run.'/rollback-account.private.json')['database_user'] ?? '';
    if (! preg_match('/^cofrac_mymar_br_[a-f0-9]{8}$/D', $user)) {
        throw new RuntimeException('mymar.invalid_reader_account');
    }
    if ($action === 'locked') {
        return State::admin("SELECT account_locked FROM mysql.user WHERE User='$user' AND Host='localhost';", $run);
    }
    if (! in_array($action, ['lock', 'unlock'], true)) {
        throw new RuntimeException('mymar.invalid_reader_account_action');
    }
    State::admin("ALTER USER IF EXISTS '$user'@'localhost' ACCOUNT ".($action === 'lock' ? 'LOCK' : 'UNLOCK').';', $run);

    return readerAccount($run, 'locked');
}

try {
    $phase = $argv[1] ?? '';
    $run = $argv[2] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3 || ! extension_loaded('pdo_mysql') || ! extension_loaded('curl')
        || ! function_exists('mb_check_encoding') || ! in_array($phase, ['--preflight', '--stage', '--unlock', '--observe', '--publish', '--lock', '--disable'], true)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-http-[A-Za-z0-9]+$~D', $run)
        || realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700
        || realpath(State::STATE) !== State::STATE || fileowner(State::STATE) !== 0 || (fileperms(State::STATE) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_booking_http_invocation');
    }
    // Cleanup is independent of old requests, mappings and application source proofs.
    if (in_array($phase, ['--lock', '--disable'], true)) {
        if (is_file($run.'/rollback-account.private.json') && ! in_array(readerAccount($run, 'lock'), ['Y', ''], true)) {
            throw new RuntimeException('mymar.reader_lock_not_confirmed');
        }
        if ($phase === '--disable' && file_exists(Plan::ROOT.'/private/config.json')) {
            $configPath = Plan::ROOT.'/private/config.json';
            $config = State::read($configPath);
            if (($config['database_user'] ?? null) !== (readerPrivate($run.'/rollback-account.private.json')['database_user'] ?? null)
                || realpath($configPath) !== $configPath || fileowner($configPath) !== 0) {
                throw new RuntimeException('mymar.reader_disable_identity_refused');
            }
            $config['read_enabled'] = $config['writes_enabled'] = false;
            $gid = filegroup($configPath);
            State::write($configPath, $config, true);
            chgrp($configPath, $gid);
            chmod($configPath, 0640);
        }
        exit;
    }
    foreach (['MymarReadOnlyAudit', 'MymarWorkflowAudit', 'MymarApprovedTechnicianMap', 'MymarPendingBookingPage'] as $class) {
        require __DIR__.'/'.$class.'.php';
    }
    $request = readerPrivate(__DIR__.'/REQUEST.json');
    $revision = $request['runtime_revision'] ?? '';
    $preparation = $request['preparation_directory'] ?? '';
    $marker = State::STATE.'/MYMAR-BOOKING-HTTP.json';
    if (($request['format'] ?? null) !== 'cofrac.mymar-booking-http-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $revision)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-preparation-[A-Za-z0-9]+$~D', $preparation)
        || realpath($preparation) !== $preparation || fileowner($preparation) !== 0 || (fileperms($preparation) & 0777) !== 0700
        || ! preg_match('/^[a-f0-9]{64}$/D', $request['preparation_summary_sha256'] ?? '')
        || ! hash_equals($request['preparation_summary_sha256'], hash_file('sha256', $preparation.'/summary.json'))) {
        throw new RuntimeException('mymar.unpinned_booking_http_preparation');
    }
    $prepared = readerPrivate($preparation.'/config.private.json');
    $preparedMarker = readerPrivate(State::STATE.'/MYMAR-BOOKING-PREPARED.json');
    $writer = $prepared['database_user'] ?? '';
    if (($preparedMarker['stage'] ?? null) !== 'prepared_disabled' || ($preparedMarker['run_directory'] ?? null) !== $preparation
        || ($prepared['read_enabled'] ?? null) !== false || ($prepared['writes_enabled'] ?? null) !== false
        || ! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $writer)
        || State::admin("SELECT account_locked FROM mysql.user WHERE User='$writer' AND Host='localhost';", $run) !== 'Y') {
        throw new RuntimeException('mymar.prepared_writer_must_stay_locked');
    }
    if ($phase === '--preflight') {
        if (file_exists($marker) || is_link($marker) || (readerPrivate(State::STATE.'/RUNTIME-PREPARED.json')['revision'] ?? null) !== $revision
            || ! is_file(State::STATE.'/INTERNAL-SITE-OPEN.json') || is_link(State::STATE.'/INTERNAL-SITE-OPEN.json')) {
            throw new RuntimeException('mymar.booking_http_already_present_or_release_changed');
        }
        foreach ([Plan::ROOT, Plan::CONFIG, '/var/log/cofrac-mymar-booking-gateway', '/run/cofrac-mymar-booking-fpm', '/run/cofrac-mymar-booking-http',
            ...array_map(static fn ($unit) => '/etc/systemd/system/'.$unit, Plan::UNITS)] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('mymar.booking_http_path_present');
            }
        }
        $server = json_decode(State::admin("SELECT JSON_OBJECT('user',CURRENT_USER(),'version',@@version,'port',@@port,'roles',@@mandatory_roles);", $run), true, 8, JSON_THROW_ON_ERROR);
        if (($server['user'] ?? null) !== 'debian-sys-maint@localhost' || ($server['port'] ?? null) !== 3306
            || ! str_starts_with($server['version'] ?? '', '8.0.') || version_compare(explode('-', $server['version'])[0], '8.0.22', '<')
            || ($server['roles'] ?? null) !== '') {
            throw new RuntimeException('mymar.booking_http_maintenance_identity_refused');
        }
        Sql::validateGrants(explode("\n", State::admin("SHOW GRANTS FOR '$writer'@'localhost';", $run)));
        $originalRequest = readerPrivate($preparation.'/bundle/REQUEST.json');
        $mapPath = $originalRequest['mapping_path'] ?? '';
        if (! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $mapPath)
            || ! hash_equals($originalRequest['mapping_sha256'] ?? '', hash_file('sha256', $mapPath))) {
            throw new RuntimeException('mymar.booking_http_mapping_changed');
        }
        $map = readerPrivate($mapPath);
        $fresh = State::freshMap($mapPath, $map, $run);
        if ($fresh['bindings'] !== ($prepared['bindings'] ?? null)
            || array_column($fresh['bindings'], 'source_technician_id') !== ($prepared['technician_ids'] ?? null)) {
            throw new RuntimeException('mymar.booking_http_prepared_scope_changed');
        }
        $ids = implode(',', array_column($fresh['bindings'], 'cofrac_user_id'));
        if ((int) State::admin("SELECT COUNT(*) FROM cofrac2production.users WHERE id IN ($ids) AND role=2 AND admin=0 AND external_client_id IS NULL AND deleted_at IS NULL;", $run) !== count($fresh['bindings'])) {
            throw new RuntimeException('mymar.booking_http_target_not_active');
        }
        $author = $prepared['technical_author_id'] ?? null;
        if (! is_int($author) || $author < 1 || State::admin("SELECT COUNT(*) FROM MYMAR.users u WHERE u.id=$author AND u.enabled=0 AND u.name='Cofrac planning' AND u.email='cofrac-planning@integration.invalid' AND NOT EXISTS(SELECT 1 FROM MYMAR.model_has_roles r WHERE r.model_id=u.id AND r.model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User')));", $run) !== '1'
            || State::admin('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands;', $run) !== '0') {
            throw new RuntimeException('mymar.booking_http_author_or_journal_changed');
        }
        $source = MymarWorkflowAudit::sources('/home/ubuntu/www/MYMAR');
        if (array_map(static fn ($file) => $file['sha256'], $source['files']) !== ($originalRequest['workflow_files'] ?? null)) {
            throw new RuntimeException('mymar.booking_http_source_changed');
        }
        $proof = [];
        foreach ($source['proof'] as $relative => $sha) {
            $proof['/home/ubuntu/www/MYMAR/'.$relative] = $sha;
        }
        foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
            if (! (MymarReadOnlyAudit::identity($root, $schema)['sql_eligible'] ?? false)) {
                throw new RuntimeException('mymar.booking_http_source_identity_refused');
            }
            foreach (['.env', 'bootstrap/cache/config.php'] as $relative) {
                $path = $root.'/'.$relative;
                $proof[$path] = is_file($path) ? hash_file('sha256', $path) : null;
            }
        }
        foreach ([State::STATE.'/RUNTIME-PREPARED.json', State::STATE.'/INTERNAL-SITE-OPEN.json', State::STATE.'/MYMAR-BOOKING-PREPARED.json',
            $preparation.'/summary.json', $preparation.'/config.private.json', $preparation.'/bundle/REQUEST.json', $mapPath,
            dirname($mapPath).'/bundle/approved-identities.json'] as $path) {
            $proof[$path] = hash_file('sha256', $path);
        }
        State::assertProof($proof);
        State::write($run.'/validation.private.json', ['proof' => $proof, 'mapping_path' => $mapPath, 'validated_links' => count($fresh['bindings'])]);
        echo "Source, compte technique, liens et compte d'ecriture verrouille reverifies.\n";
        exit;
    }
    $validation = readerPrivate($run.'/validation.private.json');
    State::assertProof($validation['proof']);
    if ($phase === '--stage') {
        $account = posix_getpwnam(Plan::USER);
        if (! $account || $account['uid'] === 0 || $account['shell'] !== '/usr/sbin/nologin') {
            throw new RuntimeException('mymar.invalid_booking_http_system_user');
        }
        $credentials = Sql::credentials(readOnly: true);
        $readerUser = $credentials['database_user'];
        if (State::admin("SELECT COUNT(*) FROM mysql.user WHERE User='$readerUser';", $run) !== '0') {
            throw new RuntimeException('mymar.booking_http_reader_already_exists');
        }
        State::write($run.'/rollback-account.private.json', ['database_user' => $readerUser]);
        State::admin(Sql::createSql($readerUser, $credentials['database_password'], readOnly: true), $run);
        Sql::validateGrants(explode("\n", State::admin("SHOW GRANTS FOR '$readerUser'@'localhost';", $run)), readOnly: true);
        foreach ([Plan::ROOT, Plan::CONFIG, Plan::ROOT.'/code', Plan::ROOT.'/empty', '/var/log/cofrac-mymar-booking-gateway'] as $directory) {
            if (file_exists($directory) || is_link($directory) || ! mkdir($directory, 0755) || ! chmod($directory, 0755)) {
                throw new RuntimeException('mymar.booking_http_directory_failed');
            }
        }
        foreach (['entry.php', 'MymarBookingCommand.php', 'MymarObservation.php', 'MymarObservationPolicy.php', 'MymarBookingFailure.php',
            'MymarBookingSqlAccess.php', 'MymarBookingSource.php', 'MymarBookingGateway.php'] as $file) {
            if (! copy(__DIR__.'/'.$file, Plan::ROOT.'/code/'.$file) || ! chmod(Plan::ROOT.'/code/'.$file, 0644)) {
                throw new RuntimeException('mymar.booking_http_code_copy_failed');
            }
        }
        mkdir(Plan::ROOT.'/private', 0750);
        chmod(Plan::ROOT.'/private', 0750);
        chgrp(Plan::ROOT.'/private', $account['gid']);
        $config = ['format' => 'cofrac.mymar-booking-reader', 'version' => 1, 'read_enabled' => true, 'writes_enabled' => false,
            'technical_author_id' => $prepared['technical_author_id'], 'technician_ids' => $prepared['technician_ids'], ...$credentials];
        State::write(Plan::ROOT.'/private/config.json', $config);
        chgrp(Plan::ROOT.'/private/config.json', $account['gid']);
        chmod(Plan::ROOT.'/private/config.json', 0640);
        foreach (Plan::files() as $name => $content) {
            $path = str_ends_with($name, '.service') ? $run.'/'.$name : Plan::CONFIG.'/'.$name;
            if (file_put_contents($path, $content) !== strlen($content) || ! chmod($path, 0600)) {
                throw new RuntimeException('mymar.booking_http_artifact_failed');
            }
        }
        touch('/var/log/cofrac-mymar-booking-gateway/worker-error.log');
        chown('/var/log/cofrac-mymar-booking-gateway/worker-error.log', $account['uid']);
        chmod('/var/log/cofrac-mymar-booking-gateway/worker-error.log', 0600);
        exit;
    }
    if ($phase === '--unlock') {
        if (readerAccount($run, 'unlock') !== 'N') {
            throw new RuntimeException('mymar.booking_reader_unlock_failed');
        }
        exit;
    }
    if ($phase === '--observe') {
        State::freshMap($validation['mapping_path'], readerPrivate($validation['mapping_path']), $run);
        $config = State::read(Plan::ROOT.'/private/config.json');
        $checks = [];
        $fetch = static function (string $path, string $method = 'GET', array $headers = [], ?array $payload = null) use ($config, $run, &$checks): array {
            $body = '';
            $curl = curl_init('http://127.0.0.1:19847'.$path);
            $headers = array_replace(['Authorization' => 'Bearer '.$config['token'], 'Content-Type' => 'application/json'], $headers);
            $options = [CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_map(static fn ($key, $value) => $key.': '.$value, array_keys($headers), array_values($headers)),
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2097152) {
                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                }];
            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            curl_setopt_array($curl, $options);
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            unset($curl);
            $checks[] = ['method' => $method, 'status' => $status, 'json_response' => is_string($type) && str_starts_with($type, 'application/json')];
            State::write($run.'/http-checks.private.json', $checks, file_exists($run.'/http-checks.private.json'));
            if ($ok === false || ! is_string($type) || ! str_starts_with($type, 'application/json')) {
                throw new RuntimeException('mymar.booking_http_probe_failed');
            }

            return [$status, json_decode($body, true, 16, JSON_THROW_ON_ERROR)];
        };
        foreach ([['/v1/pending-bookings?limit=1', ['Authorization' => 'Bearer wrong'], 403],
            ['/v1/pending-bookings?limit=1', ['Host' => 'invalid.test'], 403],
            ['/v1/pending-bookings?limit=1', ['X-Forwarded-For' => '127.0.0.1'], 403],
            ['/.env', [], 404], ['/v1/pending-bookings?limit=1&limit=2', [], 422], ['/v1/pending-bookings?limit=101', [], 422]] as [$path, $headers, $expected]) {
            if ($fetch($path, headers: $headers)[0] !== $expected) {
                throw new RuntimeException('mymar.booking_http_security_failed');
            }
        }
        $dummy = ['format' => 'cofrac.mymar-booking-command', 'version' => 1, 'request_id' => '11111111-1111-4111-8111-111111111111',
            'action' => 'problem', 'dossier_id' => 1, 'expected_revision' => str_repeat('0', 64), 'cofrac_actor_id' => 1, 'comment' => 'Preparation sans execution'];
        [$status, $denied] = $fetch('/v1/booking-commands', 'POST', payload: $dummy);
        if ($status !== 503 || ($denied['error'] ?? null) !== 'booking_disabled' || ($denied['source_committed'] ?? null) !== false) {
            throw new RuntimeException('mymar.booking_http_write_gate_failed');
        }
        [$status, $missing] = $fetch('/v1/booking-commands/'.$dummy['request_id']);
        if ($status !== 404 || ! array_key_exists('source_committed', $missing) || $missing['source_committed'] !== null || ($missing['outcome'] ?? null) !== 'unknown') {
            throw new RuntimeException('mymar.booking_http_receipt_failed');
        }
        [$status, $page] = $fetch('/v1/pending-bookings?after=0&limit=1');
        if ($status !== 200) {
            throw new RuntimeException('mymar.booking_http_pending_failed');
        }
        $validatedPage = MymarPendingBookingPage::fromArray($page, 0, 1);
        $readerUser = $config['database_user'];
        Sql::validateGrants(explode("\n", State::admin("SHOW GRANTS FOR '$readerUser'@'localhost';", $run)), readOnly: true);
        if (State::admin('SELECT COUNT(*) FROM cofrac_mymar_integration.booking_commands;', $run) !== '0') {
            throw new RuntimeException('mymar.booking_http_journal_not_empty');
        }
        State::assertProof($validation['proof']);
        State::write($run.'/http-result.json', ['checks' => count($checks), 'pending_items_probed' => count($validatedPage->items), 'post_refused' => true, 'receipt_lookup_tested' => true]);
        echo "Lecture HTTP bornee validee ; POST refuse ; aucun placement ni message.\n";
        exit;
    }
    if ($phase === '--publish') {
        if (readerAccount($run, 'locked') !== 'N') {
            throw new RuntimeException('mymar.booking_reader_not_available');
        }
        $result = readerPrivate($run.'/http-result.json');
        State::write($run.'/summary.json', ['format' => 'cofrac.mymar-booking-http', 'version' => 1, 'complete' => true,
            'runtime_revision' => $revision, 'run_directory' => $run, 'checked_at_utc' => gmdate(DATE_ATOM), 'port' => 19847,
            'new_reader_select_only' => true, 'reader_account_locked' => readerAccount($run, 'locked') === 'Y', 'prepared_writer_still_locked' => true,
            'read_enabled' => true, 'writes_enabled' => false, 'cofrac_client_enabled' => false, 'can_affect_planning' => false,
            'validated_links' => $validation['validated_links'], ...$result, 'business_database_writes' => false,
            'messages_sent' => false, 'existing_services_changed' => false, 'applications_unchanged' => true]);
        State::write($marker, ['stage' => 'reader_only', 'run_directory' => $run, 'runtime_revision' => $revision, 'writes_enabled' => false]);
        exit;
    }
} catch (Throwable $error) {
    $code = preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.booking_http_failed';
    fwrite(STDERR, 'STOP : '.$code.' ; phase='.($phase ?? 'unknown').'. Diagnostic prive conserve, aucune activation des placements.'.PHP_EOL);
    exit(1);
}
