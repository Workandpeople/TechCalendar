<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/** Time-overlap preview only. Does not authorize a booking, evaluate travel or change pilot flags. */
final class MymarOccupancySnapshot
{
    private array $periods = [];

    private function __construct(private readonly MymarPilotConfiguration $pilot, private string $state = 'unavailable',
        private array $metadata = [], private int $windowStart = 0, private int $windowEnd = 0) {}

    public static function fromCache(MymarPilotConfiguration $pilot, ?array $cache, bool $lastSyncFailed, DateTimeImmutable $now): self
    {
        $result = new self($pilot);
        if (! $pilot->syncEnabled || ! $pilot->displayEnabled) {
            $result->state = 'disabled';

            return $result;
        }
        if ($cache === null) {
            return $result;
        }
        try {
            MymarCachedObservation::validate($cache, $pilot);
            $age = $now->getTimestamp() - MymarCachedObservation::instant($cache['generated_at'])->getTimestamp();
            $result->state = $age < -60 ? 'future' : ($age > 300 ? 'stale' : ($lastSyncFailed ? 'degraded' : 'fresh'));
            $result->windowStart = MymarCachedObservation::instant($cache['window_start'])->getTimestamp();
            $result->windowEnd = MymarCachedObservation::instant($cache['window_end'])->getTimestamp();
            $result->metadata = array_intersect_key($cache, array_flip(['generated_at', 'window_start', 'window_end', 'warnings']));
            foreach ($cache['periods'] as $period) {
                $result->periods[$period['cofrac_user_id']][] = [...$period,
                    'start_epoch' => MymarCachedObservation::instant($period['starts_at_utc'])->getTimestamp(),
                    'end_epoch' => MymarCachedObservation::instant($period['ends_at_utc'])->getTimestamp()];
            }
        } catch (Throwable) {
            $result->state = 'invalid';
            $result->metadata = [];
            $result->periods = [];
        }

        return $result;
    }

    public function summary(): array
    {
        return ['state' => $this->state, 'cache_ready' => $this->state === 'fresh', 'blocking_enabled' => false,
            'policy_confirmation_required' => ($this->metadata['warnings'] ?? []) !== [], 'travel_evaluated' => false,
            'pilot_technicians' => count($this->pilot->technicianMap),
            'periods' => array_sum(array_map('count', $this->periods)), ...$this->metadata];
    }

    public function inspect(int $technicianId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $empty = ['in_pilot' => false, 'known' => false, 'state' => 'outside_pilot', 'overlaps' => [], 'blocking_enabled' => false];
        if (! in_array($technicianId, $this->pilot->technicianMap, true)) {
            return $empty;
        }
        $empty['in_pilot'] = true;
        if ($this->state !== 'fresh') {
            return [...$empty, 'state' => $this->state];
        }
        if ($to <= $from) {
            return [...$empty, 'state' => 'invalid_window'];
        }
        $start = $from->getTimestamp();
        $end = $to->getTimestamp();
        if ($start < $this->windowStart || $end > $this->windowEnd) {
            return [...$empty, 'state' => 'outside_coverage'];
        }
        $overlaps = [];
        foreach ($this->periods[$technicianId] ?? [] as $period) {
            if ($period['start_epoch'] < $end && $period['end_epoch'] > $start) {
                $overlaps[] = array_intersect_key($period, array_flip(['source_rdv_id', 'source_dossier_id', 'starts_at_utc', 'ends_at_utc', 'visit_phase']));
            }
        }

        return [...$empty, 'known' => true, 'state' => $overlaps === [] ? 'no_overlap' : 'overlap', 'overlaps' => $overlaps];
    }
}
