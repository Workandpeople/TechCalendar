<?php

use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarPilotConfiguration;
use Tests\Fixtures\MymarPilotFixture;

it('projects only minimal occupations and retains explicit unconfirmed policy warnings', function () {
    $pilot = MymarPilotFixture::pilot();
    $now = new DateTimeImmutable('2026-11-02T10:00:00Z');
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($pilot, $now), new MymarObservationPolicy, $now, $pilot->technicianMap);
    $cache = MymarCachedObservation::project($report, $pilot, $now);
    expect($cache['periods'])->toHaveCount(1)->and($cache['periods'][0]['cofrac_user_id'])->toBe(101)
        ->and($cache['warnings'])->toBe(MymarCachedObservation::EXPECTED_WARNINGS)
        ->and(json_encode($cache))->not->toContain('email', 'address', 'password', 'token', 'customer', 'editable');
    MymarCachedObservation::validate($cache, $pilot);
});

it('refuses incomplete malformed stale or anomalous observations instead of publishing partial free time', function (Closure $change) {
    $pilot = MymarPilotFixture::pilot();
    $now = new DateTimeImmutable('2026-11-02T10:00:00Z');
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($pilot, $now), new MymarObservationPolicy, $now, $pilot->technicianMap);
    expect(fn () => MymarCachedObservation::project($change($report), $pilot, $now))->toThrow(RuntimeException::class);
})->with([
    fn ($r) => array_replace($r, ['snapshot_complete' => false]),
    fn ($r) => array_replace($r, ['can_affect_planning' => true]),
    fn ($r) => array_replace($r, ['scope_technician_ids' => [12]]),
    fn ($r) => array_replace($r, ['issues' => [['code' => 'snapshot_stale']]]),
    fn ($r) => array_replace($r, ['issues' => [['code' => 'invalid_duration']]]),
    function ($r) {
        $r['candidates'][0]['cofrac_user_id'] = 999;

        return $r;
    },
    function ($r) {
        $r['candidates'][0]['duration_minutes'] = 120;

        return $r;
    },
]);

it('refuses cache tampering and configuration drift', function (Closure $change) {
    $pilot = MymarPilotFixture::pilot();
    $now = new DateTimeImmutable('2026-11-02T10:00:00Z');
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($pilot, $now), new MymarObservationPolicy, $now, $pilot->technicianMap);
    $cache = MymarCachedObservation::project($report, $pilot, $now);
    expect(fn () => MymarCachedObservation::validate($change($cache), $pilot))->toThrow(RuntimeException::class);
})->with([
    fn ($v) => array_replace($v, ['fingerprint' => 'other']),
    fn ($v) => array_replace($v, ['warnings' => ['unexpected']]),
    fn ($v) => array_replace($v, ['generated_at' => '2026-02-31T12:00:00+00:00']),
    fn ($v) => array_replace($v, ['periods' => [...$v['periods'], ...$v['periods']]]),
    function ($v) {
        $v['periods'][0]['address'] = 'private';

        return $v;
    },
]);

it('never accepts a configuration that activates planning or ambiguous bindings', function () {
    $data = ['format' => 'cofrac.mymar-observation-pilot', 'version' => 1, 'sync_enabled' => true, 'display_enabled' => true,
        'blocking_enabled' => true, 'generation' => str_repeat('a', 32), 'bindings' => [['source_technician_id' => 11, 'cofrac_user_id' => 101]]];
    expect(fn () => MymarPilotConfiguration::fromArray($data))->toThrow(RuntimeException::class);
    $data['blocking_enabled'] = false;
    $data['bindings'][] = ['source_technician_id' => 12, 'cofrac_user_id' => 101];
    expect(fn () => MymarPilotConfiguration::fromArray($data))->toThrow(RuntimeException::class);
});
