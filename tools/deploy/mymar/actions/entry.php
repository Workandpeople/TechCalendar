<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarBookingFailure;
use Cofrac2\Deploy\Mymar\MymarBookingGateway;
use Cofrac2\Deploy\Mymar\MymarBookingSource;

ini_set('display_errors', '0');
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $directory = '/var/lib/cofrac-mymar-actions-gateway/private';
    $path = getenv('MYMAR_ACTIONS_CONFIG') ?: '';
    if ($path !== $directory.'/config.json' || realpath($path) !== $path || ! is_file($path) || filesize($path) > 32768
        || fileowner($path) !== 0 || filegroup($path) !== posix_getegid() || (fileperms($path) & 0777) !== 0640
        || realpath($directory) !== $directory || fileowner($directory) !== 0 || (fileperms($directory) & 0777) !== 0750) {
        throw new RuntimeException;
    }
    $config = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    if (($config['format'] ?? null) !== 'cofrac.mymar-actions-gateway' || ($config['version'] ?? null) !== 1
        || ($config['read_enabled'] ?? null) !== true || ! is_bool($config['writes_enabled'] ?? null)
        || ! preg_match('/^cofrac_mymar_rw_[a-f0-9]{8}$/D', $config['database_user'] ?? '')
        || ! preg_match('/^Mb3![a-f0-9]{64}$/D', $config['database_password'] ?? '')) {
        throw new RuntimeException;
    }
    if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || ! in_array(strtolower($_SERVER['HTTP_HOST'] ?? ''), ['127.0.0.1:19848', 'localhost:19848'], true)) {
        http_response_code(403);
        echo '{"error":"booking_access_denied","source_committed":false}';
        exit;
    }
    foreach ($_SERVER as $key => $_) {
        if ($key === 'HTTP_FORWARDED' || str_starts_with($key, 'HTTP_X_FORWARDED_') || $key === 'HTTP_X_ORIGINAL_URL' || $key === 'HTTP_X_REWRITE_URL') {
            http_response_code(403);
            echo '{"error":"booking_access_denied","source_committed":false}';
            exit;
        }
    }
    foreach (['MymarBookingCommand', 'MymarObservation', 'MymarObservationPolicy', 'MymarBookingFailure', 'MymarBookingSqlAccess', 'MymarBookingSource', 'MymarBookingGateway'] as $class) {
        require __DIR__.'/'.$class.'.php';
    }
    $source = null;
    $adapter = static function () use (&$source, $config): MymarBookingSource {
        return $source ??= new MymarBookingSource(new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=MYMAR;charset=utf8mb4',
            $config['database_user'], $config['database_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]),
            $config['technical_author_id'], $config['technician_ids'], enabled: true, writesEnabled: $config['writes_enabled']);
    };
    $gateway = new MymarBookingGateway(true, $config['token'],
        static fn () => throw new MymarBookingFailure('booking_method_not_allowed', 405),
        static fn ($command, $now) => $config['writes_enabled'] ? $adapter()->execute($command, $now) : throw new MymarBookingFailure('booking_disabled', 503),
        static fn ($id) => $adapter()->result($id));
    $body = file_get_contents('php://input', false, null, 0, 16385);
    $response = $gateway->handle($_SERVER['REQUEST_METHOD'] ?? '', explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0],
        $_SERVER['QUERY_STRING'] ?? '', $_SERVER['CONTENT_TYPE'] ?? '', $body === false ? '' : $body,
        $_SERVER['HTTP_AUTHORIZATION'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', new DateTimeImmutable);
    http_response_code($response['status']);
    echo json_encode($response['body'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo '{"error":"booking_gateway_unavailable","outcome":"unknown","source_committed":false}';
}
