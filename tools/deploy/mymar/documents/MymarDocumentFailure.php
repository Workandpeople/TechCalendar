<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use RuntimeException;

final class MymarDocumentFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 409)
    {
        parent::__construct('mymar.'.$reason);
    }
}
