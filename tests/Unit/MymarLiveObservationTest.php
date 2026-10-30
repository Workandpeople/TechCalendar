<?php

use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarLiveObservation;

require_once __DIR__.'/../../tools/deploy/mymar/observation/MymarLiveObservation.php';
require_once __DIR__.'/../../tools/deploy/mymar/gateway/MymarReadOnlySource.php';

function liveMymarReference(int $id = 26, array $overrides = []): array
{
    return array_replace(['section' => 'technician', 'mymar_id' => $id, 'mymar_name' => 'Source fixture', 'enabled_raw' => 1,
        'historical_source_duplicates' => 1, 'historical_v2_id' => 151, 'historical_v2_name' => 'Target fixture',
        'historical_v2_role' => 2, 'historical_v2_disabled' => 0, 'historical_v2_external' => 0,
        'historical_email_matches' => 1, 'email_candidates' => 1, 'unique_email_v2_id' => 151], $overrides);
}

function liveMymarReferences(array $technicians): array
{
    return [['section' => 'role', 'id' => 2, 'name' => 'Technicien Terrain'],
        ...array_map(fn ($row) => ['section' => 'role_binding', 'model_id' => $row['mymar_id'], 'role_id' => 2, 'model_type' => 'App\\Models\\User'], $technicians),
        ...$technicians];
}

function liveMymarRecords(array $appointments = []): array
{
    return [...array_map(fn ($row) => ['section' => 'appointment', ...$row], $appointments),
        ['section' => 'clock', 'generated_at' => '2026-10-30T19:00:00Z', 'read_only' => 1],
        ['section' => 'unassigned', 'rows' => 1], ['section' => 'complete', 'complete' => true]];
}

it('keeps identity proposals separate from approved links and filters source roles and activity', function () {
    $records = liveMymarReferences([liveMymarReference(), liveMymarReference(27, ['enabled_raw' => 0])]);
    $records[] = liveMymarReference(28);
    $review = MymarLiveObservation::scope($records);
    expect($review)->toHaveCount(1)->and($review[0])->toMatchArray(['source_technician_id' => 26,
        'suggested_cofrac_user_id' => 151, 'mapping_approved' => false, 'review_reasons' => []]);
});

it('never proposes ambiguous inactive external or nontechnician targets', function (array $change, string $reason) {
    $review = MymarLiveObservation::scope(liveMymarReferences([liveMymarReference(overrides: $change)]));
    expect($review[0]['suggested_cofrac_user_id'])->toBeNull()->and($review[0]['review_reasons'])->toContain($reason);
})->with([
    [['historical_source_duplicates' => 2], 'historical_link_not_unique'],
    [['email_candidates' => 2], 'email_not_unique_or_different'],
    [['historical_email_matches' => 0], 'email_not_unique_or_different'],
    [['unique_email_v2_id' => 152], 'historical_and_email_targets_differ'],
    [['historical_v2_disabled' => 1], 'target_not_active_internal_technician'],
    [['historical_v2_external' => 1], 'target_not_active_internal_technician'],
    [['historical_v2_role' => 0], 'target_not_active_internal_technician'],
]);

it('rejects many source proposals for one target and masks inappropriate display labels', function () {
    $review = MymarLiveObservation::scope(liveMymarReferences([liveMymarReference(overrides: ['mymar_name' => 'PRIVATE@example.test']), liveMymarReference(27)]));
    expect(array_column($review, 'suggested_cofrac_user_id'))->toBe([null, null])->and($review[0]['source_name'])->toBeNull();
    foreach ($review as $row) {
        expect($row['review_reasons'])->toContain('multiple_sources_for_same_target');
    }
});

it('refuses empty partial duplicate and excessive source scopes', function (array $records) {
    expect(fn () => MymarLiveObservation::scope($records))->toThrow(RuntimeException::class);
})->with([
    fn () => [],
    fn () => liveMymarReferences([liveMymarReference(overrides: ['enabled_raw' => 0])]),
    fn () => liveMymarReferences([liveMymarReference(), liveMymarReference()]),
    fn () => [['section' => 'role', 'id' => 2, 'name' => 'Technicien Terrain'], ['section' => 'role_binding', 'role_id' => 2, 'model_id' => 26, 'model_type' => 'App\\User']],
    fn () => liveMymarReferences(array_map(fn ($id) => liveMymarReference($id), range(1, 201))),
]);

