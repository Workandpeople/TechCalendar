<?php

namespace App\Services\Capcee;

use RuntimeException;

class CapceeApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message);
    }
}
