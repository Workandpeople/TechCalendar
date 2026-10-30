<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use RuntimeException;

/** Root-owned pilot metadata is readable on the web; only the sync command loads the separate API token. */
class MymarPilotSettings
{
    public const DIRECTORY = '/etc/cofrac2-mymar';

    public function load(): MymarPilotConfiguration
    {
        $path = self::DIRECTORY.'/pilot.json';
        if (! file_exists($path) && ! is_link($path)) {
            return MymarPilotConfiguration::disabled();
        }
        $data = json_decode($this->readPrivateFile($path, 32768), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('mymar.invalid_pilot_configuration');
        }

        return MymarPilotConfiguration::fromArray($data);
    }

    public function token(): string
    {
        $token = trim($this->readPrivateFile(self::DIRECTORY.'/api-token', 128));
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || count(array_unique(str_split($token))) < 8) {
            throw new RuntimeException('mymar.invalid_pilot_token');
        }

        return $token;
    }

    private function readPrivateFile(string $path, int $maximum): string
    {
        if (realpath($path) !== $path || ! is_file($path) || ! is_readable($path) || filesize($path) > $maximum
            || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0640
            || realpath(self::DIRECTORY) !== self::DIRECTORY || fileowner(self::DIRECTORY) !== 0
            || (fileperms(self::DIRECTORY) & 0777) !== 0750) {
            throw new RuntimeException('mymar.unsafe_pilot_file');
        }
        $content = file_get_contents($path);
        if (! is_string($content)) {
            throw new RuntimeException('mymar.unreadable_pilot_file');
        }

        return $content;
    }
}
