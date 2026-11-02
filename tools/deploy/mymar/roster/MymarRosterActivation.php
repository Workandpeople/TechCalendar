<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarPilotConfiguration;
use RuntimeException;

/** Monotonic scope expansion: publish the application allowlist only after the new cache is ready. */
final class MymarRosterActivation
{
    public const PATHS = [
        'occupations' => '/var/lib/cofrac-mymar-gateway/private/scope.json',
        'reader' => '/var/lib/cofrac-mymar-booking-gateway/private/config.json',
        'writer' => '/var/lib/cofrac-mymar-actions-gateway/private/config.json',
        'pilot' => '/etc/cofrac2-mymar/pilot.json',
        'client' => '/etc/cofrac2-mymar-actions/actions.json',
    ];

    public static function pairs(array $bindings): array
    {
        $sources = $targets = $result = [];
        if (! array_is_list($bindings) || $bindings === [] || count($bindings) > 200) {
            throw new RuntimeException('mymar.invalid_roster_bindings');
        }
        foreach ($bindings as $binding) {
            $source = $binding['source_technician_id'] ?? null;
            $target = $binding['cofrac_user_id'] ?? null;
            if (! is_int($source) || $source < 1 || ! is_int($target) || $target < 1 || isset($sources[$source]) || isset($targets[$target])) {
                throw new RuntimeException('mymar.invalid_roster_bindings');
            }
            $sources[$source] = $targets[$target] = true;
            $result[$source] = ['source_technician_id' => $source, 'cofrac_user_id' => $target];
        }
        ksort($result, SORT_NUMERIC);

        return array_values($result);
    }

    public static function prepare(#[\SensitiveParameter] array $configs, array $bindings): array
    {
        if (array_keys($configs) !== array_keys(self::PATHS)) {
            throw new RuntimeException('mymar.incomplete_roster_configuration');
        }
        MymarActionSettings::validate($configs['client']);
        MymarPilotConfiguration::fromArray($configs['pilot']);
        $old = self::pairs($configs['client']['bindings']);
        $new = self::pairs($bindings);
        $oldIds = array_column($old, 'source_technician_id');
        $ids = array_column($new, 'source_technician_id');
        if ($configs['client']['guard_enabled'] !== true || $configs['pilot']['sync_enabled'] !== true
            || $configs['pilot']['display_enabled'] !== true || self::pairs($configs['pilot']['bindings']) !== $old
            || array_keys($configs['occupations']) !== ['technician_ids']
            || self::ids($configs['occupations']['technician_ids']) !== $oldIds) {
            throw new RuntimeException('mymar.roster_scope_inconsistent_or_disabled');
        }
        foreach (['reader' => 'cofrac.mymar-booking-reader', 'writer' => 'cofrac.mymar-actions-gateway'] as $name => $format) {
            $config = $configs[$name];
            if (count($config) !== 9 || ($config['format'] ?? null) !== $format || ($config['version'] ?? null) !== 1
                || ($config['read_enabled'] ?? null) !== true || ! is_bool($config['writes_enabled'] ?? null)
                || ! is_int($config['technical_author_id'] ?? null) || $config['technical_author_id'] < 1
                || self::ids($config['technician_ids'] ?? []) !== $oldIds
                || ! preg_match('/^cofrac_mymar_'.($name === 'reader' ? 'br' : 'rw').'_[a-f0-9]{8}$/D', $config['database_user'] ?? '')
                || ! preg_match('/^Mb3![a-f0-9]{64}$/D', $config['database_password'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/D', $config['token'] ?? '') || count(array_unique(str_split($config['token']))) < 8
                || in_array($config['technical_author_id'], $ids, true)) {
                throw new RuntimeException('mymar.invalid_roster_gateway_configuration');
            }
        }
        if ($configs['reader']['writes_enabled'] !== false || $configs['writer']['writes_enabled'] !== $configs['client']['writes_enabled']
            || $configs['reader']['technical_author_id'] !== $configs['writer']['technical_author_id']) {
            throw new RuntimeException('mymar.roster_gates_inconsistent');
        }
        foreach ($old as $pair) {
            if (! in_array($pair, $new, true)) {
                throw new RuntimeException('mymar.roster_removal_or_remap_refused');
            }
        }
        $configs['occupations']['technician_ids'] = $ids;
        $configs['reader']['technician_ids'] = $ids;
        $configs['writer']['technician_ids'] = $ids;
        $configs['pilot']['bindings'] = $new;
        $configs['client']['bindings'] = $new;

        return $configs;
    }

    public static function readFiles(): array
    {
        $files = [];
        foreach (self::PATHS as $name => $path) {
            clearstatcache(true, $path);
            $scope = $name === 'occupations';
            $user = $scope ? 'cofrac-mymar' : ($name === 'reader' ? 'cofrac-mymar-booking' : ($name === 'writer' ? 'cofrac-mymar-actions' : 'cofrac2-app'));
            $account = posix_getpwnam($user);
            if ($account === false || realpath($path) !== $path || ! is_file($path) || filesize($path) > 32768
                || (fileperms($path) & 0777) !== ($scope ? 0600 : 0640)
                || ! in_array(fileowner($path), $scope ? [0, $account['uid']] : [0], true)
                || (! $scope && filegroup($path) !== $account['gid'])
                || realpath(dirname($path)) !== dirname($path)
                || (fileperms(dirname($path)) & 0777) !== ($scope ? 0700 : 0750)
                || ! in_array(fileowner(dirname($path)), $scope ? [0, $account['uid']] : [0], true)) {
                throw new RuntimeException('mymar.unsafe_roster_configuration');
            }
            $content = file_get_contents($path);
            $files[$name] = ['content' => $content, 'sha256' => hash('sha256', $content),
                'uid' => fileowner($path), 'gid' => filegroup($path), 'mode' => fileperms($path) & 0777];
        }

        return $files;
    }

    public static function replace(string $name, string $expected, #[\SensitiveParameter] string $content, array $metadata): void
    {
        $path = self::PATHS[$name] ?? throw new RuntimeException('mymar.invalid_roster_target');
        clearstatcache(true, $path);
        if (realpath($path) !== $path || ! is_file($path) || ! hash_equals($expected, hash_file('sha256', $path))
            || fileowner($path) !== $metadata['uid'] || filegroup($path) !== $metadata['gid'] || (fileperms($path) & 0777) !== $metadata['mode']) {
            throw new RuntimeException('mymar.roster_configuration_changed');
        }
        $temporary = $path.'.roster-'.bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'x');
        try {
            if ($handle === false || fwrite($handle, $content) !== strlen($content) || ! fflush($handle) || ! fsync($handle)
                || ! chown($temporary, $metadata['uid']) || ! chgrp($temporary, $metadata['gid']) || ! chmod($temporary, $metadata['mode'])
                || ! rename($temporary, $path)) {
                throw new RuntimeException('mymar.roster_configuration_write_failed');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        clearstatcache(true, $path);
    }

    private static function ids(array $ids): array
    {
        if (! array_is_list($ids) || $ids === [] || count($ids) > 200 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
            || count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) !== count($ids)) {
            throw new RuntimeException('mymar.invalid_roster_scope');
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