it('reuses the exact gateway query in bounded read only SQL and requests no contact or password fields', function () {
    $queries = MymarLiveObservation::sql(new MymarReadWindow('2026-10-30T00:00:00+01:00', '2026-11-06T00:00:00+01:00', [26, 27]));
    foreach ($queries as $sql) {
        expect($sql)->toContain('max_execution_time=1500', 'START TRANSACTION READ ONLY', 'ROLLBACK;', 'LIMIT 2001', 'technicien_id IN (26,27)')
            ->not->toContain('?', 'password', 'email', 'UPDATE ', 'CREATE ', 'DELETE ', 'INSERT ', 'FOR UPDATE');
    }
    expect($queries['explain'])->toContain('EXPLAIN SELECT')->not->toContain('EXPLAIN ANALYZE');
});

it('normalizes live occupations but never applies the proposed mapping or enables planning', function () {
    $row = ['rdv_id' => 12317, 'dossier_id' => 91, 'technician_id' => 26, 'status_id' => 16,
        'is_old' => 0, 'stored_start' => '2026-11-02 09:30:00', 'raw_duration' => 1];
    $window = new MymarReadWindow('2026-10-30T00:00:00+01:00', '2026-11-06T00:00:00+01:00', [26]);
    $report = MymarLiveObservation::report(liveMymarRecords([$row]), $window, new DateTimeImmutable('2026-10-30T19:00:01Z'));
    expect($report['candidates'][0])->toMatchArray(['cofrac_user_id' => null, 'starts_at' => '2026-11-02T09:30:00+01:00',
        'ends_at' => '2026-11-02T10:30:00+01:00', 'duration_estimated' => true]);
    expect($report)->toMatchArray(['connector_enabled' => false, 'can_affect_planning' => false, 'database_writes' => false,
        'source_database_read' => true, 'unassigned_source_rows_in_envelope' => 1, 'references_same_transaction' => false]);
});

it('exports only a bounded technical summary of the actual query plan', function () {
    $line = "1\tSIMPLE\tr\tNULL\tALL\tNULL\tNULL\tNULL\tNULL\t12000\t10.00\tUsing where; Using filesort";
    $second = "1\tSIMPLE\td\tNULL\teq_ref\tPRIMARY\tPRIMARY\t8\tr.dossier_id\t1\t20.00\tUsing where";
    $plan = MymarLiveObservation::queryPlan($line."\n".$second."\n");
    expect($plan)->toHaveCount(2)->and($plan[0])->toMatchArray(['table_alias' => 'r', 'estimated_rows' => 12000, 'using_filesort' => true]);
    expect(fn () => MymarLiveObservation::queryPlan(''))->toThrow(RuntimeException::class);
    expect(fn () => MymarLiveObservation::queryPlan($line))->toThrow(RuntimeException::class);
    expect(fn () => MymarLiveObservation::queryPlan(str_repeat('x', 16385)))->toThrow(RuntimeException::class);
});

it('does not publish partial stale writable or duplicate-clock results as a complete observation', function (array $records) {
    expect(fn () => MymarLiveObservation::report($records,
        new MymarReadWindow('2026-10-30T00:00:00+01:00', '2026-11-06T00:00:00+01:00', [26]),
        new DateTimeImmutable('2026-10-30T19:00:01Z')))->toThrow(RuntimeException::class);
})->with([
    fn () => [],
    fn () => array_slice(liveMymarRecords(), 0, -1),
    fn () => [...liveMymarRecords(), ['section' => 'clock']],
    fn () => array_map(fn ($row) => $row['section'] === 'clock' ? array_replace($row, ['read_only' => 0]) : $row, liveMymarRecords()),
    fn () => array_map(fn ($row) => $row['section'] === 'clock' ? array_replace($row, ['generated_at' => '2026-10-30T18:00:00Z']) : $row, liveMymarRecords()),
    fn () => [...liveMymarRecords(), ['section' => 'private', 'value' => 'CANARY']],
]);
