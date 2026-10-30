<?php

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;

function mymarObservationRow(array $overrides = []): array
{
    return array_replace(['rdv_id' => 12317, 'dossier_id' => 91, 'technician_id' => 26, 'status_id' => 16,
        'is_old' => 0, 'stored_start' => '2026-11-02 09:30:00', 'raw_duration' => 1], $overrides);
}

function mymarObservationSnapshot(array $overrides = []): array
{
    return array_replace(['format' => MymarObservation::FORMAT, 'version' => 1, 'complete' => true,
        'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours',
        'generated_at' => '2026-10-30T15:47:52Z', 'window_start' => '2026-11-01T23:00:00Z', 'window_end' => '2026-11-08T23:00:00Z',
        'technician_ids' => [26, 32], 'status_ids' => [3, 16], 'appointments' => [mymarObservationRow()]], $overrides);
}

function inspectMymarSnapshot(array $snapshot, ?MymarObservationPolicy $policy = null, array $map = []): array
{
    return (new MymarObservation)->inspect($snapshot, $policy ?? new MymarObservationPolicy,
        new DateTimeImmutable('2026-10-30T15:48:52Z'), $map);
}

it('preserves the observed local 09:30 and marks hours and timezone interpretation as provisional', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot());
    expect($report['candidates'])->toHaveCount(1);
    $row = $report['candidates'][0];
    expect($row)->toMatchArray(['source_key' => 'mymar:12317', 'source_dossier_id' => 91, 'duration_minutes' => 60,
        'starts_at' => '2026-11-02T09:30:00+01:00', 'ends_at' => '2026-11-02T10:30:00+01:00',
        'starts_at_utc' => '2026-11-02T08:30:00+00:00', 'duration_estimated' => true, 'cofrac_user_id' => null,
        'time_interpretation_confirmed' => false, 'location_precision' => 'unknown', 'visit_phase' => 'after_works', 'editable' => false]);
    expect(array_column($report['issues'], 'code'))->toContain('duration_unit_assumed', 'timezone_assumed', 'status_scope_unconfirmed', 'unmapped_technician');
    expect($report['connector_enabled'])->toBeFalse()->and($report['can_affect_planning'])->toBeFalse()->and($report['database_writes'])->toBeFalse();
});

it('normalizes two hours and keeps distinct appointments for the same dossier', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['appointments' => [
        mymarObservationRow(['raw_duration' => 2]), mymarObservationRow(['rdv_id' => 12318, 'status_id' => 3, 'stored_start' => '2026-11-02 13:00:00']),
    ]]));
    expect(array_column($report['candidates'], 'duration_minutes'))->toBe([120, 60])
        ->and(array_column($report['candidates'], 'source_dossier_id'))->toBe([91, 91])
        ->and(array_column($report['candidates'], 'visit_phase'))->toBe(['after_works', 'initial']);
});

it('does not invent a duration or normalize invalid numeric values', function (mixed $duration) {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['appointments' => [mymarObservationRow(['raw_duration' => $duration])]]));
    expect($report['candidates'])->toBe([])->and(array_column($report['issues'], 'code'))->toContain('invalid_duration');
})->with([null, 0, -1, 25, '1', 'PRIVATE-SECRET', INF, NAN, 0.001]);

it('supports an explicit duration policy rather than baking the assumption into the row format', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['duration_unit' => 'minutes', 'appointments' => [mymarObservationRow(['raw_duration' => 90])]]),
        new MymarObservationPolicy(durationUnit: 'minutes', durationUnitConfirmed: true));
    expect($report['candidates'][0]['duration_minutes'])->toBe(90)->and($report['candidates'][0]['duration_estimated'])->toBeFalse();
});

it('never maps technicians automatically and rejects many source identities merged into one target', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(), map: [26 => 151]);
    expect($report['candidates'][0]['cofrac_user_id'])->toBe(151)
        ->and($report['candidates'][0]['mapping_basis'])->toBe('operator_supplied_not_runtime_verified')
        ->and($report['planning_ready'])->toBeFalse();
    expect(fn () => inspectMymarSnapshot(mymarObservationSnapshot(), map: [26 => 151, 32 => 151]))->toThrow(InvalidArgumentException::class);
    expect(fn () => inspectMymarSnapshot(mymarObservationSnapshot(), map: [26 => '151']))->toThrow(InvalidArgumentException::class);
});

it('retains an unresolved occupation when the source technician is missing rather than treating it as free time', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['appointments' => [mymarObservationRow(['technician_id' => null])]]));
    expect($report['candidates'])->toHaveCount(1)->and($report['candidates'][0]['cofrac_user_id'])->toBeNull()
        ->and(array_column($report['issues'], 'code'))->toContain('missing_source_technician');
});

