<?php

use App\Models\Appointment;
use App\Models\MobileAccessToken;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('authenticates technicians and returns a revocable mobile token', function () {
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech@example.test',
        'password' => Hash::make('secret-password'),
    ]);

    $response = $this->postJson(route('api.mobile.login'), [
        'email' => 'tech@example.test',
        'password' => 'secret-password',
        'device_name' => 'iPhone test',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.id', $technician->id)
        ->assertJsonPath('user.full_name', $technician->full_name)
        ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user']);

    $token = $response->json('token');

    expect(MobileAccessToken::query()->where('user_id', $technician->id)->count())->toBe(1)
        ->and(MobileAccessToken::query()->first()->token_hash)->toBe(hash('sha256', $token));

    $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.mobile.me'))
        ->assertOk()
        ->assertJsonPath('user.email', 'tech@example.test');

    $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson(route('api.mobile.logout'))
        ->assertOk();

    expect(MobileAccessToken::query()->count())->toBe(0);
});

it('rejects non technician accounts on mobile login', function () {
    User::factory()->create([
        'role' => 1,
        'admin' => false,
        'email' => 'planning@example.test',
        'password' => Hash::make('secret-password'),
    ]);

    $this->postJson(route('api.mobile.login'), [
        'email' => 'planning@example.test',
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email')
        ->assertJsonPath('errors.email.0', 'Cette application est réservée aux techniciens.');
});

it('requires mobile technicians to replace their initial password', function () {
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech@example.test',
        'password' => Hash::make('temporary-password'),
        'must_change_password' => true,
    ]);

    $token = $this->postJson(route('api.mobile.login'), [
        'email' => 'tech@example.test',
        'password' => 'temporary-password',
    ])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true)
        ->json('token');

    $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson(route('api.mobile.first-password.update'), [
            'password' => 'New-secure-password1',
            'password_confirmation' => 'New-secure-password1',
        ])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', false);

    $technician->refresh();

    expect($technician->must_change_password)->toBeFalse()
        ->and(Hash::check('New-secure-password1', $technician->password))->toBeTrue();
});

it('returns the authenticated technician planning and cached weekly widgets', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'Europe/Paris'));

    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité',
        'average_duration_minutes' => 120,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech@example.test',
        'password' => Hash::make('secret-password'),
    ]);
    $otherTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Camille',
        'customer_last_name' => 'Martin',
        'customer_phone' => '0612345678',
        'address' => '20 Rue Bellecordière, 69002 Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8422,
        'starts_at' => Carbon::parse('2026-06-15 09:30:00', 'Europe/Paris'),
        'duration_minutes' => 120,
        'ends_at' => Carbon::parse('2026-06-15 11:30:00', 'Europe/Paris'),
        'comment' => 'Prévoir badge accueil.',
    ]);
    Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $otherTechnician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Autre',
        'customer_last_name' => 'Client',
        'customer_phone' => '0611111111',
        'address' => '1 Rue Test, 69001 Lyon',
        'latitude' => 45.76,
        'longitude' => 4.84,
        'starts_at' => Carbon::parse('2026-06-15 10:00:00', 'Europe/Paris'),
        'duration_minutes' => 60,
        'ends_at' => Carbon::parse('2026-06-15 11:00:00', 'Europe/Paris'),
    ]);
    TechnicianDailyRouteMetric::query()->create([
        'technician_id' => $technician->id,
        'service_date' => '2026-06-15',
        'appointment_count' => 1,
        'drive_distance_km' => 38.4,
        'drive_duration_minutes' => 72,
        'overtime_minutes' => 15,
        'calculation_source' => 'mapbox',
        'route_hash' => hash('sha256', 'mobile-test'),
        'calculated_at' => now(),
    ]);

    $token = $this->postJson(route('api.mobile.login'), [
        'email' => 'tech@example.test',
        'password' => 'secret-password',
    ])->json('token');

    $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.mobile.planning'))
        ->assertOk()
        ->assertJsonPath('widgets.today_count', 1)
        ->assertJsonPath('widgets.week_count', 1)
        ->assertJsonPath('widgets.week_planned_hours', 2)
        ->assertJsonPath('widgets.week_drive_km', 38.4)
        ->assertJsonPath('widgets.week_drive_hours', 1.2)
        ->assertJsonPath('widgets.week_overtime_hours', 0.3)
        ->assertJsonCount(1, 'appointments')
        ->assertJsonPath('appointments.0.customer_name', 'Camille Martin')
        ->assertJsonPath('appointments.0.postal_code', '69002')
        ->assertJsonPath('appointments.0.city', 'Lyon');

});
