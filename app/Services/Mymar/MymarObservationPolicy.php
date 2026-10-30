<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeZone;
use InvalidArgumentException;

final readonly class MymarObservationPolicy
{
    public function __construct(
        public string $sourceTimezone = 'Europe/Paris',
        public bool $timezoneConfirmed = false,
        public string $durationUnit = 'hours',
        public bool $durationUnitConfirmed = false,
        public array $statusIds = [3, 16],
        public bool $statusScopeConfirmed = false,
        public int $maximumAgeSeconds = 300,
    ) {
        if (! in_array($sourceTimezone, DateTimeZone::listIdentifiers(), true)
            || ! in_array($durationUnit, ['hours', 'minutes'], true)
            || $maximumAgeSeconds < 60 || $maximumAgeSeconds > 3600
            || ! array_is_list($statusIds) || $statusIds === [] || count($statusIds) > 50
            || count(array_unique($statusIds, SORT_REGULAR)) !== count($statusIds)) {
            throw new InvalidArgumentException('Invalid MYMAR observation policy.');
        }
        foreach ($statusIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invalid MYMAR status scope.');
            }
        }
    }

    public function describe(): array
    {
        return [
            'source_timezone' => $this->sourceTimezone, 'timezone_confirmed' => $this->timezoneConfirmed,
            'duration_unit' => $this->durationUnit, 'duration_unit_confirmed' => $this->durationUnitConfirmed,
            'status_ids' => $this->statusIds, 'status_scope_confirmed' => $this->statusScopeConfirmed,
            'maximum_age_seconds' => $this->maximumAgeSeconds,
        ];
    }
}
