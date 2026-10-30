<?php

use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarOccupancySnapshot;
use App\Services\Mymar\MymarPilotConfiguration;
use Tests\Fixtures\MymarPilotFixture;

function occupancyFixture(): array
{
    $pilot = MymarPilotFixture::pilot([11 => 101, 12 => 102]);
    $now = new DateTimeImmutable('2026-11-02T10:00:00Z');
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($pilot, $now), new MymarObservationPolicy, $now, $pilot->technicianMap);
    $cache = MymarCachedObservation::project($report, $pilot, $now);

    return [$pilot, $now, $cache];
}

it('compares half-open absolute intervals without treating adjacent appointments as overlaps', function (string $start, string $end, string $expected) {
    [$pilot, $now, $cache] = occupancyFixture();
    $snapshot = MymarOccupancySnapshot::fromCache($pilot, $cache, false, $now);
    $result = $snapshot->inspect(101, new DateTimeImmutable($start), new DateTimeImmutable($end));
    expect($result)->toMatchArray(['known' => true, 'in_pilot' => true, 'state' => $expected, 'blocking_enabled' => false]);
    if ($expected === 'overlap') {
        expect($result['overlaps'][0]['source_rdv_id'])->toBe(555);
    } else {
        expect($result['overlaps'])->toBe([]);
    }
})->with([
    ['2026-11-03T08:00:00Z', '2026-11-03T08:30:00Z', 'no_overlap'],
    ['2026-11-03T09:30:00Z', '2026-11-03T10:00:00Z', 'no_overlap'],
    ['2026-11-03T08:30:00Z', '2026-11-03T09:30:00Z', 'overlap'],
    ['2026-11-03T09:45:00+01:00', '2026-11-03T10:00:00+01:00', 'overlap'],
    ['2026-11-03T07:00:00Z', '2026-11-03T11:00:00Z', 'overlap'],
]);

it('never calls an unknown cache or an uncovered window free', function (string $mode, string $expected) {
    [$pilot, $now, $cache] = occupancyFixture();
    $failed = false;
    if ($mode === 'missing') {
        $cache = null;
    }
    if ($mode === 'stale') {
        $cache['generated_at'] = $now->modify('-301 seconds')->format(DATE_ATOM);
    }
    if ($mode === 'future') {
        $cache['generated_at'] = $now->modify('+61 seconds')->format(DATE_ATOM);
    }
    if ($mode === 'invalid') {
        $cache['fingerprint'] = 'changed';
    }
    if ($mode === 'failure') {
        $failed = true;
    }
    $snapshot = MymarOccupancySnapshot::fromCache($pilot, $cache, $failed, $now);
    $from = new DateTimeImmutable($mode === 'coverage' ? '2026-12-01T10:00:00Z' : '2026-11-03T08:30:00Z');
    $to = $mode === 'window' ? $from : $from->modify('+1 hour');
    expect($snapshot->inspect(101, $from, $to))->toMatchArray(['known' => false, 'state' => $expected, 'overlaps' => [], 'blocking_enabled' => false]);
    expect($snapshot->inspect(999, $from, $to))->toMatchArray(['in_pilot' => false, 'state' => 'outside_pilot']);
})->with([
    ['missing', 'unavailable'], ['stale', 'stale'], ['future', 'future'], ['invalid', 'invalid'],
    ['failure', 'degraded'], ['coverage', 'outside_coverage'], ['window', 'invalid_window'],
]);

it('keeps a completely empty covered snapshot distinct from a failed read and never activates policy or travel checks', function () {
    [$pilot, $now, $cache] = occupancyFixture();
    $cache['periods'] = [];
    $snapshot = MymarOccupancySnapshot::fromCache($pilot, $cache, false, $now);
    expect($snapshot->summary())->toMatchArray(['cache_ready' => true, 'periods' => 0, 'policy_confirmation_required' => true,
        'travel_evaluated' => false, 'blocking_enabled' => false]);
    expect($snapshot->inspect(102, $now, $now->modify('+1 hour'))['state'])->toBe('no_overlap');
    expect(MymarOccupancySnapshot::fromCache(MymarPilotConfiguration::disabled(), null, false, $now)->summary()['state'])->toBe('disabled');
});

it('does not confuse the two occurrences of a Paris daylight-saving hour', function () {
    [$pilot, , $cache] = occupancyFixture();
    $now = new DateTimeImmutable('2026-10-25T00:00:00Z');
    $cache['window_start'] = '2026-10-24T00:00:00+00:00';
    $cache['generated_at'] = $now->format(DATE_ATOM);
    $cache['periods'][0]['starts_at_utc'] = '2026-10-25T00:30:00+00:00';
    $cache['periods'][0]['ends_at_utc'] = '2026-10-25T01:30:00+00:00';
    $snapshot = MymarOccupancySnapshot::fromCache($pilot, $cache, false, $now);
    expect($snapshot->inspect(101, new DateTimeImmutable('2026-10-25T02:45:00+02:00'), new DateTimeImmutable('2026-10-25T02:55:00+02:00'))['state'])->toBe('overlap');
    expect($snapshot->inspect(101, new DateTimeImmutable('2026-10-25T02:45:00+01:00'), new DateTimeImmutable('2026-10-25T02:55:00+01:00'))['state'])->toBe('no_overlap');
});
