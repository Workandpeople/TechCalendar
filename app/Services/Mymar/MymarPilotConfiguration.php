<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use RuntimeException;

final readonly class MymarPilotConfiguration
{
    private function __construct(public bool $syncEnabled, public bool $displayEnabled, public array $technicianMap, public string $generation) {}

    public static function disabled(): self
    {
        return new self(false, false, [], '');
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['format', 'version', 'sync_enabled', 'display_enabled', 'blocking_enabled', 'generation', 'bindings'])
            || ($data['format'] ?? null) !== 'cofrac.mymar-observation-pilot' || ($data['version'] ?? null) !== 1
            || ! is_bool($data['sync_enabled'] ?? null) || ! is_bool($data['display_enabled'] ?? null)
            || ($data['blocking_enabled'] ?? null) !== false || ! is_string($data['generation'] ?? null)
            || ! preg_match('/^[a-f0-9]{32}$/D', $data['generation']) || ! is_array($data['bindings'] ?? null)
            || ! array_is_list($data['bindings']) || $data['bindings'] === [] || count($data['bindings']) > 200) {
            throw new RuntimeException('mymar.invalid_pilot_configuration');
        }
        $map = [];
        foreach ($data['bindings'] as $binding) {
            if (! is_array($binding) || count($binding) !== 2 || ! is_int($binding['source_technician_id'] ?? null)
                || $binding['source_technician_id'] < 1 || ! is_int($binding['cofrac_user_id'] ?? null) || $binding['cofrac_user_id'] < 1
                || isset($map[$binding['source_technician_id']]) || in_array($binding['cofrac_user_id'], $map, true)) {
                throw new RuntimeException('mymar.invalid_pilot_mapping');
            }
            $map[$binding['source_technician_id']] = $binding['cofrac_user_id'];
        }
        ksort($map, SORT_NUMERIC);

        return new self($data['sync_enabled'], $data['display_enabled'], $map, $data['generation']);
    }

    public function cacheKey(): string
    {
        return 'mymar:observation:v1:'.hash('sha256', json_encode([$this->generation, $this->technicianMap], JSON_THROW_ON_ERROR));
    }
}
