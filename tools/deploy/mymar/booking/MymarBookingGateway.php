<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarBookingCommand;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** Separate protocol; never extends the active read-only occupations endpoint. */
final readonly class MymarBookingGateway
{
    public function __construct(
        private bool $enabled,
        #[\SensitiveParameter] private string $token,
        private Closure $pending,
        private Closure $execute,
        private Closure $result,
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || count(array_unique(str_split($token))) < 8) {
            throw new InvalidArgumentException('mymar.invalid_booking_credential');
        }
    }

    public function handle(string $method, string $path, string $query, string $contentType, string $body,
        #[\SensitiveParameter] string $authorization, string $peer, DateTimeImmutable $now): array
    {
        if (! $this->enabled) {
            return self::error(503, 'booking_disabled');
        }
        if (! in_array($peer, ['127.0.0.1', '::1'], true) || ! hash_equals('Bearer '.$this->token, $authorization)) {
            return self::error(403, 'booking_access_denied');
        }
        if (strlen($body) > 16384 || strlen($query) > 128) {
            return self::error(413, 'booking_request_too_large');
        }
        if ($method === 'GET' && $body !== '') {
            return self::error(422, 'invalid_booking_request');
        }
        try {
            if ($path === '/v1/pending-bookings') {
                if ($method !== 'GET') {
                    return self::error(405, 'booking_method_not_allowed');
                }
                try {
                    [$after, $limit] = self::page($query);
                } catch (Throwable) {
                    return self::error(422, 'invalid_booking_page');
                }
                $payload = ($this->pending)($after, $limit);
            } elseif (preg_match('~^/v1/booking-commands/([a-f0-9-]{36})$~D', $path, $matches)) {
                if ($method !== 'GET') {
                    return self::error(405, 'booking_method_not_allowed');
                }
                if ($query !== '' || ! MymarBookingCommand::isRequestId($matches[1])) {
                    return self::error(422, 'invalid_booking_request_id');
                }
                $payload = ($this->result)($matches[1]);
                if ($payload === null) {
                    // An absent receipt does not prove failure of an in-flight request.
                    return self::error(404, 'booking_receipt_not_found', 'unknown');
                }
            } elseif ($path === '/v1/booking-commands') {
                if ($method !== 'POST') {
                    return self::error(405, 'booking_method_not_allowed');
                }
                if ($query !== '' || strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
                    return self::error(415, 'booking_json_required');
                }
                try {
                    $command = self::command($body);
                } catch (Throwable) {
                    return self::error(422, 'invalid_booking_command');
                }
                $payload = ($this->execute)($command, $now);
            } else {
                return self::error(404, 'booking_endpoint_not_found');
            }
            if (! is_array($payload) || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 2097152) {
                return self::error(503, 'booking_response_invalid', 'unknown');
            }

            return ['status' => 200, 'body' => $payload];
        } catch (MymarBookingFailure $error) {
            if ($error->reason === 'booking_disabled') {
                return self::error(503, 'booking_disabled');
            }

            return self::error($error->httpStatus, $error->reason, $error->httpStatus >= 500 ? 'unknown' : 'rejected');
        } catch (Throwable) {
            // SQL, credentials and raw exception text must never reach the caller.
            return self::error(503, 'booking_source_unavailable', 'unknown');
        }
    }

    public static function command(string $body): MymarBookingCommand
    {
        if ($body === '' || strlen($body) > 16384 || ! str_starts_with(ltrim($body), '{')) {
            throw new InvalidArgumentException('mymar.invalid_booking_json');
        }
        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('mymar.invalid_booking_json');
        }
        // Valid JSON can still contain duplicate (including escaped) keys. Reject rather
        // than let different consumers disagree on the author, target or idempotency key.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $body, $strings, PREG_OFFSET_CAPTURE);
        $keys = [];
        foreach ($strings[0] as [$string, $offset]) {
            if (! str_starts_with(ltrim(substr($body, $offset + strlen($string))), ':')) {
                continue;
            }
            $key = json_decode($string, true, 2, JSON_THROW_ON_ERROR);
            if (isset($keys[$key])) {
                throw new InvalidArgumentException('mymar.duplicate_booking_key');
            }
            $keys[$key] = true;
        }

        return MymarBookingCommand::fromArray($data);
    }

    private static function page(string $query): array
    {
        $values = ['after' => 0, 'limit' => 50];
        $seen = [];
        if ($query === '') {
            return [0, 50];
        }
        if (preg_match('/%(?![a-fA-F0-9]{2})/', $query)) {
            throw new InvalidArgumentException;
        }
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : '';
            if (! array_key_exists($key, $values) || isset($seen[$key]) || ! preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value)) {
                throw new InvalidArgumentException;
            }
            $seen[$key] = true;
            $values[$key] = (int) $value;
        }
        if ($values['limit'] < 1 || $values['limit'] > 100) {
            throw new InvalidArgumentException;
        }

        return [$values['after'], $values['limit']];
    }

    private static function error(int $status, string $code, string $outcome = 'not_executed'): array
    {
        return ['status' => $status, 'body' => ['error' => $code, 'outcome' => $outcome,
            'source_committed' => $outcome === 'unknown' ? null : false]];
    }
}
