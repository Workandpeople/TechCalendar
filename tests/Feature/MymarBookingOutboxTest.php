<?php

use App\Models\MymarBookingRequest;
use App\Models\User;
use App\Services\Mymar\MymarBookingClient;
use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarBookingOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => (string) Str::uuid(), 'action' => 'place', 'dossier_id' => 123,
        'expected_revision' => str_repeat('a', 64), 'cofrac_actor_id' => $this->actor->id,
        'technician_id' => 5, 'starts_at' => '2026-11-05T10:30:00+01:00', 'duration_minutes' => 60]);
    $this->client = new MymarBookingClient(Http::getFacadeRoot(), enabled: true, writesEnabled: true, token: str_repeat('0123456789abcdef', 4));
    $this->outbox = new MymarBookingOutbox($this->client, enabled: true);
    $this->receipt = fn (array $overrides = []) => array_replace(['format' => 'cofrac.mymar-booking-result', 'version' => 1,
        'request_id' => $this->command->payload['request_id'], 'action' => 'place', 'dossier_id' => 123, 'status_id' => 3,
        'rdv_id' => 456, 'starts_at' => '2026-11-05T10:30:00+01:00', 'duration_minutes' => 60, 'source_committed' => true], $overrides);
});

it('does nothing with the default disabled services', function () {
    $client = new MymarBookingClient(Http::getFacadeRoot());
    expect(fn () => (new MymarBookingOutbox($client))->enqueue($this->command, $this->actor))->toThrow(RuntimeException::class, 'mymar.booking_disabled');
    expect(fn () => $client->submit($this->command))->toThrow(RuntimeException::class, 'mymar.booking_disabled');
    expect(fn () => $client->result($this->command))->toThrow(RuntimeException::class, 'mymar.booking_disabled');
    expect(fn () => $client->pending())->toThrow(RuntimeException::class, 'mymar.booking_disabled');
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertNothingSent();
});

it('reads validated pending pages without enabling writes or creating native records', function () {
    $item = ['dossier_id' => 123, 'status_id' => 14, 'phase' => 'after_works', 'expected_revision' => str_repeat('b', 64),
        'first_name' => 'Test', 'last_name' => 'Beneficiary', 'address' => 'Test address', 'postal_code' => '75001', 'city' => 'Paris',
        'phone' => '', 'email' => null, 'latitude' => 48.86, 'longitude' => 2.34];
    Http::fake(fn () => Http::response(['format' => 'cofrac.mymar-pending-bookings', 'version' => 1, 'items' => [$item], 'next_after' => 123]));
    $client = new MymarBookingClient(Http::getFacadeRoot(), enabled: true, token: str_repeat('0123456789abcdef', 4));
    $page = $client->pending(100, 1);
    expect($page->items)->toBe([$item])->and($page->nextAfter)->toBe(123)->and(MymarBookingRequest::count())->toBe(0);
    expect(fn () => $client->submit($this->command))->toThrow(RuntimeException::class, 'mymar.booking_disabled');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://127.0.0.1:19847/v1/pending-bookings?after=100&limit=1');
});

it('validates page arguments and local connection settings before HTTP', function () {
    foreach ([[-1, 10], [0, 0], [0, 101]] as [$after, $limit]) {
        expect(fn () => $this->client->pending($after, $limit))->toThrow(RuntimeException::class, 'mymar.invalid_booking_page');
    }
    foreach ([19846, 80, 65536] as $port) {
        $client = new MymarBookingClient(Http::getFacadeRoot(), enabled: true, port: $port, token: str_repeat('0123456789abcdef', 4));
        expect(fn () => $client->pending())->toThrow(RuntimeException::class, 'mymar.invalid_booking_connection');
    }
    Http::assertNothingSent();
});

it('does not treat an unavailable pending page as an empty list', function () {
    Http::fake(fn () => Http::response(['error' => 'booking_disabled'], 503));
    expect(fn () => $this->client->pending())->toThrow(RuntimeException::class, 'mymar.booking_pending_unavailable');
    Http::assertSentCount(1);
});

