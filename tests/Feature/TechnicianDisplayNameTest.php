<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('formats technician names with every assigned department code', function () {
    Department::query()->updateOrCreate(['code' => '01'], ['name' => 'Ain']);
    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $technician = User::factory()->create([
        'first_name' => 'Arthur',
        'last_name' => 'Martin',
        'role' => 2,
        'admin' => false,
        'department_code' => '69',
    ]);

    $technician->departments()->attach(['69', '01']);

    expect($technician->fresh()->full_name_with_departments)->toBe('Arthur Martin (01,69)');
});

it('falls back to the legacy department code for technicians without assigned departments', function () {
    $technician = User::factory()->create([
        'first_name' => 'Nora',
        'last_name' => 'Tech',
        'role' => 2,
        'admin' => false,
        'department_code' => '69',
    ]);

    expect($technician->fresh()->full_name_with_departments)->toBe('Nora Tech (69)');
});
