<?php

namespace App\Services;

final class DocumentUpload
{
    public const MAX_KILOBYTES = 1_048_576;

    public const MAX_BYTES = self::MAX_KILOBYTES * 1024;

    public const SIZE_RULE = 'max:'.self::MAX_KILOBYTES;

    public const LIMIT_LABEL = '1 Go';

    public const TOO_LARGE = 'Le document ne doit pas dépasser 1 Go.';
}
