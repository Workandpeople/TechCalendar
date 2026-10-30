<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

/** Pure, offline interpretation of the proposed private API contract. Never affects availability. */
final class MymarObservation
{
    public const FORMAT = 'cofrac.mymar-occupations-source';

    public const MAXIMUM_ROWS = 2000;

    public function inspect(array $snapshot, MymarObservationPolicy $policy, DateTimeImmutable $now, array $technicianMap = []): array
    {
        $this->exactKeys($snapshot, ['format', 'version', 'generated_at', 'complete', 'source_timezone', 'duration_unit', 'window_start', 'window_end', 'technician_ids', 'status_ids', 'appointments']);
        if ($snapshot['format'] !== self::FORMAT || $snapshot['version'] !== 1 || ! is_bool($snapshot['complete'])
            || ! is_array($snapshot['appointments']) || ! array_is_list($snapshot['appointments'])
            || count($snapshot['appointments']) > self::MAXIMUM_ROWS) {
            throw new UnexpectedValueException('mymar.invalid_snapshot');
        }
        if ($snapshot['source_timezone'] !== $policy->sourceTimezone || $snapshot['duration_unit'] !== $policy->durationUnit) {
            throw new UnexpectedValueException('mymar.interpretation_mismatch');
        }
        $scope = $this->ids($snapshot['technician_ids'], 200);
        $statuses = $this->ids($snapshot['status_ids'], 50);
        if (array_diff($statuses, $policy->statusIds) || array_diff($policy->statusIds, $statuses)) {
            throw new UnexpectedValueException('mymar.status_scope_mismatch');
        }
        $generated = $this->instant($snapshot['generated_at']);
        $from = $this->instant($snapshot['window_start']);
        $to = $this->instant($snapshot['window_end']);
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 31 * 86400) {
            throw new UnexpectedValueException('mymar.invalid_window');
        }
        $this->validateMap($technicianMap);
        $issues = [];
        foreach (['duration_unit_assumed' => $policy->durationUnitConfirmed, 'timezone_assumed' => $policy->timezoneConfirmed,
            'status_scope_unconfirmed' => $policy->statusScopeConfirmed, 'partial_snapshot' => $snapshot['complete']] as $code => $confirmed) {
            if (! $confirmed) {
                $issues[] = ['code' => $code];
            }
        }
        $age = $now->getTimestamp() - $generated->getTimestamp();
        if ($age < -60 || $age > $policy->maximumAgeSeconds) {
            $issues[] = ['code' => $age < -60 ? 'snapshot_from_future' : 'snapshot_stale'];
        }
        $seen = $candidates = [];
        $excludedOld = $excludedOutsideWindow = 0;
        foreach ($snapshot['appointments'] as $row) {
            if (! is_array($row)) {
                throw new UnexpectedValueException('mymar.invalid_row');
            }
            $this->exactKeys($row, ['rdv_id', 'dossier_id', 'technician_id', 'status_id', 'is_old', 'stored_start', 'raw_duration']);
            if (! $this->positiveId($row['rdv_id']) || ! $this->positiveId($row['dossier_id'])
                || ! is_int($row['status_id']) || ! in_array($row['status_id'], $statuses, true)
                || ! in_array($row['is_old'], [0, 1], true)) {
                throw new UnexpectedValueException('mymar.invalid_row_scope');
            }
            if (isset($seen[$row['rdv_id']])) {
                throw new UnexpectedValueException('mymar.duplicate_appointment_id');
            }
            $seen[$row['rdv_id']] = true;
            if ($row['technician_id'] !== null && (! $this->positiveId($row['technician_id']) || ! in_array($row['technician_id'], $scope, true))) {
                throw new UnexpectedValueException('mymar.technician_outside_scope');
            }
            if ($row['is_old'] === 1) {
                $excludedOld++;

                continue;
            }
            try {
                $minutes = $this->durationMinutes($row['raw_duration'], $policy);
                $start = $this->wallTime($row['stored_start'], $policy->sourceTimezone);
                // Durations are elapsed minutes: do not add local hours across a clock change.
                $end = $start->setTimezone(new DateTimeZone('UTC'))->modify('+'.$minutes.' minutes')->setTimezone($start->getTimezone());
            } catch (UnexpectedValueException $exception) {
                $issues[] = ['code' => $exception->getMessage(), 'source_rdv_id' => $row['rdv_id']];

                continue;
            }
            if ($start >= $to || $end <= $from) {
                $excludedOutsideWindow++;

                continue;
            }
            $target = $row['technician_id'] === null ? null : ($technicianMap[$row['technician_id']] ?? null);
            if ($target === null) {
                $issues[] = ['code' => $row['technician_id'] === null ? 'missing_source_technician' : 'unmapped_technician',
                    'source_rdv_id' => $row['rdv_id'], 'source_technician_id' => $row['technician_id']];
            }
            $candidates[] = [
                'source' => 'mymar', 'source_key' => 'mymar:'.$row['rdv_id'], 'source_rdv_id' => $row['rdv_id'],
                'source_dossier_id' => $row['dossier_id'], 'source_technician_id' => $row['technician_id'],
                'cofrac_user_id' => $target, 'mapping_basis' => $target === null ? 'unresolved' : 'operator_supplied_not_runtime_verified',
                'source_status_id' => $row['status_id'], 'visit_phase' => $row['status_id'] === 16 ? 'after_works' : ($row['status_id'] === 3 ? 'initial' : 'unclassified'),
                'source_wall_start' => $row['stored_start'], 'starts_at' => $start->format(DATE_ATOM), 'ends_at' => $end->format(DATE_ATOM),
                'starts_at_utc' => $start->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                'ends_at_utc' => $end->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                'source_duration' => $row['raw_duration'], 'duration_minutes' => $minutes,
                'duration_estimated' => ! $policy->durationUnitConfirmed, 'time_interpretation_confirmed' => $policy->timezoneConfirmed,
                'location_precision' => 'unknown', 'editable' => false,
            ];
        }
        usort($candidates, fn (array $left, array $right): int => [$left['starts_at_utc'], $left['source_rdv_id']] <=> [$right['starts_at_utc'], $right['source_rdv_id']]);

