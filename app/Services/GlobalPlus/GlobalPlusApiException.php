<?php

namespace App\Services\GlobalPlus;

use RuntimeException;

class GlobalPlusApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
