<?php

use Cofrac2\Deploy\Mymar\MymarApprovedTechnicianMap;

require_once __DIR__.'/../../tools/deploy/mymar/mapping/MymarApprovedTechnicianMap.php';

function approvedMymarIdentities(): array
{
    return ['format' => 'cofrac.mymar-approved-identities', 'version' => 1, 'bindings' => [
        ['source_technician_id' => 11, 'source_name' => 'Alex (zone nord)', 'cofrac_user_id' => 101, 'cofrac_name' => 'Alex Martin (59,62)'],
        ['source_technician_id' => 22, 'source_name' => 'Camille Petit', 'cofrac_user_id' => 102, 'cofrac_name' => 'Camille Petit'],
    ]];
}

function approvedMymarSnapshot(): array
{
    $rows = [['section' => 'server', 'version' => '8.0.46', 'port' => 3306, 'socket' => '/var/run/mysqld/mysqld.sock', 'read_only' => 1],
        ['section' => 'clock', 'utc' => '2026-10-30T20:00:00Z', 'read_only' => 1], ['section' => 'complete', 'complete' => true]];
    foreach (['MYMAR.users' => ['id', 'name', 'enabled'], 'MYMAR.roles' => ['id', 'name'],
        'MYMAR.model_has_roles' => ['model_id', 'model_type', 'role_id'],
        'cofrac2production.users' => ['id', 'first_name', 'last_name', 'role', 'deleted_at', 'external_client_id']] as $key => $fields) {
        [$schema, $table] = explode('.', $key);
        $rows[] = ['section' => 'table', 'schema' => $schema, 'table' => $table, 'engine' => 'InnoDB'];
        foreach ($fields as $column) {
            $rows[] = ['section' => 'column', 'schema' => $schema, 'table' => $table, 'column' => $column];
        }
    }

    return [...$rows,
        ['section' => 'source', 'id' => 11, 'name' => 'Alex (zone nord)', 'enabled' => 1, 'terrain' => 1],
        ['section' => 'source', 'id' => 22, 'name' => 'Camille Petit', 'enabled' => 1, 'terrain' => 1],
        ['section' => 'target', 'id' => 101, 'name' => 'Alex Martin (59,62)', 'role' => 2, 'disabled' => 0, 'external' => 0],
        ['section' => 'target', 'id' => 102, 'name' => 'Camille Petit', 'role' => 2, 'disabled' => 0, 'external' => 0]];
}

it('prepares only explicitly approved IDs after a fresh readonly source and active target check', function () {
    $result = MymarApprovedTechnicianMap::validate(approvedMymarIdentities(), approvedMymarSnapshot(), new DateTimeImmutable('2026-10-30T20:00:01Z'));
    expect(array_column($result['bindings'], 'source_technician_id'))->toBe([11, 22])
        ->and(array_column($result['bindings'], 'cofrac_user_id'))->toBe([101, 102]);
    expect($result)->toMatchArray(['requires_runtime_revalidation' => true, 'connector_enabled' => false, 'can_affect_planning' => false, 'account_writes' => false]);
    expect(json_encode($result))->not->toContain('Alex', 'Camille', 'password', 'email');
    expect($result['bindings'][0]['source_identity_sha256'])->toHaveLength(64);
});

it('does not require equal email addresses after human identity confirmation and tolerates changed department suffixes', function () {
    $records = array_map(fn ($row) => ($row['section'] === 'target' && $row['id'] === 101)
        ? array_replace($row, ['name' => ' ALEX  MARTIN (80) ']) : $row, approvedMymarSnapshot());
    $result = MymarApprovedTechnicianMap::validate(approvedMymarIdentities(), $records, new DateTimeImmutable('2026-10-30T20:00:01Z'));
    expect($result['bindings'][0]['cofrac_user_id'])->toBe(101);
});

it('binds by approved numeric IDs rather than the order of rows or approval entries', function () {
    $approval = approvedMymarIdentities();
    $approval['bindings'] = array_reverse($approval['bindings']);
    $result = MymarApprovedTechnicianMap::validate($approval, array_reverse(approvedMymarSnapshot()), new DateTimeImmutable('2026-10-30T20:00:01Z'));
    expect(array_column($result['bindings'], 'source_technician_id'))->toBe([11, 22])
        ->and(array_column($result['bindings'], 'cofrac_user_id'))->toBe([101, 102]);
});

