<?php

declare(strict_types=1);

use App\Services\Mymar\MymarPendingBookingPage;
use Cofrac2\Deploy\Mymar\MymarRuntimeState as State;

umask(0077);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__.'/MymarRuntimeState.php';
require __DIR__.'/MymarPendingBookingPage.php';

function uiPrivate(string $path, int $mode = 0600): array
{
    if (realpath($path) !== $path || ! is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0777) !== $mode) {
        throw new RuntimeException('mymar.unsafe_ui_input');
    }

    return State::read($path);
}

function uiWrite(string $path, array $value, int $gid, bool $replace = false): void
{
    // Set the runtime-readable group before the atomic switch, never after it.
    $temporary = $path.'.new-'.bin2hex(random_bytes(6));
    State::write($temporary, $value);
    if (! chgrp($temporary, $gid) || ! chmod($temporary, 0640) || (! $replace && (file_exists($path) || is_link($path)))
        || ! rename($temporary, $path)) {
        throw new RuntimeException('mymar.ui_settings_write_failed');
    }
}

try {
    $phase = $argv[1] ?? '';
    $run = $argv[2] ?? '';
    $directory = '/etc/cofrac2-mymar-booking';
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80401 || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3 || ! extension_loaded('curl')
        || ! in_array($phase, ['--check', '--enable', '--publish', '--disable'], true)
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-ui-[A-Za-z0-9]+$~D', $run)
        || realpath($run) !== $run || fileowner($run) !== 0 || (fileperms($run) & 0777) !== 0700
        || realpath(State::STATE) !== State::STATE || fileowner(State::STATE) !== 0 || (fileperms(State::STATE) & 0777) !== 0700) {
        throw new RuntimeException('mymar.invalid_booking_ui_invocation');
    }
    $group = posix_getgrnam('cofrac2-app');
    if (! $group || $group['gid'] === 0) {
        throw new RuntimeException('mymar.runtime_group_missing');
    }
    $path = $directory.'/reader.json';
    // This rollback needs no source/API/SQL/release availability and never stops a service.
    if ($phase === '--disable') {
        $owned = uiPrivate($run.'/ownership.private.json');
        $settings = uiPrivate($path, 0640);
        if (($settings['generation'] ?? null) !== ($owned['generation'] ?? null) || ($settings['writes_enabled'] ?? null) !== false
            || realpath($directory) !== $directory || fileowner($directory) !== 0 || (fileperms($directory) & 0777) !== 0750) {
            throw new RuntimeException('mymar.ui_disable_identity_refused');
        }
        $settings['read_enabled'] = false;
        uiWrite($path, $settings, $group['gid'], true);
        echo "Liste MAR desactivee ; site et passerelles inchanges.\n";
        exit;
    }
    $request = uiPrivate(__DIR__.'/REQUEST.json');
    if (($request['format'] ?? null) !== 'cofrac.mymar-booking-ui-request' || ($request['version'] ?? null) !== 1
        || ! preg_match('/^[a-f0-9]{40}$/D', $request['revision'] ?? '') || ! preg_match('/^[a-f0-9]{40}$/D', $request['base_revision'] ?? '')
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-http-[A-Za-z0-9]+$~D', $request['reader_directory'] ?? '')
        || ! preg_match('/^[a-f0-9]{64}$/D', $request['reader_summary_sha256'] ?? '')) {
        throw new RuntimeException('mymar.invalid_booking_ui_request');
    }
    $sourceRun = $request['reader_directory'];
    if (realpath($sourceRun) !== $sourceRun || fileowner($sourceRun) !== 0 || (fileperms($sourceRun) & 0777) !== 0700
        || ! hash_equals($request['reader_summary_sha256'], hash_file('sha256', $sourceRun.'/summary.json'))) {
        throw new RuntimeException('mymar.booking_ui_reader_proof_changed');
    }
    $summary = uiPrivate($sourceRun.'/summary.json');
    $marker = uiPrivate(State::STATE.'/MYMAR-BOOKING-HTTP.json');
    $revision = uiPrivate(State::STATE.'/RUNTIME-PREPARED.json')['revision'] ?? null;
    if (($summary['complete'] ?? null) !== true || ($summary['new_reader_select_only'] ?? null) !== true || ($summary['post_refused'] ?? null) !== true
        || ($marker['stage'] ?? null) !== 'reader_only' || ($marker['run_directory'] ?? null) !== $sourceRun || ($marker['writes_enabled'] ?? null) !== false
        || ! in_array($revision, $phase === '--check' ? [$request['base_revision'], $request['revision']] : [$request['revision']], true)
        || ! is_file(State::STATE.'/INTERNAL-SITE-OPEN.json') || is_link(State::STATE.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('mymar.booking_ui_release_or_reader_refused');
    }
    $sourcePath = '/var/lib/cofrac-mymar-booking-gateway/private/config.json';
    $source = uiPrivate($sourcePath, 0640);
    if (($source['format'] ?? null) !== 'cofrac.mymar-booking-reader' || ($source['version'] ?? null) !== 1
        || ($source['read_enabled'] ?? null) !== true || ($source['writes_enabled'] ?? null) !== false
        || ! preg_match('/^cofrac_mymar_br_[a-f0-9]{8}$/D', $source['database_user'] ?? '')
        || ! preg_match('/^[a-f0-9]{64}$/D', $source['token'] ?? '') || count(array_unique(str_split($source['token']))) < 8) {
        throw new RuntimeException('mymar.booking_ui_requires_readonly_gateway');
    }
    $preparedMarker = uiPrivate(State::STATE.'/MYMAR-BOOKING-PREPARED.json');
    $preparedRun = $preparedMarker['run_directory'] ?? '';
    if (($preparedMarker['stage'] ?? null) !== 'prepared_disabled'
        || ! preg_match('~^/var/lib/cofrac2-production/mymar-booking-preparation-[A-Za-z0-9]+$~D', $preparedRun)) {
        throw new RuntimeException('mymar.booking_ui_prepared_writer_refused');
    }
    $writer = uiPrivate($preparedRun.'/config.private.json');
    $writerName = $writer['database_user'] ?? '';
    if (! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $writerName) || ($writer['read_enabled'] ?? null) !== false || ($writer['writes_enabled'] ?? null) !== false
        || State::admin("SET SESSION max_execution_time=1500; START TRANSACTION READ ONLY; SELECT account_locked FROM mysql.user WHERE User='$writerName' AND Host='localhost'; ROLLBACK;", $run) !== 'Y') {
        throw new RuntimeException('mymar.booking_ui_writer_must_stay_locked');
    }
    if ($phase === '--check') {
        if (file_exists($directory) || is_link($directory) || file_exists(State::STATE.'/MYMAR-BOOKING-UI.json')) {
            throw new RuntimeException('mymar.booking_ui_already_prepared');
        }
        // One bounded GET, no POST, recipient or message. No response body is persisted.
        $body = '';
        $curl = curl_init('http://127.0.0.1:19847/v1/pending-bookings?after=0&limit=1');
        curl_setopt_array($curl, [CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$source['token']],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2097152) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        if ($ok === false || $code !== 200 || ! is_string($type) || ! str_starts_with($type, 'application/json')) {
            throw new RuntimeException('mymar.booking_ui_source_unavailable');
        }
        MymarPendingBookingPage::fromArray(json_decode($body, true, 16, JSON_THROW_ON_ERROR), 0, 1);
        State::write($run.'/validation.private.json', ['request_sha256' => hash_file('sha256', __DIR__.'/REQUEST.json'),
            'source_sha256' => hash_file('sha256', $sourcePath)]);
        echo "Lecteur prive et compte d'ecriture verrouille verifies avant deploiement.\n";
        exit;
    }
    $validation = uiPrivate($run.'/validation.private.json');
    if (! hash_equals($validation['request_sha256'] ?? '', hash_file('sha256', __DIR__.'/REQUEST.json'))
        || ! hash_equals($validation['source_sha256'] ?? '', hash_file('sha256', $sourcePath))) {
        throw new RuntimeException('mymar.booking_ui_inputs_changed');
    }
    if ($phase === '--enable') {
        if (file_exists($directory) || is_link($directory) || file_exists(State::STATE.'/MYMAR-BOOKING-UI.json')) {
            throw new RuntimeException('mymar.booking_ui_already_prepared');
        }
        $hex = bin2hex(random_bytes(16));
        $generation = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-'.dechex(8 + random_int(0, 3)).substr($hex, 17, 3).'-'.substr($hex, 20);
        State::write($run.'/ownership.private.json', ['generation' => $generation]);
        if (! mkdir($directory, 0750) || ! chgrp($directory, $group['gid']) || ! chmod($directory, 0750)) {
            throw new RuntimeException('mymar.booking_ui_directory_failed');
        }
        $tokenPath = $directory.'/reader-token';
        if (file_put_contents($tokenPath, $source['token']."\n", LOCK_EX) !== 65 || ! chgrp($tokenPath, $group['gid']) || ! chmod($tokenPath, 0640)) {
            throw new RuntimeException('mymar.booking_ui_token_write_failed');
        }
        uiWrite($path, ['format' => 'cofrac.mymar-booking-reader-client', 'version' => 1, 'read_enabled' => true,
            'writes_enabled' => false, 'port' => 19847, 'generation' => $generation], $group['gid']);
        echo "Lecture des demandes MAR ouverte ; aucun identifiant SQL transmis a Cofrac.\n";
        exit;
    }
    $settings = uiPrivate($path, 0640);
    $owned = uiPrivate($run.'/ownership.private.json');
    $probe = uiPrivate($run.'/application-probe.json');
    if (($settings['generation'] ?? null) !== ($owned['generation'] ?? null) || ($settings['read_enabled'] ?? null) !== true
        || ($settings['writes_enabled'] ?? null) !== false || ($probe['reader_ready'] ?? null) !== true || ($probe['writes_enabled'] ?? null) !== false
        || ! is_int($probe['pending_items_probed'] ?? null) || $probe['pending_items_probed'] < 0 || $probe['pending_items_probed'] > 1) {
        throw new RuntimeException('mymar.booking_ui_application_probe_failed');
    }
    State::write($run.'/summary.json', ['format' => 'cofrac.mymar-booking-ui', 'version' => 1, 'complete' => true,
        'revision' => $request['revision'], 'checked_at_utc' => gmdate(DATE_ATOM), 'read_enabled' => true, 'writes_enabled' => false,
        'prepared_writer_still_locked' => true, 'pending_items_probed' => $probe['pending_items_probed'], 'native_business_schema_changed' => false,
        'source_business_data_changed' => false, 'messages_sent' => false, 'source_services_changed' => false]);
    State::write(State::STATE.'/MYMAR-BOOKING-UI.json', ['stage' => 'reader_only', 'run_directory' => $run,
        'revision' => $request['revision'], 'generation' => $owned['generation'], 'writes_enabled' => false]);
} catch (Throwable $error) {
    $reason = preg_match('/^mymar\.[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'mymar.booking_ui_failed';
    fwrite(STDERR, 'STOP : '.$reason.' ; phase='.($phase ?? 'unknown').'. Aucun placement active.'.PHP_EOL);
    exit(1);
}