it('rejects duplicate appointment IDs and data outside the requested scope', function (array $snapshot) {
    expect(fn () => inspectMymarSnapshot($snapshot))->toThrow(UnexpectedValueException::class);
})->with([
    fn () => mymarObservationSnapshot(['appointments' => [mymarObservationRow(), mymarObservationRow()]]),
    fn () => mymarObservationSnapshot(['appointments' => [mymarObservationRow(['technician_id' => 999])]]),
    fn () => mymarObservationSnapshot(['appointments' => [mymarObservationRow(['status_id' => 99])]]),
    fn () => mymarObservationSnapshot(['appointments' => [mymarObservationRow(['is_old' => false])]]),
    fn () => mymarObservationSnapshot(['appointments' => [mymarObservationRow(['email' => 'PRIVATE@example.test'])]]),
    fn () => mymarObservationSnapshot(['appointments' => array_fill(0, 2001, mymarObservationRow())]),
    fn () => mymarObservationSnapshot(['window_end' => '2026-12-12T00:00:00Z']),
    fn () => mymarObservationSnapshot(['window_start' => '2026-11-02 09:00:00']),
    fn () => mymarObservationSnapshot(['technician_ids' => [26, 26]]),
    fn () => mymarObservationSnapshot(['status_ids' => [3]]),
    fn () => mymarObservationSnapshot(['window_start' => '2026-11-02T25:00:00Z']),
]);

it('never treats old or boundary appointments as an overlap of the observation window', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['window_start' => '2026-11-02T09:30:00Z', 'window_end' => '2026-11-02T10:30:00Z', 'appointments' => [
        mymarObservationRow(), // Ends at the window start.
        mymarObservationRow(['rdv_id' => 12318, 'stored_start' => '2026-11-02 11:30:00']), // Starts at its end.
        mymarObservationRow(['rdv_id' => 12319, 'is_old' => 1, 'stored_start' => null, 'raw_duration' => null]),
        mymarObservationRow(['rdv_id' => 12320, 'stored_start' => '2026-11-02 10:45:00']),
    ]]));
    expect(array_column($report['candidates'], 'source_rdv_id'))->toBe([12320])
        ->and($report['counts']['excluded_old'])->toBe(1)->and($report['counts']['excluded_outside_window'])->toBe(2);
});

it('explicitly reports partial stale and future snapshots instead of certifying availability', function () {
    foreach (['2026-10-30T13:00:00Z' => 'snapshot_stale', '2026-10-30T17:00:00Z' => 'snapshot_from_future'] as $generated => $issue) {
        $report = inspectMymarSnapshot(mymarObservationSnapshot(['generated_at' => $generated, 'complete' => false, 'appointments' => []]));
        expect(array_column($report['issues'], 'code'))->toContain($issue, 'partial_snapshot')
            ->and($report['quality_checks_passed'])->toBeFalse()->and($report['can_affect_planning'])->toBeFalse();
    }
});

it('does not silently resolve nonexistent or ambiguous wall times during clock changes', function (string $date, string $code) {
    $day = str_starts_with($date, '2026-02-30') ? '2026-02-28' : substr($date, 0, 10);
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['window_start' => $day.'T00:00:00Z', 'window_end' => $day.'T23:00:00Z',
        'appointments' => [mymarObservationRow(['stored_start' => $date])]]));
    expect($report['candidates'])->toBe([])->and(array_column($report['issues'], 'code'))->toContain($code);
})->with([
    ['2026-03-29 02:30:00', 'nonexistent_local_time'],
    ['2026-10-25 02:30:00', 'ambiguous_local_time'],
    ['2026-02-30 10:30:00', 'invalid_wall_time'],
]);

it('adds elapsed duration across a clock change and never depends on PHP default timezone', function () {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set('Pacific/Honolulu');
        $report = inspectMymarSnapshot(mymarObservationSnapshot(['window_start' => '2026-03-28T23:00:00Z', 'window_end' => '2026-03-29T23:00:00Z',
            'appointments' => [mymarObservationRow(['stored_start' => '2026-03-29 01:30:00', 'raw_duration' => 2])]]));
        expect($report['candidates'][0]['starts_at'])->toBe('2026-03-29T01:30:00+01:00')
            ->and($report['candidates'][0]['ends_at'])->toBe('2026-03-29T04:30:00+02:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('stays inert even when every interpretation and supplied mapping has been confirmed', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(), new MymarObservationPolicy(timezoneConfirmed: true,
        durationUnitConfirmed: true, statusScopeConfirmed: true), [26 => 151]);
    expect($report['quality_checks_passed'])->toBeTrue()->and($report['issues'])->toBe([])
        ->and($report['connector_enabled'])->toBeFalse()->and($report['planning_ready'])->toBeFalse()
        ->and($report['can_affect_planning'])->toBeFalse()->and($report['http_requests'])->toBeFalse();
});

it('supports a deliberately selected UTC interpretation without borrowing the server timezone', function () {
    $report = inspectMymarSnapshot(mymarObservationSnapshot(['source_timezone' => 'UTC']), new MymarObservationPolicy(sourceTimezone: 'UTC'));
    expect($report['candidates'][0]['starts_at'])->toBe('2026-11-02T09:30:00+00:00')
        ->and($report['candidates'][0]['time_interpretation_confirmed'])->toBeFalse();
});

it('rejects unsafe policies before consuming any payload', function () {
    foreach ([['durationUnit' => 'days'], ['sourceTimezone' => 'SERVER_DEFAULT'], ['statusIds' => [3, 3]],
        ['statusIds' => ['3', '16']], ['maximumAgeSeconds' => 0]] as $arguments) {
        expect(fn () => new MymarObservationPolicy(...$arguments))->toThrow(InvalidArgumentException::class);
    }
});
