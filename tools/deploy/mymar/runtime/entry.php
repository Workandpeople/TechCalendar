<?php

declare(strict_types=1);

// Scope gate in front of the sealed standalone gateway; never bootstrap either Laravel app.
ini_set('display_errors', '0');
try {
    $path = getenv('MYMAR_GATEWAY_SCOPE') ?: '';
    if ($path !== '/var/lib/cofrac-mymar-gateway/private/scope.json' || realpath($path) !== $path
        || ! is_file($path) || filesize($path) > 8192 || (fileperms($path) & 0777) !== 0600
        || (fileperms(dirname($path)) & 0777) !== 0700 || ! in_array(fileowner($path), [0, posix_geteuid()], true)) {
        throw new RuntimeException;
    }
    $scope = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($scope) || array_keys($scope) !== ['technician_ids'] || ! is_array($scope['technician_ids'])
        || ! array_is_list($scope['technician_ids']) || $scope['technician_ids'] === [] || count($scope['technician_ids']) > 200) {
        throw new RuntimeException;
    }
    foreach ($scope['technician_ids'] as $id) {
        if (! is_int($id) || $id < 1) {
            throw new RuntimeException;
        }
    }
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if (strlen($query) > 4096) {
        throw new RuntimeException;
    }
    // The downstream handler validates duplicate, unknown and malformed parameters too.
    foreach (explode('&', $query) as $pair) {
        $parts = explode('=', $pair, 2);
        if (urldecode($parts[0]) !== 'technician_ids' || count($parts) !== 2) {
            continue;
        }
        foreach (explode(',', urldecode($parts[1])) as $id) {
            if (! preg_match('/^[1-9][0-9]*$/D', $id) || (string) (int) $id !== $id || ! in_array((int) $id, $scope['technician_ids'], true)) {
                http_response_code(403);
                header('Content-Type: application/json');
                header('Cache-Control: no-store');
                echo '{"error":"technician_scope_denied","complete":false}';
                exit;
            }
        }
    }
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo '{"error":"scope_unavailable","complete":false}';
    exit;
}
require __DIR__.'/../gateway/serve.php';
