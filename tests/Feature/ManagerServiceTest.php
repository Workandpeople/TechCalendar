<?php

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders active technicians in the service creation form', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'first_name' => 'Nora',
        'last_name' => 'Tech',
        'department_code' => '69',
    ]);
    $planningUser = User::factory()->create([
        'role' => 1,
        'admin' => false,
        'first_name' => 'Paul',
        'last_name' => 'Planning',
    ]);

    $this->actingAs($manager)
        ->get(route('manager.services'))
        ->assertOk()
        ->assertSee('Attribuer à des techniciens')
        ->assertSee($technician->full_name_with_departments)
        ->assertSee('Départements 69')
        ->assertSee($technician->email)
        ->assertDontSee($planningUser->email);
});

it('assigns a new service to selected technicians', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $firstTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $secondTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $otherTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.services.store'), [
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit terrain renforcé',
            'average_duration_minutes' => 135,
            'technician_ids' => [
                $firstTechnician->id,
                $secondTechnician->id,
                $secondTechnician->id,
            ],
        ])
        ->assertRedirect(route('manager.services'))
        ->assertSessionHas('status', 'Prestation créée avec succès.');

    $service = Service::query()->where('name', 'Audit terrain renforcé')->firstOrFail();

    expect($service->technicians()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$firstTechnician->id, $secondTechnician->id])
        ->and($otherTechnician->services()->whereKey($service->id)->exists())->toBeFalse();
});

it('rejects non technician users when assigning a service during creation', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $planningUser = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->from(route('manager.services'))
        ->post(route('manager.services.store'), [
            'type' => Service::TYPE_MAR,
            'name' => 'Contrôle MAR sensible',
            'average_duration_minutes' => 90,
            'technician_ids' => [$planningUser->id],
        ])
        ->assertRedirect(route('manager.services'))
        ->assertSessionHasErrors('technician_ids.0');

    expect(Service::query()->where('name', 'Contrôle MAR sensible')->exists())->toBeFalse();
});
