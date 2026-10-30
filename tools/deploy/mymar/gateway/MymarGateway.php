<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class MymarGateway
{
    public function __construct(
        private bool $enabled,
        #[\SensitiveParameter] private string $token,
        private MymarObservationPolicy $policy,
        private Closure $readSource,
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || count(array_unique(str_split($token))) < 8) {
            throw new InvalidArgumentException('mymar.invalid_gateway_credential');
        }
    }

    public function handle(string $method, string $path, string $query, #[\SensitiveParameter] string $authorization, string $peer, DateTimeImmutable $now): array
    {
        if (! $this->enabled) {
            return self::error(503, 'gateway_disabled');
        }
        if (! in_array($peer, ['127.0.0.1', '::1'], true)
            || ! hash_equals('Bearer '.$this->token, $authorization)) {
            return self::error(403, 'access_denied');
        }
        if ($method !== 'GET') {
            return self::error(405, 'read_only_endpoint');
        }
        if ($path !== '/v1/occupations') {
            return self::error(404, 'unknown_endpoint');
        }
        try {
            $parameters = $this->parameters($query);
            $ids = explode(',', $parameters['technician_ids']);
            foreach ($ids as &$id) {
                if (! preg_match('/^[1-9][0-9]*$/D', $id) || (string) (int) $id !== $id) {
                    throw new InvalidArgumentException('mymar.invalid_scope');
                }
                $id = (int) $id;
            }
            unset($id);
            $window = new MymarReadWindow($parameters['window_start'], $parameters['window_end'], $ids);
        } catch (Throwable) {
            return self::error(422, 'invalid_scope');
        }
        try {
            $rows = ($this->readSource)($window, $this->policy);
            $snapshot = ['format' => MymarObservation::FORMAT, 'version' => 1,
                'generated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'complete' => true,
                'source_timezone' => $this->policy->sourceTimezone, 'duration_unit' => $this->policy->durationUnit,
                'window_start' => $window->from->format(DATE_ATOM), 'window_end' => $window->to->format(DATE_ATOM),
                'technician_ids' => $window->technicianIds, 'status_ids' => $this->policy->statusIds, 'appointments' => $rows];
            (new MymarObservation)->inspect($snapshot, $this->policy, $now);

            return ['status' => 200, 'body' => $snapshot];
        } catch (Throwable) {
            // No raw SQL, source configuration, exception, token or business text in responses.
            return self::error(503, 'source_unavailable');
        }
    }

    public static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code, 'complete' => false]];
    }

    private function parameters(string $query): array
    {
        if ($query === '' || strlen($query) > 4096 || preg_match('/%(?![a-fA-F0-9]{2})/', $query)) {
            throw new InvalidArgumentException('mymar.invalid_query');
        }
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            if (count($parts) !== 2 || isset($parameters[$key]) || ! in_array($key, ['window_start', 'window_end', 'technician_ids'], true)) {
                throw new InvalidArgumentException('mymar.invalid_query');
            }
            $parameters[$key] = urldecode($parts[1]);
        }
        if (count($parameters) !== 3) {
            throw new InvalidArgumentException('mymar.missing_query_parameter');
        }

        return $parameters;
    }
}
