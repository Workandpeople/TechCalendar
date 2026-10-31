<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use RuntimeException;

class MymarActionSettings
{
    public const DIRECTORY = '/etc/cofrac2-mymar-actions';

    public function load(): ?array
    {
        $path = self::DIRECTORY.'/actions.json';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }

        return self::validate(json_decode($this->read($path, 32768), true, 16, JSON_THROW_ON_ERROR));
    }

    public static function validate(array $value): array
    {
        if (count($value) !== 7 || ($value['format'] ?? null) !== 'cofrac.mymar-actions-client' || ($value['version'] ?? null) !== 1
            || ! is_bool($value['guard_enabled'] ?? null) || ! is_bool($value['writes_enabled'] ?? null)
            || ($value['writes_enabled'] && ! $value['guard_enabled']) || ($value['port'] ?? null) !== 19848
            || ! MymarBookingCommand::isRequestId($value['generation'] ?? null)
            || ! is_array($value['bindings'] ?? null) || ! array_is_list($value['bindings']) || $value['bindings'] === [] || count($value['bindings']) > 200) {
            throw new RuntimeException('mymar.invalid_actions_settings');
        }
        $source = $native = [];
        foreach ($value['bindings'] as $binding) {
            if (! is_array($binding) || count($binding) !== 2 || ! is_int($binding['source_technician_id'] ?? null) || $binding['source_technician_id'] < 1
                || ! is_int($binding['cofrac_user_id'] ?? null) || $binding['cofrac_user_id'] < 1
                || isset($source[$binding['source_technician_id']]) || isset($native[$binding['cofrac_user_id']])) {
                throw new RuntimeException('mymar.invalid_actions_mapping');
            }
            $source[$binding['source_technician_id']] = true;
            $native[$binding['cofrac_user_id']] = true;
        }

        return $value;
    }

    public function token(): string
    {
        $value = trim($this->read(self::DIRECTORY.'/api-token', 128));
        if (! preg_match('/^[a-f0-9]{64}$/D', $value) || count(array_unique(str_split($value))) < 8) {
            throw new RuntimeException('mymar.invalid_actions_token');
        }

        return $value;
    }

    private function read(string $path, int $maximum): string
    {
        if (realpath(self::DIRECTORY) !== self::DIRECTORY || fileowner(self::DIRECTORY) !== 0 || (fileperms(self::DIRECTORY) & 0777) !== 0750
            || realpath($path) !== $path || ! is_file($path) || ! is_readable($path) || filesize($path) > $maximum
            || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0640) {
            throw new RuntimeException('mymar.unsafe_actions_settings');
        }

        return file_get_contents($path);
    }
}
