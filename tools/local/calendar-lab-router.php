<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

// This router serves only the fictitious calendar and static public assets.
if (PHP_SAPI !== 'cli-server' || getenv('COFRAC_CALENDAR_LAB') !== '1') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$public = realpath($root.'/public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath($public.rawurldecode($path ?: '/'));
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)
    && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['js', 'css', 'png', 'jpg', 'jpeg', 'svg', 'ico', 'woff', 'woff2'], true)) {
    return false;
}
if ($path !== '/local/calendar' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(404);
    exit('Page indisponible dans le laboratoire local.');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.calendar-lab-not-used');
$app->useStoragePath(getenv('COFRAC_LAB_STORAGE'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->make(Vite::class)->useHotFile(getenv('COFRAC_LAB_STORAGE').'/no-vite-hot-file');
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
