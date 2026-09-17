<?php

namespace App\Services\GlobalPlus;

use RuntimeException;

class GlobalPlusApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $httpMethod = null,
        private readonly ?string $apiPath = null,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function requestContext(): array
    {
        return array_filter([
            'http_status' => $this->statusCode,
            'http_method' => $this->httpMethod,
            'api_path' => $this->apiPath,
        ], fn ($value) => $value !== null);
    }
}
