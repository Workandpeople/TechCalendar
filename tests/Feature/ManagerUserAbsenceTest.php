<?php

use App\Models\TechnicianAbsence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets managers create and delete technician absences', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.users.absences.store', $technician), [
            'starts_on' => '2026-06-15',
            'ends_on' => '2026-06-20',
            'reason' => 'Conges',
        ])
        ->assertRedirect(route('manager.users'));

    $absence = TechnicianAbsence::query()->first();

    expect($absence)->not->toBeNull()
        ->and($absence->technician_id)->toBe($technician->id)
        ->and($absence->starts_at->toDateString())->toBe('2026-06-15')
        ->and($absence->ends_at->toDateString())->toBe('2026-06-20')
        ->and($absence->reason)->toBe('Conges');

    $this->actingAs($manager)
        ->delete(route('manager.users.absences.destroy', [$technician, $absence]))
        ->assertRedirect(route('manager.users'));

    expect(TechnicianAbsence::query()->exists())->toBeFalse();
});

it('rejects overlapping technician absences', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);

    TechnicianAbsence::query()->create([
        'technician_id' => $technician->id,
        'created_by' => $manager->id,
        'starts_at' => '2026-06-15 00:00:00',
        'ends_at' => '2026-06-20 23:59:59',
        'reason' => 'Formation',
    ]);

    $this->actingAs($manager)
        ->from(route('manager.users'))
        ->post(route('manager.users.absences.store', $technician), [
            'starts_on' => '2026-06-18',
            'ends_on' => '2026-06-22',
        ])
        ->assertRedirect(route('manager.users'))
        ->assertSessionHasErrors('absence');

    expect(TechnicianAbsence::query()->count())->toBe(1);
});

it('renders absence actions only for active technicians', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.users'))
        ->assertOk()
        ->assertSee('data-modal-open="absence-user-modal"', false);
});
