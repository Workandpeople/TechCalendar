<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarObservationPolicy;
use PDO;
use Pdo\Mysql;
use RuntimeException;

final class MymarGatewayConfig
{
    public static function load(string $path): array
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || is_link($path) || ! is_file($path)
            || (fileperms($path) & 0777) !== 0600 || (fileperms(dirname($path)) & 0777) !== 0700
            || ! in_array(fileowner($path), [0, posix_geteuid()], true)
            || filesize($path) > 8192 || preg_match('~^/(?:var/www|home/ubuntu/www)(?:/|$)~', $path)) {
            throw new RuntimeException('mymar.invalid_private_configuration');
        }
        $config = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $keys = ['enabled', 'token', 'source_timezone', 'duration_unit', 'database_socket', 'database_user', 'database_password'];
        if (! is_array($config) || array_diff(array_keys($config), $keys) || array_diff($keys, array_keys($config))
            || ! is_bool($config['enabled']) || ! is_string($config['token']) || ! preg_match('/^[a-f0-9]{64}$/D', $config['token'])
            || count(array_unique(str_split($config['token']))) < 8
            || ! is_string($config['source_timezone']) || ! is_string($config['duration_unit'])
            || $config['database_socket'] !== '/var/run/mysqld/mysqld.sock'
            || ! is_string($config['database_user']) || ! preg_match('/^cofrac_mymar_ro(?:_[a-z0-9]{1,10})?$/D', $config['database_user'])
            || ! is_string($config['database_password']) || strlen($config['database_password']) < 24 || strlen($config['database_password']) > 256) {
            throw new RuntimeException('mymar.invalid_private_configuration');
        }
        new MymarObservationPolicy(sourceTimezone: $config['source_timezone'], durationUnit: $config['duration_unit']);

        return $config;
    }

    public static function database(#[\SensitiveParameter] array $config): PDO
    {
        return new PDO('mysql:unix_socket='.$config['database_socket'].';dbname=MYMAR;charset=utf8mb4',
            $config['database_user'], $config['database_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_TIMEOUT => 2,
                Mysql::ATTR_MULTI_STATEMENTS => false]);
    }
}
