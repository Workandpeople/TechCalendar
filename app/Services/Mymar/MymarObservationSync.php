<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class MymarObservationSync
{
    public function __construct(private readonly MymarPilotSettings $settings, private readonly Factory $http) {}

    public function run(): array
    {
        if (! config('crm.v2_enabled')) {
            return ['status' => 'disabled'];
        }
        $lock = null;
        $pilot = null;
        $stage = 'configuration';
        try {
            $pilot = $this->settings->load();
            if (! $pilot->syncEnabled) {
                return ['status' => 'disabled'];
            }
            $cache = Cache::store();
            $stage = 'cache_lock';
            $lock = $cache->lock($pilot->cacheKey().':lock', 120);
            if (! $lock->get()) {
                return ['status' => 'busy'];
            }
            $targets = array_values($pilot->technicianMap);
            $stage = 'target_validation';
            if (User::query()->internal()->where('role', 2)->where('admin', false)->whereIn('id', $targets)->count() !== count($targets)) {
                throw new RuntimeException('mymar.target_mapping_changed');
            }
            $now = new DateTimeImmutable;
            $stage = 'api_read';
            $from = $now->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0)->modify('-7 days');
            $window = new MymarReadWindow($from->format(DATE_ATOM), $from->modify('+28 days')->format(DATE_ATOM), array_keys($pilot->technicianMap));
            $report = (new MymarGatewayClient($this->http, true, token: $this->settings->token()))
                ->observe($window, new MymarObservationPolicy, $now, $pilot->technicianMap);
            $projection = MymarCachedObservation::project($report, $pilot, new DateTimeImmutable);
            $stage = 'cache_write';
            // One atomic put after a complete validated read; a failure never replaces the last good snapshot.
            // MySQL reports zero affected rows for an identical value written twice within one second.
            if (! $cache->put($pilot->cacheKey(), $projection, 86400) && $cache->get($pilot->cacheKey()) !== $projection) {
                throw new RuntimeException('mymar.cache_write_failed');
            }
            $cache->forget($pilot->cacheKey().':failure');

            return ['status' => 'observing', 'periods' => count($projection['periods']), 'generated_at' => $projection['generated_at'],
                'window_start' => $projection['window_start'], 'window_end' => $projection['window_end'], 'blocking_enabled' => false];
        } catch (Throwable $exception) {
            $reason = $exception instanceof RuntimeException && in_array($exception->getMessage(), ['mymar.target_mapping_changed',
                'mymar.unusable_observation', 'mymar.gateway_observation_failed', 'mymar.unsafe_pilot_file',
                'mymar.invalid_pilot_configuration', 'mymar.invalid_pilot_token', 'mymar.cache_write_failed'], true) ? $exception->getMessage() : 'mymar.sync_failed';
            if ($pilot?->syncEnabled) {
                try {
                    Cache::put($pilot->cacheKey().':failure', ['reason' => $reason, 'at' => now()->toIso8601String()], 86400);
                } catch (Throwable) {
                    // A cache outage must not expose credentials or turn stale data into an empty successful planning.
                }
            }

            return ['status' => 'failed', 'reason' => $reason, 'stage' => $stage];
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // The bounded lease expires even when the cache backend becomes unavailable.
            }
        }
    }
}
