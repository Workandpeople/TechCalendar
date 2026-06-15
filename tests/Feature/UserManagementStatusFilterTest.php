<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not apply empty admin filters when filtering admin users by status', function () {
    $admin = User::factory()->create([
        'admin' => true,
        'role' => 0,
        'first_name' => 'Alice',
        'last_name' => 'Admin',
    ]);
    $activePlanning = User::factory()->create([
        'admin' => false,
        'role' => 1,
        'first_name' => 'Paul',
        'last_name' => 'Planning',
    ]);
    $deletedTech = User::factory()->create([
        'admin' => false,
        'role' => 2,
        'first_name' => 'Theo',
        'last_name' => 'Deleted',
    ]);
    $deletedTech->delete();

    $this->actingAs($admin)
        ->get(route('admin.users', [
            'q' => '',
            'role' => '',
            'admin' => '',
            'status' => 'all',
        ]))
        ->assertOk()
        ->assertSee($activePlanning->email)
        ->assertSee($deletedTech->email);

    $this->actingAs($admin)
        ->get(route('admin.users', [
            'q' => '',
            'role' => '',
            'admin' => '',
            'status' => 'trashed',
        ]))
        ->assertOk()
        ->assertDontSee($activePlanning->email)
        ->assertSee($deletedTech->email);
});

it('does not apply empty role filters when filtering manager users by status', function () {
    $manager = User::factory()->create([
        'admin' => false,
        'role' => 0,
    ]);
    $activePlanning = User::factory()->create([
        'admin' => false,
        'role' => 1,
        'first_name' => 'Julie',
        'last_name' => 'Planning',
    ]);
    $deletedTech = User::factory()->create([
        'admin' => false,
        'role' => 2,
        'first_name' => 'Marc',
        'last_name' => 'Deleted',
    ]);
    $deletedTech->delete();

    $this->actingAs($manager)
        ->get(route('manager.users', [
            'q' => '',
            'role' => '',
            'status' => 'all',
        ]))
        ->assertOk()
        ->assertSee($activePlanning->email)
        ->assertSee($deletedTech->email);

    $this->actingAs($manager)
        ->get(route('manager.users', [
            'q' => '',
            'role' => '',
            'status' => 'trashed',
        ]))
        ->assertOk()
        ->assertDontSee($activePlanning->email)
        ->assertSee($deletedTech->email);
});

it('renders the existing department geojson asset in user management pages', function () {
    $admin = User::factory()->create([
        'admin' => true,
        'role' => 0,
    ]);
    $manager = User::factory()->create([
        'admin' => false,
        'role' => 0,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('geo\/departements.geojson', false)
        ->assertDontSee('geo\/départements.geojson', false);

    $this->actingAs($manager)
        ->get(route('manager.users'))
        ->assertOk()
        ->assertSee('geo\/departements.geojson', false)
        ->assertDontSee('geo\/départements.geojson', false);
});
