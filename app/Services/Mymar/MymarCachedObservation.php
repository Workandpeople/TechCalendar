<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Minimal, atomic cache projection. Never creates an Appointment or authorizes a placement. */
final class MymarCachedObservation
{
    public const EXPECTED_WARNINGS = ['duration_unit_assumed', 'timezone_assumed', 'status_scope_unconfirmed'];

    public static function project(array $report, MymarPilotConfiguration $pilot, DateTimeImmutable $now): array
    {
        if (($report['snapshot_complete'] ?? null) !== true || ($report['can_affect_planning'] ?? null) !== false
            || array_diff(array_column($report['issues'] ?? [], 'code'), self::EXPECTED_WARNINGS)
            || ($report['scope_technician_ids'] ?? null) !== array_keys($pilot->technicianMap)) {
            throw new RuntimeException('mymar.unusable_observation');
        }
        $periods = [];
        foreach ($report['candidates'] ?? [] as $row) {
            $periods[] = array_intersect_key($row, array_flip(['source_rdv_id', 'source_dossier_id', 'source_technician_id',
                'cofrac_user_id', 'starts_at_utc', 'ends_at_utc', 'duration_minutes', 'visit_phase']));
        }
        $value = ['format' => 'cofrac.mymar-cached-observation', 'version' => 1, 'fingerprint' => $pilot->cacheKey(),
            'generated_at' => $report['source_generated_at'], 'fetched_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'window_start' => $report['window_start'], 'window_end' => $report['window_end'],
            'warnings' => array_values(array_unique(array_column($report['issues'], 'code'))), 'periods' => $periods];
        self::validate($value, $pilot);

        return $value;
    }

    public static function validate(array $value, MymarPilotConfiguration $pilot): void
    {
        if (array_diff(array_keys($value), ['format', 'version', 'fingerprint', 'generated_at', 'fetched_at', 'window_start', 'window_end', 'warnings', 'periods'])
            || ($value['format'] ?? null) !== 'cofrac.mymar-cached-observation' || ($value['version'] ?? null) !== 1
            || ($value['fingerprint'] ?? null) !== $pilot->cacheKey() || ! is_array($value['warnings'] ?? null) || ! array_is_list($value['warnings'])
            || count($value['warnings']) > 3 || count(array_filter($value['warnings'], 'is_string')) !== count($value['warnings'])
            || array_diff($value['warnings'], self::EXPECTED_WARNINGS) || ! is_array($value['periods'] ?? null)
            || ! array_is_list($value['periods']) || count($value['periods']) > MymarObservation::MAXIMUM_ROWS) {
            throw new RuntimeException('mymar.invalid_cached_observation');
        }
        $from = self::instant($value['window_start'] ?? null);
        $to = self::instant($value['window_end'] ?? null);
        self::instant($value['generated_at'] ?? null);
        self::instant($value['fetched_at'] ?? null);
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 31 * 86400) {
            throw new RuntimeException('mymar.invalid_cached_window');
        }
        $ids = [];
        foreach ($value['periods'] as $row) {
            if (! is_array($row) || count($row) !== 8 || array_diff(array_keys($row), ['source_rdv_id', 'source_dossier_id', 'source_technician_id',
                'cofrac_user_id', 'starts_at_utc', 'ends_at_utc', 'duration_minutes', 'visit_phase'])
                || ! is_int($row['source_rdv_id'] ?? null) || $row['source_rdv_id'] < 1 || isset($ids[$row['source_rdv_id']])
                || ! is_int($row['source_dossier_id'] ?? null) || $row['source_dossier_id'] < 1
                || ! is_int($row['source_technician_id'] ?? null) || ! is_int($row['cofrac_user_id'] ?? null)
                || ($pilot->technicianMap[$row['source_technician_id']] ?? null) !== $row['cofrac_user_id']
                || ! is_int($row['duration_minutes'] ?? null) || $row['duration_minutes'] < 1 || $row['duration_minutes'] > 1440
                || ! in_array($row['visit_phase'] ?? null, ['initial', 'after_works'], true)) {
                throw new RuntimeException('mymar.invalid_cached_period');
            }
            $start = self::instant($row['starts_at_utc']);
            $end = self::instant($row['ends_at_utc']);
            if ($end->getTimestamp() - $start->getTimestamp() !== $row['duration_minutes'] * 60 || $start >= $to || $end <= $from) {
                throw new RuntimeException('mymar.invalid_cached_period');
            }
            $ids[$row['source_rdv_id']] = true;
        }
    }

    public static function instant(mixed $value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value) : false;
        if ($date === false || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('mymar.invalid_cached_instant');
        }

        return $date;
    }
}
