<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MapboxDrivingRouteService
{
    /**
     * @var array<string, array{distance_km: float, duration_minutes: int, source: string}>
     */
    private array $routeCache = [];

    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $cacheKey = $this->cacheKey($fromLat, $fromLng, $toLat, $toLng);

        if (isset($this->routeCache[$cacheKey])) {
            return $this->routeCache[$cacheKey];
        }

        $fallbackDistanceKm = $this->haversine($fromLat, $fromLng, $toLat, $toLng);
        $fallbackDurationMinutes = $this->fallbackDurationMinutes($fallbackDistanceKm);
        $token = (string) config('services.mapbox.token');

        if ($token === '') {
            return $this->routeCache[$cacheKey] = [
                'distance_km' => round($fallbackDistanceKm, 1),
                'duration_minutes' => $fallbackDurationMinutes,
                'source' => 'fallback',
            ];
        }

        $url = sprintf(
            'https://api.mapbox.com/directions/v5/mapbox/driving/%F,%F;%F,%F',
            $fromLng,
            $fromLat,
            $toLng,
            $toLat
        );

        try {
            $response = Http::timeout(8)->retry(1, 150)->get($url, [
                'access_token' => $token,
                'geometries' => 'geojson',
                'overview' => 'false',
            ]);

            if (! $response->ok()) {
                return $this->routeCache[$cacheKey] = [
                    'distance_km' => round($fallbackDistanceKm, 1),
                    'duration_minutes' => $fallbackDurationMinutes,
                    'source' => 'fallback',
                ];
            }

            $route = $response->json('routes.0');

            if (! is_array($route) || ! isset($route['distance'], $route['duration'])) {
                return $this->routeCache[$cacheKey] = [
                    'distance_km' => round($fallbackDistanceKm, 1),
                    'duration_minutes' => $fallbackDurationMinutes,
                    'source' => 'fallback',
                ];
            }

            return $this->routeCache[$cacheKey] = [
                'distance_km' => round(((float) $route['distance']) / 1000, 1),
                'duration_minutes' => max(1, (int) ceil(((float) $route['duration']) / 60)),
                'source' => 'mapbox',
            ];
        } catch (\Throwable) {
            return $this->routeCache[$cacheKey] = [
                'distance_km' => round($fallbackDistanceKm, 1),
                'duration_minutes' => $fallbackDurationMinutes,
                'source' => 'fallback',
            ];
        }
    }

    private function cacheKey(float $fromLat, float $fromLng, float $toLat, float $toLng): string
    {
        return implode(':', [
            round($fromLat, 5),
            round($fromLng, 5),
            round($toLat, 5),
            round($toLng, 5),
        ]);
    }

    private function fallbackDurationMinutes(float $distanceKm): int
    {
        return max(1, (int) ceil(($distanceKm / 65) * 60));
    }

    private function haversine(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
