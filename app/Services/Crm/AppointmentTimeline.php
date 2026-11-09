<?php

namespace App\Services\Crm;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class AppointmentTimeline
{
    public static function apply(Builder $query, string $period, CarbonImmutable $now): void
    {
        match ($period) {
            'upcoming' => $query->where('starts_at', '>', $now),
            'ongoing' => $query->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'past' => $query->where('ends_at', '<=', $now),
            default => null,
        };
    }

    public static function matches(array $event, string $period, CarbonImmutable $now): bool
    {
        $start = CarbonImmutable::parse($event['start']);
        $end = CarbonImmutable::parse($event['end']);

        return match ($period) {
            'upcoming' => $start > $now,
            'ongoing' => $start <= $now && $end > $now,
            'past' => $end <= $now,
            default => true,
        };
    }
}
