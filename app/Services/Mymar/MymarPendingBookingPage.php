<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use RuntimeException;

/** External text stays plain text: consumers must escape it, never render it as HTML. */
final readonly class MymarPendingBookingPage
{
    private function __construct(public array $items, public ?int $nextAfter) {}

    public static function fromArray(array $page, int $after, int $limit): self
    {
        if ($after < 0 || $limit < 1 || $limit > 100 || count($page) !== 4
            || ($page['format'] ?? null) !== 'cofrac.mymar-pending-bookings' || ($page['version'] ?? null) !== 1
            || ! array_key_exists('next_after', $page) || ! is_array($page['items'] ?? null)
            || ! array_is_list($page['items']) || count($page['items']) > $limit) {
            throw new RuntimeException('mymar.invalid_pending_bookings');
        }
        $last = $after;
        $items = [];
        $fields = ['dossier_id', 'status_id', 'phase', 'expected_revision', 'first_name', 'last_name', 'address',
            'postal_code', 'city', 'phone', 'email', 'latitude', 'longitude'];
        foreach ($page['items'] as $item) {
            if (! is_array($item) || array_diff(array_keys($item), $fields) || array_diff($fields, array_keys($item))
                || ! is_int($item['dossier_id']) || $item['dossier_id'] <= $last || ! in_array($item['status_id'], [1, 14], true)
                || $item['phase'] !== ($item['status_id'] === 14 ? 'after_works' : 'initial')
                || ! is_string($item['expected_revision']) || ! preg_match('/^[a-f0-9]{64}$/D', $item['expected_revision'])) {
                throw new RuntimeException('mymar.invalid_pending_booking');
            }
            foreach (['first_name' => 1024, 'last_name' => 1024, 'address' => 4096, 'postal_code' => 64,
                'city' => 1024, 'phone' => 1024, 'email' => 1280] as $key => $maximum) {
                $value = $item[$key];
                if ($value === null && in_array($key, ['postal_code', 'city', 'email'], true)) {
                    continue;
                }
                if (! is_string($value) || strlen($value) > $maximum || ! mb_check_encoding($value, 'UTF-8')
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                    throw new RuntimeException('mymar.invalid_pending_booking_text');
                }
            }
            if ($item['latitude'] !== null || $item['longitude'] !== null) {
                foreach (['latitude' => 90, 'longitude' => 180] as $axis => $maximum) {
                    if ((! is_int($item[$axis]) && ! is_float($item[$axis])) || ! is_finite((float) $item[$axis]) || abs($item[$axis]) > $maximum) {
                        throw new RuntimeException('mymar.invalid_pending_booking_coordinates');
                    }
                }
                if ((float) $item['latitude'] === 0.0 && (float) $item['longitude'] === 0.0) {
                    $item['latitude'] = $item['longitude'] = null;
                }
            }
            $last = $item['dossier_id'];
            $items[] = $item;
        }
        if ($page['next_after'] !== null && (! is_int($page['next_after']) || count($page['items']) !== $limit
            || $page['next_after'] !== $last || $last <= $after)) {
            throw new RuntimeException('mymar.invalid_pending_booking_cursor');
        }

        return new self($items, $page['next_after']);
    }
}
