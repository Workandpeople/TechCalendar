<?php

use App\Mail\UserCreatedCredentialsMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('normalizes first and last names when an admin creates a user', function () {
    Mail::fake();

    $admin = User::factory()->create([
        'admin' => true,
        'role' => 0,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'first_name' => '  éLODIE  ',
            'last_name' => '  de la fontaine ',
            'email' => 'elodie@example.test',
            'role' => 1,
            'admin' => 0,
        ])
        ->assertRedirect(route('admin.users'));

    $this->assertDatabaseHas('users', [
        'first_name' => 'Élodie',
        'last_name' => 'DE LA FONTAINE',
        'email' => 'elodie@example.test',
    ]);

    Mail::assertSent(UserCreatedCredentialsMail::class);
});

it('normalizes first and last names when a manager creates a user', function () {
    Mail::fake();

    $manager = User::factory()->create([
        'admin' => false,
        'role' => 0,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.users.store'), [
            'first_name' => " jean-luc ",
            'last_name' => " d'ornano ",
            'email' => 'jean-luc@example.test',
            'role' => 1,
        ])
        ->assertRedirect(route('manager.users'));

    $this->assertDatabaseHas('users', [
        'first_name' => 'Jean-Luc',
        'last_name' => "D'ORNANO",
        'email' => 'jean-luc@example.test',
    ]);

    Mail::assertSent(UserCreatedCredentialsMail::class);
});

it('normalizes first and last names when a user is updated from management screens', function () {
    $admin = User::factory()->create([
        'admin' => true,
        'role' => 0,
    ]);
    $user = User::factory()->create([
        'admin' => false,
        'role' => 1,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'first_name' => "  maël  ",
            'last_name' => ' le roux ',
            'email' => $user->email,
            'role' => 1,
            'admin' => 0,
        ])
        ->assertRedirect(route('admin.users'));

    expect($user->refresh()->first_name)->toBe('Maël')
        ->and($user->last_name)->toBe('LE ROUX');
});
