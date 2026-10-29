<?php

use App\Services\Crm\AppointmentDateTime;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('parses Paris wall times independently of the PHP timezone', function (string $timezone) {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set($timezone);
        expect(AppointmentDateTime::parse('2026-01-15T16:30')->toIso8601String())->toBe('2026-01-15T16:30:00+01:00')
            ->and(AppointmentDateTime::parse('2026-07-15 16:30:00')->toIso8601String())->toBe('2026-07-15T16:30:00+02:00')
            ->and(AppointmentDateTime::parse('2026-07-15T14:30:00.123Z')->toIso8601String())->toBe('2026-07-15T16:30:00+02:00')
            ->and(AppointmentDateTime::parse('2026-01-15T17:30:00+0200')->toIso8601String())->toBe('2026-01-15T16:30:00+01:00')
            ->and(AppointmentDateTime::parse('2026-07-15T23:30:00-04:00')->toIso8601String())->toBe('2026-07-16T05:30:00+02:00')
            ->and(AppointmentDateTime::parse('2026-10-25T02:30')->toIso8601String())->toBe('2026-10-25T02:30:00+01:00');
    } finally {
        date_default_timezone_set($previous);
    }
})->with(['Europe/Paris', 'UTC', 'America/New_York']);

it('formats existing Paris database wall times without applying the PHP cast timezone', function (string $timezone) {
    expect(AppointmentDateTime::formatStored(Carbon::parse('2026-01-15 16:30:00', $timezone)))->toBe('2026-01-15T16:30:00+01:00')
        ->and(AppointmentDateTime::formatStored(Carbon::parse('2026-07-15 16:30:00', $timezone)))->toBe('2026-07-15T16:30:00+02:00')
        ->and(AppointmentDateTime::formatStored(null))->toBeNull();
})->with(['Europe/Paris', 'UTC', 'America/New_York']);

it('rejects invalid or nonexistent civil times instead of silently moving the appointment', function (string $input) {
    expect(fn () => AppointmentDateTime::parse($input))->toThrow(ValidationException::class);
})->with(['2026-03-29T02:30', '2026-02-30T16:30', '2026-07-15T24:00', '2026-07-15T16:60',
    '2026-07-15T16:30:60', '2026-07-15T16:30:00+15:00', '2026-07-15T16:30:00+14:01',
    '2026-07-15T16:30:00+02:60', 'next Thursday', '', '2026-07-15T16:30 Europe/London']);

it('preserves actual duration when crossing the spring DST boundary', function () {
    $start = AppointmentDateTime::parse('2026-03-29T01:30');
    $end = $start->copy()->addMinutes(60);
    expect($start->toIso8601String())->toBe('2026-03-29T01:30:00+01:00')
        ->and($end->toIso8601String())->toBe('2026-03-29T03:30:00+02:00')
        ->and($end->getTimestamp() - $start->getTimestamp())->toBe(3600);
    AppointmentDateTime::assertStorableRange($start, $end);
});

it('does not silently lose the first occurrence of an autumn hour in offset-free storage', function (string $input) {
    $start = AppointmentDateTime::parse($input);
    expect(fn () => AppointmentDateTime::assertStorableRange($start, $start->copy()->addMinutes(60)))
        ->toThrow(ValidationException::class);
})->with(['2026-10-25T02:30:00+02:00', '2026-10-25T01:30:00+02:00']);

it('can store the standard-time occurrence of the repeated autumn hour', function () {
    $start = AppointmentDateTime::parse('2026-10-25T02:30');
    $end = $start->copy()->addMinutes(60);
    AppointmentDateTime::assertStorableRange($start, $end);
    expect(AppointmentDateTime::formatStored($start))->toBe('2026-10-25T02:30:00+01:00')
        ->and(AppointmentDateTime::formatStored($end))->toBe('2026-10-25T03:30:00+01:00');
});
