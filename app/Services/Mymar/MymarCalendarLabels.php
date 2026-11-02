<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use RuntimeException;

/** Optional display data; never used to infer occupations, locations or availability. */
final class MymarCalendarLabels
{
    public static function cacheKey(MymarPilotConfiguration $pilot): string
    {
        return $pilot->cacheKey().':labels:v1';
    }

    public static function validate(array $data, MymarReadWindow $window, DateTimeImmutable $now): array
    {
        self::keys($data, ['format', 'version', 'generated_at', 'window_start', 'window_end', 'technician_ids', 'items']);
        if ($data['format'] !== 'cofrac.mymar-calendar-labels' || $data['version'] !== 1
            || $data['technician_ids'] !== $window->technicianIds || $data['window_start'] !== $window->from->format(DATE_ATOM)
            || $data['window_end'] !== $window->to->format(DATE_ATOM) || ! is_array($data['items'])
            || ! array_is_list($data['items']) || count($data['items']) > MymarObservation::MAXIMUM_ROWS) {
            throw new RuntimeException('mymar.invalid_calendar_labels');
        }
        $generated = MymarCachedObservation::instant($data['generated_at']);
        $age = $now->getTimestamp() - $generated->getTimestamp();
        if ($age < -60 || $age > 300) {
            throw new RuntimeException('mymar.stale_calendar_labels');
        }
        $seen = [];
        foreach ($data['items'] as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('mymar.invalid_calendar_labels');
            }
            self::keys($item, ['rdv_id', 'dossier_id', 'technician_id', 'customer_name', 'address', 'postal_code', 'city']);
            foreach (['rdv_id', 'dossier_id', 'technician_id'] as $field) {
                if (! is_int($item[$field]) || $item[$field] < 1) {
                    throw new RuntimeException('mymar.invalid_calendar_labels');
                }
            }
            if (isset($seen[$item['rdv_id']]) || ! in_array($item['technician_id'], $window->technicianIds, true)) {
                throw new RuntimeException('mymar.invalid_calendar_labels_scope');
            }
            $seen[$item['rdv_id']] = true;
            foreach (['customer_name' => 321, 'address' => 500, 'postal_code' => 20, 'city' => 120] as $field => $maximum) {
                if (! is_string($item[$field]) || ! mb_check_encoding($item[$field], 'UTF-8')
                    || mb_strlen($item[$field]) > $maximum || preg_match('/\p{C}/u', $item[$field])) {
                    throw new RuntimeException('mymar.invalid_calendar_labels');
                }
            }
        }

        return $data;
    }

    public static function forSnapshot(mixed $data, MymarPilotConfiguration $pilot, array $snapshot): array
    {
        if (! is_array($data)) {
            return [];
        }
        try {
            $window = new MymarReadWindow($snapshot['window_start'], $snapshot['window_end'], array_keys($pilot->technicianMap));
            $data = self::validate($data, $window, new DateTimeImmutable);

            return array_column($data['items'], null, 'rdv_id');
        } catch (\Throwable) {
            // Unavailable labels must not hide an occupation or turn a busy slot into free time.
            return [];
        }
    }

    private static function keys(array $data, array $keys): void
    {
        if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) {
            throw new RuntimeException('mymar.invalid_calendar_labels_fields');
        }
    }
}
