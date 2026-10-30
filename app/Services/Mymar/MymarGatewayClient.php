<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

/** Explicit observation client only: not registered in routes, the scheduler or booking services. */
final readonly class MymarGatewayClient
{
    public const MAXIMUM_BYTES = 2097152;

    public function __construct(
        private Factory $http,
        private bool $enabled = false,
        private int $port = 19846,
        #[\SensitiveParameter] private ?string $token = null,
    ) {}

    public function observe(MymarReadWindow $window, MymarObservationPolicy $policy, DateTimeImmutable $now, array $technicianMap = []): array
    {
        if (! $this->enabled) {
            throw new RuntimeException('mymar.connector_disabled');
        }
        if ($this->port < 1024 || $this->port > 65535 || ! is_string($this->token)
            || ! preg_match('/^[a-f0-9]{64}$/D', $this->token) || count(array_unique(str_split($this->token))) < 8) {
            throw new RuntimeException('mymar.invalid_local_connection');
        }
        $stream = null;
        try {
            $response = $this->http->withToken($this->token)->acceptJson()->withoutRedirecting()
                ->connectTimeout(1)->timeout(5)->withOptions([
                    'proxy' => '', 'cookies' => false, 'stream' => true, 'read_timeout' => 2,
                    'decode_content' => false,
                    'progress' => static function (float $total, float $received): void {
                        if (max($total, $received) > self::MAXIMUM_BYTES) {
                            throw new RuntimeException('mymar.response_too_large');
                        }
                    },
                ])->get('http://127.0.0.1:'.$this->port.'/v1/occupations', $window->query());
            $stream = $response->toPsrResponse()->getBody();
            if ($response->status() !== 200 || strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/json'
                || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)
                || (int) $response->header('Content-Length') > self::MAXIMUM_BYTES) {
                throw new RuntimeException('mymar.invalid_gateway_response');
            }
            $body = '';
            $deadline = hrtime(true) + 5_000_000_000;
            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, self::MAXIMUM_BYTES + 1 - strlen($body)));
                $body .= $chunk;
                if ($chunk === '' || strlen($body) > self::MAXIMUM_BYTES || hrtime(true) > $deadline) {
                    throw new RuntimeException('mymar.incomplete_gateway_response');
                }
            }
            $snapshot = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot)) {
                throw new RuntimeException('mymar.invalid_gateway_response');
            }
            $report = (new MymarObservation)->inspect($snapshot, $policy, $now, $technicianMap);
            $scope = $report['scope_technician_ids'];
            sort($scope, SORT_NUMERIC);
            if ($scope !== $window->technicianIds || $report['window_start'] !== $window->from->format(DATE_ATOM)
                || $report['window_end'] !== $window->to->format(DATE_ATOM) || ! $report['snapshot_complete']
                || array_intersect(['snapshot_stale', 'snapshot_from_future'], array_column($report['issues'], 'code'))) {
                throw new RuntimeException('mymar.incomplete_gateway_scope');
            }

            return array_replace($report, ['mode' => 'private_gateway_observation', 'http_requests' => true]);
        } catch (Throwable) {
            // Never forward an exception containing the Authorization header, SMTP data or SQL credentials.
            throw new RuntimeException('mymar.gateway_observation_failed');
        } finally {
            $stream?->close();
        }
    }
}
