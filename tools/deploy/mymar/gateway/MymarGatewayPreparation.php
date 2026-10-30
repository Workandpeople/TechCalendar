<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use RuntimeException;

/** Stages immutable code only. No credentials, SQL, network, system user or service configuration. */
final class MymarGatewayPreparation
{
    public const FILES = [
        'app/Services/Mymar/MymarObservation.php',
        'app/Services/Mymar/MymarObservationPolicy.php',
        'app/Services/Mymar/MymarReadWindow.php',
        'tools/deploy/mymar/gateway/MymarGateway.php',
        'tools/deploy/mymar/gateway/MymarGatewayConfig.php',
        'tools/deploy/mymar/gateway/MymarReadOnlySource.php',
        'tools/deploy/mymar/gateway/serve.php',
        'tools/deploy/mymar/gateway/README.txt',
    ];

    public function stage(string $source, string $parent, string $revision): array
    {
        if (realpath($source) !== $source || realpath($parent) !== $parent || ! is_dir($parent)
            || (fileperms($parent) & 0777) !== 0700 || ! preg_match('/^[a-f0-9]{40}$/D', $revision)
            || $parent === $source || str_starts_with($parent.'/', $source.'/')) {
            throw new RuntimeException('mymar.invalid_preparation_paths');
        }
        $files = [];
        foreach (self::FILES as $relative) {
            $path = $source.'/'.$relative;
            if (realpath($path) !== $path || ! is_file($path) || is_link($path) || filesize($path) > 1048576) {
                throw new RuntimeException('mymar.invalid_preparation_source');
            }
            $files[$relative] = hash_file('sha256', $path);
        }
        $report = ['format' => 'cofrac.mymar-disabled-preparation', 'version' => 1, 'revision' => $revision,
            'enabled' => false, 'configured' => false, 'files' => $files, 'sql_account_created' => false,
            'source_database_read' => false, 'services_changed' => false, 'planning_changed' => false];
        $destination = $parent.'/'.$revision;
        if (file_exists($destination) || is_link($destination)) {
            if (realpath($destination) !== $destination || ! is_dir($destination) || is_link($destination)
                || (fileperms($destination) & 0777) !== 0700 || ! is_file($destination.'/DISABLED.json')
                || is_link($destination.'/DISABLED.json')
                || filesize($destination.'/DISABLED.json') > 16384
                || json_decode(file_get_contents($destination.'/DISABLED.json'), true, 16, JSON_THROW_ON_ERROR) !== $report) {
                throw new RuntimeException('mymar.preparation_already_exists');
            }
            foreach ($files as $relative => $sha) {
                if (realpath($destination.'/'.$relative) !== $destination.'/'.$relative || ! is_file($destination.'/'.$relative)
                    || ! hash_equals($sha, hash_file('sha256', $destination.'/'.$relative))) {
                    throw new RuntimeException('mymar.preparation_modified');
                }
            }
            $entries = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                $relative = substr($entry->getPathname(), strlen($destination) + 1);
                if (++$entries > 40 || $entry->isLink()
                    || ($entry->getPerms() & 0777) !== ($entry->isDir() ? 0700 : 0600)
                    || (! $entry->isDir() && (! $entry->isFile() || ($relative !== 'DISABLED.json' && ! isset($files[$relative]))))) {
                    throw new RuntimeException('mymar.preparation_modified');
                }
            }

            return $report;
        }
        $temporary = $parent.'/.stage-'.bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('mymar.preparation_directory_failed');
        }
        foreach ($files as $relative => $sha) {
            $path = $temporary.'/'.$relative;
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
                throw new RuntimeException('mymar.preparation_directory_failed');
            }
            if (! copy($source.'/'.$relative, $path) || ! chmod($path, 0600) || ! hash_equals($sha, hash_file('sha256', $path))) {
                throw new RuntimeException('mymar.preparation_copy_failed');
            }
        }
        if (file_put_contents($temporary.'/DISABLED.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n") === false
            || ! chmod($temporary.'/DISABLED.json', 0600) || ! rename($temporary, $destination)) {
            throw new RuntimeException('mymar.preparation_seal_failed');
        }

        return $report;
    }
}
