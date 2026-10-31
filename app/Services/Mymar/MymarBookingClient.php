<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

/** Loopback client. Readers never gain write permission through this shared contract. */
final readonly class MymarBookingClient
{
    public function __construct(
        private Factory $http,
        private bool $enabled = false,
        private bool $writesEnabled = false,
        private int $port = 19847,
        #[\SensitiveParameter] private ?string $token = null,
    ) {}

    public function assertEnabled(bool $write = false): void
    {
        if (! $this->enabled || ($write && ! $this->writesEnabled)) {
            throw new RuntimeException('mymar.booking_disabled');
        }
        if ($this->port < 1024 || $this->port > 65535 || $this->port === 19846
            || ! is_string($this->token) || ! preg_match('/^[a-f0-9]{64}$/D', $this->token)
            || count(array_unique(str_split($this->token))) < 8) {
            throw new RuntimeException('mymar.invalid_booking_connection');
        }
    }

    public function submit(MymarBookingCommand $command): MymarBookingAttempt
    {
        $this->assertEnabled(write: true);

        return $this->request($command, true);
    }

    public function result(MymarBookingCommand $command): MymarBookingAttempt
    {
        $this->assertEnabled();

        return $this->request($command, false);
    }

    public function pending(int $after = 0, int $limit = 50): MymarPendingBookingPage
    {
        $this->assertEnabled();
        if ($after < 0 || $limit < 1 || $limit > 100) {
            throw new RuntimeException('mymar.invalid_booking_page');
        }
        try {
            [$status, $data] = $this->exchange('GET', '/v1/pending-bookings', ['after' => $after, 'limit' => $limit], 2097152);
            if ($status !== 200) {
                throw new RuntimeException('mymar.booking_pending_unavailable');
            }

            return MymarPendingBookingPage::fromArray($data, $after, $limit);
        } catch (Throwable) {
            throw new RuntimeException('mymar.booking_pending_unavailable');
        }
    }

    private function request(MymarBookingCommand $command, bool $submit): MymarBookingAttempt
    {
        try {
            // Never retry a POST automatically: a timeout can happen after the source COMMIT.
            [$status, $data] = $this->exchange($submit ? 'POST' : 'GET', '/v1/booking-commands'.($submit ? '' : '/'.$command->payload['request_id']), $submit ? $command->payload : [], 8192);
            if ($status === 200) {
                return new MymarBookingAttempt('confirmed', result: $this->validateResult($data, $command));
            }
            $reason = $data['error'] ?? '';
            if (! is_string($reason) || ! preg_match('/^(?:invalid_)?booking_[a-z0-9_]{1,80}$/D', $reason)) {
                $reason = 'booking_gateway_unavailable';
            }
            if ($submit && $status >= 400 && $status < 500 && ($data['source_committed'] ?? null) === false
                && in_array($data['outcome'] ?? null, ['rejected', 'not_executed'], true)) {
                return new MymarBookingAttempt('rejected', $reason);
            }

            return new MymarBookingAttempt('uncertain', $reason);
        } catch (Throwable) {
            // Do not expose HTTP exceptions: they can carry the bearer token or request body.
            return new MymarBookingAttempt('uncertain', 'booking_gateway_unavailable');
        }
    }

    private function exchange(string $method, string $path, array $payload, int $maximumBytes): array
    {
        $stream = null;
        try {
            $request = $this->http->withToken($this->token)->acceptJson()->withoutRedirecting()
                ->connectTimeout(1)->timeout(5)->withOptions([
                    'proxy' => '', 'cookies' => false, 'stream' => true, 'read_timeout' => 2, 'decode_content' => false,
                    'progress' => static function (float $total, float $received) use ($maximumBytes): void {
                        if (max($total, $received) > $maximumBytes) {
                            throw new RuntimeException('mymar.booking_response_limit');
                        }
                    },
                ]);
            $url = 'http://127.0.0.1:'.$this->port.$path;
            $response = $method === 'POST' ? $request->post($url, $payload) : $request->get($url, $payload);
            $stream = $response->toPsrResponse()->getBody();
            if (strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/json'
                || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)
                || (int) $response->header('Content-Length') > $maximumBytes) {
                throw new RuntimeException('mymar.invalid_booking_response');
            }
            $body = '';
            $deadline = hrtime(true) + 5_000_000_000;
            while (! $stream->eof()) {
                $chunk = $stream->read(min(4096, $maximumBytes + 1 - strlen($body)));
                $body .= $chunk;
                if ($chunk === '' || strlen($body) > $maximumBytes || hrtime(true) > $deadline) {
                    throw new RuntimeException('mymar.invalid_booking_response');
                }
            }
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new RuntimeException('mymar.invalid_booking_response');
            }

            return [$response->status(), $data];
        } finally {
            $stream?->close();
        }
    }

    private function validateResult(array $result, MymarBookingCommand $command): array
    {
        $payload = $command->payload;
        $fields = ['format', 'version', 'request_id', 'action', 'dossier_id', 'status_id', 'rdv_id', 'starts_at', 'duration_minutes', 'source_committed'];
        if (array_diff(array_keys($result), $fields) || array_diff($fields, array_keys($result))
            || $result['format'] !== 'cofrac.mymar-booking-result' || $result['version'] !== 1 || $result['source_committed'] !== true
            || $result['request_id'] !== $payload['request_id'] || $result['dossier_id'] !== $payload['dossier_id']
            || $result['action'] !== $payload['action'] || ! in_array($result['status_id'], $payload['action'] === 'place' ? [3, 16] : [2, 15], true)) {
            throw new RuntimeException('mymar.invalid_booking_result');
        }
        if ($payload['action'] === 'place') {
            if (! is_int($result['rdv_id']) || $result['rdv_id'] < 1 || $result['duration_minutes'] !== $payload['duration_minutes']
                || MymarBookingCommand::instant($result['starts_at'])->format(DATE_ATOM) !== $payload['starts_at']) {
                throw new RuntimeException('mymar.invalid_booking_result');
            }
        } elseif ($result['rdv_id'] !== null || $result['starts_at'] !== null || $result['duration_minutes'] !== null) {
            throw new RuntimeException('mymar.invalid_booking_result');
        }

        return $result;
    }
}
