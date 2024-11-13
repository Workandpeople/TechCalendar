<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Prestation;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Créer un administrateur spécifique
        $admin = User::factory()->create([
            'nom' => 'Dinnichert',
            'prenom' => 'Lucas',
            'email' => 'contact@lucas-dinnichert.fr',
        ]);
        Role::factory()->administrateur()->create(['user_id' => $admin->id]);

        // Créer 3 assistantes
        User::factory()
            ->count(3)
            ->create()
            ->each(function ($user) {
                Role::factory()->assistante()->create(['user_id' => $user->id]);
            });

        // Créer 16 techniciens
        User::factory()
            ->count(16)
            ->create()
            ->each(function ($user) {
                Role::factory()->technicien()->create(['user_id' => $user->id]);
            });

        // Créer 50 prestations
        Prestation::factory()
            ->count(50)
            ->create();
    }
}