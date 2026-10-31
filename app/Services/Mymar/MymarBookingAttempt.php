<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use InvalidArgumentException;

final readonly class MymarBookingAttempt
{
    public function __construct(public string $state, public ?string $reason = null, public ?array $result = null)
    {
        if (! in_array($state, ['confirmed', 'rejected', 'uncertain'], true)
            || ($reason !== null && ! preg_match('/^(?:invalid_)?booking_[a-z0-9_]{1,80}$/D', $reason))
            || ($state === 'confirmed') !== ($result !== null)) {
            throw new InvalidArgumentException('mymar.invalid_booking_attempt');
        }
    }
}