        return [
            'format' => 'cofrac.mymar-observation', 'version' => 1, 'mode' => 'offline_observation',
            'observed_at_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'source_generated_at' => $generated->format(DATE_ATOM), 'source_age_seconds' => $age,
            'window_start' => $from->format(DATE_ATOM), 'window_end' => $to->format(DATE_ATOM),
            'snapshot_complete' => $snapshot['complete'], 'scope_technician_ids' => $scope, 'policy' => $policy->describe(),
            'counts' => ['source_rows' => count($snapshot['appointments']), 'candidates' => count($candidates),
                'excluded_old' => $excludedOld, 'excluded_outside_window' => $excludedOutsideWindow, 'issues' => count($issues)],
            'candidates' => $candidates, 'issues' => $issues,
            'quality_checks_passed' => $issues === [],
            // No report, including a clean one, authorizes an import or a real planning decision.
            'connector_enabled' => false, 'planning_ready' => false, 'can_affect_planning' => false,
            'database_writes' => false, 'http_requests' => false,
        ];
    }

    private function durationMinutes(mixed $value, MymarObservationPolicy $policy): int
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new UnexpectedValueException('invalid_duration');
        }
        $minutes = $value * ($policy->durationUnit === 'hours' ? 60 : 1);
        if ($minutes < 1 || $minutes > 1440 || abs($minutes - round($minutes)) > 0.0000001) {
            throw new UnexpectedValueException('invalid_duration');
        }

        return (int) round($minutes);
    }

    private function wallTime(mixed $value, string $timezone): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
            throw new UnexpectedValueException('invalid_wall_time');
        }
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($naive === false || $naive->format('Y-m-d H:i:s') !== $value) {
            throw new UnexpectedValueException('invalid_wall_time');
        }
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($naive->getTimestamp() - 172800, $naive->getTimestamp() + 172800);
        $offsets = array_unique(array_column($transitions ?: [], 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = (new DateTimeImmutable('@'.($naive->getTimestamp() - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $value) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new UnexpectedValueException($matches === [] ? 'nonexistent_local_time' : 'ambiguous_local_time');
        }

        return array_values($matches)[0];
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D', $value)) {
            throw new UnexpectedValueException('mymar.invalid_instant');
        }
        $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $instant = DateTimeImmutable::createFromFormat('!'.DATE_ATOM, $canonical);
        if ($instant === false || $instant->format(DATE_ATOM) !== $canonical) {
            throw new UnexpectedValueException('mymar.invalid_instant');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    private function validateMap(array $map): void
    {
        if (count($map) > 200 || count(array_unique(array_values($map), SORT_REGULAR)) !== count($map)) {
            throw new InvalidArgumentException('Invalid or ambiguous MYMAR technician mapping.');
        }
        foreach ($map as $source => $target) {
            if (! $this->positiveId($source) || ! $this->positiveId($target)) {
                throw new InvalidArgumentException('Invalid MYMAR technician mapping.');
            }
        }
    }

    private function positiveId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function ids(mixed $values, int $limit): array
    {
        if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > $limit
            || count(array_unique($values, SORT_REGULAR)) !== count($values)) {
            throw new UnexpectedValueException('mymar.invalid_scope');
        }
        foreach ($values as $value) {
            if (! $this->positiveId($value)) {
                throw new UnexpectedValueException('mymar.invalid_scope');
            }
        }

        return $values;
    }

    private function exactKeys(array $value, array $keys): void
    {
        if (array_diff(array_keys($value), $keys) || array_diff($keys, array_keys($value))) {
            throw new UnexpectedValueException('mymar.unexpected_payload_fields');
        }
    }
}
