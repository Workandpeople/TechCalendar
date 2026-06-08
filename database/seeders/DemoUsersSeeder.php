<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedManagers();
        $this->seedPlanners();
        $this->seedTechnicians();
    }

    private function seedManagers(): void
    {
        $missing = 2 - User::query()->where('admin', false)->where('role', 0)->count();

        if ($missing > 0) {
            User::factory()->count($missing)->create([
                'role' => 0,
                'admin' => false,
                'must_change_password' => false,
            ]);
        }
    }

    private function seedPlanners(): void
    {
        $missing = 2 - User::query()->where('admin', false)->where('role', 1)->count();

        if ($missing > 0) {
            User::factory()->count($missing)->create([
                'role' => 1,
                'admin' => false,
                'must_change_password' => false,
            ]);
        }
    }

    private function seedTechnicians(): void
    {
        $serviceIds = Service::query()->orderBy('type')->orderBy('name')->pluck('id')->values();
        $departmentCenters = $this->departmentCenters();
        $departments = collect(config('departments'));
        $faker = fake('fr_FR');

        User::query()
            ->where('email', 'like', 'tech%@demo.local')
            ->where('email', 'not like', 'tech-dept-%@demo.local')
            ->delete();

        foreach ($departments as $departmentCode => $departmentName) {
            $index = $departments->keys()->search($departmentCode);
            $center = $departmentCenters[$departmentCode] ?? ['lat' => 46.6, 'lng' => 2.4];
            $email = sprintf('tech-dept-%s@demo.local', Str::lower($departmentCode));

            $technician = User::withTrashed()->updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => $faker->firstName(),
                    'last_name' => sprintf('Tech %s', $departmentCode),
                    'password' => Hash::make('password'),
                    'must_change_password' => false,
                    'role' => 2,
                    'admin' => false,
                    'phone' => sprintf('06%08d', 70000000 + (int) $index),
                    'address' => sprintf('Prefecture %s, %s, France', $departmentName, $departmentCode),
                    'department_code' => $departmentCode,
                    'latitude' => $center['lat'],
                    'longitude' => $center['lng'],
                    'day_start_time' => $this->dayStartTime((int) $index),
                    'day_end_time' => $this->dayEndTime((int) $index),
                    'break_duration_minutes' => [30, 45, 60][((int) $index) % 3],
                    'email_verified_at' => now(),
                ]
            );

            if ($technician->trashed()) {
                $technician->restore();
            }

            $technician->services()->sync($this->serviceIdsForTechnician($serviceIds, (int) $index));
            $technician->departments()->sync($this->departmentCodesForTechnician($departmentCode, $departmentCenters));
        }
    }

    private function dayStartTime(int $index): string
    {
        return ['07:30', '08:00', '08:30', '09:00'][$index % 4];
    }

    private function dayEndTime(int $index): string
    {
        return ['16:30', '17:00', '17:30', '18:00'][$index % 4];
    }

    private function serviceIdsForTechnician($serviceIds, int $index): array
    {
        if ($serviceIds->isEmpty()) {
            return [];
        }

        return $serviceIds
            ->filter(fn (int $serviceId, int $serviceIndex): bool => ($serviceIndex + $index) % max(1, $serviceIds->count()) !== 0)
            ->values()
            ->all();
    }

    /**
     * @param array<string, array{lat: float, lng: float}> $departmentCenters
     * @return array<int, string>
     */
    private function departmentCodesForTechnician(string $departmentCode, array $departmentCenters): array
    {
        $origin = $departmentCenters[$departmentCode] ?? null;

        if (! $origin) {
            return [$departmentCode];
        }

        $nearestDepartments = collect($departmentCenters)
            ->reject(fn (array $center, string $code): bool => $code === $departmentCode)
            ->map(fn (array $center, string $code): array => [
                'code' => $code,
                'distance' => $this->haversine($origin['lat'], $origin['lng'], $center['lat'], $center['lng']),
            ])
            ->sortBy('distance')
            ->take(4)
            ->pluck('code')
            ->all();

        return array_values(array_unique([$departmentCode, ...$nearestDepartments]));
    }

    /**
     * @return array<string, array{lat: float, lng: float}>
     */
    private function departmentCenters(): array
    {
        $geoJsonPath = public_path('geo/departements.geojson');

        if (! is_file($geoJsonPath)) {
            return [];
        }

        $geoJson = json_decode((string) file_get_contents($geoJsonPath), true);

        return collect($geoJson['features'] ?? [])
            ->mapWithKeys(function (array $feature): array {
                $code = Str::upper((string) ($feature['properties']['code'] ?? $feature['properties']['CODE_DEPT'] ?? ''));
                $bounds = $this->geometryBounds($feature['geometry'] ?? []);

                if ($code === '' || ! $bounds) {
                    return [];
                }

                return [$code => [
                    'lat' => round(($bounds['minLat'] + $bounds['maxLat']) / 2, 6),
                    'lng' => round(($bounds['minLng'] + $bounds['maxLng']) / 2, 6),
                ]];
            })
            ->all();
    }

    /**
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null
     */
    private function geometryBounds(array $geometry): ?array
    {
        $bounds = null;
        $this->walkCoordinates($geometry['coordinates'] ?? [], $bounds);

        return $bounds;
    }

    private function walkCoordinates(array $coordinates, ?array &$bounds): void
    {
        if (count($coordinates) >= 2 && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];

            $bounds = $bounds === null
                ? ['minLat' => $lat, 'maxLat' => $lat, 'minLng' => $lng, 'maxLng' => $lng]
                : [
                    'minLat' => min($bounds['minLat'], $lat),
                    'maxLat' => max($bounds['maxLat'], $lat),
                    'minLng' => min($bounds['minLng'], $lng),
                    'maxLng' => max($bounds['maxLng'], $lng),
                ];

            return;
        }

        foreach ($coordinates as $coordinate) {
            if (is_array($coordinate)) {
                $this->walkCoordinates($coordinate, $bounds);
            }
        }
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
