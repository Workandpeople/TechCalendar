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
        $admin = User::factory()->adminData()->create([
            'nom' => 'Dinnichert',
            'prenom' => 'Lucas',
            'email' => 'contact@lucas-dinnichert.fr',
        ]);
        Role::factory()->administrateur()->create(['user_id' => $admin->id]);

        // Créer 3 assistantes
        User::factory()
            ->adminData()
            ->count(5)
            ->create()
            ->each(function ($user) {
                Role::factory()->assistante()->create(['user_id' => $user->id]);
            });

        // Créer 300 techniciens
        User::factory()
            ->count(300)
            ->create()
            ->each(function ($user) {
                Role::factory()->technicien()->create(['user_id' => $user->id]);
            });

        // Créer 20 prestations
        Prestation::factory()
            ->count(20)
            ->create();
    }
}