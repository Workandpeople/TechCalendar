<?php

declare(strict_types=1);

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarGatewayConfig;
use Cofrac2\Deploy\Mymar\MymarGatewayPreparation;
use Cofrac2\Deploy\Mymar\MymarRuntimePlan as Plan;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;
use Cofrac2\Deploy\Mymar\MymarSqlGatewayPreparation as Sql;
use Cofrac2\Deploy\MymarReadOnlyAudit;

umask(0077);
ini_set('display_errors', '0');
$phase = $argv[1] ?? '';
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! extension_loaded('pdo_mysql') || ! extension_loaded('curl')
        || ! function_exists('posix_geteuid') || posix_geteuid() !== 0 || explode('.', gethostname())[0] !== 'ns365601'
        || $argc !== 3 || ! in_array($phase, ['--preflight', '--stage', '--unlock', '--observe', '--publish', '--lock', '--disable'], true)) {
        throw new RuntimeException('mymar.invalid_runtime_invocation');
    }
    $run = $argv[2];
    if (realpath($run) !== $run || ! preg_match('~^/var/lib/cofrac2-production/mymar-http-runtime-[A-Za-z0-9]+$~D', $run)
        || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700
        || realpath('/var/lib/cofrac2-production') !== '/var/lib/cofrac2-production'
        || fileowner('/var/lib/cofrac2-production') !== 0 || (fileperms('/var/lib/cofrac2-production') & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_runtime_directory');
    }
    foreach (['MymarRuntimePlan', 'MymarRuntimeState', 'MymarSqlGatewayPreparation', 'MymarApprovedTechnicianMap', 'MymarGatewayPreparation', 'MymarReadOnlyAudit'] as $class) {
        require __DIR__.'/'.$class.'.php';
    }
    // Independent cleanup must still work if the source map or a prepared file changes later.
    if (in_array($phase, ['--lock', '--disable'], true)) {
        $accountPath = $run.'/rollback-account.private.json';
        if (fileowner($accountPath) !== 0 || (fileperms($accountPath) & 0777) !== 0600) {
            throw new RuntimeException('mymar.invalid_rollback_account');
        }
        $user = State::read($accountPath)['database_user'] ?? '';
        State::admin(Sql::accountSql($user, 'lock'), $run);
        if (State::admin(Sql::accountSql($user, 'locked'), $run) !== 'Y') {
            throw new RuntimeException('mymar.sql_lock_not_confirmed');
        }
        if ($phase === '--disable') {
            $path = Plan::ROOT.'/private/config.json';
            $active = State::read($path, 8192);
            if (($active['database_user'] ?? null) !== $user || (fileperms($path) & 0777) !== 0600) {
                throw new RuntimeException('mymar.unexpected_active_configuration');
            }
            $active['enabled'] = false;
            if (file_put_contents($path, json_encode($active, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n") === false) {
                throw new RuntimeException('mymar.disable_configuration_failed');
            }
        }
        echo "Compte SQL MYMAR dedie verrouille.\n";
        exit;
    }
    $request = State::read(__DIR__.'/REQUEST.json');
    $connection = $request['connection_directory'] ?? '';
    $mapPath = $request['mapping_path'] ?? '';
    $mapSha = $request['mapping_sha256'] ?? '';
    $revision = $request['source_revision'] ?? '';
    if (($request['format'] ?? null) !== 'cofrac.mymar-private-runtime-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-sql-gateway-[A-Za-z0-9]+$~D', $connection)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-mapping-[A-Za-z0-9]+/approved-map\.json$~D', $mapPath)
        || ! preg_match('/^[a-f0-9]{64}$/D', $mapSha) || ! preg_match('/^[a-f0-9]{40}$/D', $revision)
        || realpath($connection) !== $connection || fileowner($connection) !== 0 || (fileperms($connection) & 0777) !== 0700
        || ! hash_equals($mapSha, hash_file('sha256', $mapPath))) {
        throw new RuntimeException('mymar.unpinned_runtime_preparation');
    }
    $registry = State::read(State::STATE.'/mymar-sql-connections/'.$mapSha.'.json');
    if (($registry['stage'] ?? null) !== 'prepared_account_locked' || ($registry['run_directory'] ?? null) !== $connection
        || ($registry['source_revision'] ?? null) !== $revision
        || ! hash_equals($registry['configuration_sha256'] ?? '', hash_file('sha256', $connection.'/config/config.json'))) {
        throw new RuntimeException('mymar.sql_preparation_changed');
    }
    $map = State::read($mapPath);
    if (($map['connector_enabled'] ?? null) !== false || ($map['can_affect_planning'] ?? null) !== false
        || ($map['source_revision'] ?? null) !== $revision) {
        throw new RuntimeException('mymar.disabled_mapping_required');
    }
    $config = State::read($connection.'/config/config.json', 8192);
    if (($config['enabled'] ?? null) !== false) {
        throw new RuntimeException('mymar.original_preparation_must_remain_disabled');
    }
    $user = $config['database_user'];
    $disabledPath = State::STATE.'/mymar-gateway-prepared/'.$revision.'/DISABLED.json';
    $disabled = State::read($disabledPath);
    if (($disabled['enabled'] ?? null) !== false || ($disabled['configured'] ?? null) !== false
        || ($disabled['revision'] ?? null) !== $revision || array_keys($disabled['files'] ?? []) !== MymarGatewayPreparation::FILES) {
        throw new RuntimeException('mymar.sealed_code_required');
    }
    foreach ($disabled['files'] as $relative => $sha) {
        State::assertProof([$connection.'/code/'.$relative => $sha]);
    }
    foreach (['MymarObservationPolicy', 'MymarReadWindow', 'MymarObservation'] as $class) {
        require $connection.'/code/app/Services/Mymar/'.$class.'.php';
    }
    require $connection.'/code/tools/deploy/mymar/gateway/MymarGatewayConfig.php';
    $config = MymarGatewayConfig::load($connection.'/config/config.json');
    $marker = State::STATE.'/MYMAR-HTTP-RUNTIME.json';
    if ($phase === '--preflight') {
        if (file_exists($marker) || is_link($marker) || (State::read(State::STATE.'/RUNTIME-PREPARED.json')['revision'] ?? null) !== $revision
            || ! is_file(State::STATE.'/INTERNAL-SITE-OPEN.json') || is_link(State::STATE.'/INTERNAL-SITE-OPEN.json')
            || State::admin(Sql::accountSql($user, 'locked'), $run) !== 'Y') {
            throw new RuntimeException('mymar.runtime_already_present_or_unexpected_release');
        }
        foreach ([Plan::ROOT, Plan::CONFIG, '/var/log/cofrac-mymar-gateway', '/run/cofrac-mymar-fpm', '/run/cofrac-mymar-http',
            ...array_map(static fn ($unit) => '/etc/systemd/system/'.$unit, Plan::UNITS)] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('mymar.runtime_path_already_present');
            }
        }
        $identity = json_decode(State::admin("SELECT JSON_OBJECT('user',CURRENT_USER(),'version',@@version,'port',@@port,'roles',@@mandatory_roles);", $run), true, 8, JSON_THROW_ON_ERROR);
        if (($identity['user'] ?? null) !== 'debian-sys-maint@localhost' || ($identity['port'] ?? null) !== 3306
            || ! str_starts_with($identity['version'] ?? '', '8.0.') || ($identity['roles'] ?? null) !== '') {
            throw new RuntimeException('mymar.unexpected_maintenance_identity');
        }
        State::freshMap($mapPath, $map, $run);
        $proof = [$mapPath => $mapSha, $connection.'/config/config.json' => $registry['configuration_sha256']];
        foreach ($disabled['files'] as $relative => $sha) {
            $proof[$connection.'/code/'.$relative] = $sha;
        }
        foreach (['/home/ubuntu/www/MYMAR' => 'MYMAR', '/var/www/cofrac2' => 'cofrac2production'] as $root => $schema) {
            if (realpath($root) !== $root || ! (MymarReadOnlyAudit::identity($root, $schema)['sql_eligible'] ?? false)) {
                throw new RuntimeException('mymar.application_identity_not_confirmed');
            }
            foreach (['.env', 'bootstrap/cache/config.php'] as $relative) {
                $path = $root.'/'.$relative;
                if (is_link($path)) {
                    throw new RuntimeException('mymar.indirect_application_configuration');
                }
                $proof[$path] = is_file($path) ? hash_file('sha256', $path) : null;
            }
        }
        foreach ([$disabledPath, State::STATE.'/RUNTIME-PREPARED.json', State::STATE.'/INTERNAL-SITE-OPEN.json',
            dirname($mapPath).'/bundle/approved-identities.json'] as $path) {
            $proof[$path] = hash_file('sha256', $path);
        }
        State::assertProof($proof);
        State::write($run.'/rollback-account.private.json', ['database_user' => $user]);
        State::write($run.'/validation.private.json', ['proof' => $proof, 'code_files' => $disabled['files'], 'mapping_sha256' => $mapSha]);
        echo "Preparation SQL, release et profils approuves reverifies.\n";
        exit;
    }
    $validated = State::read($run.'/validation.private.json');
    State::assertProof($validated['proof']);
    if ($phase === '--stage') {
        $account = posix_getpwnam(Plan::USER);
        if (! is_array($account) || $account['uid'] === 0 || $account['shell'] !== '/usr/sbin/nologin') {
            throw new RuntimeException('mymar.invalid_gateway_system_user');
        }
        foreach ([Plan::ROOT, Plan::CONFIG, '/var/log/cofrac-mymar-gateway'] as $directory) {
            if (file_exists($directory) || is_link($directory) || ! mkdir($directory, 0755) || ! chmod($directory, 0755)) {
                throw new RuntimeException('mymar.new_runtime_directory_failed');
            }
        }
        mkdir(Plan::ROOT.'/empty', 0755);
        chmod(Plan::ROOT.'/empty', 0755);
        foreach ($validated['code_files'] as $relative => $sha) {
            $target = Plan::ROOT.'/code/'.$relative;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (! copy($connection.'/code/'.$relative, $target) || ! chmod($target, 0644) || ! hash_equals($sha, hash_file('sha256', $target))) {
                throw new RuntimeException('mymar.runtime_code_copy_failed');
            }
        }
        mkdir(Plan::ROOT.'/code/tools/deploy/mymar/runtime', 0755);
        copy(__DIR__.'/entry.php', Plan::ROOT.'/code/tools/deploy/mymar/runtime/entry.php');
        chmod(Plan::ROOT.'/code/tools/deploy/mymar/runtime/entry.php', 0644);
        // mkdir respects umask, explicitly set read/traverse on immutable runtime code directories.
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Plan::ROOT.'/code', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isDir()) {
                chmod($file->getPathname(), 0755);
            }
        }
        chmod(Plan::ROOT.'/code', 0755);
        mkdir(Plan::ROOT.'/private', 0700);
        chown(Plan::ROOT.'/private', $account['uid']);
        chgrp(Plan::ROOT.'/private', $account['gid']);
        foreach (['config.json' => array_replace($config, ['enabled' => true]),
            'scope.json' => ['technician_ids' => array_column($map['bindings'], 'source_technician_id')]] as $name => $value) {
            $path = Plan::ROOT.'/private/'.$name;
            State::write($path, $value);
            chown($path, $account['uid']);
            chgrp($path, $account['gid']);
        }
        foreach (Plan::files() as $name => $content) {
            $path = str_ends_with($name, '.service') ? $run.'/'.$name : Plan::CONFIG.'/'.$name;
            if (file_put_contents($path, $content) !== strlen($content) || ! chmod($path, 0600)) {
                throw new RuntimeException('mymar.runtime_artifact_write_failed');
            }
        }
        touch('/var/log/cofrac-mymar-gateway/worker-error.log');
        chown('/var/log/cofrac-mymar-gateway/worker-error.log', $account['uid']);
        chmod('/var/log/cofrac-mymar-gateway/worker-error.log', 0600);
        echo "Code isole et configurations prepares ; aucun service demarre par cette etape.\n";
        exit;
    }
    if ($phase === '--unlock') {
        State::admin(Sql::accountSql($user, 'unlock'), $run);
        if (State::admin(Sql::accountSql($user, 'locked'), $run) !== 'N') {
            throw new RuntimeException('mymar.sql_unlock_failed');
        }
        exit;
    }
    if ($phase === '--observe') {
        State::freshMap($mapPath, $map, $run);
        $now = new DateTimeImmutable;
        $from = $now->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0);
        $window = new MymarReadWindow($from->format(DATE_ATOM), $from->modify('+7 days')->format(DATE_ATOM), array_column($map['bindings'], 'source_technician_id'));
        $url = 'http://127.0.0.1:'.Plan::PORT.'/v1/occupations?'.http_build_query($window->query());
        $checks = [];
        $fetch = static function (string $url, string $method, array $headers) use ($config, $run, &$checks): array {
            $body = '';
            $curl = curl_init($url);
            $requestHeaders = array_replace(['Authorization' => 'Bearer '.$config['token'], 'Accept' => 'application/json'], $headers);
            curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_map(static fn ($key, $value) => $key.': '.$value,
                    array_keys($requestHeaders), array_values($requestHeaders)),
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2097152) {
                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                }]);
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            $curlCode = curl_errno($curl);
            curl_close($curl);
            $checks[] = ['method' => $method, 'http_status' => $status, 'json_response' => is_string($type)
                && strtolower(trim(explode(';', $type)[0])) === 'application/json', 'curl_code' => $curlCode];
            State::write($run.'/http-checks.private.json', $checks, is_file($run.'/http-checks.private.json'));
            if ($ok === false || ! is_string($type) || strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
                throw new RuntimeException('mymar.private_http_probe_failed');
            }

            return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
        };
        foreach ([[$url, 'GET', ['Authorization' => 'Bearer wrong'], 403], [$url, 'POST', [], 405],
            ['http://127.0.0.1:'.Plan::PORT.'/.env', 'GET', [], 404], [$url, 'GET', ['Host' => 'example.test'], 403],
            [$url, 'GET', ['X-Forwarded-For' => '127.0.0.1'], 403],
            [str_replace(http_build_query($window->query()), http_build_query(array_replace($window->query(), ['technician_ids' => '2147483647'])), $url), 'GET', [], 403]] as [$testUrl, $method, $headers, $expected]) {
            [$status, $body] = $fetch($testUrl, $method, $headers);
            if ($status !== $expected || ($body['complete'] ?? null) !== false) {
                throw new RuntimeException('mymar.http_security_check_failed');
            }
        }
        [$status, $snapshot] = $fetch($url, 'GET', []);
        if ($status !== 200) {
            throw new RuntimeException('mymar.private_http_source_failed');
        }
        $report = (new MymarObservation)->inspect($snapshot, new MymarObservationPolicy, $now,
            array_column($map['bindings'], 'cofrac_user_id', 'source_technician_id'));
        if (! $report['snapshot_complete'] || $report['scope_technician_ids'] !== $window->technicianIds
            || $report['window_start'] !== $window->from->format(DATE_ATOM) || $report['window_end'] !== $window->to->format(DATE_ATOM)
            || array_intersect(['snapshot_stale', 'snapshot_from_future'], array_column($report['issues'], 'code'))) {
            throw new RuntimeException('mymar.incomplete_http_scope');
        }
        State::assertProof($validated['proof']);
        $report['mode'] = 'isolated_apache_fpm_http_observation';
        $report['production_http_tested'] = true;
        $report['connector_enabled'] = false;
        $report['can_affect_planning'] = false;
        State::write($run.'/observation.json', $report);
        echo "HTTP interne : lecture reelle validee et six controles de securite reussis.\n";
        exit;
    }
    if ($phase === '--publish') {
        $observation = State::read($run.'/observation.json', 2097152);
        if (($observation['production_http_tested'] ?? null) !== true || ($observation['can_affect_planning'] ?? null) !== false
            || State::admin(Sql::accountSql($user, 'locked'), $run) !== 'N') {
            throw new RuntimeException('mymar.runtime_not_validated');
        }
        $files = [];
        foreach (Plan::files() as $name => $content) {
            $path = str_ends_with($name, '.service') ? '/etc/systemd/system/'.$name : Plan::CONFIG.'/'.$name;
            if (! hash_equals(hash('sha256', $content), hash_file('sha256', $path))) {
                throw new RuntimeException('mymar.installed_artifact_changed');
            }
            $files[$path] = hash_file('sha256', $path);
        }
        State::write($marker, ['format' => 'cofrac.mymar-private-http-runtime', 'version' => 1, 'run_directory' => $run,
            'files' => $files, 'mapping_sha256' => $mapSha, 'crm_connector_enabled' => false, 'can_affect_planning' => false]);
        $summary = ['format' => 'cofrac.mymar-private-http-observation', 'version' => 1, 'source_revision' => $revision,
            'validated_links' => count($map['bindings']), 'production_http_tested' => true, 'private_http_listening' => '127.0.0.1:'.Plan::PORT,
            'sql_account_readonly' => true, 'sql_account_locked' => false, 'credentials_exported' => false,
            'new_private_services_only' => true, 'original_services_changed' => false, 'business_database_writes' => false,
            'original_files_unchanged' => true, 'counts' => $observation['counts'], 'issue_counts' => array_count_values(array_column($observation['issues'], 'code')),
            'connector_enabled' => false, 'can_affect_planning' => false, 'private_runtime_directory' => $run,
            'rollback_command' => 'sudo bash '.$run.'/bundle/stop.sh'];
        State::write($run.'/summary.json', $summary);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $exception) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $exception->getMessage()) ? $exception->getMessage() : 'mymar.runtime_control_failed';
    fwrite(STDERR, "STOP : $reason ; phase=$phase. Aucun branchement au planning.\n");
    exit(1);
}
