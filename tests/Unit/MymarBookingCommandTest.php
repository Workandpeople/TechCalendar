<?php

use App\Services\Mymar\MymarBookingCommand;

function mymarPlacePayload(array $changes = []): array
{
    return array_replace(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => '11111111-1111-4111-8111-111111111111', 'action' => 'place', 'dossier_id' => 20,
        'expected_revision' => str_repeat('a', 64), 'cofrac_actor_id' => 101, 'technician_id' => 5,
        'starts_at' => '2026-11-02T10:40:00+01:00', 'duration_minutes' => 120], $changes);
}

it('has distinct initial and after-works transitions without accepting arbitrary target states', function () {
    expect(MymarBookingCommand::targetStatus(1, 'place'))->toBe(3)
        ->and(MymarBookingCommand::targetStatus(14, 'place'))->toBe(16)
        ->and(MymarBookingCommand::targetStatus(1, 'problem'))->toBe(2)
        ->and(MymarBookingCommand::targetStatus(14, 'problem'))->toBe(15)
        ->and(fn () => MymarBookingCommand::targetStatus(3, 'place'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MymarBookingCommand::targetStatus(15, 'problem'))->toThrow(InvalidArgumentException::class);
});

it('normalizes explicit UTC to Paris and retains the requested local clock time', function () {
    $local = MymarBookingCommand::fromArray(mymarPlacePayload());
    $utc = MymarBookingCommand::fromArray(mymarPlacePayload(['starts_at' => '2026-11-02T09:40:00+00:00']));
    expect($local->payload['starts_at'])->toBe('2026-11-02T10:40:00+01:00')
        ->and($utc->fingerprint())->toBe($local->fingerprint());
    $shuffled = array_reverse(mymarPlacePayload(), true);
    expect(MymarBookingCommand::fromArray($shuffled)->fingerprint())->toBe($local->fingerprint());
});

it('refuses unsupported time forms and does not silently shift a bad Paris offset', function (string $time) {
    expect(fn () => MymarBookingCommand::fromArray(mymarPlacePayload(['starts_at' => $time])))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '2026-11-02 10:40:00', '2026-11-02T10:40:00Z', '2026-11-02T10:40:00+02:00',
    '2026-02-31T10:40:00+01:00', '2026-11-02T25:40:00+01:00', '2026-11-02T10:40:01+01:00',
    '2026-10-25T02:30:00+01:00', '2026-10-25T02:30:00+02:00',
    '2026-03-29T02:30:00+01:00', '2026-11-02T10:40:00+15:00',
]);

it('rejects hours that cannot be stored losslessly in the MYMAR integer hours field', function (mixed $duration) {
    expect(fn () => MymarBookingCommand::fromArray(mymarPlacePayload(['duration_minutes' => $duration])))
        ->toThrow(InvalidArgumentException::class);
})->with([30, 45, 90, 0, -60, 540, '60', 60.5, null]);

it('does not allow spoofed authors, statuses, SQL values or force flags', function (array $changes) {
    expect(fn () => MymarBookingCommand::fromArray(mymarPlacePayload($changes)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [['author_id' => 1]], [['status_id' => 16]], [['force' => true]], [['technician_id' => '1 OR 1=1']],
    [['dossier_id' => -1]], [['cofrac_actor_id' => '101']], [['request_id' => 'not-a-uuid']],
    [['expected_revision' => 'unknown']], [['version' => '1']], [['action' => 'delete']],
]);

it('preserves plain problem text and recall time, with a stable canonical command', function () {
    $data = mymarPlacePayload(['action' => 'problem']);
    unset($data['technician_id'], $data['starts_at'], $data['duration_minutes']);
    $command = MymarBookingCommand::fromArray([...$data, 'comment' => "Pas de reponse\nRappeler demain", 'recall_at' => '2026-11-03T09:00:00+00:00']);
    expect($command->payload['comment'])->toBe("Pas de reponse\nRappeler demain")
        ->and($command->payload['reason'])->toBeNull()
        ->and($command->payload['recall_at'])->toBe('2026-11-03T10:00:00+01:00');
    foreach (['', '<img src=x onerror=alert(1)>', str_repeat('x', 256), "abc\0def"] as $text) {
        expect(fn () => MymarBookingCommand::fromArray([...$data, 'comment' => $text]))->toThrow(InvalidArgumentException::class);
    }
});
