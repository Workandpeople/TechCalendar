<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class MymarCalendarLabelsSync
{
    public function __construct(private readonly MymarBookingReaderSettings $reader, private readonly Factory $http) {}

    public function refresh(MymarPilotConfiguration $pilot, array $snapshot): bool
    {
        try {
            if ($snapshot['periods'] === [] || ! $this->reader->load()) {
                Cache::forget(MymarCalendarLabels::cacheKey($pilot));

                return false;
            }
            $window = new MymarReadWindow($snapshot['window_start'], $snapshot['window_end'], array_keys($pilot->technicianMap));
            $data = (new MymarBookingClient($this->http, enabled: true, writesEnabled: false, token: $this->reader->token()))->calendarLabels($window);
            return Cache::put(MymarCalendarLabels::cacheKey($pilot), $data, 300)
                || Cache::get(MymarCalendarLabels::cacheKey($pilot)) === $data;
        } catch (Throwable) {
            // Old gateways can return 404 until upgraded; occupation sync remains independent.
            return false;
        }
    }
}
