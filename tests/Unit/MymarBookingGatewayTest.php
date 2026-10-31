<?php

use Cofrac2\Deploy\Mymar\MymarBookingFailure;
use Cofrac2\Deploy\Mymar\MymarBookingGateway;
use Cofrac2\Deploy\Mymar\MymarBookingSource;
use Cofrac2\Deploy\Mymar\MymarBookingSqlAccess;

require_once __DIR__.'/../../tools/deploy/mymar/booking/MymarBookingFailure.php';
require_once __DIR__.'/../../tools/deploy/mymar/booking/MymarBookingGateway.php';
require_once __DIR__.'/../../tools/deploy/mymar/booking/MymarBookingSource.php';
require_once __DIR__.'/../../tools/deploy/mymar/booking/MymarBookingSqlAccess.php';

function mymarBookingBody(): string
{
    return json_encode(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => '11111111-1111-4111-8111-111111111111', 'action' => 'place', 'dossier_id' => 20,
        'expected_revision' => str_repeat('a', 64), 'cofrac_actor_id' => 101, 'technician_id' => 5,
        'starts_at' => '2026-11-02T10:40:00+01:00', 'duration_minutes' => 60], JSON_THROW_ON_ERROR);
}

it('keeps the new gateway inert unless enabled, authenticated locally and strictly validated', function (array $changes, int $status) {
    $calls = 0;
    $called = function () use (&$calls) {
        $calls++;

        return [];
    };
    $token = hash('sha256', 'synthetic-booking-token');
    $gateway = new MymarBookingGateway($changes['enabled'] ?? true, $token, $called, $called, $called);
    $request = array_replace(['method' => 'POST', 'path' => '/v1/booking-commands', 'query' => '',
        'contentType' => 'application/json', 'body' => mymarBookingBody(), 'authorization' => 'Bearer '.$token,
        'peer' => '127.0.0.1'], $changes);
    unset($request['enabled']);
    $response = $gateway->handle(...[...$request, 'now' => new DateTimeImmutable('2026-11-01T10:00:00Z')]);
    expect($response['status'])->toBe($status)->and($calls)->toBe(0)
        ->and($response['body']['source_committed'])->toBeFalse();
})->with([
    [['enabled' => false], 503], [['peer' => '192.0.2.1'], 403], [['authorization' => ''], 403],
    [['method' => 'PUT'], 405], [['path' => '/api/ajouter-rdv'], 404], [['contentType' => 'text/plain'], 415],
    [['query' => 'force=1'], 415], [['body' => '{}'], 422], [['body' => str_repeat('x', 16385)], 413],
    [['method' => 'GET', 'path' => '/v1/pending-bookings', 'body' => '', 'query' => 'limit=0'], 422],
    [['method' => 'GET', 'path' => '/v1/pending-bookings', 'body' => '', 'query' => 'limit=50&limit=100'], 422],
    [['method' => 'GET', 'path' => '/v1/pending-bookings', 'body' => '', 'query' => 'token=secret'], 422],
    [['method' => 'GET', 'path' => '/v1/pending-bookings', 'body' => '', 'query' => 'after=%GG'], 422],
]);

it('rejects duplicate and escaped duplicate command keys', function () {
    foreach (['"dossier_id":21,', '"dossier_\\u0069d":21,'] as $duplicate) {
        $body = '{'.$duplicate.substr(mymarBookingBody(), 1);
        expect(fn () => MymarBookingGateway::command($body))->toThrow(InvalidArgumentException::class);
    }
    $normal = MymarBookingGateway::command(mymarBookingBody());
    expect($normal->payload['dossier_id'])->toBe(20);
});

it('does not misrepresent a missing receipt or source timeout as a known failed placement', function (string $mode) {
    $token = hash('sha256', 'synthetic-booking-token');
    $read = fn () => null;
    $write = function () use ($mode) {
        if ($mode === 'source_conflict') {
            throw new MymarBookingFailure('booking_source_changed');
        }
        throw new RuntimeException('PASSWORD_CANARY SQL private@example.test');
    };
    $gateway = new MymarBookingGateway(true, $token, $read, $write, $read);
    $isMissing = $mode === 'missing_receipt';
    $response = $gateway->handle($isMissing ? 'GET' : 'POST',
        '/v1/booking-commands'.($isMissing ? '/11111111-1111-4111-8111-111111111111' : ''), '', 'application/json',
        $isMissing ? '' : mymarBookingBody(), 'Bearer '.$token, '127.0.0.1', new DateTimeImmutable);
    expect($response['body']['source_committed'])->toBe($mode === 'source_conflict' ? false : null)
        ->and($response['body']['outcome'])->toBe($mode === 'source_conflict' ? 'rejected' : 'unknown')
        ->and(json_encode($response))->not->toContain('CANARY', '@', 'SQL');
})->with(['missing_receipt', 'timeout', 'source_conflict']);

