<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Models\MymarBookingRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable result tracking only. Native slot reservations/authorization must precede enqueue. */
final readonly class MymarBookingOutbox
{
    public function __construct(private MymarBookingClient $client, private bool $enabled = false) {}

    public function enqueue(MymarBookingCommand $command, User $actor): MymarBookingRequest
    {
        $this->assertEnabled();
        $this->client->assertEnabled(write: true);
        if ($command->payload['cofrac_actor_id'] !== $actor->id) {
            throw new RuntimeException('mymar.booking_actor_mismatch');
        }
        try {
            return DB::transaction(function () use ($command): MymarBookingRequest {
                $data = $command->payload;
                $author = User::query()->find($data['cofrac_actor_id']);
                if (! $author || $author->isExternal() || (! $author->admin && ! in_array($author->role, [0, 1], true))) {
                    throw new RuntimeException('mymar.booking_author_not_allowed');
                }
                if ($existing = MymarBookingRequest::query()->find($data['request_id'])) {
                    return $this->sameCommand($existing, $command);
                }
                $record = new MymarBookingRequest;
                $record->forceFill(['id' => $data['request_id'], 'author_id' => $author->id, 'source_dossier_id' => $data['dossier_id'],
                    'active_dossier_id' => $data['dossier_id'], 'fingerprint' => $command->fingerprint(), 'command' => $data, 'status' => 'queued'])->save();

                return $record;
            });
        } catch (QueryException $error) {
            // Unique keys are the final arbiter for concurrent enqueue, not a preflight count.
            if (! in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new RuntimeException('mymar.booking_local_storage_unavailable');
            }
            if ($existing = MymarBookingRequest::query()->find($command->payload['request_id'])) {
                return $this->sameCommand($existing, $command);
            }
            throw new RuntimeException('mymar.booking_local_dossier_busy');
        }
    }

    public function process(string $requestId): MymarBookingRequest
    {
        $this->assertEnabled();
        $this->client->assertEnabled();
        if (! MymarBookingCommand::isRequestId($requestId)) {
            throw new RuntimeException('mymar.invalid_booking_request_id');
        }
        $claim = DB::transaction(function () use ($requestId): ?array {
            $record = MymarBookingRequest::query()->lockForUpdate()->find($requestId);
            if (! $record) {
                throw new RuntimeException('mymar.booking_local_request_missing');
            }
            if (in_array($record->status, ['confirmed', 'rejected'], true) || ($record->lease_until ?? 0) > time()) {
                return null;
            }
            $command = MymarBookingCommand::fromArray($record->command);
            $this->sameCommand($record, $command);
            $submit = $record->status === 'queued';
            if ($submit) {
                $this->client->assertEnabled(write: true);
                $author = User::query()->find($record->author_id);
                if (! $author || $author->isExternal() || (! $author->admin && ! in_array($author->role, [0, 1], true))) {
                    $record->forceFill(['status' => 'rejected', 'reason' => 'booking_author_not_allowed', 'active_dossier_id' => null, 'finished_at' => now()])->save();

                    return null;
                }
            }
            // A crashed/expired dispatch is reconciled by GET only, never by a second POST.
            $claimId = (string) Str::uuid();
            $record->forceFill(['status' => 'dispatching', 'claim_id' => $claimId, 'lease_until' => time() + 30, 'attempts' => $record->attempts + 1])->save();

            return [$command, $submit, $claimId];
        });
        if ($claim !== null) {
            [$command, $submit, $claimId] = $claim;
            $attempt = $submit ? $this->client->submit($command) : $this->client->result($command);
            $terminal = in_array($attempt->state, ['confirmed', 'rejected'], true);
            MymarBookingRequest::query()->whereKey($requestId)->where('claim_id', $claimId)->where('status', 'dispatching')
                ->update(['status' => $attempt->state, 'reason' => $attempt->reason,
                    'result' => $attempt->result === null ? null : json_encode($attempt->result, JSON_THROW_ON_ERROR),
                    'claim_id' => null, 'lease_until' => $terminal ? null : time() + 30,
                    'active_dossier_id' => $terminal ? null : $command->payload['dossier_id'], 'finished_at' => $terminal ? now() : null]);
        }

        return MymarBookingRequest::query()->findOrFail($requestId);
    }

    private function sameCommand(MymarBookingRequest $record, MymarBookingCommand $command): MymarBookingRequest
    {
        if (! hash_equals($record->fingerprint, $command->fingerprint()) || $record->author_id !== $command->payload['cofrac_actor_id']
            || $record->source_dossier_id !== $command->payload['dossier_id']) {
            throw new RuntimeException('mymar.booking_local_idempotency_conflict');
        }

        return $record;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled) {
            throw new RuntimeException('mymar.booking_disabled');
        }
    }
}