it('ignores a stale worker response once its claim has been superseded', function () {
    $record = $this->outbox->enqueue($this->command, $this->actor);
    Http::fake(function () use ($record) {
        $record->refresh()->forceFill(['claim_id' => (string) Str::uuid(), 'lease_until' => time() + 30])->save();

        return Http::response(($this->receipt)());
    });
    expect($this->outbox->process($record->id)->status)->toBe('dispatching')->and($record->fresh()->result)->toBeNull()
        ->and($record->fresh()->active_dossier_id)->toBe(123);
    Http::assertSentCount(1);
});

it('encrypts command contents, enqueues idempotently and never sends from enqueue', function () {
    $command = MymarBookingCommand::fromArray(array_replace(array_intersect_key($this->command->payload,
        array_flip(['format', 'version', 'request_id', 'action', 'dossier_id', 'expected_revision', 'cofrac_actor_id'])),
        ['action' => 'problem', 'comment' => 'PRIVATE_REASON_CANARY']));
    $first = $this->outbox->enqueue($command, $this->actor);
    $second = $this->outbox->enqueue($command, $this->actor);
    expect($first->id)->toBe($second->id)->and(MymarBookingRequest::count())->toBe(1)
        ->and($first->command)->toBe($command->payload)->and($first->toArray())->not->toHaveKeys(['command', 'claim_id', 'fingerprint']);
    expect(DB::table('mymar_booking_requests')->value('command'))->not->toContain('PRIVATE_REASON_CANARY');
    Http::assertNothingSent();
});

it('rejects reuse with different content and competing active requests for the same dossier', function () {
    $this->outbox->enqueue($this->command, $this->actor);
    foreach ([['duration_minutes' => 120], ['cofrac_actor_id' => User::factory()->create(['role' => 0])->id]] as $changes) {
        $modified = MymarBookingCommand::fromArray(array_replace($this->command->payload, $changes));
        expect(fn () => $this->outbox->enqueue($modified, User::findOrFail($modified->payload['cofrac_actor_id'])))->toThrow(RuntimeException::class, 'mymar.booking_local_idempotency_conflict');
    }
    $other = MymarBookingCommand::fromArray(array_replace($this->command->payload, ['request_id' => (string) Str::uuid()]));
    expect(fn () => $this->outbox->enqueue($other, $this->actor))->toThrow(RuntimeException::class, 'mymar.booking_local_dossier_busy');
    expect(MymarBookingRequest::count())->toBe(1);
    Http::assertNothingSent();
});

it('confirms only a validated committed source receipt and never sends twice', function () {
    Http::fake(fn () => Http::response(($this->receipt)()));
    $record = $this->outbox->enqueue($this->command, $this->actor);
    $processed = $this->outbox->process($record->id);
    expect($processed->status)->toBe('confirmed')->and($processed->result)->toBe(($this->receipt)())
        ->and($processed->active_dossier_id)->toBeNull()->and($processed->finished_at)->not->toBeNull();
    expect($this->outbox->process($record->id)->status)->toBe('confirmed');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'http://127.0.0.1:19847/v1/booking-commands'
        && $request->data() === $this->command->payload && $request->hasHeader('Authorization', 'Bearer '.str_repeat('0123456789abcdef', 4)));
});

it('keeps timeouts uncertain and reconciles by GET with the original UUID only', function () {
    Http::fake(fn ($request) => $request->method() === 'POST'
        ? throw new ConnectionException('TOKEN_AND_PRIVATE_BODY_CANARY') : Http::response(($this->receipt)()));
    $record = $this->outbox->enqueue($this->command, $this->actor);
    expect($this->outbox->process($record->id)->status)->toBe('uncertain');
    expect($record->fresh()->reason)->toBe('booking_gateway_unavailable')->and($record->fresh()->active_dossier_id)->toBe(123);
    $record->forceFill(['lease_until' => 0])->save();
    expect($this->outbox->process($record->id)->status)->toBe('confirmed');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/'.$record->id));
});

it('does not assume failure or release a dossier on absent or invalid receipts', function (int $status, array $reply) {
    Http::fake(fn () => Http::response($reply, $status));
    $record = $this->outbox->enqueue($this->command, $this->actor);
    expect($this->outbox->process($record->id)->status)->toBe('uncertain')
        ->and($record->fresh()->active_dossier_id)->toBe(123)->and($record->fresh()->finished_at)->toBeNull();
})->with([
    'receipt not present' => [404, ['error' => 'booking_receipt_not_found', 'source_committed' => null, 'outcome' => 'unknown']],
    'storage error' => [503, ['error' => 'booking_storage_unavailable', 'source_committed' => null, 'outcome' => 'unknown']],
    'untrusted error message' => [503, ['error' => 'PASSWORD_CANARY']],
    'false success' => [200, ['source_committed' => false]],
]);

