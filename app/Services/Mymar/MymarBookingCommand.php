<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Versioned internal contract. Never accepts a MYMAR author or an arbitrary target status. */
final readonly class MymarBookingCommand
{
    private function __construct(public array $payload) {}

    public static function fromArray(array $data): self
    {
        $common = ['format', 'version', 'request_id', 'action', 'dossier_id', 'expected_revision', 'cofrac_actor_id'];
        $action = $data['action'] ?? null;
        $fields = match ($action) {
            'place' => ['technician_id', 'starts_at', 'duration_minutes'],
            'problem' => ['comment', 'reason', 'recall_at'],
            default => throw new InvalidArgumentException('mymar.invalid_booking_command'),
        };
        if (array_diff(array_keys($data), [...$common, ...$fields])
            || array_diff($common, array_keys($data))
            || ($data['format'] ?? null) !== 'cofrac.mymar-booking-command' || ($data['version'] ?? null) !== 1
            || ! self::isRequestId($data['request_id'] ?? null)
            || ! is_string($data['expected_revision'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $data['expected_revision'])
            || ! is_int($data['dossier_id'] ?? null) || $data['dossier_id'] < 1
            || ! is_int($data['cofrac_actor_id'] ?? null) || $data['cofrac_actor_id'] < 1) {
            throw new InvalidArgumentException('mymar.invalid_booking_command');
        }
        $payload = array_intersect_key($data, array_flip($common));
        if ($action === 'place') {
            if (! is_int($data['technician_id'] ?? null) || $data['technician_id'] < 1
                || ! is_int($data['duration_minutes'] ?? null) || $data['duration_minutes'] < 60
                || $data['duration_minutes'] > 480 || $data['duration_minutes'] % 60 !== 0) {
                throw new InvalidArgumentException('mymar.invalid_booking_duration_or_technician');
            }
            $start = self::instant($data['starts_at'] ?? null);
            $end = $start->modify('+'.$data['duration_minutes'].' minutes');
            self::assertUnambiguousWallTime($start);
            self::assertUnambiguousWallTime($end);
            // A naive MYMAR DATETIME cannot describe a visit crossing a clock change safely.
            if ($start->format('P') !== $end->format('P')) {
                throw new InvalidArgumentException('mymar.ambiguous_booking_time');
            }
            $payload += ['technician_id' => $data['technician_id'], 'starts_at' => $start->format(DATE_ATOM),
                'duration_minutes' => $data['duration_minutes']];
        } else {
            $comment = self::text($data['comment'] ?? null, 3, 255);
            $reason = ($data['reason'] ?? null) === null ? null : self::text($data['reason'], 1, 100);
            $recall = ($data['recall_at'] ?? null) === null ? null : self::instant($data['recall_at']);
            if ($recall !== null) {
                self::assertUnambiguousWallTime($recall);
            }
            $payload += ['comment' => $comment, 'reason' => $reason, 'recall_at' => $recall?->format(DATE_ATOM)];
        }
        ksort($payload);

        return new self($payload);
    }

    public static function isRequestId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }

    public static function targetStatus(int $pendingStatus, string $action): int
    {
        return match ([$pendingStatus, $action]) {
            [1, 'place'] => 3, [14, 'place'] => 16,
            [1, 'problem'] => 2, [14, 'problem'] => 15,
            default => throw new InvalidArgumentException('mymar.booking_not_pending'),
        };
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function instant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:00[+-][0-9]{2}:[0-9]{2}$/D', $value)) {
            throw new InvalidArgumentException('mymar.explicit_booking_timezone_required');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format(DATE_ATOM) !== $value
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || abs($date->getOffset()) > 14 * 3600
            || $date->format('Y') < '2000' || $date->format('Y') > '2100') {
            throw new InvalidArgumentException('mymar.invalid_booking_time');
        }

        $paris = $date->setTimezone(new DateTimeZone('Europe/Paris'));
        if ($date->getOffset() !== 0 && $paris->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException('mymar.booking_paris_offset_mismatch');
        }

        return $paris;
    }

    private static function assertUnambiguousWallTime(DateTimeImmutable $date): void
    {
        $zone = new DateTimeZone('Europe/Paris');
        $wall = $date->format('Y-m-d H:i:s');
        foreach ($zone->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400) as $transition) {
            $alternative = (new DateTimeImmutable($wall.'+00:00'))->modify('-'.$transition['offset'].' seconds')->setTimezone($zone);
            if ($alternative->getTimestamp() !== $date->getTimestamp() && $alternative->format('Y-m-d H:i:s') === $wall) {
                throw new InvalidArgumentException('mymar.ambiguous_booking_time');
            }
        }
    }

    private static function text(mixed $value, int $minimum, int $maximum): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('mymar.invalid_booking_text');
        }
        $value = trim($value);
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum
            || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('mymar.invalid_booking_text');
        }

        return $value;
    }
}
