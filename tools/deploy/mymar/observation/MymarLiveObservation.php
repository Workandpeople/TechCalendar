<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use DateTimeImmutable;
use RuntimeException;

/** One-shot rehearsal of the gateway query. Proposed identities never become approved mappings. */
final class MymarLiveObservation
{
    public static function scope(array $records): array
    {
        $roles = $terrain = $technicians = [];
        foreach ($records as $row) {
            if (($row['section'] ?? null) === 'role' && ($row['name'] ?? null) === 'Technicien Terrain') {
                $roles[] = $row['id'];
            }
        }
        if (count($roles) !== 1 || ! is_int($roles[0])) {
            throw new RuntimeException('mymar.terrain_role_not_unique');
        }
        foreach ($records as $row) {
            if (($row['section'] ?? null) === 'role_binding' && ($row['role_id'] ?? null) === $roles[0]
                && in_array($row['model_type'] ?? null, ['App\\Models\\User', 'App\\User'], true)) {
                $terrain[$row['model_id']] = true;
            }
            if (($row['section'] ?? null) === 'technician') {
                $id = $row['mymar_id'] ?? null;
                if (! is_int($id) || $id < 1 || isset($technicians[$id])) {
                    throw new RuntimeException('mymar.invalid_technician_reference');
                }
                $technicians[$id] = $row;
            }
        }
        $review = $targets = [];
        foreach ($technicians as $id => $row) {
            if (! isset($terrain[$id]) || ! in_array($row['enabled_raw'] ?? null, [1, '1', true], true)) {
                continue;
            }
            $candidate = $row['historical_v2_id'] ?? null;
            $reasons = [];
            foreach ([
                'historical_link_not_unique' => ($row['historical_source_duplicates'] ?? null) === 1,
                'email_not_unique_or_different' => ($row['email_candidates'] ?? null) === 1
                    && in_array($row['historical_email_matches'] ?? null, [1, true], true),
                'historical_and_email_targets_differ' => is_int($candidate) && $candidate > 0 && $candidate === ($row['unique_email_v2_id'] ?? null),
                'target_not_active_internal_technician' => ($row['historical_v2_role'] ?? null) === 2
                    && in_array($row['historical_v2_disabled'] ?? null, [0, false], true)
                    && in_array($row['historical_v2_external'] ?? null, [0, false], true),
            ] as $reason => $valid) {
                if (! $valid) {
                    $reasons[] = $reason;
                }
            }
            $suggested = $reasons === [] ? $candidate : null;
            if ($suggested !== null) {
                $targets[$suggested][] = $id;
            }
            $review[$id] = ['source_technician_id' => $id, 'source_name' => self::label($row['mymar_name'] ?? null),
                'suggested_cofrac_user_id' => $suggested,
                'suggested_cofrac_name' => $suggested === null ? null : self::label($row['historical_v2_name'] ?? null),
                'mapping_approved' => false, 'review_reasons' => $reasons];
        }
        foreach ($targets as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $review[$id]['suggested_cofrac_user_id'] = null;
                    $review[$id]['suggested_cofrac_name'] = null;
                    $review[$id]['review_reasons'][] = 'multiple_sources_for_same_target';
                }
            }
        }
        if ($review === [] || count($review) > 200 || array_diff_key($terrain, $technicians)) {
            throw new RuntimeException('mymar.incomplete_or_excessive_technician_scope');
        }
        ksort($review, SORT_NUMERIC);

        return array_values($review);
    }

    public static function sql(MymarReadWindow $window): array
    {
        $policy = new MymarObservationPolicy;
        [$sql, $parameters] = MymarReadOnlySource::query($window, $policy);
        $parts = explode('?', $sql);
        if (count($parts) !== count($parameters) + 1) {
            throw new RuntimeException('mymar.invalid_gateway_query');
        }
        $query = array_shift($parts);
        foreach ($parameters as $index => $value) {
            $query .= self::literal($value).$parts[$index];
        }
        [$from, $to] = $window->sourceBounds($policy->sourceTimezone);
        $bounds = '((r.date_rdv>='.self::literal($from).' AND r.date_rdv<'.self::literal($to).')'
            ." OR r.date_rdv IS NULL OR r.date_rdv<'1000-01-01' OR r.duree IS NULL OR r.duree<1 OR r.duree>24)";
        $begin = "SET SESSION max_execution_time=1500;\nSET SESSION lock_wait_timeout=2;\nSET SESSION innodb_lock_wait_timeout=2;\n"
            ."SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\nSET SESSION TRANSACTION READ ONLY;\nSTART TRANSACTION READ ONLY;\n";
        $observation = $begin."SELECT JSON_OBJECT('section','appointment','rdv_id',q.rdv_id,'dossier_id',q.dossier_id,"
            ."'technician_id',q.technician_id,'status_id',q.status_id,'is_old',q.is_old,'stored_start',CAST(q.stored_start AS CHAR),'raw_duration',q.raw_duration)"
            ." FROM ($query) q ORDER BY q.rdv_id;\n"
            ."SELECT JSON_OBJECT('section','unassigned','rows',COUNT(*)) FROM MYMAR.dossier_rdv r JOIN MYMAR.dossiers d ON d.id=r.dossier_id"
            ." WHERE r.is_old=0 AND d.status_id IN (3,16) AND d.technicien_id IS NULL AND $bounds;\n"
            ."SELECT JSON_OBJECT('section','clock','generated_at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ'),'read_only',@@transaction_read_only);\n"
            ."ROLLBACK;\nSELECT JSON_OBJECT('section','complete','complete',true);\n";

        return ['observation' => $observation, 'explain' => $begin.'EXPLAIN '.$query.";\nROLLBACK;\n"];
    }

    public static function report(array $records, MymarReadWindow $window, DateTimeImmutable $now): array
    {
        $appointments = $metadata = [];
        foreach ($records as $row) {
            $section = $row['section'] ?? null;
            unset($row['section']);
            if ($section === 'appointment') {
                $appointments[] = $row;
            } elseif (in_array($section, ['clock', 'complete', 'unassigned'], true) && ! isset($metadata[$section])) {
                $metadata[$section] = $row;
            } else {
                throw new RuntimeException('mymar.unexpected_observation_record');
            }
        }
        if (($metadata['complete']['complete'] ?? null) !== true || ($metadata['clock']['read_only'] ?? null) !== 1
            || ! is_int($metadata['unassigned']['rows'] ?? null) || $metadata['unassigned']['rows'] < 0
            || ! is_string($metadata['clock']['generated_at'] ?? null)) {
            throw new RuntimeException('mymar.incomplete_live_observation');
        }
        $policy = new MymarObservationPolicy;
        $snapshot = ['format' => MymarObservation::FORMAT, 'version' => 1, 'generated_at' => $metadata['clock']['generated_at'],
            'complete' => true, 'source_timezone' => $policy->sourceTimezone, 'duration_unit' => $policy->durationUnit,
            'window_start' => $window->from->format(DATE_ATOM), 'window_end' => $window->to->format(DATE_ATOM),
            'technician_ids' => $window->technicianIds, 'status_ids' => $policy->statusIds, 'appointments' => $appointments];
        $report = (new MymarObservation)->inspect($snapshot, $policy, $now);
        if (array_intersect(['snapshot_stale', 'snapshot_from_future'], array_column($report['issues'], 'code'))) {
            throw new RuntimeException('mymar.stale_live_observation');
        }
        $report['mode'] = 'one_shot_sql_observation_no_mapping';
        $report['source_database_read'] = true;
        $report['unassigned_source_rows_in_envelope'] = $metadata['unassigned']['rows'];
        $report['global_technician_coverage_confirmed'] = false;
        $report['references_same_transaction'] = false;

        return $report;
    }

    public static function queryPlan(string $text): array
    {
        if (strlen($text) > 16384) {
            throw new RuntimeException('mymar.excessive_query_plan');
        }
        $plan = [];
        foreach (explode("\n", trim($text)) as $line) {
            $columns = explode("\t", $line);
            if (count($columns) !== 12 || ! ctype_digit($columns[0]) || ! ctype_digit($columns[9])
                || ! in_array($columns[2], ['r', 'd'], true) || count($plan) >= 4) {
                throw new RuntimeException('mymar.unexpected_query_plan');
            }
            $plan[] = ['query_block' => (int) $columns[0], 'table_alias' => $columns[2], 'access_type' => $columns[4],
                'estimated_rows' => (int) $columns[9], 'using_filesort' => str_contains($columns[11], 'Using filesort'),
                'using_temporary' => str_contains($columns[11], 'Using temporary')];
        }
        if (count($plan) !== 2) {
            throw new RuntimeException('mymar.unexpected_query_plan');
        }

        return $plan;
    }

    private static function literal(mixed $value): string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
            return "'$value'";
        }
        throw new RuntimeException('mymar.unsafe_query_parameter');
    }

    private static function label(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 200 && ! str_contains($value, '@')
            && ! preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : null;
    }
}