it('rejects mismatched receipts even when the gateway answers 200', function (array $change) {
    Http::fake(fn () => Http::response(($this->receipt)($change)));
    $record = $this->outbox->enqueue($this->command, $this->actor);
    expect($this->outbox->process($record->id)->status)->toBe('uncertain');
})->with([
    'different request' => [['request_id' => '11111111-1111-4111-8111-111111111111']],
    'different dossier' => [['dossier_id' => 124]], 'different action' => [['action' => 'problem']],
    'uncommitted' => [['source_committed' => false]], 'wrong status' => [['status_id' => 2]],
    'different hour' => [['starts_at' => '2026-11-05T11:30:00+01:00']], 'different duration' => [['duration_minutes' => 120]],
    'extra data' => [['email' => 'PRIVATE_CANARY']],
]);

it('records explicit noncommitted rejection without replaying a POST', function () {
    Http::fake(fn () => Http::response(['error' => 'booking_source_changed', 'outcome' => 'rejected', 'source_committed' => false], 409));
    $record = $this->outbox->enqueue($this->command, $this->actor);
    expect($this->outbox->process($record->id)->status)->toBe('rejected')->and($record->fresh()->active_dossier_id)->toBeNull();
    $this->outbox->process($record->id);
    Http::assertSentCount(1);
});

it('does not dispatch twice while a worker owns the lease', function () {
    $record = $this->outbox->enqueue($this->command, $this->actor);
    Http::fake(function () use ($record) {
        expect($this->outbox->process($record->id)->status)->toBe('dispatching');

        return Http::response(($this->receipt)());
    });
    expect($this->outbox->process($record->id)->status)->toBe('confirmed');
    Http::assertSentCount(1);
});

it('reconciles an abandoned dispatch without submitting it again, even after author deactivation', function () {
    $record = $this->outbox->enqueue($this->command, $this->actor);
    $record->forceFill(['status' => 'dispatching', 'claim_id' => (string) Str::uuid(), 'lease_until' => 0])->save();
    $this->actor->delete();
    Http::fake(fn () => Http::response(($this->receipt)()));
    expect($this->outbox->process($record->id)->status)->toBe('confirmed');
    Http::assertSent(fn ($request) => $request->method() === 'GET');
    Http::assertSentCount(1);
});

it('refuses external, technician and deleted actors without sending', function (string $kind) {
    match ($kind) {
        'external' => $this->actor->forceFill(['role' => 3])->save(),
        'technician' => $this->actor->forceFill(['role' => 2])->save(),
        'deleted' => $this->actor->delete(),
    };
    expect(fn () => $this->outbox->enqueue($this->command, $this->actor))->toThrow(RuntimeException::class, 'mymar.booking_author_not_allowed');
    Http::assertNothingSent();
})->with(['external', 'technician', 'deleted']);

it('checks author rights again before first dispatch', function () {
    $record = $this->outbox->enqueue($this->command, $this->actor);
    $this->actor->delete();
    expect($this->outbox->process($record->id)->status)->toBe('rejected')->and($record->fresh()->reason)->toBe('booking_author_not_allowed');
    Http::assertNothingSent();
});

it('never accepts a client-supplied actor ID in place of the authenticated actor', function () {
    $other = User::factory()->create(['role' => 0]);
    expect(fn () => $this->outbox->enqueue($this->command, $other))->toThrow(RuntimeException::class, 'mymar.booking_actor_mismatch');
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps uncertain state on redirects, HTML, oversized or compressed responses', function (string $body, array $headers) {
    Http::fake(fn () => Http::response($body, 200, $headers));
    $record = $this->outbox->enqueue($this->command, $this->actor);
    expect($this->outbox->process($record->id)->status)->toBe('uncertain');
})->with([
    'HTML login' => ['<html>login</html>', ['Content-Type' => 'text/html']],
    'oversized' => [str_repeat('a', 8193), ['Content-Type' => 'application/json']],
    'compressed' => ['{}', ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']],
]);