it('reads only the selected pending page with bounded defaults', function () {
    $seen = null;
    $token = hash('sha256', 'synthetic-booking-token');
    $gateway = new MymarBookingGateway(true, $token,
        function ($after, $limit) use (&$seen) {
            $seen = [$after, $limit];

            return ['items' => []];
        }, fn () => [], fn () => null);
    $result = $gateway->handle('GET', '/v1/pending-bookings', 'after=25&limit=10', '', '', 'Bearer '.$token, '::1', new DateTimeImmutable);
    expect($seen)->toBe([25, 10])->and($result['status'])->toBe(200);
});

it('does not connect to SQL or issue statements when the adapter remains disabled', function () {
    $database = new class extends PDO
    {
        public function __construct() {}

        public function getAttribute(int $attribute): mixed
        {
            throw new LogicException('No SQL calls permitted.');
        }
    };
    $source = new MymarBookingSource($database, 99, [5]);
    foreach ([fn () => $source->pending(), fn () => $source->execute(MymarBookingGateway::command(mymarBookingBody()), new DateTimeImmutable),
        fn () => $source->result('11111111-1111-4111-8111-111111111111')] as $call) {
        try {
            $call();
            $this->fail('Disabled source accepted a call.');
        } catch (MymarBookingFailure $error) {
            expect($error->reason)->toBe('booking_disabled');
        }
    }
});

it('creates only a separately locked account with column grants, never alters the observation account', function () {
    $sql = MymarBookingSqlAccess::createSql('cofrac_mymar_rw_abcdef01', 'Mb3!'.str_repeat('a', 64));
    expect($sql)->toContain('ACCOUNT LOCK', 'cofrac_mymar_integration')
        ->and($sql)->not->toContain('ACCOUNT UNLOCK', 'cofrac_mymar_ro_', 'GRANT ALL', 'DELETE', 'ALTER', 'TRIGGER', 'GRANT OPTION', 'UPDATE (`password`');
});

it('validates the exact column privileges and refuses broader or missing grants', function () {
    $grants = ['GRANT USAGE ON *.* TO `cofrac_mymar_rw_abcdef01`@`localhost`'];
    foreach (MymarBookingSqlAccess::privileges() as $table => $operations) {
        $parts = [];
        foreach ($operations as $verb => $columns) {
            $parts[] = $verb.' ('.implode(', ', $columns).')';
        }
        $grants[] = 'GRANT '.implode(', ', $parts).' ON '.str_replace('.', '`.`', '`'.$table.'`').' TO `cofrac_mymar_rw_abcdef01`@`localhost`';
    }
    MymarBookingSqlAccess::validateGrants($grants);
    expect(fn () => MymarBookingSqlAccess::validateGrants([...$grants, 'GRANT ALL PRIVILEGES ON *.* TO `cofrac_mymar_rw_abcdef01`@`localhost`']))->toThrow(RuntimeException::class)
        ->and(fn () => MymarBookingSqlAccess::validateGrants(array_slice($grants, 0, -1)))->toThrow(RuntimeException::class)
        ->and(fn () => MymarBookingSqlAccess::validateGrants([...$grants, 'GRANT DELETE ON MYMAR.dossiers TO `cofrac_mymar_rw_abcdef01`@`localhost`']))->toThrow(RuntimeException::class);
});

it('includes beneficiary changes and every current appointment in the optimistic revision', function () {
    $record = ['id' => 1, 'client_id' => 2, 'status_id' => 14, 'updated_at' => '2026-11-01 10:00:00',
        'client_updated_at' => '2026-11-01 10:00:00', 'adress' => '3 rue test'];
    $rdv = ['id' => 3, 'dossier_id' => 1, 'date_rdv' => '2026-11-02 10:40:00', 'duree' => 1, 'is_old' => 0];
    $revision = MymarBookingSource::revision($record, [$rdv]);
    expect(MymarBookingSource::revision([...$record, 'adress' => '4 rue test'], [$rdv]))->not->toBe($revision)
        ->and(MymarBookingSource::revision([...$record, 'client_updated_at' => '2026-11-01 10:00:01'], [$rdv]))->not->toBe($revision)
        ->and(MymarBookingSource::revision($record, [[...$rdv, 'duree' => 2]]))->not->toBe($revision)
        ->and(MymarBookingSource::revision($record, []))->not->toBe($revision);
});
