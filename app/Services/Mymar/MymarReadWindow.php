<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class MymarReadWindow
{
    public DateTimeImmutable $from;

    public DateTimeImmutable $to;

    public array $technicianIds;

    public function __construct(string $from, string $to, array $technicianIds)
    {
        $this->from = self::instant($from);
        $this->to = self::instant($to);
        if ($this->to <= $this->from || $this->to->getTimestamp() - $this->from->getTimestamp() > 31 * 86400
            || ! array_is_list($technicianIds) || $technicianIds === [] || count($technicianIds) > 200
            || count(array_unique($technicianIds, SORT_REGULAR)) !== count($technicianIds)) {
            throw new InvalidArgumentException('mymar.invalid_read_window');
        }
        foreach ($technicianIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('mymar.invalid_technician_scope');
            }
        }
        sort($technicianIds, SORT_NUMERIC);
        $this->technicianIds = $technicianIds;
    }

    public function query(): array
    {
        return ['window_start' => $this->from->format(DATE_ATOM), 'window_end' => $this->to->format(DATE_ATOM),
            'technician_ids' => implode(',', $this->technicianIds)];
    }

    /** Envelope of every possible local start of an occupation lasting at most 24 elapsed hours. */
    public function sourceBounds(string $timezone): array
    {
        $earliest = $this->from->getTimestamp() - 86400;
        $latest = $this->to->getTimestamp();
        $zone = new DateTimeZone($timezone);
        $offsets = array_column($zone->getTransitions($earliest, $latest) ?: [], 'offset');
        if ($offsets === []) {
            throw new InvalidArgumentException('mymar.invalid_source_timezone');
        }

        return [gmdate('Y-m-d H:i:s', $earliest + min($offsets)), gmdate('Y-m-d H:i:s', $latest + max($offsets))];
    }

    private static function instant(string $value): DateTimeImmutable
    {
        if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D', $value)) {
            throw new InvalidArgumentException('mymar.invalid_window_instant');
        }
        $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $instant = DateTimeImmutable::createFromFormat('!'.DATE_ATOM, $canonical);
        if ($instant === false || $instant->format(DATE_ATOM) !== $canonical) {
            throw new InvalidArgumentException('mymar.invalid_window_instant');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
