<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use RuntimeException;

/** Deliberately separate from occupation settings and any future write credential. */
class MymarBookingReaderSettings
{
    public const DIRECTORY = '/etc/cofrac2-mymar-booking';

    public function load(): ?array
    {
        $path = self::DIRECTORY.'/reader.json';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        $value = json_decode($this->read($path, 4096), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($value) || count($value) !== 6 || ($value['format'] ?? null) !== 'cofrac.mymar-booking-reader-client'
            || ($value['version'] ?? null) !== 1 || ! is_bool($value['read_enabled'] ?? null)
            || ($value['writes_enabled'] ?? null) !== false || ($value['port'] ?? null) !== 19847
            || ! MymarBookingCommand::isRequestId($value['generation'] ?? null)) {
            throw new RuntimeException('mymar.invalid_reader_settings');
        }

        return $value['read_enabled'] ? $value : null;
    }

    public function token(): string
    {
        $token = trim($this->read(self::DIRECTORY.'/reader-token', 128));
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || count(array_unique(str_split($token))) < 8) {
            throw new RuntimeException('mymar.invalid_reader_token');
        }

        return $token;
    }

    private function read(string $path, int $maximum): string
    {
        if (realpath(self::DIRECTORY) !== self::DIRECTORY || fileowner(self::DIRECTORY) !== 0 || (fileperms(self::DIRECTORY) & 0777) !== 0750
            || realpath($path) !== $path || ! is_file($path) || ! is_readable($path) || filesize($path) > $maximum
            || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0640) {
            throw new RuntimeException('mymar.unsafe_reader_settings');
        }

        return file_get_contents($path);
    }
}
