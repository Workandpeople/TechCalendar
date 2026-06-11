<?php

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\ManagerDashboardDataService;
use App\Services\TechnicianDailyRouteMetricService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('counts return travel after technician day end as overtime', function () {
    config(['services.mapbox.token' => 'test-token']);

    Http::fake(fn () => Http::response([
        'routes' => [[
            'distance' => 50000,
            'duration' => 3600,
            'legs' => [
                ['distance' => 0, 'duration' => 0],
                ['distance' => 50000, 'duration' => 3600],
            ],
        ]],
    ]));

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'day_start_time' => '07:00',
        'day_end_time' => '20:00',
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit soir',
        'average_duration_minutes' => 60,
    ]);
    $startsAt = Carbon::parse('2026-06-15 19:00:00');

    Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Soir',
        'customer_phone' => '0600000000',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $metrics = app(TechnicianDailyRouteMetricService::class)->ensureForTechnicianPeriod(
        $technician,
        $startsAt->copy()->startOfWeek(),
        $startsAt->copy()->endOfWeek(),
    );
    $metric = $metrics->first();

    $payload = app(ManagerDashboardDataService::class)->payload(
        $startsAt->copy()->startOfWeek(),
        $startsAt->copy()->endOfWeek(),
    );
    $overtimeStat = collect($payload['stats'])->firstWhere('label', 'Heures supp terrain');

    expect($metric->drive_duration_minutes)->toBe(60)
        ->and($metric->overtime_minutes)->toBe(60)
        ->and($metric->calculation_source)->toBe('mapbox')
        ->and($overtimeStat['value'])->toBe('1h')
        ->and($payload['technicianEfficiency'][0]['overtime_hours'])->toBe(1.0);
});
