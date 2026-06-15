<?php

use App\Models\Department;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes technician working hours when an admin updates a user', function () {
    $admin = User::factory()->create([
        'admin' => true,
        'role' => 0,
    ]);
    $technician = User::factory()->create([
        'admin' => false,
        'role' => 2,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit test',
        'average_duration_minutes' => 90,
    ]);
    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $technician), technicianPayload($technician, $service))
        ->assertRedirect(route('admin.users'))
        ->assertSessionHasNoErrors();

    $technician->refresh();

    expect(substr((string) $technician->day_start_time, 0, 5))->toBe('08:00')
        ->and(substr((string) $technician->day_end_time, 0, 5))->toBe('18:30');
});

it('normalizes technician working hours when a manager updates a user', function () {
    $manager = User::factory()->create([
        'admin' => false,
        'role' => 0,
    ]);
    $technician = User::factory()->create([
        'admin' => false,
        'role' => 2,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle test',
        'average_duration_minutes' => 120,
    ]);
    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $this->actingAs($manager)
        ->put(route('manager.users.update', $technician), technicianPayload($technician, $service))
        ->assertRedirect(route('manager.users'))
        ->assertSessionHasNoErrors();

    $technician->refresh();

    expect(substr((string) $technician->day_start_time, 0, 5))->toBe('08:00')
        ->and(substr((string) $technician->day_end_time, 0, 5))->toBe('18:30');
});

function technicianPayload(User $technician, Service $service): array
{
    return [
        'first_name' => $technician->first_name,
        'last_name' => $technician->last_name,
        'email' => $technician->email,
        'role' => 2,
        'admin' => false,
        'phone' => '0601020304',
        'address' => '1 place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'day_start_time' => '08:00:00',
        'day_end_time' => '18:30:00',
        'break_duration_minutes' => 45,
        'service_ids' => [$service->id],
        'department_codes' => ['69'],
    ];
}
