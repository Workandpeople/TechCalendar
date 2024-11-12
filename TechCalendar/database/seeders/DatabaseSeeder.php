<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Créer l'administrateur avec des infos spécifiques
        $admin = User::factory()->create([
            'nom' => 'Dinnichert',
            'prenom' => 'Lucas',
            'email' => 'contact@lucas-dinnichert.fr',  // à personnaliser
        ]);
        Role::create([
            'user_id' => $admin->id,
            'role' => 'administrateur',
        ]);

        // Créer 3 assistantes
        User::factory()
            ->count(3)
            ->create()
            ->each(function ($user) {
                Role::create([
                    'user_id' => $user->id,
                    'role' => 'assistante',
                ]);
            });

        // Créer les autres utilisateurs en tant que techniciens
        User::factory()
            ->count(16)
            ->create()
            ->each(function ($user) {
                Role::create([
                    'user_id' => $user->id,
                    'role' => 'technicien',
                ]);
            });
    }
}