it('rejects malformed approvals duplicates and account merges before building SQL', function (Closure $change) {
    expect(fn () => MymarApprovedTechnicianMap::sql($change(approvedMymarIdentities())))->toThrow(RuntimeException::class);
})->with([
    fn ($a) => array_replace($a, ['bindings' => []]),
    fn ($a) => array_replace($a, ['version' => 2]),
    fn ($a) => array_replace($a, ['extra' => true]),
    function ($a) {
        $a['bindings'][1]['source_technician_id'] = 11;

        return $a;
    },
    function ($a) {
        $a['bindings'][1]['cofrac_user_id'] = 101;

        return $a;
    },
    function ($a) {
        $a['bindings'][0]['source_technician_id'] = '11 OR 1=1';

        return $a;
    },
    function ($a) {
        $a['bindings'][0]['cofrac_user_id'] = 0;

        return $a;
    },
    function ($a) {
        $a['bindings'][0]['source_name'] = 'SECRET@example.test';

        return $a;
    },
    function ($a) {
        $a['bindings'][0]['cofrac_name'] = '(59)';

        return $a;
    },
    function ($a) {
        $a['bindings'][0]['other'] = 'value';

        return $a;
    },
]);

it('refuses missing changed disabled external or nontechnician profiles rather than guessing or reactivating', function (string $section, array $changes) {
    $records = array_map(fn ($row) => $row['section'] === $section ? array_replace($row, $changes) : $row, approvedMymarSnapshot());
    expect(fn () => MymarApprovedTechnicianMap::validate(approvedMymarIdentities(), $records,
        new DateTimeImmutable('2026-10-30T20:00:01Z')))->toThrow(RuntimeException::class);
})->with([
    ['source', ['enabled' => 0]], ['source', ['terrain' => 0]], ['source', ['name' => 'Different technician']],
    ['target', ['role' => 0]], ['target', ['role' => 3]], ['target', ['disabled' => 1]],
    ['target', ['external' => 1]], ['target', ['name' => 'Different identity']],
]);

it('requires complete transactional metadata a trusted fresh clock and no duplicate rows', function (Closure $change) {
    expect(fn () => MymarApprovedTechnicianMap::validate(approvedMymarIdentities(), $change(approvedMymarSnapshot()),
        new DateTimeImmutable('2026-10-30T20:00:01Z')))->toThrow(RuntimeException::class);
})->with([
    fn ($rows) => array_slice($rows, 0, -1),
    fn ($rows) => [...$rows, end($rows)],
    fn ($rows) => [...$rows, ['section' => 'complete', 'complete' => true]],
    fn ($rows) => [...$rows, ['section' => 'unexpected']],
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'server' ? array_replace($r, ['read_only' => 0]) : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'server' ? array_replace($r, ['port' => 3307]) : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'clock' ? array_replace($r, ['utc' => '2026-10-30T18:00:00Z']) : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'clock' ? array_replace($r, ['utc' => '2026-10-30T21:00:00Z']) : $r, $rows),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'clock' ? array_replace($r, ['utc' => '2026-02-30T20:00:00Z']) : $r, $rows),
    fn ($rows) => array_values(array_filter($rows, fn ($r) => $r['section'] !== 'complete')),
    fn ($rows) => array_map(fn ($r) => $r['section'] === 'table' ? array_replace($r, ['engine' => 'MyISAM']) : $r, $rows),
    fn ($rows) => array_values(array_filter($rows, fn ($r) => $r['section'] !== 'column' || $r['column'] !== 'enabled')),
]);

it('selects only the approved IDs with bounded read only SQL and never requests emails or passwords', function () {
    $sql = MymarApprovedTechnicianMap::sql(approvedMymarIdentities());
    expect($sql)->toContain('START TRANSACTION READ ONLY', 'max_execution_time=1500', 'WHERE u.id IN (11,22)', 'WHERE id IN (101,102)', 'ROLLBACK;')
        ->not->toContain('password', 'email', 'CREATE ', 'UPDATE ', 'DELETE ', 'INSERT ', 'SELECT *', 'FOR UPDATE');
});
