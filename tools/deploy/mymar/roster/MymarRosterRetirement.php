<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use RuntimeException;

/** Explicit revocation only. The normal roster activation still refuses removals and remapping. */
final class MymarRosterRetirement
{
    public static function prepare(#[\SensitiveParameter] array $configs, array $request): array
    {
        $source = $request['source_technician_id'] ?? null;
        $target = $request['cofrac_user_id'] ?? null;
        $before = $request['expected_before'] ?? null;
        if (! is_int($source) || $source < 1 || ! is_int($target) || $target < 1 || ! is_int($before) || $before < 2 || $before > 200) {
            throw new RuntimeException('mymar.invalid_retirement_request');
        }
        $pairs = MymarRosterActivation::pairs($configs['client']['bindings'] ?? []);
        $validated = MymarRosterActivation::prepare($configs, $pairs);
        $remaining = [];
        $found = false;
        foreach ($pairs as $pair) {
            if ($pair['source_technician_id'] === $source || $pair['cofrac_user_id'] === $target) {
                if ($pair !== ['source_technician_id' => $source, 'cofrac_user_id' => $target]) {
                    throw new RuntimeException('mymar.retirement_identity_changed');
                }
                $found = true;

                continue;
            }
            $remaining[] = $pair;
        }
        if (count($pairs) !== ($found ? $before : $before - 1)) {
            throw new RuntimeException('mymar.retirement_scope_changed');
        }
        foreach (['occupations', 'reader', 'writer'] as $name) {
            $validated[$name]['technician_ids'] = array_column($remaining, 'source_technician_id');
        }
        foreach (['pilot', 'client'] as $name) {
            $validated[$name]['bindings'] = $remaining;
        }

        return ['configs' => $validated, 'already_applied' => ! $found, 'remaining' => $remaining];
    }

    public static function sql(array $pairs, int $retired): string
    {
        $ids = array_unique([...array_column(MymarRosterActivation::pairs($pairs), 'cofrac_user_id'), $retired]);
        if ($retired < 1) {
            throw new RuntimeException('mymar.invalid_retirement_request');
        }

        return "SET SESSION max_execution_time=1500;\nSET SESSION lock_wait_timeout=2;\n"
            ."SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\nSET SESSION TRANSACTION READ ONLY;\nSTART TRANSACTION READ ONLY;\n"
            ."SELECT JSON_OBJECT('section','user','id',id,'role',role,'admin',CAST(admin=1 AS UNSIGNED),'external',CAST(external_client_id IS NOT NULL AS UNSIGNED),'deleted',CAST(deleted_at IS NOT NULL AS UNSIGNED)) FROM cofrac2production.users WHERE id IN (".implode(',', $ids).");\n"
            ."SELECT JSON_OBJECT('section','inflight','count',COUNT(*)) FROM cofrac2production.mymar_booking_requests WHERE status IN ('queued','dispatching','uncertain');\n"
            ."SELECT JSON_OBJECT('section','complete','read_only',@@transaction_read_only);\nROLLBACK;\n";
    }

    public static function validateTargets(array $rows, array $remaining, int $retired): void
    {
        $users = [];
        $inflight = $complete = null;
        foreach ($rows as $row) {
            if (($row['section'] ?? null) === 'user') {
                if (isset($users[$row['id']])) {
                    throw new RuntimeException('mymar.retirement_incomplete_audit');
                }
                $users[$row['id']] = $row;
            } elseif (($row['section'] ?? null) === 'inflight' && $inflight === null) {
                $inflight = $row['count'];
            } elseif (($row['section'] ?? null) === 'complete' && $complete === null) {
                $complete = $row['read_only'];
            } else {
                throw new RuntimeException('mymar.retirement_incomplete_audit');
            }
        }
        if ($complete !== 1 || $inflight === null || count($users) !== count($remaining) + 1) {
            throw new RuntimeException('mymar.retirement_incomplete_audit');
        }
        if ($inflight !== 0) {
            throw new RuntimeException('mymar.retirement_commands_in_flight');
        }
        $removed = $users[$retired] ?? null;
        if (! $removed || $removed['deleted'] !== 1 || $removed['role'] !== 2 || $removed['admin'] !== 0 || $removed['external'] !== 0) {
            throw new RuntimeException('mymar.retirement_account_not_deleted_technician');
        }
        foreach (MymarRosterActivation::pairs($remaining) as $pair) {
            $user = $users[$pair['cofrac_user_id']] ?? null;
            if (! $user || $user['deleted'] !== 0 || $user['role'] !== 2 || $user['admin'] !== 0 || $user['external'] !== 0) {
                throw new RuntimeException('mymar.retirement_other_target_changed');
            }
        }
    }
}
