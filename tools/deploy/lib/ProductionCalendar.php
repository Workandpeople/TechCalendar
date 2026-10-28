<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

final class ProductionCalendar
{
    public static function environment(string $source, string $engine): string
    {
        if (! in_array($engine, ['native', 'fullcalendar'], true)) {
            throw new RuntimeException('Moteur de calendrier non autorise.');
        }
        $pattern = '/^(?:export[ \t]+)?COFRAC_CALENDAR_ENGINE[ \t]*=[^\r\n]*\r?$/m';
        if (preg_match_all($pattern, $source) > 1) {
            throw new RuntimeException('Variable calendrier dupliquee.');
        }
        $assignment = 'COFRAC_CALENDAR_ENGINE='.$engine;

        return preg_match($pattern, $source)
            ? preg_replace($pattern, $assignment, $source)
            : rtrim($source, "\r\n")."\n".$assignment."\n";
    }
}
