<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ReportOutcome
{
    public static function normalize(?string $outcome): ?string
    {
        return match (Str::lower(Str::squish(Str::ascii($outcome ?? '')))) {
            'satisfactory', 'satisfaisant', 's' => 'satisfactory',
            'unsatisfactory', 'non satisfaisant', 'ns' => 'unsatisfactory',
            'unverifiable', 'inaccessible', 'non verifiable', 'inaccessible / non verifiable', 'nv' => 'unverifiable',
            default => null,
        };
    }

    public static function downloadPrefix(?string $outcome): ?string
    {
        return match (self::normalize($outcome)) {
            'satisfactory' => 'S',
            'unsatisfactory' => 'NS',
            'unverifiable' => 'NV',
            default => null,
        };
    }
}
