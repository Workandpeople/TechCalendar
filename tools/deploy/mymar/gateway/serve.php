<?php

declare(strict_types=1);

use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarGateway;
use Cofrac2\Deploy\Mymar\MymarGatewayConfig;
use Cofrac2\Deploy\Mymar\MymarReadOnlySource;

// Standalone entry point. Neither application's autoload, configuration or bootstrap is executed.
ini_set('display_errors', '0');
require_once __DIR__.'/../../../../app/Services/Mymar/MymarObservationPolicy.php';
require_once __DIR__.'/../../../../app/Services/Mymar/MymarReadWindow.php';
require_once __DIR__.'/../../../../app/Services/Mymar/MymarObservation.php';
require_once __DIR__.'/MymarGateway.php';
require_once __DIR__.'/MymarGatewayConfig.php';
require_once __DIR__.'/MymarReadOnlySource.php';

try {
    if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || ! preg_match('/^127\.0\.0\.1(?::[0-9]{1,5})?$/D', $_SERVER['HTTP_HOST'] ?? '')
        || isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_FORWARDED'])) {
        $result = MymarGateway::error(403, 'access_denied');
    } else {
        $config = MymarGatewayConfig::load(getenv('MYMAR_GATEWAY_CONFIG') ?: '/etc/cofrac-mymar-gateway/config.json');
        $gateway = new MymarGateway($config['enabled'], $config['token'],
            new MymarObservationPolicy(sourceTimezone: $config['source_timezone'], durationUnit: $config['duration_unit']),
            static fn (MymarReadWindow $window, MymarObservationPolicy $policy): array => (new MymarReadOnlySource(MymarGatewayConfig::database($config)))->fetch($window, $policy));
        $result = $gateway->handle($_SERVER['REQUEST_METHOD'] ?? '', explode('?', $_SERVER['REQUEST_URI'] ?? '')[0],
            $_SERVER['QUERY_STRING'] ?? '', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $_SERVER['REMOTE_ADDR'], new DateTimeImmutable('now'));
    }
} catch (Throwable) {
    $result = MymarGateway::error(503, 'gateway_unavailable');
}
http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($result['status'] === 405) {
    header('Allow: GET');
}
echo json_encode($result['body'], JSON_THROW_ON_ERROR);
