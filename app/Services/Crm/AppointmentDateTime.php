<?php

namespace App\Services\Crm;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final class AppointmentDateTime
{
    public const TIMEZONE = 'Europe/Paris';

    public static function parse(string $value, string $field = 'starts_at'): Carbon
    {
        $pattern = '/\A(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?)?\z/';
        if (! preg_match($pattern, $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) ($parts[4] ?? 0) > 23 || (int) ($parts[5] ?? 0) > 59 || (int) ($parts[6] ?? 0) > 59) {
            throw ValidationException::withMessages([$field => 'Renseigne une date et une heure valides.']);
        }

        $offset = $parts[7] ?? '';
        if ($offset !== '' && $offset !== 'Z') {
            $digits = str_replace(':', '', substr($offset, 1));
            if ((int) substr($digits, 0, 2) > 14 || (int) substr($digits, 2, 2) > 59
                || ((int) substr($digits, 0, 2) === 14 && (int) substr($digits, 2, 2) !== 0)) {
                throw ValidationException::withMessages([$field => 'Le decalage horaire est invalide.']);
            }
        }

        $date = Carbon::parse($value, self::TIMEZONE);
        // PHP otherwise silently moves a missing spring-DST hour to the next hour.
        if ($offset === '' && $date->format('Y-m-d H:i') !== sprintf('%s-%s-%s %s:%s',
            $parts[1], $parts[2], $parts[3], $parts[4] ?? '00', $parts[5] ?? '00')) {
            throw ValidationException::withMessages([$field => 'Cette heure n\'existe pas en Europe/Paris (changement d\'heure). Choisis un autre horaire.']);
        }

        // Existing DATETIME columns store Paris wall time, not UTC or an offset.
        return $date->setTimezone(self::TIMEZONE);
    }

    public static function formatStored(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Model casts inherit the PHP timezone; the stored wall time remains Paris.
        return Carbon::createFromFormat('!Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), self::TIMEZONE)->toIso8601String();
    }

    public static function assertStorableRange(Carbon $start, Carbon $end): void
    {
        foreach ([$start, $end] as $date) {
            // DATETIME cannot distinguish both occurrences of the autumn 02:00 hour.
            if (Carbon::parse(self::formatStored($date))->getTimestamp() !== $date->getTimestamp()) {
                throw ValidationException::withMessages(['starts_at' => 'Ce creneau tombe dans l\'heure repetee du changement d\'heure. Choisis l\'heure d\'hiver (UTC+01:00) ou un autre creneau.']);
            }
        }
    }
}
