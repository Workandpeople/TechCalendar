<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use RuntimeException;

final class MymarBookingFailure extends RuntimeException
{
    public readonly string $reason;

    public readonly int $httpStatus;

    public function __construct(string $reason, int $httpStatus = 409, public readonly array $diagnostic = [])
    {
        $valid = preg_match('/^(?:invalid_)?booking_[a-z0-9_]{1,80}$/D', $reason) && in_array($httpStatus, [403, 404, 409, 422, 503], true);
        $this->reason = $valid ? $reason : 'booking_source_unavailable';
        $this->httpStatus = $valid ? $httpStatus : 503;
        parent::__construct('mymar.'.$this->reason);
    }
}
