<?php

use App\Services\MapboxDrivingRouteService;
use Illuminate\Support\Facades\Http;

it('adds at least ten minutes safety margin to mapbox route durations', function () {
    config(['services.mapbox.token' => 'test-token']);

    Http::fake([
        'api.mapbox.com/*' => Http::response([
            'routes' => [[
                'distance' => 12_300,
                'duration' => 20 * 60,
            ]],
        ]),
    ]);

    $route = app(MapboxDrivingRouteService::class)->estimate(45.75, 4.85, 45.8, 4.9);

    expect($route['source'])->toBe('mapbox')
        ->and($route['distance_km'])->toBe(12.3)
        ->and($route['duration_minutes'])->toBe(30);
});

it('uses fifteen percent safety margin when it is greater than ten minutes', function () {
    config(['services.mapbox.token' => 'test-token']);

    Http::fake([
        'api.mapbox.com/*' => Http::response([
            'routes' => [[
                'distance' => 85_000,
                'duration' => 100 * 60,
            ]],
        ]),
    ]);

    $route = app(MapboxDrivingRouteService::class)->estimate(45.75, 4.85, 46.2, 5.2);

    expect($route['source'])->toBe('mapbox')
        ->and($route['duration_minutes'])->toBe(115);
});
